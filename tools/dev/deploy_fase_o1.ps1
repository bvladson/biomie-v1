# Fase O.1 — Deploy Base Fiscal Comercial Extendida (BIomie)
#
# Cobertura Fase O.1:
#   - migrate_fiscal_value_basis.php reescrito: --status/--apply, 7 novas colunas, 4 índices
#   - backfill_fiscal_value_basis.php NOVO: --status/--dry-run/--apply, XML NF-e parsing
#   - FiscalValueBasis.php: hasInsurance(), preComputedBaseSql(), commercialBaseFromRow() atualizado
#   - CanonicalReadSupport.php: commercialBaseSql() 3-tier, invoiceSelectSql() com novas cols
#   - CanonicalWriteRepository.php: tryUpdateFiscalBasis() escreve 7 campos O.1 em sync
#   - fiscal_value_basis_audit.php: diagnóstico completo por escopo
#   - fiscal_value_basis_guard.php NOVO: 10 checks de integridade
#
# Sequência obrigatória pós-deploy:
#   1. migrate --apply      (schema)
#   2. backfill --dry-run   (validar)
#   3. backfill --apply     (dados)
#   4. guard                (integridade)
#   5. audit                (totais)
#   6. rematerializar marts
#
# Uso: .\tools\dev\deploy_fase_o1.ps1
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

Write-Host "`n=== FASE O.1 DEPLOY — Base Fiscal Extendida ===" -ForegroundColor Yellow
Write-Host "Servidor: $srv:$port" -ForegroundColor Yellow
Write-Host "Destino : $remote`n" -ForegroundColor Yellow

# ── 1. Helper — FiscalValueBasis (atualizado: hasInsurance, preComputedBaseSql) ─
Upload "app\Support\FiscalValueBasis.php" "app/Support/FiscalValueBasis.php"

# ── 2. Canonical Write Repository (tryUpdateFiscalBasis escreve 7 campos) ──────
Upload "app\Canonical\CanonicalWriteRepository.php" "app/Canonical/CanonicalWriteRepository.php"

# ── 3. Read support — commercialBaseSql 3-tier + novas colunas em invoiceSelectSql
Upload "app\DataPlatform\Silver\Readers\CanonicalReadSupport.php" "app/DataPlatform/Silver/Readers/CanonicalReadSupport.php"

# ── 4. Ferramentas de migração e diagnóstico ─────────────────────────────────
Upload "tools\dev\migrate_fiscal_value_basis.php"   "tools/dev/migrate_fiscal_value_basis.php"
Upload "tools\dev\backfill_fiscal_value_basis.php"  "tools/dev/backfill_fiscal_value_basis.php"
Upload "tools\dev\fiscal_value_basis_audit.php"     "tools/dev/fiscal_value_basis_audit.php"
Upload "tools\dev\fiscal_value_basis_guard.php"     "tools/dev/fiscal_value_basis_guard.php"

Write-Host "`n=== UPLOADS CONCLUIDOS ===" -ForegroundColor Green
Write-Host "`nPROXIMOS PASSOS (rodar no servidor via SSH):" -ForegroundColor Yellow
Write-Host @"

cd $remote

# 1. Lint de todos os arquivos
php -l app/Support/FiscalValueBasis.php
php -l app/Canonical/CanonicalWriteRepository.php
php -l app/DataPlatform/Silver/Readers/CanonicalReadSupport.php
php -l tools/dev/migrate_fiscal_value_basis.php
php -l tools/dev/backfill_fiscal_value_basis.php
php -l tools/dev/fiscal_value_basis_audit.php
php -l tools/dev/fiscal_value_basis_guard.php
echo "--- Lint OK ---"

# 2. Resetar OPcache
curl -s "https://magmoveiscorporativos.com.br/biomie/tools/dev/opcache_reset.php?token=TOKEN" | python3 -m json.tool

# 3. Status da migração (antes de aplicar)
php tools/dev/migrate_fiscal_value_basis.php --status

# 4. Aplicar migração (adiciona 7 novas colunas + 4 índices)
php tools/dev/migrate_fiscal_value_basis.php --apply

# 5. Status pós-migração
php tools/dev/migrate_fiscal_value_basis.php --status

# 6. Diagnóstico PRÉ-backfill
php tools/dev/fiscal_value_basis_audit.php --start=2026-05-01 --end=2026-05-31

# 7. Dry-run do backfill (maio/2026 primeiro)
php tools/dev/backfill_fiscal_value_basis.php --dry-run --start=2026-05-01 --end=2026-05-31

# 8. Aplicar backfill em maio/2026
php tools/dev/backfill_fiscal_value_basis.php --apply --start=2026-05-01 --end=2026-05-31

# 9. Status do backfill
php tools/dev/backfill_fiscal_value_basis.php --status

# 10. Guard de integridade — todos os 10 checks devem passar
php tools/dev/fiscal_value_basis_guard.php --start=2026-05-01 --end=2026-05-31

# 11. Auditoria final
php tools/dev/fiscal_value_basis_audit.php --start=2026-05-01 --end=2026-05-31 --verbose

# 12. Rematerializar marts (recalcula com nova base fiscal pré-computada)
php artisan gold:materialize-commercial --start=2026-05-01 --end=2026-05-31

# Critério de aceite maio/2026:
#   - fiscal_value_basis_guard.php: 10/10 PASS
#   - fiscal_value_basis_audit.php [commercial]:
#       gross_total_nf       ≈ R$ 421.998,04
#       valor_frete          ≈ R$     650,00
#       valor_outras_despesas≈ R$     300,00
#       valor_seguro         = R$       0,00
#       commercial_base_nf   ≈ R$ 421.048,04
#   - backfill_fiscal_value_basis.php --status: pending = 0

"@
