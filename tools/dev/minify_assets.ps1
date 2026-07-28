# Minifica JS/CSS de assets/ em irmãos .min.js/.min.css via esbuild.
#
# Uso:  powershell -File tools/dev/minify_assets.ps1
# Requer Node.js (o esbuild é resolvido via npx na primeira execução).
#
# O runtime (BIomie\Support\Asset::url) serve o .min automaticamente quando ele
# existe e não está mais velho que o fonte — rodar este script faz parte do
# deploy; esquecer de rodar apenas mantém o fonte cru sendo servido.

$ErrorActionPreference = 'Stop'
$root = Resolve-Path (Join-Path $PSScriptRoot '..\..')
Set-Location $root

$skip = @(
    'assets/js/chart.umd.js'   # dist vendorado do Chart.js (já otimizado)
)

$targets = Get-ChildItem -Recurse -File -Path 'assets' -Include '*.js', '*.css' |
    Where-Object { $_.Name -notmatch '\.min\.(js|css)$' } |
    Where-Object {
        $rel = ($_.FullName.Substring($root.Path.Length + 1)) -replace '\\', '/'
        $skip -notcontains $rel
    }

$done = 0
foreach ($f in $targets) {
    $min = $f.FullName -replace '\.(js|css)$', '.min.$1'
    npx --yes esbuild $f.FullName --minify --target=es2017 --charset=utf8 "--outfile=$min" --log-level=error
    if ($LASTEXITCODE -ne 0) { throw "esbuild falhou em $($f.FullName)" }
    $done++
}

Write-Host "Minificados: $done arquivos (irmãos .min gerados em assets/)"
