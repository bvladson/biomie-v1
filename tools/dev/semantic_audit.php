<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

$semanticDir = dirname(__DIR__, 2) . '/app/DataPlatform/Semantic/';
require_once $semanticDir . 'DataSourceTruth.php';
require_once $semanticDir . 'MetricEntity.php';
require_once $semanticDir . 'MetricDimension.php';
require_once $semanticDir . 'MetricMeasure.php';
require_once $semanticDir . 'KpiDefinition.php';
require_once $semanticDir . 'MetricPermissionMap.php';
require_once $semanticDir . 'KpiContract.php';
require_once $semanticDir . 'SemanticAuditResult.php';
require_once $semanticDir . 'MetricRegistry.php';
require_once $semanticDir . 'SemanticMetricValidator.php';

use BIomie\DataPlatform\Semantic\MetricRegistry;
use BIomie\DataPlatform\Semantic\SemanticAuditResult;
use BIomie\DataPlatform\Semantic\SemanticMetricValidator;

/**
 * Semantic layer audit tool.
 *
 * Usage:
 *   php tools/dev/semantic_audit.php
 *   php tools/dev/semantic_audit.php --json
 *   php tools/dev/semantic_audit.php --markdown
 *   php tools/dev/semantic_audit.php --fail-on-warning
 *
 * Exit codes:
 *   0 = OK
 *   1 = validation errors found
 *   2 = warnings found (only when --fail-on-warning is set)
 */

$args = $argv ?? [];
$outputJson     = in_array('--json', $args, true);
$outputMarkdown = in_array('--markdown', $args, true);
$failOnWarning  = in_array('--fail-on-warning', $args, true);

// ── Build and validate ────────────────────────────────────────────────────────

try {
    $registry  = MetricRegistry::build();
    $validator = new SemanticMetricValidator();
    $audit     = $validator->validate($registry);
} catch (\Throwable $e) {
    if ($outputJson) {
        echo json_encode(['ok' => false, 'errors' => [$e->getMessage()]], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        fwrite(STDERR, "FATAL: " . $e->getMessage() . "\n");
    }
    exit(1);
}

// ── Output ────────────────────────────────────────────────────────────────────

if ($outputJson) {
    echo json_encode($audit->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} elseif ($outputMarkdown) {
    outputMarkdown($audit, $registry);
} else {
    outputHuman($audit);
}

// ── Exit code ────────────────────────────────────────────────────────────────

if ($audit->hasErrors()) {
    exit(1);
}
if ($failOnWarning && $audit->hasWarnings()) {
    exit(2);
}
exit(0);

// ── Helpers ──────────────────────────────────────────────────────────────────

function outputHuman(SemanticAuditResult $audit): void
{
    $status = $audit->ok ? 'OK' : 'FAIL';
    echo "Semantic Audit: {$status}\n";
    echo str_repeat('-', 40) . "\n";
    echo "Total KPIs : {$audit->metricsTotal}\n";
    echo "  active    : {$audit->active}\n";
    echo "  draft     : {$audit->draft}\n";
    echo "  deprecated: {$audit->deprecated}\n";

    if ($audit->hasErrors()) {
        echo "\nERRORS (" . count($audit->errors) . "):\n";
        foreach ($audit->errors as $err) {
            echo "  [ERROR] {$err}\n";
        }
    }

    if ($audit->hasWarnings()) {
        echo "\nWARNINGS (" . count($audit->warnings) . "):\n";
        foreach ($audit->warnings as $warn) {
            echo "  [WARN]  {$warn}\n";
        }
    }

    if (!$audit->hasErrors() && !$audit->hasWarnings()) {
        echo "\nAll checks passed.\n";
    }
}

function outputMarkdown(SemanticAuditResult $audit, MetricRegistry $registry): void
{
    $status = $audit->ok ? 'OK' : 'FAIL';
    echo "# Semantic Layer Audit\n\n";
    echo "**Status:** {$status}  \n";
    echo "**Total KPIs:** {$audit->metricsTotal} (active: {$audit->active}, draft: {$audit->draft}, deprecated: {$audit->deprecated})\n\n";

    if ($audit->hasErrors()) {
        echo "## Errors\n\n";
        foreach ($audit->errors as $err) {
            echo "- {$err}\n";
        }
        echo "\n";
    }

    if ($audit->hasWarnings()) {
        echo "## Warnings\n\n";
        foreach ($audit->warnings as $warn) {
            echo "- {$warn}\n";
        }
        echo "\n";
    }

    echo "## KPI Summary\n\n";
    echo "| ID | Name | Domain | Status | Permission | Source |\n";
    echo "|---|---|---|---|---|---|\n";

    foreach ($registry->all() as $def) {
        $name = str_replace('|', '\\|', $def->name);
        echo "| `{$def->id}` | {$name} | {$def->domain} | {$def->status} | {$def->permission} | {$def->sourceOfTruth} |\n";
    }
    echo "\n";
}
