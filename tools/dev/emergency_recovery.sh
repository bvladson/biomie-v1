#!/bin/sh
# BIomie — Emergency Recovery Script (multi-empresa)
# Corrige: Pipeline Jobs CRITICAL, Shadow Validation CRITICAL, Freshness WARNING, Wallpaper WARNING
#
# Uso no servidor (via SSH):
#   cd /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie
#   sh tools/dev/emergency_recovery.sh

set -u
PHP=/usr/bin/php
ROOT="$(CDPATH= cd "$(dirname "$0")/../.." && pwd)"
echo "[RECOVERY] Diretorio: $ROOT"
echo "[RECOVERY] PHP: $PHP"
echo "[RECOVERY] $(date '+%Y-%m-%d %H:%M:%S') Iniciando recuperacao de emergencia"

# ── 1. Limpar wallpaper cache (remove fallback travado) ───────────────────────
WALLPAPER_CACHE="$ROOT/storage/cache/login_wallpaper.json"
if [ -f "$WALLPAPER_CACHE" ]; then
  rm "$WALLPAPER_CACHE"
  echo "[RECOVERY] [OK] Wallpaper cache removido — sera regenerado no proximo login"
else
  echo "[RECOVERY] [INFO] Wallpaper cache nao encontrado (ja limpo)"
fi

# ── 2. Materializar Gold Mart para todas as empresas ─────────────────────────
echo "[RECOVERY] Materializando gold mart para todas as empresas..."
sh "$ROOT/cron/materialize_all_companies.sh"
echo "[RECOVERY] [OK] Gold mart concluido"

# ── 3. Sync Omie comercial para todas as empresas ────────────────────────────
echo "[RECOVERY] Iniciando sync comercial Omie (delta) — todas as empresas..."
sh "$ROOT/cron/sync_all_companies.sh" --domains=commercial --mode=delta
echo "[RECOVERY] [OK] Sync comercial concluido"

# ── 4. Sync Omie financeiro para todas as empresas ───────────────────────────
echo "[RECOVERY] Iniciando sync financeiro Omie (delta) — todas as empresas..."
sh "$ROOT/cron/sync_all_companies.sh" --domains=finance --mode=delta
echo "[RECOVERY] [OK] Sync financeiro concluido"

# ── 5. Segunda materialização (com dados frescos do sync) ────────────────────
echo "[RECOVERY] Segunda materializacao Gold (pos-sync)..."
sh "$ROOT/cron/materialize_all_companies.sh"
echo "[RECOVERY] [OK] Segunda materializacao concluida"

echo ""
echo "==========================================="
echo "  RECOVERY CONCLUIDO — $(date '+%Y-%m-%d %H:%M:%S')"
echo "==========================================="
echo ""
echo "PROXIMO PASSO OBRIGATORIO: Cadastrar crons no Hostinger hPanel"
echo ""
echo "hPanel → Hosting → Gerenciar → Advanced → Cron Jobs"
echo "Adicionar as 5 entradas de docs/hostinger-cron-setup.md"
echo ""
echo "Entrada 1 — Worker (*/5 min):"
echo "*/5 * * * * $PHP $ROOT/cron/worker.php --queue=high,default,low --limit=50 --max-runtime=240 --stop-when-empty --json >> $ROOT/storage/logs/worker.log 2>&1"
echo ""
echo "Entrada 2 — Sync comercial (*/15 min):"
echo "*/15 * * * * /bin/sh $ROOT/cron/sync_all_companies.sh --domains=commercial --mode=delta >> $ROOT/storage/logs/sync-commercial.log 2>&1"
echo ""
echo "Entrada 3 — Sync financeiro (offsets 7,22,37,52):"
echo "7,22,37,52 * * * * /bin/sh $ROOT/cron/sync_all_companies.sh --domains=finance --mode=delta >> $ROOT/storage/logs/sync-finance.log 2>&1"
echo ""
echo "Entrada 4 — Materialize Gold (*/30 min):"
echo "*/30 * * * * /bin/sh $ROOT/cron/materialize_all_companies.sh >> $ROOT/storage/logs/materialize.log 2>&1"
echo ""
echo "Entrada 5 — Reconcile diario (3h):"
echo "0 3 * * * /bin/sh $ROOT/cron/sync_all_companies.sh --domains=commercial,finance --mode=reconcile --reconcile=7 >> $ROOT/storage/logs/reconcile.log 2>&1"
