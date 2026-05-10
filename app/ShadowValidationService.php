<?php

declare(strict_types=1);

namespace BIomie;

use BIomie\DataPlatform\Validation\ShadowComparisonEngine;
use BIomie\DataPlatform\Validation\ShadowTolerancePolicy;
use BIomie\DataPlatform\Validation\ShadowValidationCoordinator;
use BIomie\DataPlatform\Validation\ShadowValidationFormatter;
use BIomie\DataPlatform\Validation\ShadowValidationRepository;
use BIomie\DataPlatform\Validation\ShadowValidationRunner;
use PDO;
use Throwable;

final class ShadowValidationService
{
    private static bool $shutdownRegistered = false;

    /** @var list<callable():void> */
    private static array $deferredTasks = [];

    private function __construct(
        private readonly ShadowValidationCoordinator $coordinator,
    ) {
    }

    public static function boot(Config $config, Logger $logger, ?PDO $pdo = null): self
    {
        self::registerShutdownRunner();

        $formatter = new ShadowValidationFormatter();
        $repository = new ShadowValidationRepository(
            $logger,
            $formatter,
            $pdo ?? self::connectDefaultPdo($config, $logger),
            Bootstrap::rootPath('storage/logs/shadow_validation.log')
        );
        $engine = new ShadowComparisonEngine(new ShadowTolerancePolicy(), $formatter);
        $runner = new ShadowValidationRunner($logger, $engine, $repository, $formatter);

        return new self(new ShadowValidationCoordinator(
            [
                'enabled' => (bool) $config->get('shadow_validation.enabled', false),
                'sample_rate' => max(0.0, min(100.0, (float) $config->get('shadow_validation.sample_rate', 0))),
                'persist_matches' => (bool) $config->get('shadow_validation.persist_matches', true),
                'endpoints' => is_array($config->get('shadow_validation.endpoints', []))
                    ? $config->get('shadow_validation.endpoints', [])
                    : [],
            ],
            $runner,
            $formatter
        ));
    }

    /**
     * @param callable():mixed $runLegacy
     * @param callable():mixed $runCandidate
     * @param array<string,mixed> $queryContext
     */
    public function defer(string $endpoint, callable $runLegacy, callable $runCandidate, array $queryContext = []): void
    {
        $task = $this->coordinator->deferTask($endpoint, $runLegacy, $runCandidate, $queryContext);
        if ($task !== null) {
            self::$deferredTasks[] = $task;
        }
    }

    private static function registerShutdownRunner(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }

        self::$shutdownRegistered = true;

        register_shutdown_function(static function (): void {
            if (self::$deferredTasks === []) {
                return;
            }

            ignore_user_abort(true);
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }

            foreach (self::$deferredTasks as $task) {
                try {
                    $task();
                } catch (Throwable) {
                    // Falha isolada: nunca afetar a resposta oficial.
                }
            }

            self::$deferredTasks = [];
        });
    }

    private static function connectDefaultPdo(Config $config, Logger $logger): ?PDO
    {
        try {
            $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
            if (!Database::isConfigured($config, $connection)) {
                return null;
            }

            return Database::connect($config, $connection);
        } catch (Throwable $exception) {
            $logger->warning('Shadow validation sem banco configurado; usando fallback em log.', [
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
