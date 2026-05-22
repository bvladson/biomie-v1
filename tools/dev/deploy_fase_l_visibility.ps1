# Fase L (Visibilidade Semântica) — Deploy + Validação BIomie
# Uso: .\tools\dev\deploy_fase_l_visibility.ps1
# Requer: pscp.exe no PATH (PuTTY em C:\Program Files\PuTTY\)

$base   = "c:\Users\Usuario\Downloads\biomie"
$srv    = "u106688109@82.112.247.199"
$port   = 65002
$remote = "/home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie"
$pscp   = "pscp"

function Upload($local, $remotePath) {
    Write-Host "  → $local" -ForegroundColor Cyan
    & $pscp -P $port "$base\$local" "${srv}:${remote}/${remotePath}"
    if ($LASTEXITCODE -ne 0) { Write-Host "  ERRO no upload de $local" -ForegroundColor Red; exit 1 }
}

Write-Host "`n=== FASE L — VISIBILIDADE SEMANTICA LICITACAO ===" -ForegroundColor Yellow
Write-Host "Servidor : $srv`:$port" -ForegroundColor Yellow
Write-Host "Destino  : $remote`n" -ForegroundColor Yellow

# ── 1. Helper central BiVisibilityScope ──────────────────────────────────────
Write-Host "[1/9] BiVisibilityScope helper" -ForegroundColor Green
Upload "app\Support\BiVisibilityScope.php" "app/Support/BiVisibilityScope.php"

# ── 2. Migrations ─────────────────────────────────────────────────────────────
Write-Host "[2/9] Migrations" -ForegroundColor Green
Upload "database\migrations\Version20260522020000_VendorScopeRules.php"         "database/migrations/Version20260522020000_VendorScopeRules.php"
Upload "database\migrations\Version20260522030000_CanonicalVisibilityFields.php" "database/migrations/Version20260522030000_CanonicalVisibilityFields.php"

# ── 3. Mart builders ──────────────────────────────────────────────────────────
Write-Host "[3/9] Mart builders" -ForegroundColor Green
Upload "app\DataPlatform\Gold\Builders\AbstractMartBuilder.php"       "app/DataPlatform/Gold/Builders/AbstractMartBuilder.php"
Upload "app\DataPlatform\Gold\Builders\VendorDayMartBuilder.php"      "app/DataPlatform/Gold/Builders/VendorDayMartBuilder.php"
Upload "app\DataPlatform\Gold\Builders\CustomerPeriodMartBuilder.php" "app/DataPlatform/Gold/Builders/CustomerPeriodMartBuilder.php"
Upload "app\DataPlatform\Gold\Builders\ProductPeriodMartBuilder.php"  "app/DataPlatform/Gold/Builders/ProductPeriodMartBuilder.php"
Upload "app\DataPlatform\Gold\Builders\CnaeSalesMartBuilder.php"      "app/DataPlatform/Gold/Builders/CnaeSalesMartBuilder.php"
Upload "app\DataPlatform\Gold\Builders\GeoBuyerMartBuilder.php"       "app/DataPlatform/Gold/Builders/GeoBuyerMartBuilder.php"

# ── 4. APIs canonicas com visibilidade ───────────────────────────────────────
Write-Host "[4/9] APIs canonicas" -ForegroundColor Green
Upload "api\orders_search.php"   "api/orders_search.php"
Upload "api\vendors_search.php"  "api/vendors_search.php"
Upload "api\products_search.php" "api/products_search.php"

# ── 5. Tools CLI ──────────────────────────────────────────────────────────────
Write-Host "[5/9] Tools CLI" -ForegroundColor Green
Upload "tools\dev\licitacao_diagnosis.php"        "tools/dev/licitacao_diagnosis.php"
Upload "tools\dev\backfill_visibility_scope.php"  "tools/dev/backfill_visibility_scope.php"
Upload "tools\dev\licitacao_visibility_guard.php" "tools/dev/licitacao_visibility_guard.php"
Upload "tools\dev\fase_l_qa.php"                  "tools/dev/fase_l_qa.php"

# ── 6. GoldRefreshCoordinator (chama os mart builders atualizados) ────────────
Write-Host "[6/9] GoldRefreshCoordinator" -ForegroundColor Green
Upload "app\DataPlatform\Gold\GoldRefreshCoordinator.php" "app/DataPlatform/Gold/GoldRefreshCoordinator.php"

# ── 7. APIs de materialização e geo ──────────────────────────────────────────
Write-Host "[7/9] APIs materializacao + geo" -ForegroundColor Green
Upload "api\geo_materialize.php"     "api/geo_materialize.php"
Upload "api\geo_summary.php"         "api/geo_summary.php"
Upload "api\omie_materialize_commercial.php" "api/omie_materialize_commercial.php"
Upload "api\admin_geo_rebuild.php"   "api/admin_geo_rebuild.php"

# ── 8. Guards e auditorias anteriores (dependências) ─────────────────────────
Write-Host "[8/9] Guards / audit existentes" -ForegroundColor Green
Upload "tools\dev\fiscal_value_basis_guard.php"       "tools/dev/fiscal_value_basis_guard.php"
Upload "tools\dev\commercial_recalibration_audit.php" "tools/dev/commercial_recalibration_audit.php"
Upload "tools\dev\commercial_kpi_scope_guard.php"     "tools/dev/commercial_kpi_scope_guard.php"
Upload "tools\dev\cache_clear.php"                    "tools/dev/cache_clear.php"

# ── 9. bin/materialize_marts.php ─────────────────────────────────────────────
Write-Host "[9/9] bin/materialize_marts" -ForegroundColor Green
Upload "bin\materialize_marts.php" "bin/materialize_marts.php"

# ── Concluído ─────────────────────────────────────────────────────────────────
Write-Host "`n=== UPLOADS CONCLUIDOS ===" -ForegroundColor Green

Write-Host @"

PROXIMOS PASSOS — rodar no servidor via SSH:

  ssh -p 65002 u106688109@82.112.247.199

  cd /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie

  # Lint PHP rapido
  php -l app/Support/BiVisibilityScope.php
  php -l database/migrations/Version20260522020000_VendorScopeRules.php
  php -l database/migrations/Version20260522030000_CanonicalVisibilityFields.php
  php -l tools/dev/licitacao_diagnosis.php
  php -l tools/dev/backfill_visibility_scope.php
  php -l tools/dev/licitacao_visibility_guard.php
  php -l tools/dev/fase_l_qa.php

  # 1. Migrations
  php bin/schema_migrate.php migrate

  # 2. Diagnostico (read-only)
  php tools/dev/licitacao_diagnosis.php --pretty

  # 3. Backfill dry-run
  php tools/dev/backfill_visibility_scope.php --dry-run --pretty

  # 4. Backfill apply (se dry-run estiver OK)
  php tools/dev/backfill_visibility_scope.php --apply --pretty

  # 5. Rematerializar marts comerciais (jan–mai 2026)
  php bin/materialize_marts.php --start=2026-01-01 --end=2026-01-31
  php bin/materialize_marts.php --start=2026-02-01 --end=2026-02-28
  php bin/materialize_marts.php --start=2026-03-01 --end=2026-03-31
  php bin/materialize_marts.php --start=2026-04-01 --end=2026-04-30
  php bin/materialize_marts.php --start=2026-05-01 --end=2026-05-31

  # 6. QA master (12 criterios de aceite)
  php tools/dev/fase_l_qa.php --start=2026-01-01 --end=2026-05-31 --pretty

  # 7. Guards adicionais
  php tools/dev/fiscal_value_basis_guard.php --pretty
  php tools/dev/commercial_recalibration_audit.php --start=2026-01-01 --end=2026-05-31

  # 8. Limpar cache
  php tools/dev/cache_clear.php --all

"@ -ForegroundColor Yellow

Write-Host "Se tudo passar (fase_l_qa 12/12 OK):" -ForegroundColor Green
Write-Host "  git add ." -ForegroundColor White
Write-Host '  git commit -m "feat: deploy semantic licitacao exclusion from commercial BI"' -ForegroundColor White
Write-Host "  git tag fase-l-qa-producao" -ForegroundColor White
