<?php

declare(strict_types=1);

namespace BIomie\SystemHealth\Checks;

use BIomie\DataPlatform\Semantic\FreshnessService;
use BIomie\SystemHealth\CheckInterface;
use BIomie\SystemHealth\SystemHealthCheck;
use PDO;
use Throwable;

final class FreshnessHealthCheck implements CheckInterface
{
    private const DOMAINS = ['gold', 'canonical', 'sales', 'fiscal', 'geo'];

    /** Freshness statuses that are not "fresh" — ordered by severity. */
    private const BAD_STATUSES = ['unknown', 'failed', 'partial', 'stale'];

    public function __construct(private readonly ?PDO $pdo) {}

    public function id(): string    { return 'freshness'; }
    public function label(): string { return 'Freshness de Dados'; }

    public function run(): SystemHealthCheck
    {
        $start = microtime(true);

        if ($this->pdo === null) {
            return new SystemHealthCheck(
                $this->id(), $this->label(), 'unknown', 'info',
                'Banco de dados inacessível — check não executado.', [], [], date('c'), 0,
            );
        }

        $service = new FreshnessService($this->pdo);

        $domainResults = [];
        $stale         = [];
        $failed        = [];
        $unknown       = [];

        foreach (self::DOMAINS as $domain) {
            try {
                $r = $service->forDomain($domain);
                $domainResults[$domain] = $r['status'];
                match ($r['status']) {
                    'stale', 'partial' => $stale[]   = $domain,
                    'failed'           => $failed[]  = $domain,
                    'unknown'          => $unknown[]  = $domain,
                    default            => null,
                };
            } catch (Throwable $e) {
                $domainResults[$domain] = 'unknown';
                $unknown[] = $domain;
            }
        }

        $ms = (int) round((microtime(true) - $start) * 1000);
        $metrics = array_merge(['domains_checked' => count(self::DOMAINS)], $domainResults);

        if ($failed !== []) {
            return SystemHealthCheck::critical(
                $this->id(), $this->label(),
                'Domínios com falha de refresh: ' . implode(', ', $failed),
                $metrics,
                ['Verificar logs do gold_mart_refresh e cron materialize_marts.php.'],
                $ms,
            );
        }

        if ($stale !== []) {
            return SystemHealthCheck::warning(
                $this->id(), $this->label(),
                'Domínios com dados defasados: ' . implode(', ', $stale),
                $metrics,
                ['Verificar se o cron materialize_marts.php está dentro do SLA.'],
                $ms,
            );
        }

        if ($unknown !== [] && count($unknown) === count(self::DOMAINS)) {
            return SystemHealthCheck::unknown(
                $this->id(), $this->label(),
                new \RuntimeException('Nenhum domínio com status conhecido. Banco pode estar indisponível.'),
                $ms,
            );
        }

        $freshCount = count(array_filter($domainResults, static fn($s) => $s === 'fresh'));
        return SystemHealthCheck::ok(
            $this->id(), $this->label(),
            "{$freshCount}/" . count(self::DOMAINS) . " domínios frescos.",
            $metrics,
            $ms,
        );
    }
}
