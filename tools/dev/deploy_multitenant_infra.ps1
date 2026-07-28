# Multitenant Infrastructure Fix — Deploy
#
# Arquivos incluídos:
#   1. CustomerDimensionMaterializationService.php — multitenant: company_id em todas queries/upserts
#   2. GeoReadRepository.php                       — company_id nos 4 métodos de query geo (ReadModel path)
#   3. api/client_profile.php                      — company_id + geo_dim join composto
#   4. CustomerDetailBuilder.php                   — fetchOneByIdentity + fetchFinanceTitles com tenant filter
#   5. api/invoice_detail.php                      — titulos_financeiros + itens_canonicos com company_id
#   6. api/order_detail.php                        — titulos_financeiros + itens_canonicos com company_id
#   7. api/top_clients_by_cnae.php                 — mart_vendas_por_cnae_cnpj com company_id
#   8. api/nf_periodo_table.php                    — recordsTotal com tenant filter
#   9. bin/materialize_client_dim.php              — CLI novo: materializa clientes por empresa
#  10. bin/cancel_stuck_sync_runs.php              — CLI novo: cancela runs presos
#
# Cache key isolation (CRÍTICO — cache compartilhado por empresa):
#  11. OverviewRequest.php                         — cacheKey() agora inclui company_id
#  12. HomeBootstrapService.php                    — cacheKey() e loadCachedOverviewPayload() com company_id
#  13. FinanceRepository.php                       — finance_chunk/finance_budget keys com company_id
#  14. SalesAnalyticsService.php                   — snapshot_fiscal_c{N} key
#  15. FinanceAnalyticsService.php                 — finance_snapshot_c{N} + history key
#
# Permissoes por empresa + operacao:
#  16. TenantContext.php                           — SELECT role_override em listAccessible()
#  17. Auth.php                                    — companyEffectiveRole() para role_override por empresa
#  18. api/company_health_status.php               — indicador saude por empresa (sync/freshness/marts/creds)
#  19. bin/smoke_test_all_companies.php            — T1-T10 paridade funcional + isolamento dados
#  20. database/migrations/Version20260623...      — adiciona role_override em biomie_user_companies
#  21. api/user_company_role.php                   — admin API GET/SET/CLEAR role_override por empresa
#  22. bin/cache_cleanup.php                       — remove cache files sem prefixo c{N} (formato antigo)
#
# Frescor + completude:
#  23. app/DataPlatform/Monitoring/CompanyCompletenessService.php — score 0-100, 9 dims, coverage, marts
#  24. api/company_health_status.php (reescrito)   — usa CompanyCompletenessService; freshness por domínio
#  25. bin/company_completeness_check.php          — CLI report: score/tier/matrix/remediation
#
# Uso: .\tools\dev\deploy_multitenant_infra.ps1

$base   = "c:\Users\Usuario\Downloads\biomie"
$srv    = "u106688109@82.112.247.199"
$port   = 65002
$remote = "/home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie"
$pscp   = "pscp"

function Upload($local, $remotePath) {
    Write-Host "-> $local" -ForegroundColor Cyan
    & $pscp -P $port "$base\$local" "${srv}:${remote}/${remotePath}"
    if ($LASTEXITCODE -ne 0) { Write-Host "  ERRO no upload" -ForegroundColor Red; exit 1 }
    else { Write-Host "  OK" -ForegroundColor Green }
}

Write-Host "`n=== MULTITENANT INFRA DEPLOY ===" -ForegroundColor Yellow
Write-Host "Servidor: $srv:$port" -ForegroundColor Yellow

# P0 — GeoReadRepository (ReadModel path sem company_id)
Upload "app\DataPlatform\Repository\GeoReadRepository.php" "app/DataPlatform/Repository/GeoReadRepository.php"

# P0 — client_profile (query principal + geo join composto)
Upload "api\client_profile.php" "api/client_profile.php"

# P1 — CustomerDimensionMaterializationService (multitenant)
Upload "app\CustomerDimensionMaterializationService.php" "app/CustomerDimensionMaterializationService.php"

# P2 — CustomerDetailBuilder (fetchOneByIdentity + fetchFinanceTitles com tenant filter)
Upload "app\Modules\Customers\Application\CustomerDetail\CustomerDetailBuilder.php" "app/Modules/Customers/Application/CustomerDetail/CustomerDetailBuilder.php"

# P3 — top_clients_by_cnae (mart_vendas_por_cnae_cnpj sem company_id)
Upload "api\top_clients_by_cnae.php" "api/top_clients_by_cnae.php"

# P4 — invoice_detail (titulos_financeiros + itens sem company_id)
Upload "api\invoice_detail.php" "api/invoice_detail.php"

# P4 — order_detail (titulos_financeiros + itens sem company_id)
Upload "api\order_detail.php" "api/order_detail.php"

# P5 — nf_periodo_table (recordsTotal sem tenant filter)
Upload "api\nf_periodo_table.php" "api/nf_periodo_table.php"

# CLI novos
Upload "bin\materialize_client_dim.php" "bin/materialize_client_dim.php"
Upload "bin\cancel_stuck_sync_runs.php" "bin/cancel_stuck_sync_runs.php"

# Cache key isolation — CRÍTICO
Upload "app\Modules\Sales\Application\Overview\OverviewRequest.php" "app/Modules/Sales/Application/Overview/OverviewRequest.php"
Upload "app\HomeBootstrapService.php" "app/HomeBootstrapService.php"
Upload "app\FinanceRepository.php" "app/FinanceRepository.php"
Upload "app\SalesAnalyticsService.php" "app/SalesAnalyticsService.php"
Upload "app\FinanceAnalyticsService.php" "app/FinanceAnalyticsService.php"

# Permissões por empresa + health dashboard
Upload "app\Support\TenantContext.php" "app/Support/TenantContext.php"
Upload "app\Auth.php" "app/Auth.php"
Upload "api\company_health_status.php" "api/company_health_status.php"
Upload "bin\smoke_test_all_companies.php" "bin/smoke_test_all_companies.php"

# Migration: role_override em biomie_user_companies
Upload "database\migrations\Version20260623020000_UserCompanyRoleOverride.php" "database/migrations/Version20260623020000_UserCompanyRoleOverride.php"

# Novo: admin API para gerenciar role_override por empresa
Upload "api\user_company_role.php" "api/user_company_role.php"

# Novo: limpar cache files no formato antigo (sem c{N} prefix)
Upload "bin\cache_cleanup.php" "bin/cache_cleanup.php"

# Frescor + completude por empresa
Upload "app\DataPlatform\Monitoring\CompanyCompletenessService.php" "app/DataPlatform/Monitoring/CompanyCompletenessService.php"
Upload "bin\company_completeness_check.php" "bin/company_completeness_check.php"

# Segurança + operacional (hardening fixes)
#  26. .htaccess — CSP enforced (promovido de Report-Only)
#  27. healthz.php — full details gateado por X-Healthz-Token ou loopback IP
#  28. api/omie_webhook.php — token scrubbed do QUERY_STRING no fallback file
#  29. config/env.php — DB_PERSISTENT e DB_POOL_PROBE_ON_REUSE default '0' (shared hosting)
#  30. bin/smoke_test_all_companies.php — T6 corrigido (nota_key em vez de codigo_pedido)
Upload ".htaccess" ".htaccess"
Upload "healthz.php" "healthz.php"
Upload "api\omie_webhook.php" "api/omie_webhook.php"
Upload "config\env.php" "config/env.php"
Upload "bin\smoke_test_all_companies.php" "bin/smoke_test_all_companies.php"

Write-Host "`n=== Verificando sintaxe no servidor ===" -ForegroundColor Yellow
& plink -P $port $srv @"
php -l /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/app/DataPlatform/Repository/GeoReadRepository.php && \
php -l /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/api/client_profile.php && \
php -l /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/app/CustomerDimensionMaterializationService.php && \
php -l /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/app/Modules/Customers/Application/CustomerDetail/CustomerDetailBuilder.php && \
php -l /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/bin/materialize_client_dim.php && \
php -l /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/bin/cancel_stuck_sync_runs.php && \
php -l /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/api/top_clients_by_cnae.php && \
php -l /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/api/invoice_detail.php && \
php -l /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/api/order_detail.php && \
php -l /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/api/nf_periodo_table.php && \
php -l /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/app/Modules/Sales/Application/Overview/OverviewRequest.php && \
php -l /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/app/HomeBootstrapService.php && \
php -l /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/app/FinanceRepository.php && \
php -l /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/app/SalesAnalyticsService.php && \
php -l /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/app/FinanceAnalyticsService.php && \
php -l /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/app/Support/TenantContext.php && \
php -l /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/app/Auth.php && \
php -l /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/api/company_health_status.php && \
php -l /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/bin/smoke_test_all_companies.php && \
php -l /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/database/migrations/Version20260623020000_UserCompanyRoleOverride.php && \
php -l /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/api/user_company_role.php && \
php -l /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/bin/cache_cleanup.php && \
php -l /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/app/DataPlatform/Monitoring/CompanyCompletenessService.php && \
php -l /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/bin/company_completeness_check.php && \
php -l /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/healthz.php && \
php -l /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/api/omie_webhook.php && \
php -l /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/config/env.php && \
php -l /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/bin/smoke_test_all_companies.php && \
echo "All syntax OK"
"@

Write-Host "`n=== Deploy concluido ===" -ForegroundColor Green
$r = "/home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie"
Write-Host ""
Write-Host "=== Sequencia pos-deploy ===" -ForegroundColor Cyan
Write-Host ""
Write-Host "1. Aplicar migration (role_override + auth indexes):" -ForegroundColor Yellow
Write-Host "   plink -P 65002 ${srv} `"php ${r}/bin/schema_migrate.php`""
Write-Host ""
Write-Host "2. Limpar cache antigo (sem company prefix):" -ForegroundColor Yellow
Write-Host "   plink -P 65002 ${srv} `"php ${r}/bin/cache_cleanup.php --dry-run --verbose`""
Write-Host "   plink -P 65002 ${srv} `"php ${r}/bin/cache_cleanup.php`""
Write-Host ""
Write-Host "3. Cancelar runs presos:" -ForegroundColor Yellow
Write-Host "   plink -P 65002 ${srv} `"php ${r}/bin/cancel_stuck_sync_runs.php --threshold=60 --dry-run`""
Write-Host "   plink -P 65002 ${srv} `"php ${r}/bin/cancel_stuck_sync_runs.php --threshold=60`""
Write-Host ""
Write-Host "4. Materializar dim clientes para cada empresa:" -ForegroundColor Yellow
Write-Host "   plink -P 65002 ${srv} `"php ${r}/bin/materialize_client_dim.php --company=EXPERT`""
Write-Host "   plink -P 65002 ${srv} `"php ${r}/bin/materialize_client_dim.php --company=UNI_DESIGN`""
Write-Host "   plink -P 65002 ${srv} `"php ${r}/bin/materialize_client_dim.php --company=MAG_MATRIZ`""
Write-Host "   plink -P 65002 ${srv} `"php ${r}/bin/materialize_client_dim.php --company=ERGOOFFICE`""
Write-Host "   plink -P 65002 ${srv} `"php ${r}/bin/materialize_client_dim.php --company=MAG_FILIAL`""
Write-Host ""
Write-Host "5. Smoke test paridade funcional:" -ForegroundColor Yellow
Write-Host "   plink -P 65002 ${srv} `"php ${r}/bin/smoke_test_all_companies.php --json`""
Write-Host ""
Write-Host "6. Relatório de completude por empresa:" -ForegroundColor Yellow
Write-Host "   plink -P 65002 ${srv} `"php ${r}/bin/company_completeness_check.php`""
Write-Host ""
Write-Host "7. Verificar saude por empresa (HTTP):" -ForegroundColor Yellow
Write-Host "   GET /api/company_health_status.php   (requer jobs.view ou admin.view)"
