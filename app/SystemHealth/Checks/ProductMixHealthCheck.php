<?php

declare(strict_types=1);

namespace BIomie\SystemHealth\Checks;

use BIomie\Http;
use BIomie\SystemHealth\CheckInterface;
use BIomie\SystemHealth\SystemHealthCheck;
use PDO;

final class ProductMixHealthCheck implements CheckInterface
{
    public function __construct(private readonly ?PDO $pdo) {}
    public function id(): string { return 'product_mix'; }
    public function label(): string { return 'Mix Faturado'; }

    public function run(): SystemHealthCheck
    {
        $start = microtime(true);
        if (!$this->pdo instanceof PDO) {
            return SystemHealthCheck::unknown($this->id(), $this->label(), new \RuntimeException('Banco indisponível.'), 0);
        }
        $monthStart = date('Y-m-01');
        $today = date('Y-m-d');
        $metrics = ['period_start' => $monthStart, 'period_end' => $today, 'nfs' => 0, 'items' => 0, 'products' => 0];
        try {
            if (!Http::tableExists($this->pdo, 'biomie_notas_fiscais_canonicas') || !Http::tableExists($this->pdo, 'biomie_notas_fiscais_itens_canonicos')) {
                return SystemHealthCheck::warning($this->id(), $this->label(), 'Tabelas canônicas de NF/itens não existem.', $metrics, ['Rodar migrations canônicas e sync fiscal.'], 0);
            }
            $stmt = $this->pdo->prepare("SELECT COUNT(DISTINCT n.nota_key) AS nfs, COUNT(i.id) AS items, COUNT(DISTINCT i.codigo_produto) AS products FROM biomie_notas_fiscais_canonicas n LEFT JOIN biomie_notas_fiscais_itens_canonicos i ON i.nota_key = n.nota_key WHERE n.status_fiscal = 'autorizada' AND n.data_competencia BETWEEN :start AND :end");
            $stmt->execute(['start' => $monthStart, 'end' => $today]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $metrics['nfs'] = (int) ($row['nfs'] ?? 0);
            $metrics['items'] = (int) ($row['items'] ?? 0);
            $metrics['products'] = (int) ($row['products'] ?? 0);
        } catch (\Throwable $e) {
            return SystemHealthCheck::unknown($this->id(), $this->label(), $e, (int) round((microtime(true) - $start) * 1000));
        }
        $ms = (int) round((microtime(true) - $start) * 1000);
        if ($metrics['nfs'] > 0 && $metrics['items'] === 0) {
            return SystemHealthCheck::critical($this->id(), $this->label(), 'Existem NFs autorizadas, mas os itens canônicos estão vazios.', $metrics, ['Reprocessar sync fiscal de itens e materialização de produtos.'], $ms);
        }
        if ($metrics['items'] > 0 && $metrics['products'] === 0) {
            return SystemHealthCheck::warning($this->id(), $this->label(), 'Itens encontrados, mas sem produtos distintos.', $metrics, ['Verificar codigo_produto/descricao_produto no sync fiscal.'], $ms);
        }
        return SystemHealthCheck::ok($this->id(), $this->label(), 'Mix faturado com base canônica consistente.', $metrics, $ms);
    }
}
