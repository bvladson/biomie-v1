#!/bin/sh
set -u

# ── BIomie · Enriquecimento geográfico recorrente (todas as empresas) ──────────
#
# Preenche cidade/bairro dos clientes via Omie ConsultarCliente para CADA empresa
# ativa. Idempotente: geo_r21_enrich roda com only_missing=true, então só toca
# clientes ainda sem cidade — quando uma empresa está 100% enriquecida, o custo é
# zero (0 elegíveis). Bounded por --limit para não estourar quota num único run;
# execuções diárias sucessivas drenam a fila e capturam clientes novos.
#
# As chamadas Omie passam pelo coordenador de quota GLOBAL (OmieRateLimiter), que
# limita o total entre as empresas e propaga cooldown — seguro rodar em sequência.
#
# O rebuild do mart geo NÃO é feito aqui: o cron materialize.sh / geo_rebuild.sh
# já re-materializa mart_compradores_por_geo e capta os novos endereços.
#
# Agendar no hPanel (ex.: diário 04:10, após o sync da madrugada):
#   10 4 * * *  /bin/sh /home/.../biomie/cron/hostinger/geo_enrich.sh

# Raiz DERIVADA da propria localizacao do script, nao chumbada.
#
# O default aqui era o caminho do HOST ANTIGO. Funcionava so porque
# /etc/cron.d/biomie define BIOMIE_PROJECT_ROOT -- tire a variavel, ou rode o
# script a mao, e ele passa a apontar para um diretorio de OUTRA maquina, em
# silencio. Derivar de $0 esta certo nas duas maquinas e em qualquer release.
PROJECT_ROOT="${BIOMIE_PROJECT_ROOT:-$(cd "$(dirname "$0")/../.." && pwd)}"
PHP_BIN="${BIOMIE_PHP_BIN:-/usr/bin/php}"
LOG_DIR="$PROJECT_ROOT/storage/logs/cron"
LOCK_DIR="$PROJECT_ROOT/storage/locks"
LOG_FILE="$LOG_DIR/geo_enrich.log"
LOCK_FILE="$LOCK_DIR/geo_enrich.lock"

# Bounded por run. only_missing=true torna o custo proporcional ao que falta.
LIMIT="${BIOMIE_GEO_ENRICH_LIMIT:-50}"
BATCHES="${BIOMIE_GEO_ENRICH_BATCHES:-3}"
SLEEP_MS="${BIOMIE_GEO_ENRICH_SLEEP_MS:-150}"
COMPANIES="${BIOMIE_GEO_ENRICH_COMPANIES:-MAG_FILIAL EXPERT UNI_DESIGN ERGOOFFICE MAG_MATRIZ}"

mkdir -p "$LOG_DIR" "$LOCK_DIR" || exit 1
cd "$PROJECT_ROOT" || exit 1

echo "[$(date '+%Y-%m-%d %H:%M:%S')] geo_enrich start (limit=$LIMIT batches=$BATCHES)" >> "$LOG_FILE"

# Lock para não sobrepor execuções (libera ao sair).
if command -v flock >/dev/null 2>&1; then
  exec 9>"$LOCK_FILE"
  if ! flock -n 9; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] geo_enrich skip: lock em uso" >> "$LOG_FILE"
    exit 0
  fi
fi

code=0
for SLUG in $COMPANIES; do
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] geo_enrich company=$SLUG" >> "$LOG_FILE"
  "$PHP_BIN" bin/geo_r21_enrich.php --company="$SLUG" --limit="$LIMIT" --batches="$BATCHES" --sleep="$SLEEP_MS" \
    >> "$LOG_FILE" 2>&1
  c=$?
  if [ "$c" -ne 0 ]; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] geo_enrich WARN company=$SLUG exit=$c" >> "$LOG_FILE"
    code=$c
  fi
done

echo "[$(date '+%Y-%m-%d %H:%M:%S')] geo_enrich finish exit=$code" >> "$LOG_FILE"
exit "$code"
