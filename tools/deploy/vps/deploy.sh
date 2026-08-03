#!/usr/bin/env bash
# Deploy do BIomie para a VPS — rode a partir da RAIZ do repositório, no Git Bash.
#
#   bash tools/deploy/vps/deploy.sh              # deploya o HEAD
#   bash tools/deploy/vps/deploy.sh <commit>     # deploya um commit específico
#   bash tools/deploy/vps/deploy.sh --dry-run    # só valida, não envia nada
#
# A fonte do deploy é o GIT (git archive), nunca a árvore de trabalho: o que vai
# para produção é exatamente o que está commitado.
set -euo pipefail

HOST="${BIOMIE_VPS_HOST:-179.197.238.57}"
SSH_USER="${BIOMIE_VPS_USER:-root}"
KEY="${BIOMIE_VPS_KEY:-$HOME/.ssh/id_ed25519_biomie_vps}"
BASE="${BIOMIE_VPS_BASE:-/var/www/biomie}"
KEEP="${BIOMIE_KEEP_RELEASES:-5}"

DRY_RUN=0
SKIP_MINIFY=0
REF="HEAD"
for arg in "$@"; do
    case "$arg" in
        --dry-run)      DRY_RUN=1 ;;
        --skip-minify)  SKIP_MINIFY=1 ;;
        -*)             echo "flag desconhecida: $arg" >&2; exit 2 ;;
        *)              REF="$arg" ;;
    esac
done

cd "$(git rev-parse --show-toplevel)"
SSH="ssh -o BatchMode=yes -o ConnectTimeout=15 -i $KEY ${SSH_USER}@${HOST}"

step() { echo; echo "==> $*"; }
fail() { echo "ERRO: $*" >&2; exit 1; }

# ------------------------------------------------- 1. a árvore está limpa?
step "verificando o repositório"
[ -z "$(git status --porcelain)" ] || {
    git status --short | head -20
    fail "há mudanças não commitadas. O deploy sai do git — commite antes."
}
COMMIT="$(git rev-parse --short "$REF")"
echo "    deployando $COMMIT ($(git log -1 --format=%s "$REF"))"

# -------------------------------- 2. assets minificados estão atualizados?
# Asset::preferMinified serve o .min quando ele é >= ao fonte. Como o git archive
# dá o MESMO mtime a todos os arquivos, um .min desatualizado no git seria servido
# calado. Regerar e exigir árvore limpa é o que garante que isso não aconteça.
if [ "$SKIP_MINIFY" -eq 0 ] && command -v powershell.exe >/dev/null 2>&1; then
    step "regerando assets minificados"
    powershell.exe -NoProfile -ExecutionPolicy Bypass -File "tools\\dev\\minify_assets.ps1" >/dev/null 2>&1 || true
    [ -z "$(git status --porcelain)" ] || {
        git status --short | head -20
        fail "a minificação mudou arquivos — commite os .min regerados e rode de novo."
    }
    echo "    minificados em dia"
fi

# ------------------------------------------------------- 3. montar o pacote
STAMP="$(date '+%Y%m%d_%H%M%S')"
VERSION="${COMMIT}-${STAMP}"
TAR="$(mktemp -t biomie-deploy-XXXXXX.tar)"
step "empacotando a partir do git"
git archive --format=tar "$REF" > "$TAR"
echo "    $(du -h "$TAR" | cut -f1) · $(tar -tf "$TAR" | wc -l) arquivos"

if [ "$DRY_RUN" -eq 1 ]; then
    step "--dry-run: nada foi enviado"
    rm -f "$TAR"
    exit 0
fi

# ------------------------------------------------------------- 4. enviar
step "enviando para $HOST"
REMOTE_TAR="/tmp/biomie-deploy-${STAMP}.tar"
$SSH "cat > $REMOTE_TAR" < "$TAR"
rm -f "$TAR"
$SSH "cat > /tmp/biomie_remote_deploy.sh" < tools/deploy/vps/remote_deploy.sh

# ------------------------------------------------------------- 5. executar
step "executando o deploy na VPS"
$SSH "bash /tmp/biomie_remote_deploy.sh '$BASE' '$STAMP' '$KEEP' '$VERSION' '$REMOTE_TAR'"

step "pronto"
echo "    https://biomie.magmoveiscorporativos.com.br/biomie/"
echo "    rollback: bash tools/deploy/vps/rollback.sh"
