# Fase O — Deploy Base Fiscal Comercial (BIomie)
#
# Cobre os arquivos da Fase O (base comercial = valor_total - frete - outras - seguro).
# Para deploy completo M+N+N.1+O, adicionar os arquivos desta fase ao deploy_fase_m.ps1.
#
# Sequência obrigatória pós-deploy:
#   1. migrate_fiscal_value_basis.php  (adiciona colunas ao schema)
#   2. Disparar sync de NFs            (popula os novos campos)
#   3. Rematerializar marts            (recalcula com nova base)
#   4. fiscal_value_basis_audit.php    (validação)
#
# Uso: .\tools\dev\deploy_fase_o.ps1
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

Write-Host "`n=== FASE O DEPLOY — Base Fiscal Comercial ===" -ForegroundColor Yellow
Write-Host "Servidor: $srv:$port" -ForegroundColor Yellow
Write-Host "Destino : $remote`n" -ForegroundColor Yellow

# ── 1. Helper — FiscalValueBasis ─────────────────────────────────────────────
Upload "app\Support\FiscalValueBasis.php" "app/Support/FiscalValueBasis.php"

# ── 2. Mapper — extração de vIPI, vFrete, vOutro, vSeg do Omie ───────────────
Upload "app\FiscalSync\FiscalCanonicalMapper.php" "app/FiscalSync/FiscalCanonicalMapper.php"

# ── 3. Write repository — tryUpdateFiscalBasis ────────────────────────────────
Upload "app\Canonical\CanonicalWriteRepository.php" "app/Canonical/CanonicalWriteRepository.php"

# ── 4. Read support — invoiceSelectSql + commercialBaseSql ───────────────────
Upload "app\DataPlatform\Silver\Readers\CanonicalReadSupport.php" "app/DataPlatform/Silver/Readers/CanonicalReadSupport.php"

# ── 5. FiscalReadRepository — summarizeInvoicesCommercial usa base fiscal ─────
Upload "app\Modules\Fiscal\Infrastructure\FiscalReadRepository.php" "app/Modules/Fiscal/Infrastructure/FiscalReadRepository.php"

# ── 6. Gold mart builders — valor_nfe / faturamento usa base fiscal ───────────
Upload "app\DataPlatform\Gold\Builders\VendorDayMartBuilder.php"     "app/DataPlatform/Gold/Builders/VendorDayMartBuilder.php"
Upload "app\DataPlatform\Gold\Builders\CustomerPeriodMartBuilder.php" "app/DataPlatform/Gold/Builders/CustomerPeriodMartBuilder.php"
Upload "app\DataPlatform\Gold\Builders\CnaeSalesMartBuilder.php"      "app/DataPlatform/Gold/Builders/CnaeSalesMartBuilder.php"
Upload "app\DataPlatform\Gold\Builders\GeoBuyerMartBuilder.php"       "app/DataPlatform/Gold/Builders/GeoBuyerMartBuilder.php"

# ── 7. APIs — breakdown fiscal completo ──────────────────────────────────────
Upload "api\invoice_detail.php"  "api/invoice_detail.php"
Upload "api\orders_search.php"   "api/orders_search.php"

# ── 8. View — coluna "Base Comercial" ────────────────────────────────────────
Upload "views\pedidos.html" "views/pedidos.html"

# ── 9. CSS — .nf-freight-indicator ───────────────────────────────────────────
Upload "assets\css\module-pages.css" "assets/css/module-pages.css"

# ── 10. JS — page-drilldown usa base_value ───────────────────────────────────
Upload "assets\js\modules\orders\page-drilldown.js" "assets/js/modules/orders/page-drilldown.js"

# ── 11. Ferramentas de migração e diagnóstico ─────────────────────────────────
Upload "tools\dev\migrate_fiscal_value_basis.php" "tools/dev/migrate_fiscal_value_basis.php"
Upload "tools\dev\fiscal_value_basis_audit.php"   "tools/dev/fiscal_value_basis_audit.php"

# ── 12. Service Worker (version bump) ────────────────────────────────────────
Upload "sw.js" "sw.js"

Write-Host "`n=== UPLOADS CONCLUIDOS ===" -ForegroundColor Green
Write-Host "`nPROXIMOS PASSOS (rodar no servidor via SSH):" -ForegroundColor Yellow
Write-Host @"

cd $remote

# 1. Lint
php -l app/Support/FiscalValueBasis.php
php -l app/FiscalSync/FiscalCanonicalMapper.php
php -l app/Canonical/CanonicalWriteRepository.php
php -l app/DataPlatform/Silver/Readers/CanonicalReadSupport.php
php -l app/Modules/Fiscal/Infrastructure/FiscalReadRepository.php
php -l app/DataPlatform/Gold/Builders/VendorDayMartBuilder.php
php -l app/DataPlatform/Gold/Builders/CustomerPeriodMartBuilder.php
php -l app/DataPlatform/Gold/Builders/CnaeSalesMartBuilder.php
php -l app/DataPlatform/Gold/Builders/GeoBuyerMartBuilder.php
php -l api/invoice_detail.php
php -l api/orders_search.php
php -l tools/dev/migrate_fiscal_value_basis.php
php -l tools/dev/fiscal_value_basis_audit.php
echo "--- Lint OK ---"

# 2. Resetar OPcache
curl -s "https://magmoveiscorporativos.com.br/biomie/tools/dev/opcache_reset.php?token=TOKEN" | python3 -m json.tool

# 3. Diagnóstico PRÉ-migração (mostra estado atual, sem alterar dados)
php tools/dev/fiscal_value_basis_audit.php --start=2026-05-01 --end=2026-05-31

# 4. Migração de schema (adiciona colunas valor_ipi/frete/outras/seguro)
php tools/dev/migrate_fiscal_value_basis.php

# 5. Diagnóstico PÓS-migração (confirma que colunas existem, valores ainda NULL)
php tools/dev/fiscal_value_basis_audit.php --start=2026-05-01 --end=2026-05-31

# 6. Rematerializar marts com nova base fiscal
php artisan gold:materialize-commercial --start=2026-05-01 --end=2026-05-31

# 7. Diagnóstico FINAL (valores NULL até re-sync das NFs)
php tools/dev/fiscal_value_basis_audit.php --verbose --start=2026-05-01 --end=2026-05-31

# Critério de aceite:
# - fiscal_value_basis_audit.php: "Todas as colunas de base fiscal presentes"
# - api/orders_search.php retorna base_value e valor_base_comercial por row
# - api/invoice_detail.php retorna totals.freight, totals.base_comercial
# - Tabela de pedidos mostra coluna "Base Comercial" em vez de "Total"
# - Indicador +F aparece em NFs com frete > 0 (após re-sync)

"@
