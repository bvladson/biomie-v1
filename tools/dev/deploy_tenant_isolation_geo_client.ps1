# Tenant Isolation Fix — GeoReadRepository + client_profile
#
# Corrige vazamento de dados entre empresas:
#   - GeoReadRepository.php: 4 métodos sem company_id (geoWhere, neighborhoodSales, territoryMartWhere, territoryInvoices)
#   - client_profile.php: query principal sem company_id em biomie_clientes_dim e biomie_vendas_geo_cliente_fact
#
# Uso: .\tools\dev\deploy_tenant_isolation_geo_client.ps1

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

Write-Host "`n=== TENANT ISOLATION FIX DEPLOY ===" -ForegroundColor Yellow
Write-Host "Servidor: $srv:$port" -ForegroundColor Yellow

Upload "app\DataPlatform\Repository\GeoReadRepository.php" "app/DataPlatform/Repository/GeoReadRepository.php"
Upload "api\client_profile.php"                            "api/client_profile.php"

Write-Host "`n=== Verificando sintaxe no servidor ===" -ForegroundColor Yellow
& plink -P $port $srv "php -l /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/app/DataPlatform/Repository/GeoReadRepository.php && php -l /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/api/client_profile.php && echo 'All OK'"

Write-Host "`n=== Deploy concluido ===" -ForegroundColor Green
