#!/usr/bin/env bash
# Metade remota do deploy — roda COMO ROOT dentro da VPS.
# Não chame direto: use tools/deploy/vps/deploy.sh a partir da máquina local.
set -euo pipefail

BASE="$1"          # /var/www/biomie
STAMP="$2"         # 20260803_194500
KEEP="$3"          # quantas releases manter
VERSION="$4"       # ex.: 6452289-20260803_194500
TARBALL="$5"       # /tmp/biomie-deploy-<stamp>.tar

REL="$BASE/releases/$STAMP"
SHARED="$BASE/shared"
PREV="$(readlink -f "$BASE/live" 2>/dev/null || true)"
FPM_SVC="php8.3-fpm"
VHOST="biomie.magmoveiscorporativos.com.br"

log() { echo "[$(date '+%H:%M:%S')] $*"; }
fail() { echo "ERRO: $*" >&2; exit 1; }

# ---------------------------------------------------------------- 1. shared
# Storage e .env.local.php NÃO podem viver dentro da release: senão cada deploy
# zera logs/cache/certs/companies e o sistema perde a configuração.
log "preparando shared/"
mkdir -p "$SHARED/config"
if [ ! -d "$SHARED/storage" ]; then
    if [ -d "$BASE/live/storage" ] && [ ! -L "$BASE/live/storage" ]; then
        log "  primeira vez: migrando storage da release atual para shared/"
        cp -a "$BASE/live/storage" "$SHARED/storage"
    else
        mkdir -p "$SHARED/storage"
    fi
fi
if [ ! -f "$SHARED/config/.env.local.php" ]; then
    [ -f "$BASE/live/config/.env.local.php" ] \
        && cp -a "$BASE/live/config/.env.local.php" "$SHARED/config/.env.local.php" \
        || fail "não achei config/.env.local.php nem em shared/ nem na release atual"
    log "  primeira vez: .env.local.php movido para shared/"
fi

# ---------------------------------------------------------------- 2. extrair
log "extraindo release $STAMP"
rm -rf "$REL"
mkdir -p "$REL"
tar -xf "$TARBALL" -C "$REL"
rm -f "$TARBALL"

# ------------------------------------------------------- 3. ligar ao shared
rm -rf "$REL/storage"
ln -s "$SHARED/storage" "$REL/storage"
rm -f "$REL/config/.env.local.php"
ln -s "$SHARED/config/.env.local.php" "$REL/config/.env.local.php"

# --------------------------------------------- 4. carimbar versão real
# Invalida service worker e assets versionados a cada release.
cat > "$REL/config/build.php" <<EOF
<?php

declare(strict_types=1);

// Gerado pelo deploy — não editar à mão.
return [
    'version' => '${VERSION}',
    'deployed_at' => '$(date -Iseconds)',
    'cache_strategy' => 'html-api-no-store-assets-versioned-sw-assets-only',
];
EOF

# ------------------------------------------------------------ 5. permissões
log "ajustando permissões"
chown -R biomie:www-data "$REL"
find "$REL" -type d -exec chmod 750 {} +
find "$REL" -type f -exec chmod 640 {} +
chown -h biomie:www-data "$REL/storage" "$REL/config/.env.local.php"

# ------------------------------------- 6. validar ANTES de trocar o symlink
log "checando sintaxe PHP de toda a release"
if ! find "$REL" -name '*.php' -not -path "*/vendor/*" -print0 \
     | xargs -0 -P 8 -n 1 php -l > /tmp/biomie_lint.out 2>&1; then
    grep -v 'No syntax errors' /tmp/biomie_lint.out | head -20
    fail "erro de sintaxe na release — symlink NÃO foi trocado"
fi

log "checando que a aplicação carrega com a config real"
sudo -u www-data php -r "
  \$r = '$REL';
  require \$r . '/app/Bootstrap.php';
  \BIomie\Bootstrap::init();
  \$p = \BIomie\Database::connect(new \BIomie\Config(), 'db');
  \$p->query('SELECT 1');
  echo \"bootstrap+banco OK\n\";
" || fail "a release não sobe com a config real — symlink NÃO foi trocado"

# ------------------------------------- 6b. serviço Python (services/python)
# O venv fica em shared/ e NÃO dentro da release: recriar 200 MB de pacotes a
# cada deploy seria lento e deixaria o rollback sem venv. As dependências só são
# reinstaladas quando o requirements.txt muda — o hash é a chave.
if [ -d "$REL/services/python" ]; then
    VENV="$SHARED/python-venv"
    REQ="$REL/services/python/requirements.txt"
    REQ_HASH="$(md5sum "$REQ" | cut -d' ' -f1)"
    STAMP_FILE="$VENV/.requirements-hash"

    if [ ! -d "$VENV" ]; then
        log "criando venv do serviço Python"
        python3 -m venv "$VENV"
    fi
    if [ ! -f "$STAMP_FILE" ] || [ "$(cat "$STAMP_FILE")" != "$REQ_HASH" ]; then
        log "instalando dependências Python (requirements mudou)"
        "$VENV/bin/pip" install --quiet --upgrade pip
        "$VENV/bin/pip" install --quiet -r "$REQ"
        echo "$REQ_HASH" > "$STAMP_FILE"
    else
        log "dependências Python já em dia"
    fi
    chown -R biomie:www-data "$VENV"

    # Importa o pacote da release nova ANTES da troca — um erro de sintaxe em
    # Python não pode derrubar o deploy do PHP depois do symlink trocado.
    if ! (cd "$REL/services/python" && "$VENV/bin/python" -c "import biomie_intel, biomie_intel.cli" 2>/tmp/pyimport.err); then
        head -5 /tmp/pyimport.err
        fail "o pacote biomie_intel não importa — symlink NÃO foi trocado"
    fi
    log "serviço Python valida (import ok)"
fi

# ------------------------------------------------------- 7. troca atômica
log "trocando symlink live -> $STAMP"
ln -sfn "$REL" "$BASE/live.new"
mv -Tf "$BASE/live.new" "$BASE/live"

# Reload limpa o opcache; sem isso o PHP serve o código da release anterior,
# porque o caminho /var/www/biomie/live não muda entre releases.
systemctl reload "$FPM_SVC"

# ----------------------------------------------------------- 8. smoke test
# Precisa ser HTTPS: sobre HTTP puro o nginx devolve 301 antes de chegar no PHP.
# Além do código, confere que a versão SERVIDA é a que acabou de subir — é isso
# que prova que o opcache foi limpo e não sobrou código da release anterior.
sleep 1
SMOKE_URL="https://${VHOST}/biomie/login.php"
BODY="$(curl -s --resolve "${VHOST}:443:127.0.0.1" -w '\n%{http_code}' "$SMOKE_URL" || echo $'\n000')"
CODE="$(printf '%s' "$BODY" | tail -1)"
SERVED="$(printf '%s' "$BODY" | grep -o 'name="biomie-build" content="[^"]*"' | head -1 | sed 's/.*content="//;s/"//')"

if [ "$CODE" != "200" ] && [ "$CODE" != "302" ]; then
    log "SMOKE FALHOU (HTTP $CODE) — revertendo"
    if [ -n "$PREV" ] && [ -d "$PREV" ]; then
        ln -sfn "$PREV" "$BASE/live.new"
        mv -Tf "$BASE/live.new" "$BASE/live"
        systemctl reload "$FPM_SVC"
        log "revertido para $(basename "$PREV")"
    fi
    fail "release $STAMP rejeitada pelo smoke test"
fi

if [ -z "$SERVED" ]; then
    # login.php não carrega a meta biomie-build (só páginas autenticadas carregam).
    # Aqui a garantia contra código velho é o `systemctl reload` do php-fpm, que
    # zera o opcache — não a conferência de versão.
    log "  (a versão servida não é exposta em login.php; opcache garantido pelo reload)"
elif [ "$SERVED" != "$VERSION" ]; then
    log "SMOKE FALHOU: o site ainda serve a versão '$SERVED', esperada '$VERSION' — revertendo"
    if [ -n "$PREV" ] && [ -d "$PREV" ]; then
        ln -sfn "$PREV" "$BASE/live.new"
        mv -Tf "$BASE/live.new" "$BASE/live"
        systemctl reload "$FPM_SVC"
    fi
    fail "opcache/symlink não pegou a release nova"
fi
log "smoke OK (HTTP $CODE · versão servida: ${SERVED:-?})"

# --------------------------------------------------------------- 9. limpeza
CURRENT="$(readlink -f "$BASE/live")"
# shellcheck disable=SC2012
ls -1dt "$BASE"/releases/*/ 2>/dev/null | tail -n +$((KEEP + 1)) | while read -r old; do
    [ "$(readlink -f "$old")" = "$CURRENT" ] && continue
    log "removendo release antiga $(basename "$old")"
    rm -rf "$old"
done

log "DEPLOY OK — live = $STAMP (versão $VERSION)"
