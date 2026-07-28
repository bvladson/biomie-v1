<?php

declare(strict_types=1);

namespace BIomie\AdminConsole;

use BIomie\AdminConsole\Commands\ClientesEnrichOmieAllCommand;
use BIomie\AdminConsole\Commands\ClientesEnrichOmieCommand;
use BIomie\AdminConsole\Commands\ClientesGeoCoverageCommand;
use BIomie\AdminConsole\Commands\ClientesOmieSampleCommand;
use BIomie\AdminConsole\Commands\ClientesMaterializeDimCommand;
use BIomie\AdminConsole\Commands\ClientesStatusCommand;
use BIomie\AdminConsole\Commands\CnaeDiagnoseFiscalSourcesCommand;
use BIomie\AdminConsole\Commands\CnaeDiagnoseSourcesCommand;
use BIomie\AdminConsole\Commands\CnaeMaterializeCommand;
use BIomie\AdminConsole\Commands\CacheClearCommand;
use BIomie\AdminConsole\Commands\DbCountsCommand;
use BIomie\AdminConsole\Commands\DbStatusCommand;
use BIomie\AdminConsole\Commands\EncodingCheckCommand;
use BIomie\AdminConsole\Commands\GeoDiagnoseBairrosCommand;
use BIomie\AdminConsole\Commands\GeoMapStatusCommand;
use BIomie\AdminConsole\Commands\GeoMaterializeCommand;
use BIomie\AdminConsole\Commands\GeoStatusCommand;
use BIomie\AdminConsole\Commands\GeoRebuildPeriodCommand;
use BIomie\AdminConsole\Commands\GeoValidateEnrichmentCommand;
use BIomie\AdminConsole\Commands\GoldMaterializeCommercialCommand;
use BIomie\AdminConsole\Commands\GoldStatusCommand;
use BIomie\AdminConsole\Commands\HelpCommand;
use BIomie\AdminConsole\Commands\LogsRecentCommand;
use BIomie\AdminConsole\Commands\MigrationsStatusCommand;
use BIomie\AdminConsole\Commands\OmieStatusCommand;
use BIomie\AdminConsole\Commands\RbacStatusCommand;
use BIomie\AdminConsole\Commands\SystemHealthCommand;
use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;
use PDO;

final class AdminCommandRegistry
{
    /** @var array<string,AdminCommandInterface> */
    private array $commands = [];

    public function register(AdminCommandInterface $command): void
    {
        $this->commands[$command->name()] = $command;
    }

    public function has(string $name): bool
    {
        return isset($this->commands[$name]);
    }

    public function get(string $name): ?AdminCommandInterface
    {
        return $this->commands[$name] ?? null;
    }

    /**
     * @return list<AdminCommandInterface>
     */
    public function list(): array
    {
        return array_values($this->commands);
    }

    public static function build(PDO $pdo, Logger $logger, Config $config): self
    {
        $registry = new self();

        $connectionName = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
        $appVersion = (string) $config->get('app.version', '1.0');
        $appEnv = (string) $config->get('app.env', getenv('APP_ENV') ?: 'producao');

        $registry->register(new SystemHealthCommand($pdo, $logger, $config, $appVersion, $appEnv));
        $registry->register(new CacheClearCommand(dirname(__DIR__, 2) . '/storage/cache'));
        $registry->register(new DbStatusCommand($pdo));
        $registry->register(new DbCountsCommand($pdo));
        $registry->register(new MigrationsStatusCommand($config, $logger));
        $registry->register(new GoldStatusCommand($pdo));
        $registry->register(new GoldMaterializeCommercialCommand($config, $logger, $connectionName));
        $registry->register(new ClientesMaterializeDimCommand($config, $logger, $connectionName));
        $registry->register(new GeoMapStatusCommand($pdo));
        $registry->register(new GeoMaterializeCommand($config, $logger, $connectionName));
        $registry->register(new GeoRebuildPeriodCommand($pdo, $logger));
        $registry->register(new GeoStatusCommand($pdo));
        $registry->register(new GeoValidateEnrichmentCommand($pdo));
        $registry->register(new GeoDiagnoseBairrosCommand($pdo));
        $registry->register(new ClientesStatusCommand($pdo));
        $registry->register(new ClientesGeoCoverageCommand($pdo));
        $registry->register(new ClientesEnrichOmieCommand($config, $logger, $connectionName));
        $registry->register(new ClientesEnrichOmieAllCommand($config, $logger, $connectionName));
        $registry->register(new ClientesOmieSampleCommand($config, $logger, $connectionName));
        $registry->register(new CnaeDiagnoseSourcesCommand($pdo));
        $registry->register(new CnaeDiagnoseFiscalSourcesCommand($pdo));
        $registry->register(new CnaeMaterializeCommand($pdo, $logger));
        $registry->register(new OmieStatusCommand($pdo, $config));
        $registry->register(new LogsRecentCommand($pdo));
        $registry->register(new RbacStatusCommand($pdo));
        $registry->register(new EncodingCheckCommand());

        // help must be registered last so it can list all commands
        $registry->register(new HelpCommand($registry));

        return $registry;
    }
}
