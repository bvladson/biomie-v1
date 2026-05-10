<?php

/**
 * menu_audit.php — Auditoria estrutural de views/config/menu.php
 *
 * Verifica:
 *   - Itens sem label ou sem href
 *   - permission_any não-array ou com valores vazios
 *   - permission e permission_any usados juntos (ambíguo)
 *   - Duplicidade de href e de label
 *   - Se arquivos .php referenciados existem
 *   - Se os links semânticos (cockpit_interno.php, metric_catalog.php) estão presentes
 *   - Hrefs que não terminam em .php para rotas novas do grupo Sistema
 *
 * Uso:
 *   php tools/dev/menu_audit.php
 *   php tools/dev/menu_audit.php --json
 *
 * Saída JSON: { ok, items_total, warnings, errors, semantic_links }
 */

declare(strict_types=1);

$jsonMode = in_array('--json', $argv ?? [], true);

$menuFile = dirname(__DIR__, 2) . '/views/config/menu.php';
$rootDir  = dirname(__DIR__, 2);

if (!is_file($menuFile)) {
    $out = ['ok' => false, 'error' => 'views/config/menu.php não encontrado.'];
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(1);
}

$items = require $menuFile;
if (!is_array($items)) {
    $out = ['ok' => false, 'error' => 'menu.php não retornou array.'];
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(1);
}

$errors   = [];
$warnings = [];
$hrefs    = [];
$labels   = [];

// Semantic routes that must be present
$semanticRequired = [
    'cockpit_interno.php',
    'metric_catalog.php',
];
$semanticFound = array_fill_keys($semanticRequired, false);

foreach ($items as $idx => $item) {
    if (!is_array($item)) {
        $errors[] = "Item [$idx] não é array.";
        continue;
    }

    $label = trim((string) ($item['label'] ?? ''));
    $href  = trim((string) ($item['href'] ?? ''));
    $group = (string) ($item['group'] ?? '');

    // Label obrigatório
    if ($label === '') {
        $errors[] = "Item [$idx] sem label.";
    }

    // Href obrigatório
    if ($href === '') {
        $errors[] = "Item [$idx] ($label) sem href.";
    }

    // Duplicidade de label
    if ($label !== '' && isset($labels[$label])) {
        $warnings[] = "Label duplicado: \"$label\" (itens {$labels[$label]} e $idx).";
    } elseif ($label !== '') {
        $labels[$label] = $idx;
    }

    // Duplicidade de href
    $hrefBase = strtok($href, '#') ?: $href;
    if ($hrefBase !== '' && isset($hrefs[$hrefBase])) {
        $warnings[] = "Href duplicado: \"$hrefBase\" (itens {$hrefs[$hrefBase]} e $idx).";
    } elseif ($hrefBase !== '') {
        $hrefs[$hrefBase] = $idx;
    }

    // Arquivo .php referenciado deve existir (apenas hrefs locais sem parâmetros)
    if ($hrefBase !== '' && !str_contains($hrefBase, '://') && str_ends_with($hrefBase, '.php')) {
        $filePath = $rootDir . '/' . $hrefBase;
        if (!is_file($filePath)) {
            $warnings[] = "Arquivo referenciado não encontrado: \"$hrefBase\" (item $idx: $label).";
        }
        // Track semantic routes
        if (array_key_exists($hrefBase, $semanticFound)) {
            $semanticFound[$hrefBase] = true;
        }
    }

    // permission_any validação
    $permAny = $item['permission_any'] ?? null;
    if ($permAny !== null) {
        if (!is_array($permAny)) {
            $errors[] = "Item [$idx] ($label): permission_any deve ser array, encontrado " . gettype($permAny) . ".";
        } else {
            foreach ($permAny as $i => $cap) {
                if (trim((string) $cap) === '') {
                    $warnings[] = "Item [$idx] ($label): permission_any[$i] está vazio — será ignorado pelo sidebar.";
                }
            }
        }
    }

    // permission e permission_any juntos — ambíguo
    $permSingle = $item['permission'] ?? null;
    if ($permSingle !== null && $permAny !== null) {
        $warnings[] = "Item [$idx] ($label): possui tanto 'permission' quanto 'permission_any' — sidebar usa apenas permission_any; 'permission' é ignorado.";
    }

    // Rotas do grupo Sistema devem terminar em .php (não .html)
    if ($group === 'Sistema' && $hrefBase !== '' && !str_contains($hrefBase, '://')) {
        if (str_ends_with($hrefBase, '.html')) {
            $errors[] = "Item [$idx] ($label): rota do grupo Sistema termina em .html — rotas oficiais devem ser .php.";
        }
    }

    // enabled = false → aviso informativo
    if (($item['enabled'] ?? true) === false) {
        $warnings[] = "Item [$idx] ($label): enabled=false — não aparece no menu.";
    }
}

// Semantic links obrigatórios
$semanticStatus = [];
foreach ($semanticFound as $file => $found) {
    $semanticStatus[$file] = $found;
    if (!$found) {
        $errors[] = "Link semântico obrigatório não encontrado no menu: \"$file\".";
    }
}

$ok = $errors === [];

$result = [
    'ok'           => $ok,
    'items_total'  => count($items),
    'errors'       => $errors,
    'warnings'     => $warnings,
    'semantic_links' => $semanticStatus,
];

if ($jsonMode) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit($ok ? 0 : 1);
}

// Human-readable output
echo "\n=== Menu Audit ===\n\n";
printf("   Items      : %d\n", count($items));
printf("   Errors     : %d\n", count($errors));
printf("   Warnings   : %d\n", count($warnings));
echo "\n";

foreach ($semanticStatus as $file => $found) {
    $mark = $found ? "\033[32m✓\033[0m" : "\033[31m✗\033[0m";
    echo "   [$mark] Semantic link: $file\n";
}

if ($errors !== []) {
    echo "\n\033[31mErros:\033[0m\n";
    foreach ($errors as $e) {
        echo "   • $e\n";
    }
}

if ($warnings !== []) {
    echo "\n\033[33mAvisos:\033[0m\n";
    foreach ($warnings as $w) {
        echo "   • $w\n";
    }
}

if ($ok) {
    echo "\n\033[32mMenu Audit: OK\033[0m\n\n";
} else {
    echo "\n\033[31mMenu Audit: FAIL\033[0m\n\n";
    exit(1);
}
