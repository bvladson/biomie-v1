<?php

declare(strict_types=1);

namespace BIomie\SystemHealth\Checks;

use BIomie\DataPlatform\Semantic\MetricRegistry;
use BIomie\DataPlatform\Semantic\SemanticMetricValidator;
use BIomie\SystemHealth\CheckInterface;
use BIomie\SystemHealth\SystemHealthCheck;
use Throwable;

final class SemanticHealthCheck implements CheckInterface
{
    public function id(): string    { return 'semantic'; }
    public function label(): string { return 'Semântica de KPIs'; }

    public function run(): SystemHealthCheck
    {
        $start = microtime(true);

        try {
            $registry  = MetricRegistry::build();
            $validator = new SemanticMetricValidator();
            $audit     = $validator->validate($registry);
        } catch (Throwable $e) {
            $ms = (int) round((microtime(true) - $start) * 1000);
            return SystemHealthCheck::unknown($this->id(), $this->label(), $e, $ms);
        }

        $ms = (int) round((microtime(true) - $start) * 1000);

        $auditData = method_exists($audit, 'toArray') ? $audit->toArray() : [];
        $errors    = is_array($auditData['errors'] ?? null) ? $auditData['errors'] : [];
        $warnings  = is_array($auditData['warnings'] ?? null) ? $auditData['warnings'] : [];

        $metrics = [
            'kpis_total'    => (int) ($auditData['metrics_total'] ?? 0),
            'kpis_active'   => (int) ($auditData['active'] ?? 0),
            'kpis_draft'    => (int) ($auditData['draft'] ?? 0),
            'error_count'   => count($errors),
            'warning_count' => count($warnings),
        ];

        if (count($errors) > 0) {
            return SystemHealthCheck::critical(
                $this->id(), $this->label(),
                count($errors) . ' erro(s) semântico(s) encontrado(s).',
                $metrics,
                ['Corrigir erros em app/DataPlatform/Semantic/MetricRegistry.php.'],
                $ms,
            );
        }

        if (count($warnings) > 0) {
            return SystemHealthCheck::warning(
                $this->id(), $this->label(),
                count($warnings) . ' aviso(s) semântico(s). ' . ($metrics['kpis_active']) . ' KPIs ativos.',
                $metrics,
                ['Revisar avisos em tools/dev/semantic_audit.php.'],
                $ms,
            );
        }

        return SystemHealthCheck::ok(
            $this->id(), $this->label(),
            "{$metrics['kpis_active']} KPIs ativos, 0 erros, 0 avisos.",
            $metrics,
            $ms,
        );
    }
}
