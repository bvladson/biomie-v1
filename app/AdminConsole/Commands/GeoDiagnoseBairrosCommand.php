<?php

declare(strict_types=1);

namespace BIomie\AdminConsole\Commands;

use BIomie\AdminConsole\AdminCommandContext;
use BIomie\AdminConsole\AdminCommandInterface;
use BIomie\AdminConsole\AdminCommandResult;
use BIomie\GeoNameNormalizer;
use BIomie\TableHealthService;
use PDO;
use Throwable;

final class GeoDiagnoseBairrosCommand implements AdminCommandInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function name(): string { return 'geo:diagnose-bairros'; }
    public function description(): string { return 'Analisa bairros em clientes_geo_dim vs bairros_fortaleza_dim, sugere aliases faltantes.'; }
    public function group(): string { return 'geo'; }
    public function permission(): string { return 'admin.console'; }
    public function isDestructive(): bool { return false; }
    public function requiresConfirmation(): bool { return false; }

    public function validateArgs(array $args): ?string { return null; }

    public function run(AdminCommandContext $context, array $args = []): AdminCommandResult
    {
        $tableHealth = new TableHealthService($this->pdo, null);
        $lines = [];
        $tables = [];

        if (!$tableHealth->tableExists('biomie_clientes_geo_dim')) {
            return AdminCommandResult::error('Geo Diagnose Bairros', 'biomie_clientes_geo_dim ausente.');
        }
        if (!$tableHealth->tableExists('biomie_bairros_fortaleza_dim')) {
            return AdminCommandResult::error('Geo Diagnose Bairros', 'biomie_bairros_fortaleza_dim ausente.');
        }

        // Top 30 bairros normalizados em clientes_geo_dim
        try {
            $stmt = $this->pdo->query(
                "SELECT g.bairro_norm,
                        COUNT(*) AS clientes,
                        b.bairro_nome AS nome_oficial,
                        b.bairro_nome_normalizado AS norm_oficial
                 FROM biomie_clientes_geo_dim AS g
                 LEFT JOIN biomie_bairros_fortaleza_dim AS b ON b.bairro_nome_normalizado = g.bairro_norm
                 WHERE g.bairro_norm IS NOT NULL AND g.bairro_norm != ''
                 GROUP BY g.bairro_norm, b.bairro_nome, b.bairro_nome_normalizado
                 ORDER BY clientes DESC
                 LIMIT 30"
            );
            $topBairros = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable) {
            $topBairros = [];
        }

        if ($topBairros !== []) {
            $topRows = [];
            foreach ($topBairros as $r) {
                $hasMatch = $r['nome_oficial'] !== null;
                $topRows[] = [
                    (string) ($r['bairro_norm']  ?? ''),
                    (string) ($r['clientes']     ?? '0'),
                    $hasMatch ? $r['nome_oficial'] : '— sem match',
                    $hasMatch ? 'OK' : 'sem match',
                ];
            }
            $tables[] = [
                'title'   => 'Top 30 bairros normalizados em clientes_geo_dim',
                'headers' => ['bairro_norm', 'Clientes', 'Nome oficial', 'Status'],
                'rows'    => $topRows,
            ];
        } else {
            $lines[] = 'Nenhum bairro_norm preenchido em biomie_clientes_geo_dim.';
            $lines[] = 'O endereco precisa ser sincronizado via Omie (sync de clientes) ou enriquecido manualmente.';
        }

        // Bairros oficiais sem venda
        try {
            $stmt = $this->pdo->query(
                "SELECT b.bairro_nome, b.regional_atual, b.bairro_nome_normalizado
                 FROM biomie_bairros_fortaleza_dim AS b
                 LEFT JOIN biomie_clientes_geo_dim AS g ON g.bairro_norm = b.bairro_nome_normalizado
                 WHERE b.ativo = 1 AND g.id IS NULL
                 ORDER BY b.bairro_nome
                 LIMIT 30"
            );
            $bairrosSemCliente = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable) {
            $bairrosSemCliente = [];
        }

        if ($bairrosSemCliente !== []) {
            $semClienteRows = [];
            foreach ($bairrosSemCliente as $r) {
                $semClienteRows[] = [
                    (string) ($r['bairro_nome']            ?? ''),
                    (string) ($r['regional_atual']         ?? ''),
                    (string) ($r['bairro_nome_normalizado']?? ''),
                ];
            }
            $tables[] = [
                'title'   => 'Bairros oficiais sem clientes na geo_dim (primeiros 30)',
                'headers' => ['Bairro', 'Regional', 'Normalizado'],
                'rows'    => $semClienteRows,
            ];
        }

        // ── Breakdown by city for unmatched bairros ────────────────────────────
        // Uses mart_compradores_por_geo (has cidade_norm + bairro_norm) for full picture
        $hasMart        = false;
        $semMatchFortal = [];
        $semMatchOutside = [];
        $semCidade       = [];
        $semBairroCount  = 0;
        try {
            $hasMart = (bool) $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mart_compradores_por_geo'"
            )->fetchColumn();
        } catch (Throwable) {}

        if ($hasMart) {
            try {
                // Clients without any bairro info
                $semBairroCount = (int) $this->pdo->query(
                    "SELECT COUNT(DISTINCT COALESCE(CAST(codigo_cliente_omie AS CHAR), documento, cliente_nome))
                     FROM mart_compradores_por_geo
                     WHERE bairro_norm IS NULL OR TRIM(bairro_norm) = ''"
                )->fetchColumn();

                // Clients without city info (cannot determine if Fortaleza)
                $stmtSemCidade = $this->pdo->query(
                    "SELECT bairro_norm, COUNT(DISTINCT COALESCE(CAST(codigo_cliente_omie AS CHAR), documento, cliente_nome)) AS clientes
                     FROM mart_compradores_por_geo
                     WHERE (cidade_norm IS NULL OR TRIM(cidade_norm) = '')
                       AND bairro_norm IS NOT NULL AND TRIM(bairro_norm) != ''
                     GROUP BY bairro_norm ORDER BY clientes DESC LIMIT 20"
                );
                $semCidade = $stmtSemCidade !== false ? $stmtSemCidade->fetchAll(PDO::FETCH_ASSOC) : [];

                // Fortaleza clients with unmatched bairro (left join to dim)
                $stmtFortal = $this->pdo->query(
                    "SELECT m.bairro_norm, COUNT(DISTINCT COALESCE(CAST(m.codigo_cliente_omie AS CHAR), m.documento, m.cliente_nome)) AS clientes
                     FROM mart_compradores_por_geo AS m
                     LEFT JOIN biomie_bairros_fortaleza_dim AS b ON b.bairro_nome_normalizado = m.bairro_norm AND b.ativo = 1
                     WHERE LOWER(m.cidade_norm) = 'fortaleza'
                       AND m.bairro_norm IS NOT NULL AND TRIM(m.bairro_norm) != ''
                       AND b.bairro_nome IS NULL
                     GROUP BY m.bairro_norm ORDER BY clientes DESC LIMIT 20"
                );
                $semMatchFortal = $stmtFortal !== false ? $stmtFortal->fetchAll(PDO::FETCH_ASSOC) : [];

                // Non-Fortaleza clients (no match expected — just informative)
                $stmtOutside = $this->pdo->query(
                    "SELECT m.cidade_norm, m.bairro_norm,
                            COUNT(DISTINCT COALESCE(CAST(m.codigo_cliente_omie AS CHAR), m.documento, m.cliente_nome)) AS clientes
                     FROM mart_compradores_por_geo AS m
                     WHERE m.cidade_norm IS NOT NULL AND TRIM(m.cidade_norm) != ''
                       AND LOWER(m.cidade_norm) != 'fortaleza'
                       AND m.bairro_norm IS NOT NULL AND TRIM(m.bairro_norm) != ''
                     GROUP BY m.cidade_norm, m.bairro_norm ORDER BY clientes DESC LIMIT 20"
                );
                $semMatchOutside = $stmtOutside !== false ? $stmtOutside->fetchAll(PDO::FETCH_ASSOC) : [];
            } catch (Throwable) {}
        }

        // Fallback: use geo_dim when mart is absent
        $semMatch = [];
        if (!$hasMart) {
            try {
                $stmt = $this->pdo->query(
                    "SELECT g.bairro_norm, COUNT(*) AS clientes
                     FROM biomie_clientes_geo_dim AS g
                     WHERE g.bairro_norm IS NOT NULL
                       AND g.bairro_norm != ''
                       AND g.bairro_oficial_id_fortaleza IS NULL
                     GROUP BY g.bairro_norm
                     ORDER BY clientes DESC
                     LIMIT 20"
                );
                $semMatch = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            } catch (Throwable) {}
        }

        if ($hasMart) {
            $lines[] = '';
            $lines[] = '=== Sem match — separado por cidade ===';
            $lines[] = "  Sem bairro (nulo/vazio)   : {$semBairroCount} clientes";

            if ($semCidade !== []) {
                $lines[] = '';
                $lines[] = "  Sem cidade (nao enriquecido) — bairros:";
                foreach ($semCidade as $r) {
                    $lines[] = "    '{$r['bairro_norm']}' ({$r['clientes']} cli) — cidade desconhecida";
                }
            }

            if ($semMatchFortal !== []) {
                $lines[] = '';
                $lines[] = "  Fortaleza — sem match oficial (adicione alias em GeoNameNormalizer::ALIASES):";
                foreach ($semMatchFortal as $r) {
                    $bairroNorm = (string) ($r['bairro_norm'] ?? '');
                    $reNorm     = GeoNameNormalizer::normalizeKey($bairroNorm);
                    $lines[] = "    '{$bairroNorm}' ({$r['clientes']} cli) → alias key: '{$reNorm}'";
                }
            } else {
                $lines[] = "  Fortaleza — todos os bairros com venda tem match oficial.";
            }

            if ($semMatchOutside !== []) {
                $lines[] = '';
                $lines[] = "  Fora de Fortaleza (sem match esperado — apenas informativo):";
                foreach ($semMatchOutside as $r) {
                    $lines[] = "    [{$r['cidade_norm']}] '{$r['bairro_norm']}' ({$r['clientes']} cli)";
                }
            }
        } elseif ($semMatch !== []) {
            $lines[] = '';
            $lines[] = 'Bairros sem match (sugestoes de alias para GeoNameNormalizer):';
            foreach ($semMatch as $r) {
                $bairroNorm = (string) ($r['bairro_norm'] ?? '');
                $reNorm = GeoNameNormalizer::normalizeKey($bairroNorm);
                $lines[] = "  '{$bairroNorm}' ({$r['clientes']} cli) → alias: '{$reNorm}' => '<bairro_oficial_normalizado>'";
            }
        } else {
            $lines[] = '';
            $lines[] = 'Todos os bairros com bairro_norm preenchido ja tem match oficial.';
        }

        // Summary
        try {
            $total = (int) $this->pdo->query("SELECT COUNT(*) FROM biomie_clientes_geo_dim")->fetchColumn();
            $comBairroNorm = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM biomie_clientes_geo_dim WHERE bairro_norm IS NOT NULL AND bairro_norm != ''"
            )->fetchColumn();
            $comMatch = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM biomie_clientes_geo_dim WHERE bairro_oficial_id_fortaleza IS NOT NULL"
            )->fetchColumn();
            $semBairro = $total - $comBairroNorm;

            $lines[] = '';
            $lines[] = "Resumo: {$total} clientes geo | {$comBairroNorm} com bairro_norm | {$comMatch} com match oficial | {$semBairro} sem bairro";
        } catch (Throwable) {}

        $noMatchFortalCount = count($semMatchFortal ?: $semMatch);
        $summaryText = $noMatchFortalCount > 0
            ? "{$noMatchFortalCount} bairros de Fortaleza sem match. Adicione aliases em GeoNameNormalizer::ALIASES para ampliar cobertura."
            : 'Diagnostico de bairros concluido.';

        return AdminCommandResult::ok(
            'Geo Diagnose Bairros',
            $summaryText,
            outputLines: $lines,
            tables:      $tables,
        );
    }
}
