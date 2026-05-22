<?php

declare(strict_types=1);

namespace BIomie\SystemHealth\Checks;

use BIomie\SystemHealth\CheckInterface;
use BIomie\SystemHealth\SystemHealthCheck;

final class OPcacheHealthCheck implements CheckInterface
{
    public function id(): string { return 'opcache'; }
    public function label(): string { return 'OPcache PHP'; }

    public function run(): SystemHealthCheck
    {
        $start = microtime(true);
        $enabled = function_exists('opcache_get_status') && (bool) ini_get('opcache.enable');
        $validate = (string) ini_get('opcache.validate_timestamps');
        $revalidate = (string) ini_get('opcache.revalidate_freq');
        $metrics = [
            'enabled' => $enabled ? 1 : 0,
            'validate_timestamps' => $validate,
            'revalidate_freq' => $revalidate,
            'memory_used_mb' => null,
            'hit_rate_pct' => null,
            'restart_pending' => 0,
        ];
        if ($enabled && function_exists('opcache_get_status')) {
            $status = @opcache_get_status(false);
            if (is_array($status)) {
                $memory = is_array($status['memory_usage'] ?? null) ? $status['memory_usage'] : [];
                $stats = is_array($status['opcache_statistics'] ?? null) ? $status['opcache_statistics'] : [];
                $metrics['memory_used_mb'] = round(((float) ($memory['used_memory'] ?? 0)) / 1048576, 2);
                $metrics['hit_rate_pct'] = round((float) ($stats['opcache_hit_rate'] ?? 0), 2);
                $metrics['restart_pending'] = !empty($status['restart_pending']) ? 1 : 0;
            }
        }
        $ms = (int) round((microtime(true) - $start) * 1000);
        if (!$enabled) {
            return SystemHealthCheck::warning($this->id(), $this->label(), 'OPcache não está ativo no PHP.', $metrics, ['Ativar OPcache no painel PHP para reduzir tempo de resposta.'], $ms);
        }
        if ($validate === '0') {
            return SystemHealthCheck::warning($this->id(), $this->label(), 'OPcache não valida alterações automaticamente.', $metrics, ['Após deploy, reinicie o PHP ou execute o reset de OPcache pelo admin.'], $ms);
        }
        return SystemHealthCheck::ok($this->id(), $this->label(), 'OPcache ativo e validando alterações.', $metrics, $ms);
    }
}
