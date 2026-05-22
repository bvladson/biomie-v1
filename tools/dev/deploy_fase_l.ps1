# Fase L — Deploy + Validação BIomie
# Uso: .\tools\dev\deploy_fase_l.ps1
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

function Lint($file) {
    Write-Host "  lint $file" -ForegroundColor DarkGray
    & $pscp -P $port -ls "${srv}:${remote}/$file" | Out-Null
    # Lint via plink não funciona em batch — use o painel SSH manualmente para php -l
}

Write-Host "`n=== FASE L DEPLOY ===" -ForegroundColor Yellow
Write-Host "Servidor: $srv:$port" -ForegroundColor Yellow
Write-Host "Destino : $remote`n" -ForegroundColor Yellow

# ── 1. Bug fix: FiscalReadRepository (174→165 NFs) ───────────────────────────
Upload "app\Modules\Fiscal\Infrastructure\FiscalReadRepository.php" "app/Modules/Fiscal/Infrastructure/FiscalReadRepository.php"

# ── 2. JS — app.js (renderPanelState + NF_SCOPE_META + 9 colunas pedidos) ────
Upload "assets\js\app.js" "assets/js/app.js"

# ── 3. JS — render-helpers.js (fallback renderPanelState) ────────────────────
Upload "assets\js\core\render-helpers.js" "assets/js/core/render-helpers.js"

# ── 4. Views ─────────────────────────────────────────────────────────────────
Upload "views\index.html"      "views/index.html"
Upload "views\pedidos.html"    "views/pedidos.html"
Upload "views\vendedores.html" "views/vendedores.html"
Upload "views\mercadorias.html" "views/mercadorias.html"

# ── 5. Service Worker (nova VERSION) ─────────────────────────────────────────
Upload "sw.js" "sw.js"

# ── 6. Builders Fase K ───────────────────────────────────────────────────────
Upload "app\DataPlatform\Gold\Builders\CnaeSalesMartBuilder.php"   "app/DataPlatform/Gold/Builders/CnaeSalesMartBuilder.php"
Upload "app\DataPlatform\Gold\Builders\ProductPeriodMartBuilder.php" "app/DataPlatform/Gold/Builders/ProductPeriodMartBuilder.php"

# ── 7. ProductMixReadRepository (novo — criado na Fase K) ────────────────────
Upload "app\Modules\Sales\Infrastructure\ProductMixReadRepository.php" "app/Modules/Sales/Infrastructure/ProductMixReadRepository.php"

# ── 8. APIs ───────────────────────────────────────────────────────────────────
Upload "api\orders.php"   "api/orders.php"
Upload "api\vendors.php"  "api/vendors.php"
Upload "api\products.php" "api/products.php"
Upload "api\overview.php" "api/overview.php"

# ── 9. Tools ─────────────────────────────────────────────────────────────────
Upload "tools\dev\release_integrity_check.php"     "tools/dev/release_integrity_check.php"
Upload "tools\dev\opcache_reset.php"               "tools/dev/opcache_reset.php"
Upload "tools\dev\omie_export_reconciliation_debug.php" "tools/dev/omie_export_reconciliation_debug.php"
Upload "tools\dev\rematerialize_cnae_mart.php"     "tools/dev/rematerialize_cnae_mart.php"
Upload "tools\dev\commercial_recalibration_audit.php" "tools/dev/commercial_recalibration_audit.php"
Upload "tools\dev\commercial_kpi_scope_guard.php"  "tools/dev/commercial_kpi_scope_guard.php"

Write-Host "`n=== UPLOADS CONCLUIDOS ===" -ForegroundColor Green
Write-Host "`nPROXIMOS PASSOS (rodar no servidor via SSH):" -ForegroundColor Yellow
Write-Host @"

cd $remote

# 1. Verificar sintaxe PHP
php -l app/Modules/Fiscal/Infrastructure/FiscalReadRepository.php
php -l app/Modules/Sales/Infrastructure/ProductMixReadRepository.php
php -l app/DataPlatform/Gold/Builders/CnaeSalesMartBuilder.php
php -l app/DataPlatform/Gold/Builders/ProductPeriodMartBuilder.php
php -l api/orders.php
php -l api/vendors.php
php -l api/products.php

# 2. Verificar integridade dos arquivos
php tools/dev/release_integrity_check.php

# 3. Limpar cache do app
php tools/dev/cache_clear.php --all

# 4. Resetar OPcache via web (substitua TOKEN pelo valor de BIOMIE_ADMIN_TOKEN)
curl -s "https://magmoveiscorporativos.com.br/biomie/tools/dev/opcache_reset.php?token=TOKEN" | python3 -m json.tool

# 5. Golden check comercial
php tools/dev/omie_export_reconciliation_debug.php \
  --start=2026-05-01 --end=2026-05-31 \
  --gross-nf=174 --gross-rev=484369.01 --gross-merch=476483.42 \
  --remit-nf=9   --remit-rev=76081.71  --remit-merch=73817.01 \
  --com-nf=165   --com-rev=408287.30   --com-merch=402666.41

# 6. Guard comercial
php tools/dev/commercial_kpi_scope_guard.php --start=2026-05-01 --end=2026-05-31

# 7. Auditoria KPIs
php tools/dev/commercial_recalibration_audit.php --start=2026-05-01 --end=2026-05-31

# 8. Rematerializar mart CNAE (janela completa)
php tools/dev/rematerialize_cnae_mart.php --start=2026-02-01 --end=2026-05-31

"@
