<?php
$f = '/home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/storage/cache/fiscal_summary_2026-01-01_2026-12-31.json';
$c = json_decode(file_get_contents($f), true);
echo 'ok:' . ($c['ok'] ? 'true' : 'false') . PHP_EOL;
echo 'trend:' . count($c['trend'] ?? []) . PHP_EOL;
echo 'vendors:' . count($c['vendors'] ?? []) . PHP_EOL;
echo 'kpis_keys:' . implode(',', array_keys($c['kpis'] ?? [])) . PHP_EOL;
$t = $c['trend'][0] ?? [];
echo 'trend[0]_keys:' . implode(',', array_keys($t)) . PHP_EOL;
$v = $c['vendors'][0] ?? [];
echo 'vendor[0]_keys:' . implode(',', array_keys($v)) . PHP_EOL;
