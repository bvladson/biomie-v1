<?php

declare(strict_types=1);

// No application bootstrap, database, session or external service is required.
require_once dirname(__DIR__) . '/app/Bootstrap.php';
require_once dirname(__DIR__) . '/app/Support/Asset.php';
require_once dirname(__DIR__) . '/app/PageAssetRegistry.php';
require_once dirname(__DIR__) . '/app/PageRenderer.php';

use BIomie\PageAssetRegistry;
use BIomie\PageRenderer;

$checks = 0;
$failures = [];
$check = static function (bool $ok, string $message) use (&$checks, &$failures): void {
    $checks++;
    if (!$ok) {
        $failures[] = $message;
    }
};
$css = 'assets/css/biomie-floating-scroll.css';
$js = 'assets/js/biomie-floating-scroll.js';
$pages = ['dashboard', 'time-comercial', 'nf-periodo', 'mix-faturado', 'financeiro',
    'rota-comercial', 'geografia', 'omie', 'conciliacao', 'observabilidade', 'admin', 'future-page'];

foreach ($pages as $page) {
    $check(count(array_keys(PageAssetRegistry::cssForPage($page), $css, true)) === 1, "$page: CSS registered exactly once");
    $check(count(array_keys(PageAssetRegistry::jsBodyForPage($page), $js, true)) === 1, "$page: JS registered exactly once");
    $check(!in_array($js, PageAssetRegistry::jsHeadForPage($page), true), "$page: JS does not block the head");
}
$check(is_file(dirname(__DIR__) . '/' . $css), 'CSS asset exists');
$check(is_file(dirname(__DIR__) . '/' . $js), 'JS asset exists');
$check(str_contains(PageAssetRegistry::renderJsBodyTags('future-page'), $js . '?v='), 'JS uses existing asset versioning');
$check(preg_match('~<script src="assets/js/biomie-floating-scroll\.js\?v=[^"]+" defer></script>~',
    PageAssetRegistry::renderJsBodyTags('dashboard')) === 1, 'JS uses the deferred renderer');

$headMethod = new ReflectionMethod(PageRenderer::class, 'injectRegistryHead');
$bodyMethod = new ReflectionMethod(PageRenderer::class, 'injectRegistryBody');
$head = (string) $headMethod->invoke(null, 'dashboard', '<title>BIOMIE</title>');
$body = (string) $bodyMethod->invoke(null, 'dashboard', '<p>existing content</p>');
$check(substr_count($head, $css) === 1, 'PageRenderer injects CSS once');
$check(substr_count($body, $js) === 1, 'PageRenderer injects JS once');
$check(str_contains($body, '<p>existing content</p>'), 'Existing content is preserved');
$headAgain = (string) $headMethod->invoke(null, 'dashboard', $head);
$bodyAgain = (string) $bodyMethod->invoke(null, 'dashboard', $body);
$check($headAgain === $head, 'Head injection is idempotent');
$check($bodyAgain === $body, 'Body injection is idempotent');

echo json_encode(['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 1);
