<?php

declare(strict_types=1);

namespace BIomie\SystemHealth\Checks;

use BIomie\SystemHealth\CheckInterface;
use BIomie\SystemHealth\SystemHealthCheck;
use PDO;
use Throwable;

final class CustomerDataQualityCheck implements CheckInterface
{
    private const WARN_PCT     = 10.0;
    private const CRITICAL_PCT = 30.0;

    public function __construct(private readonly ?PDO $pdo) {}

    public function id(): string    { return 'customer_quality'; }
    public function label(): string { return 'Qualidade de Dados de Clientes'; }

    public function run(): SystemHealthCheck
    {
        $start = microtime(true);

        if ($this->pdo === null) {
            return new SystemHealthCheck(
                $this->id(), $this->label(), 'unknown', 'info',
                'Banco de dados inacessível — check não executado.', [], [], date('c'), 0,
            );
        }

        try {
            $row = $this->pdo->query(
                "SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN cpf_cnpj IS NULL OR cpf_cnpj = '' THEN 1 ELSE 0 END) AS missing_doc,
                    SUM(CASE WHEN nome_cliente IS NULL OR nome_cliente = '' THEN 1 ELSE 0 END) AS missing_name
                 FROM biomie_clientes_dim"
            )->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $ms = (int) round((microtime(true) - $start) * 1000);
            return SystemHealthCheck::unknown($this->id(), $this->label(), $e, $ms);
        }

        $ms    = (int) round((microtime(true) - $start) * 1000);
        $total = (int) ($row['total'] ?? 0);

        if ($total === 0) {
            return SystemHealthCheck::warning(
                $this->id(), $this->label(),
                'biomie_clientes_dim está vazia — sync de clientes não executado.',
                ['total_customers' => 0],
                ['Executar sync de clientes via cron.'],
                $ms,
            );
        }

        $missingDoc  = (int) ($row['missing_doc']  ?? 0);
        $missingName = (int) ($row['missing_name'] ?? 0);

        $pctDoc  = round(($missingDoc  / $total) * 100, 2);
        $pctName = round(($missingName / $total) * 100, 2);

        $metrics = [
            'total_customers'    => $total,
            'missing_doc'        => $missingDoc,
            'missing_doc_pct'    => $pctDoc,
            'missing_name'       => $missingName,
            'missing_name_pct'   => $pctName,
        ];

        $worst = max($pctDoc, $pctName);

        if ($worst >= self::CRITICAL_PCT) {
            return SystemHealthCheck::critical(
                $this->id(), $this->label(),
                "Qualidade degradada: {$pctDoc}% sem CPF/CNPJ, {$pctName}% sem nome.",
                $metrics,
                ['Verificar pipeline de enriquecimento de clientes.'],
                $ms,
            );
        }

        if ($worst >= self::WARN_PCT) {
            return SystemHealthCheck::warning(
                $this->id(), $this->label(),
                "Qualidade com atenção: {$pctDoc}% sem CPF/CNPJ, {$pctName}% sem nome.",
                $metrics,
                ['Monitorar enriquecimento de dados de clientes.'],
                $ms,
            );
        }

        return SystemHealthCheck::ok(
            $this->id(), $this->label(),
            "{$total} clientes. {$pctDoc}% sem CPF/CNPJ, {$pctName}% sem nome.",
            $metrics,
            $ms,
        );
    }
}
