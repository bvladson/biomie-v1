# Fase M — Deploy + Validação BIomie
# Uso: .\tools\dev\deploy_fase_m.ps1
# Requer: pscp.exe no PATH (PuTTY em C:\Program Files\PuTTY\)

$base = "c:\Users\Usuario\Downloads\biomie"
$srv  = "u106688109@82.112.247.199"
$port = 65002
$remote = "/home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie"
$pscp = "pscp"

function Upload($local, $remotePath) {
    Write-Host "→ $local" -ForegroundColor Cyan
    & $pscp -P $port "$base\$local" "${srv}:${remote}/${remotePath}"
    if ($LASTEXITCODE -ne 0) { Write-Host "  ERRO no upload" -ForegroundColor Red; exit 1 }
}

Write-Host "`n=== FASE M DEPLOY ===" -ForegroundColor Yellow
Write-Host "Servidor: $srv:$port" -ForegroundColor Yellow
Write-Host "Destino : $remote`n" -ForegroundColor Yellow

# ── 1. Builders — ProductPeriodMartBuilder ($ownsTransaction fix) ─────────────
Upload "app\DataPlatform\Gold\Builders\ProductPeriodMartBuilder.php" "app/DataPlatform/Gold/Builders/ProductPeriodMartBuilder.php"

# ── 2. Overview — enforce-scope bypass + summary floats ───────────────────────
Upload "app\Modules\Sales\Application\Overview\OverviewPayloadBuilder.php"         "app/Modules/Sales/Application/Overview/OverviewPayloadBuilder.php"
Upload "app\Modules\Sales\Application\Overview\CanonicalOverviewPayloadBuilder.php" "app/Modules/Sales/Application/Overview/CanonicalOverviewPayloadBuilder.php"

# ── 3. Vendors — enforce-scope bypass + summary floats ───────────────────────
Upload "app\Modules\Sales\Application\Vendors\VendorPayloadBuilder.php"         "app/Modules/Sales/Application/Vendors/VendorPayloadBuilder.php"
Upload "app\Modules\Sales\Application\Vendors\CanonicalVendorPayloadBuilder.php" "app/Modules/Sales/Application/Vendors/CanonicalVendorPayloadBuilder.php"

# ── 4. Orders — enforce-scope bypass + 3-tab summary ─────────────────────────
Upload "api\orders.php" "api/orders.php"

# ── 5. Diagnostic tool ────────────────────────────────────────────────────────
Upload "tools\dev\orcpd_page_reconciliation.php"        "tools/dev/orcpd_page_reconciliation.php"
Upload "tools\dev\release_integrity_check.php"          "tools/dev/release_integrity_check.php"
Upload "tools\dev\orders_search_reconciliation.php"     "tools/dev/orders_search_reconciliation.php"

# ── 6. Visual — KPI cards + mobile FAB (Fase N) ──────────────────────────────
Upload "assets\css\flight-plan-clean.css"  "assets/css/flight-plan-clean.css"
Upload "assets\css\mag-design-system.css"  "assets/css/mag-design-system.css"
Upload "views\plano_voo.html"              "views/plano_voo.html"

# ── 7. Fase N.1 — Unified orders panel + NF search fix ───────────────────────
Upload "api\orders_search.php"                              "api/orders_search.php"
Upload "views\pedidos.html"                                 "views/pedidos.html"
Upload "assets\css\module-pages.css"                        "assets/css/module-pages.css"
Upload "assets\js\modules\orders\page-drilldown.js"         "assets/js/modules/orders/page-drilldown.js"
Upload "assets\js\app.js"                                   "assets/js/app.js"

# ── 8. Service Worker (version bump) ─────────────────────────────────────────
Upload "sw.js" "sw.js"

Write-Host "`n=== UPLOADS CONCLUIDOS ===" -ForegroundColor Green
Write-Host "`nPROXIMOS PASSOS (rodar no servidor via SSH):" -ForegroundColor Yellow
Write-Host @"

cd $remote

# 1. Verificar sintaxe PHP
php -l app/DataPlatform/Gold/Builders/ProductPeriodMartBuilder.php
php -l app/Modules/Sales/Application/Overview/OverviewPayloadBuilder.php
php -l app/Modules/Sales/Application/Overview/CanonicalOverviewPayloadBuilder.php
php -l app/Modules/Sales/Application/Vendors/VendorPayloadBuilder.php
php -l app/Modules/Sales/Application/Vendors/CanonicalVendorPayloadBuilder.php
php -l api/orders.php
php -l api/orders_search.php
php -l tools/dev/orcpd_page_reconciliation.php
php -l tools/dev/orders_search_reconciliation.php
echo "--- Lint OK ---"

# 2b. Verificar sintaxe CSS (tamanho e encoding, não lint completo)
wc -l assets/css/flight-plan-clean.css assets/css/mag-design-system.css

# 2. Resetar OPcache (substitua TOKEN)
curl -s "https://magmoveiscorporativos.com.br/biomie/tools/dev/opcache_reset.php?token=TOKEN" | python3 -m json.tool

# 3. Reconciliação de páginas (validação principal da Fase M)
php tools/dev/orcpd_page_reconciliation.php --start=2026-05-01 --end=2026-05-31

# 4. Guard comercial
php tools/dev/commercial_kpi_scope_guard.php --start=2026-05-01 --end=2026-05-31

# 5. Rematerializar mart CNAE + produto (rebuild com dados limpos)
php tools/dev/rematerialize_cnae_mart.php --start=2026-02-01 --end=2026-05-31

# 6. Rodar materializacao comercial (agora sem erro de transação dupla)
php artisan gold:materialize-commercial --start=2026-05-01 --end=2026-05-31

# 7. Auditoria KPIs
php tools/dev/commercial_recalibration_audit.php --start=2026-05-01 --end=2026-05-31

# 8. Validação busca NFs (Fase N.1)
php tools/dev/orders_search_reconciliation.php --start=2026-05-01 --end=2026-05-31

# Validação esperada após Fase M+N+N.1:
# - index.php      : R$ 408.287,30 / 165 NFs
# - vendedores     : R$ 408.287,30 / 165 NFs
# - pedidos        : R$ 408.287,30 / 165 NFs comerciais + 9 NFs remessa
# - mercadorias    : R$ 408.287,30 / 165 NFs (já correto)
# - busca NFs      : "Erro na busca" resolvido; scope isolation OK

"@
