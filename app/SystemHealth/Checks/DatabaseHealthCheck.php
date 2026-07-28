<?php

declare(strict_types=1);

namespace BIomie\SystemHealth\Checks;

use BIomie\SystemHealth\CheckInterface;
use BIomie\SystemHealth\SystemHealthCheck;
use PDO;
use Throwable;

final class DatabaseHealthCheck implements CheckInterface
{
    private const CRITICAL_TABLES = [
        'biomie_pedidos_canonicos',
        'biomie_notas_fiscais_canonicas',
        'biomie_clientes_dim',
        'biomie_pipeline_jobs',
    ];

    public function __construct(private readonly ?PDO $pdo) {}

    public function id(): string    { return 'database'; }
    public function label(): string { return 'Banco de Dados'; }

    public function run(): SystemHealthCheck
    {
        $start = microtime(true);

        if ($this->pdo === null) {
            return new SystemHealthCheck(
                $this->id(), $this->label(), 'critical', 'critical',
                'Banco de dados inacessível. Verifique o plugin de autenticação (auth_gssapi_client) ou as credenciais de conexão.',
                [], ['Alterar plugin do usuário para mysql_native_password via painel Hostinger.'], date('c'), 0,
            );
        }

        try {
            $this->pdo->query('SELECT 1');
        } catch (Throwable $e) {
            return SystemHealthCheck::critical(
                $this->id(), $this->label(),
                'Não foi possível conectar ao banco de dados: ' . $e->getMessage(),
                [],
                ['Verificar credenciais e disponibilidade do MySQL.'],
                (int) round((microtime(true) - $start) * 1000),
            );
        }

        // Count biomie_ tables
        try {
            $tableCount = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'biomie_%'"
            )->fetchColumn();
        } catch (Throwable) {
            $tableCount = 0;
        }

        $emptyCritical = [];
        $missingTables = [];
        foreach (self::CRITICAL_TABLES as $table) {
            try {
                $count = (int) $this->pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
                if ($count === 0) {
                    $emptyCritical[] = $table;
                }
            } catch (Throwable) {
                $missingTables[] = $table;
            }
        }

        $ms      = (int) round((microtime(true) - $start) * 1000);
        $metrics = [
            'biomie_tables_count' => $tableCount,
            'empty_critical_tables' => count($emptyCritical),
            'missing_critical_tables' => count($missingTables),
        ];

        if ($missingTables !== []) {
            return SystemHealthCheck::critical(
                $this->id(), $this->label(),
                'Tabelas críticas ausentes: ' . implode(', ', $missingTables),
                $metrics,
                ['Executar migrations pendentes em admin.php?tab=migrations'],
                $ms,
            );
        }

        if ($emptyCritical !== []) {
            return SystemHealthCheck::warning(
                $this->id(), $this->label(),
                'Tabelas críticas vazias: ' . implode(', ', $emptyCritical),
                $metrics,
                ['Verificar se o sync inicial foi executado.'],
                $ms,
            );
        }

        return SystemHealthCheck::ok(
            $this->id(), $this->label(),
            "{$tableCount} tabelas biomie_ disponíveis, tabelas críticas populadas.",
            $metrics,
            $ms,
        );
    }
}
