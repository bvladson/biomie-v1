<?php

declare(strict_types=1);

namespace BIomie;

use PDO;
use Throwable;

/**
 * Varre todas as fontes fiscais/comerciais disponíveis em busca de CNAE.
 * Não altera dados — somente leitura diagnóstica.
 */
final class CnaeSourceInspectorService
{
    /** Chaves CNAE conhecidas em payloads JSON do Omie */
    private const JSON_CNAE_KEYS = [
        'cnae', 'cnae_fiscal', 'cnae_principal', 'cnaePrincipal',
        'codigo_cnae', 'cod_cnae', 'cnae_codigo', 'cnaePrincipalCodigo',
        'tipo_atividade', 'atividade_principal',
    ];

    /** Paths JSON aninhados a testar */
    private const JSON_NESTED_PATHS = [
        '$.atividade_principal[0].codigo',
        '$.atividade_principal.codigo',
        '$.tipo_atividade[0].codigo',
        '$.destinatario.cnae',
        '$.tomador.cnae',
        '$.fiscal.cnae',
        '$.dados_fiscais.cnae',
        '$.cliente.cnae',
        '$.cliente.cnae_principal',
        '$.atividades[0].codigo',
    ];

    /** Nomes de coluna estruturada que podem conter CNAE */
    private const COLUMN_CNAE_CANDIDATES = [
        'cnae_codigo', 'cnae', 'cnae_principal', 'cnae_fiscal',
    ];

    public function __construct(private readonly PDO $pdo) {}

    /**
     * Executa a inspeção completa de todas as fontes.
     *
     * @return array<string,array<string,mixed>>
     */
    public function inspect(): array
    {
        return [
            'biomie_omie_bronze'              => $this->inspectBronze(),
            'biomie_notas_fiscais_canonicas'  => $this->inspectStructuredTable(
                table:          'biomie_notas_fiscais_canonicas',
                dateColumns:    ['data_emissao', 'data_competencia', 'created_at'],
                docColumns:     ['cnpj_destinatario', 'cpf_destinatario', 'documento', 'cnpj_tomador'],
                clienteColumns: ['codigo_cliente_omie'],
                nfColumns:      ['numero_nf', 'nf_numero', 'numero', 'chave_nfe'],
                pedidoColumns:  ['pedido_id_omie', 'numero_pedido'],
                jsonColumn:     'payload_json',
            ),
            'biomie_pedidos_canonicos'        => $this->inspectStructuredTable(
                table:          'biomie_pedidos_canonicos',
                dateColumns:    ['data_pedido', 'data_emissao', 'created_at'],
                docColumns:     ['cnpj_cpf', 'documento', 'cpf_cnpj'],
                clienteColumns: ['codigo_cliente_omie'],
                nfColumns:      [],
                pedidoColumns:  ['numero_pedido', 'pedido_id_omie', 'codigo_pedido_omie'],
                jsonColumn:     'payload_json',
            ),
            'mart_fiscal_nfe'                 => $this->inspectStructuredTable(
                table:          'mart_fiscal_nfe',
                dateColumns:    ['data_emissao', 'data_competencia'],
                docColumns:     ['cnpj_destinatario', 'documento_normalizado'],
                clienteColumns: ['codigo_cliente_omie'],
                nfColumns:      ['numero_nf', 'nf_numero', 'id'],
                pedidoColumns:  ['pedido_id_omie'],
                jsonColumn:     null,
            ),
            'mart_cliente_periodo'            => $this->inspectStructuredTable(
                table:          'mart_cliente_periodo',
                dateColumns:    ['competencia'],
                docColumns:     ['documento_normalizado', 'documento'],
                clienteColumns: ['codigo_cliente_omie'],
                nfColumns:      [],
                pedidoColumns:  [],
                jsonColumn:     null,
            ),
            'mart_comercial_pedido'           => $this->inspectStructuredTable(
                table:          'mart_comercial_pedido',
                dateColumns:    ['data_pedido', 'competencia', 'data_emissao'],
                docColumns:     ['documento_normalizado', 'cnpj_cpf'],
                clienteColumns: ['codigo_cliente_omie'],
                nfColumns:      [],
                pedidoColumns:  ['numero_pedido', 'pedido_id_omie'],
                jsonColumn:     null,
            ),
            'mart_bridge_pedido_nfe_titulo'   => $this->inspectStructuredTable(
                table:          'mart_bridge_pedido_nfe_titulo',
                dateColumns:    ['data_emissao', 'created_at'],
                docColumns:     [],
                clienteColumns: ['codigo_cliente_omie'],
                nfColumns:      ['nf_id', 'numero_nf'],
                pedidoColumns:  ['pedido_id_omie', 'numero_pedido'],
                jsonColumn:     null,
            ),
            'biomie_vendas_geo_cliente_fact'  => $this->inspectStructuredTable(
                table:          'biomie_vendas_geo_cliente_fact',
                dateColumns:    ['data_referencia'],
                docColumns:     [],
                clienteColumns: ['codigo_cliente_omie'],
                nfColumns:      ['nf_id'],
                pedidoColumns:  ['pedido_id'],
                jsonColumn:     null,
            ),
        ];
    }

    // ── Bronze inspection ─────────────────────────────────────────────────────

    /**
     * @return array<string,mixed>
     */
    private function inspectBronze(): array
    {
        $result = $this->emptySourceResult('biomie_omie_bronze');

        try {
            if (!$this->tableExists('biomie_omie_bronze')) {
                return $result;
            }
            $result['table_exists'] = true;

            $result['total_registros'] = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM biomie_omie_bronze WHERE domain_name = 'clientes'"
            )->fetchColumn();

            $result['registros_com_codigo_cliente'] = (int) $this->pdo->query(
                "SELECT COUNT(DISTINCT natural_key) FROM biomie_omie_bronze WHERE domain_name = 'clientes' AND natural_key > 0"
            )->fetchColumn();

            // Scan each CNAE key in flat JSON
            $foundKeys = [];
            foreach (self::JSON_CNAE_KEYS as $key) {
                try {
                    $n = (int) $this->pdo->query(
                        "SELECT COUNT(*) FROM biomie_omie_bronze
                         WHERE domain_name = 'clientes'
                           AND NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$." . addslashes($key) . "')), '') IS NOT NULL"
                    )->fetchColumn();
                    if ($n > 0) {
                        $foundKeys[$key] = $n;
                    }
                } catch (Throwable) {}
            }

            // Scan nested JSON paths
            foreach (self::JSON_NESTED_PATHS as $path) {
                try {
                    $n = (int) $this->pdo->query(
                        "SELECT COUNT(*) FROM biomie_omie_bronze
                         WHERE domain_name = 'clientes'
                           AND NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '" . addslashes($path) . "')), '') IS NOT NULL"
                    )->fetchColumn();
                    if ($n > 0) {
                        $foundKeys[$path] = $n;
                    }
                } catch (Throwable) {}
            }

            // Check CNAE PRINCIPAL (uppercase, space — must be quoted in JSON path)
            try {
                $n = (int) $this->pdo->query(
                    "SELECT COUNT(*) FROM biomie_omie_bronze
                     WHERE domain_name = 'clientes'
                       AND NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.\"CNAE PRINCIPAL\"')), '') IS NOT NULL"
                )->fetchColumn();
                if ($n > 0) {
                    $foundKeys['CNAE PRINCIPAL'] = $n;
                }
            } catch (Throwable) {}

            $result['chaves_cnae_encontradas'] = $foundKeys;
            $result['registros_com_cnae']      = !empty($foundKeys) ? max($foundKeys) : 0;

            // Document keys
            foreach (['cnpj_cpf', 'cpf_cnpj', 'documento', 'cpf', 'cnpj'] as $dk) {
                try {
                    $n = (int) $this->pdo->query(
                        "SELECT COUNT(*) FROM biomie_omie_bronze
                         WHERE domain_name = 'clientes'
                           AND NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$." . $dk . "')), '') IS NOT NULL"
                    )->fetchColumn();
                    if ($n > 0) {
                        $result['registros_com_documento'] = $n;
                        break;
                    }
                } catch (Throwable) {}
            }

            // Match analysis using CnaeResolver
            if ($result['registros_com_cnae'] > 0) {
                $resolver = new CnaeResolver($this->pdo);
                $diag     = $resolver->diagnoseBronzeDetailed();
                $result['match_por_codigo_cliente_omie']    = $diag['total_match_por_codigo'];
                $result['match_por_documento_normalizado']  = $diag['total_match_por_doc'];
                $result['sem_match']                        = $diag['total_sem_match'];

                foreach ($diag['sample'] as $s) {
                    $result['exemplos'][] = [
                        'codigo_cliente_omie' => $s['codigo_cliente_omie'],
                        'cnae_codigo'         => $s['cnae_codigo'],
                        'documento_mascarado' => $s['documento_mascarado'],
                        'match_codigo'        => $s['match_por_codigo'],
                        'match_doc'           => $s['match_por_documento'],
                    ];
                }
            }
        } catch (Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    // ── Structured table inspection ───────────────────────────────────────────

    /**
     * @param list<string> $dateColumns
     * @param list<string> $docColumns
     * @param list<string> $clienteColumns
     * @param list<string> $nfColumns
     * @param list<string> $pedidoColumns
     * @return array<string,mixed>
     */
    private function inspectStructuredTable(
        string  $table,
        array   $dateColumns,
        array   $docColumns,
        array   $clienteColumns,
        array   $nfColumns,
        array   $pedidoColumns,
        ?string $jsonColumn,
    ): array {
        $result = $this->emptySourceResult($table);

        try {
            if (!$this->tableExists($table)) {
                return $result;
            }
            $result['table_exists'] = true;
            $result['total_registros'] = (int) $this->pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();

            // Direct CNAE columns
            foreach (self::COLUMN_CNAE_CANDIDATES as $col) {
                if ($this->columnExists($table, $col)) {
                    $n = (int) $this->pdo->query(
                        "SELECT COUNT(*) FROM `{$table}` WHERE `{$col}` IS NOT NULL AND `{$col}` != ''"
                    )->fetchColumn();
                    if ($n > 0) {
                        $result['registros_com_cnae']    = $n;
                        $result['cnae_coluna_encontrada'] = $col;
                        break;
                    }
                }
            }

            // JSON column scan
            if ($jsonColumn !== null && $this->columnExists($table, $jsonColumn)) {
                $jsonFound = [];
                foreach (array_merge(self::JSON_CNAE_KEYS, ['CNAE PRINCIPAL']) as $key) {
                    $path = $key === 'CNAE PRINCIPAL' ? '$.\"CNAE PRINCIPAL\"' : '$.' . $key;
                    try {
                        $n = (int) $this->pdo->query(
                            "SELECT COUNT(*) FROM `{$table}`
                             WHERE NULLIF(JSON_UNQUOTE(JSON_EXTRACT(`{$jsonColumn}`, '" . addslashes($path) . "')), '') IS NOT NULL"
                        )->fetchColumn();
                        if ($n > 0) {
                            $jsonFound[$key] = $n;
                            if ($result['registros_com_cnae'] === 0) {
                                $result['registros_com_cnae'] = $n;
                            }
                        }
                    } catch (Throwable) {}
                }
                $result['json_cnae_keys'] = $jsonFound;
            }

            // Date column
            foreach ($dateColumns as $col) {
                if ($this->columnExists($table, $col)) {
                    $result['registros_com_data'] = (int) $this->pdo->query(
                        "SELECT COUNT(*) FROM `{$table}` WHERE `{$col}` IS NOT NULL"
                    )->fetchColumn();
                    break;
                }
            }

            // Document column
            foreach ($docColumns as $col) {
                if ($this->columnExists($table, $col)) {
                    $n = (int) $this->pdo->query(
                        "SELECT COUNT(*) FROM `{$table}` WHERE `{$col}` IS NOT NULL AND `{$col}` != ''"
                    )->fetchColumn();
                    if ($n > 0) {
                        $result['registros_com_documento'] = $n;
                        break;
                    }
                }
            }

            // Cliente code
            foreach ($clienteColumns as $col) {
                if ($this->columnExists($table, $col)) {
                    $result['registros_com_codigo_cliente'] = (int) $this->pdo->query(
                        "SELECT COUNT(*) FROM `{$table}` WHERE `{$col}` IS NOT NULL AND `{$col}` > 0"
                    )->fetchColumn();
                    break;
                }
            }

            // NF reference
            foreach ($nfColumns as $col) {
                if ($this->columnExists($table, $col)) {
                    $result['registros_com_nf'] = (int) $this->pdo->query(
                        "SELECT COUNT(*) FROM `{$table}` WHERE `{$col}` IS NOT NULL"
                    )->fetchColumn();
                    break;
                }
            }

            // Pedido reference
            foreach ($pedidoColumns as $col) {
                if ($this->columnExists($table, $col)) {
                    $n = (int) $this->pdo->query(
                        "SELECT COUNT(*) FROM `{$table}` WHERE `{$col}` IS NOT NULL AND `{$col}` != '' AND CAST(`{$col}` AS CHAR) != '0'"
                    )->fetchColumn();
                    if ($n > 0) {
                        $result['registros_com_pedido'] = $n;
                        break;
                    }
                }
            }

            // Match analysis for rows with CNAE in a direct column
            $cnaeCol    = $result['cnae_coluna_encontrada'];
            $clienteCol = null;
            foreach ($clienteColumns as $c) {
                if ($this->columnExists($table, $c)) {
                    $clienteCol = $c;
                    break;
                }
            }

            if ($cnaeCol !== null && $clienteCol !== null && $this->tableExists('biomie_clientes_dim')) {
                try {
                    $result['match_por_codigo_cliente_omie'] = (int) $this->pdo->query(
                        "SELECT COUNT(DISTINCT t.`{$clienteCol}`) FROM `{$table}` t
                         INNER JOIN biomie_clientes_dim cd ON cd.codigo_cliente_omie = t.`{$clienteCol}`
                         WHERE t.`{$cnaeCol}` IS NOT NULL AND t.`{$cnaeCol}` != ''"
                    )->fetchColumn();
                } catch (Throwable) {}

                // Masked sample
                try {
                    $stmt = $this->pdo->query(
                        "SELECT `{$clienteCol}` AS codigo_cliente, `{$cnaeCol}` AS cnae
                         FROM `{$table}` WHERE `{$cnaeCol}` IS NOT NULL AND `{$cnaeCol}` != '' LIMIT 5"
                    );
                    if ($stmt) {
                        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                            $result['exemplos'][] = [
                                'codigo_cliente_omie' => $r['codigo_cliente'] ?? null,
                                'cnae_codigo'         => $r['cnae']           ?? null,
                                'documento_mascarado' => '***',
                                'match_codigo'        => '-',
                                'match_doc'           => '-',
                            ];
                        }
                    }
                } catch (Throwable) {}
            }
        } catch (Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @return array<string,mixed>
     */
    private function emptySourceResult(string $table): array
    {
        return [
            'fonte'                          => $table,
            'table_exists'                   => false,
            'total_registros'                => 0,
            'registros_com_cnae'             => 0,
            'cnae_coluna_encontrada'         => null,
            'registros_com_data'             => 0,
            'registros_com_documento'        => 0,
            'registros_com_codigo_cliente'   => 0,
            'registros_com_nf'               => 0,
            'registros_com_pedido'           => 0,
            'match_por_codigo_cliente_omie'  => 0,
            'match_por_documento_normalizado' => 0,
            'sem_match'                      => 0,
            'chaves_cnae_encontradas'        => [],
            'json_cnae_keys'                 => [],
            'exemplos'                       => [],
            'error'                          => null,
        ];
    }

    private function tableExists(string $table): bool
    {
        try {
            $this->pdo->query("SELECT 1 FROM `{$table}` LIMIT 1");
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
            );
            $stmt->execute([$table, $column]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }
}
