# UX.R1 + VIS.6 — Deploy: Company Visual Themes (todas as empresas)
#
# Cobertura:
#   - Expert Intelligence: azul-petroleo #0E5A73 + verde #8CC63F
#   - MAG Filial: vermelho #9B1A1A + licitacao vinho #6B0F0F
#   - MAG Matriz: vermelho #9B1A1A + ouro corporativo #A86B00 (chart-2)
#   - Uni Design: vermelho design #C8242A + indigo #4338CA (opportunity)
#   - Ergo Office: laranja escuro #AD5500 (WCAG) + laranja vivo #E8811A (decorativo)
#   - mag-design-system.css: topbar/sidebar brand signals + logo fix
#   - utils.js: B.chartTheme() com paleta semantica completa
#   - sidebar.php: --has-logo class + initials fallback collapsed
#   - mercadorias.html: P0.1 fix display:none duplicado
#   - companies/*/brand/: tokens.json + chart-theme.json (documentacao)
#
# Uso: .\tools\dev\deploy_uxr1_themes.ps1

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

Write-Host "`n=== VIS.6 DEPLOY — Company Theme Packages ===" -ForegroundColor Yellow
Write-Host "Servidor: $srv:$port" -ForegroundColor Yellow

# ── Theme CSS — paleta completa por empresa ───────────────────────────────────
Upload "assets\companies\expert\theme.css"       "assets/companies/expert/theme.css"
Upload "assets\companies\mag_filial\theme.css"   "assets/companies/mag_filial/theme.css"
Upload "assets\companies\uni_design\theme.css"   "assets/companies/uni_design/theme.css"
Upload "assets\companies\mag_matriz\theme.css"   "assets/companies/mag_matriz/theme.css"
Upload "assets\companies\ergo\theme.css"         "assets/companies/ergo/theme.css"

# ── Design system — brand signals + logo fix ──────────────────────────────────
Upload "assets\css\mag-design-system.css"        "assets/css/mag-design-system.css"

# ── Utils — B.chartTheme() com semantica completa ─────────────────────────────
Upload "assets\js\core\utils.js"                 "assets/js/core/utils.js"

# ── Sidebar — logo fix (--has-logo + initials fallback) ───────────────────────
Upload "views\partials\sidebar.php"              "views/partials/sidebar.php"

# ── P0.1 fix — display:none+flex duplicado no estoqueSourceNote ──────────────
Upload "views\mercadorias.html"                  "views/mercadorias.html"

# ── Brand packages (documentacao de design) ───────────────────────────────────
Upload "companies\expert\brand\tokens.json"         "companies/expert/brand/tokens.json"
Upload "companies\expert\brand\chart-theme.json"    "companies/expert/brand/chart-theme.json"
Upload "companies\mag_filial\brand\tokens.json"     "companies/mag_filial/brand/tokens.json"
Upload "companies\mag_filial\brand\chart-theme.json" "companies/mag_filial/brand/chart-theme.json"
Upload "companies\mag_matriz\brand\tokens.json"     "companies/mag_matriz/brand/tokens.json"
Upload "companies\mag_matriz\brand\chart-theme.json" "companies/mag_matriz/brand/chart-theme.json"
Upload "companies\uni_design\brand\tokens.json"     "companies/uni_design/brand/tokens.json"
Upload "companies\uni_design\brand\chart-theme.json" "companies/uni_design/brand/chart-theme.json"
Upload "companies\ergooffice\brand\tokens.json"     "companies/ergooffice/brand/tokens.json"
Upload "companies\ergooffice\brand\chart-theme.json" "companies/ergooffice/brand/chart-theme.json"
Upload "companies\ergooffice\manifest.json"          "companies/ergooffice/manifest.json"

# ── FIX RAIZ: getCssVar lia documentElement (<html>) ao invés de body ─────────
# theme CSS está em body[data-company=] — deve ler de document.body
Upload "assets\js\core\utils.js"                             "assets/js/core/utils.js"
Upload "assets\js\chart-helpers.js"                         "assets/js/chart-helpers.js"
Upload "assets\js\finance.js"                               "assets/js/finance.js"
Upload "assets\js\flight-plan.js"                           "assets/js/flight-plan.js"
Upload "assets\js\index_geo.js"                             "assets/js/index_geo.js"
Upload "assets\js\mag-chart-defaults.js"                    "assets/js/mag-chart-defaults.js"
Upload "assets\js\modules\finance\charts.js"                "assets/js/modules/finance/charts.js"
Upload "assets\js\modules\flight-plan\charts.js"            "assets/js/modules/flight-plan/charts.js"
Upload "assets\js\modules\flight-plan\formatters.js"        "assets/js/modules/flight-plan/formatters.js"
Upload "assets\js\modules\geo-detail\charts.js"             "assets/js/modules/geo-detail/charts.js"
Upload "assets\js\modules\product-detail\charts.js"         "assets/js/modules/product-detail/charts.js"
Upload "assets\js\biomie-map.js"                            "assets/js/biomie-map.js"

# ── Health check fix: wallpaper auto-clear stale fallback ─────────────────────
Upload "app\SystemHealth\Checks\LoginWallpaperHealthCheck.php" "app/SystemHealth/Checks/LoginWallpaperHealthCheck.php"

Write-Host "`n=== DEPLOY CONCLUIDO ===" -ForegroundColor Green
Write-Host "`nVerificacao visual por empresa:" -ForegroundColor Yellow
Write-Host "  Expert    -> sidebar/charts azul-petroleo #0E5A73, meta verde universal #16A34A"
Write-Host "  MAG Filial -> vermelho #9B1A1A (varejo), vinho #6B0F0F (licitacao)"
Write-Host "  MAG Matriz -> vermelho #9B1A1A + chart-2 ouro #A86B00"
Write-Host "  Uni Design -> vermelho design #C8242A + opportunity indigo #4338CA"
Write-Host "  Ergo      -> laranja escuro #AD5500 nav/avatar, laranja vivo #E8811A charts"
Write-Host "`nSematica universal em TODOS (sem regressao de dados):"
Write-Host "  Verde #16A34A = meta/sucesso"
Write-Host "  Ambar #F59E0B = pendente/atencao"
Write-Host "  Vermelho #DC2626 = cancelado/perda"
Write-Host "  Cinza #64748B = historico/neutro"
