# Fase N.1 — Deploy Busca de NFs (BIomie)
#
# Cobre apenas os arquivos da Fase N.1 (unified orders panel + NF search fix).
# Para deploy completo M+N+N.1, use deploy_fase_m.ps1.
#
# Uso: .\tools\dev\deploy_fase_n1.ps1
# Requer: pscp.exe no PATH (PuTTY em C:\Program Files\PuTTY\)

$base   = "c:\Users\Usuario\Downloads\biomie"
$srv    = "u106688109@82.112.247.199"
$port   = 65002
$remote = "/home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie"
$pscp   = "pscp"

function Upload($local, $remotePath) {
    Write-Host "→ $local" -ForegroundColor Cyan
    & $pscp -P $port "$base\$local" "${srv}:${remote}/${remotePath}"
    if ($LASTEXITCODE -ne 0) { Write-Host "  ERRO no upload" -ForegroundColor Red; exit 1 }
}

Write-Host "`n=== FASE N.1 DEPLOY — Busca de NFs ===" -ForegroundColor Yellow
Write-Host "Servidor: $srv:$port" -ForegroundColor Yellow
Write-Host "Destino : $remote`n" -ForegroundColor Yellow

# ── 1. API — fix root cause (biomie_notas_fiscais_canonicas) ─────────────────
Upload "api\orders_search.php" "api/orders_search.php"

# ── 2. View — unified mag-orders-panel ───────────────────────────────────────
Upload "views\pedidos.html" "views/pedidos.html"

# ── 3. CSS — mag-orders-panel classes ────────────────────────────────────────
Upload "assets\css\module-pages.css" "assets/css/module-pages.css"

# ── 4. JS — page-drilldown reescrito + app.js exposures ─────────────────────
Upload "assets\js\modules\orders\page-drilldown.js" "assets/js/modules/orders/page-drilldown.js"
Upload "assets\js\app.js"                           "assets/js/app.js"

# ── 5. Ferramenta de validação ────────────────────────────────────────────────
Upload "tools\dev\orders_search_reconciliation.php" "tools/dev/orders_search_reconciliation.php"

# ── 6. Service Worker (version bump) ─────────────────────────────────────────
Upload "sw.js" "sw.js"

Write-Host "`n=== UPLOADS CONCLUIDOS ===" -ForegroundColor Green
Write-Host "`nPROXIMOS PASSOS (rodar no servidor via SSH):" -ForegroundColor Yellow
Write-Host @"

cd $remote

# 1. Lint
php -l api/orders_search.php
php -l tools/dev/orders_search_reconciliation.php
echo "--- Lint OK ---"

# 2. Resetar OPcache (substitua TOKEN)
curl -s "https://magmoveiscorporativos.com.br/biomie/tools/dev/opcache_reset.php?token=TOKEN" | python3 -m json.tool

# 3. Validar busca NFs (critério de aceite)
php tools/dev/orders_search_reconciliation.php --start=2026-05-01 --end=2026-05-31

# Critério de aceite visual:
# ┌─────────────────────────────────────────────────────────────────┐
# │ Faturamento Orçamento-PD                                        │
# │ [Orçamento-PD 165] [Remessa 9] [Todas 174]                      │
# │ [Buscar NF, pedido, cliente, CNPJ...] [tune] [Limpar]           │
# │ 165 NFs · R$ 408.287,30 · Merc: R$ 402.666,41                  │
# │ [tabela]                                                        │
# └─────────────────────────────────────────────────────────────────┘
# - Busca por "1336" → retorna NF 000001336
# - Falha de rede → "Não foi possível aplicar a busca agora."
#   → tabela anterior permanece visível (não some)
# - scope=commercial nunca retorna NF de remessa e vice-versa

"@
