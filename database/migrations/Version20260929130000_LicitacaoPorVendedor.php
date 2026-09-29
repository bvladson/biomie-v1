<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;
use BIomie\Support\LicitacaoSeller;

/**
 * Regra de canal oficial: vendedor "Licitação" no Omie => licitação; qualquer
 * outro vendedor => varejo. Vale para TODAS as empresas do grupo.
 *
 * Definida pelo dono do BIomie em 29/09/2026 (ver LicitacaoSeller). Substitui a
 * mistura herdada — LIKE '%LICITA%' no nome, palavras na observação
 * (pregão/edital/empenho), padrão por empresa ora varejo ora licitação —, que
 * inferia canal por texto e, no caso das observações, pelo cliente.
 *
 * Por empresa ativa:
 *   prioridade 1   cliente_cnpj_list -> outros   (intra-grupo: MANTIDA; não é venda)
 *   prioridade 5   vendedor_id_list  -> licitacao (código do vendedor "Licitação"
 *                                                  no cadastro Omie; sobrevive a
 *                                                  renomeação no Omie)
 *   prioridade 10  vendedor_nome_igual 'Licitação' -> licitacao (fallback por nome
 *                                                  normalizado, igualdade exata)
 *   prioridade 999 default -> varejo, confiança alta (vendedor comum)
 *   vendedor_nome_like / pedido_obs_like / projeto_id_list -> desativadas (ativo=0)
 *
 * REVERSÍVEL: o estado anterior de todas as regras fica em
 * biomie_channel_rules_20260929; down() restaura e remove as regras novas.
 * Não reclassifica dados: isso é feito, com simulação e relatório, por
 * bin/reclassify_unclassified_invoices.php.
 */
return new class extends AbstractMigration {
    private const TABLE = 'biomie_channel_classification_rules';
    private const SNAPSHOT = 'biomie_channel_rules_20260929';
    private const TYPES_OLD = "'manual','vendedor_nome_like','cliente_cnpj_list','pedido_obs_like','projeto_id_list','default'";
    private const TYPES_NEW = "'manual','vendedor_nome_like','cliente_cnpj_list','pedido_obs_like','projeto_id_list','default','vendedor_id_list','vendedor_nome_igual'";

    public function getVersion(): string
    {
        return '20260929130000';
    }

    public function getDescription(): string
    {
        return 'Canal por vendedor: "Licitação" (código ou nome) => licitação; demais => varejo. Regras antigas preservadas para rollback.';
    }

    public function getType(): string
    {
        return 'data';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE ' . self::TABLE . ' MODIFY COLUMN rule_type ENUM(' . self::TYPES_NEW . ') NOT NULL');

        $pdo->exec('CREATE TABLE IF NOT EXISTS ' . self::SNAPSHOT . ' LIKE ' . self::TABLE);
        // INSERT IGNORE: reexecutar não sobrescreve o estado ORIGINAL guardado.
        $pdo->exec('INSERT IGNORE INTO ' . self::SNAPSHOT . ' SELECT * FROM ' . self::TABLE);

        $companies = $pdo->query('SELECT id FROM biomie_companies WHERE ativo = 1 ORDER BY id')
            ->fetchAll(PDO::FETCH_COLUMN);

        $disable = $pdo->prepare(
            'UPDATE ' . self::TABLE . "
                SET ativo = 0
              WHERE company_id = :cid
                AND rule_type IN ('vendedor_nome_like', 'pedido_obs_like', 'projeto_id_list')"
        );
        $insert = $pdo->prepare(
            'INSERT IGNORE INTO ' . self::TABLE . '
                (company_id, priority, rule_type, match_value, target_channel, confidence, ativo)
             VALUES (:cid, :prio, :type, :match, :target, \'alta\', 1)'
        );
        $defaults = $pdo->prepare(
            'UPDATE ' . self::TABLE . "
                SET target_channel = 'varejo', confidence = 'alta', ativo = 1
              WHERE company_id = :cid AND rule_type = 'default'"
        );
        $countDefaults = $pdo->prepare(
            'SELECT COUNT(*) FROM ' . self::TABLE . " WHERE company_id = :cid AND rule_type = 'default'"
        );

        foreach ($companies as $companyId) {
            $cid = (int) $companyId;
            $disable->execute(['cid' => $cid]);

            $codes = $this->licitacaoSellerCodes($pdo, $cid);
            if ($codes !== []) {
                $insert->execute([
                    'cid' => $cid, 'prio' => 5, 'type' => 'vendedor_id_list',
                    'match' => implode(',', $codes), 'target' => 'licitacao',
                ]);
            }
            $insert->execute([
                'cid' => $cid, 'prio' => 10, 'type' => 'vendedor_nome_igual',
                'match' => 'Licitação', 'target' => 'licitacao',
            ]);

            $countDefaults->execute(['cid' => $cid]);
            if ((int) $countDefaults->fetchColumn() > 0) {
                $defaults->execute(['cid' => $cid]);
            } else {
                $insert->execute([
                    'cid' => $cid, 'prio' => 999, 'type' => 'default',
                    'match' => '', 'target' => 'varejo',
                ]);
            }
        }
    }

    public function down(PDO $pdo): void
    {
        if (!$this->tableExists($pdo, self::SNAPSHOT)) {
            return;
        }

        // Regras criadas por up() (não existem no retrato) saem; as que existiam
        // voltam exatamente ao estado guardado.
        $pdo->exec(
            'DELETE r FROM ' . self::TABLE . ' r
               LEFT JOIN ' . self::SNAPSHOT . ' s ON s.id = r.id
              WHERE s.id IS NULL'
        );
        $pdo->exec(
            'UPDATE ' . self::TABLE . ' r
               JOIN ' . self::SNAPSHOT . ' s ON s.id = r.id
                SET r.priority = s.priority, r.rule_type = s.rule_type,
                    r.match_value = s.match_value, r.target_channel = s.target_channel,
                    r.confidence = s.confidence, r.ativo = s.ativo'
        );
        $pdo->exec('ALTER TABLE ' . self::TABLE . ' MODIFY COLUMN rule_type ENUM(' . self::TYPES_OLD . ') NOT NULL');
        $pdo->exec('DROP TABLE ' . self::SNAPSHOT);
    }

    /**
     * Códigos Omie do vendedor "Licitação" da empresa: cadastro de vendedores e,
     * por segurança, os códigos já vistos em NFs e pedidos com esse nome.
     *
     * @return list<string>
     */
    private function licitacaoSellerCodes(PDO $pdo, int $companyId): array
    {
        $candidates = [];
        $sources = [
            'SELECT codigo_omie AS codigo, nome FROM biomie_vendedores_omie WHERE company_id = :cid',
            'SELECT DISTINCT codigo_vendedor_omie AS codigo, nome_vendedor AS nome
               FROM biomie_notas_fiscais_canonicas WHERE company_id = :cid',
            'SELECT DISTINCT codigo_vendedor_omie AS codigo, nome_vendedor AS nome
               FROM biomie_pedidos_canonicos WHERE company_id = :cid',
        ];
        foreach ($sources as $sql) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['cid' => $companyId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $code = trim((string) ($row['codigo'] ?? ''));
                if ($code !== '' && $code !== '0' && LicitacaoSeller::isLicitacaoName((string) ($row['nome'] ?? ''))) {
                    $candidates[$code] = true;
                }
            }
        }
        $codes = array_keys($candidates);
        sort($codes, SORT_STRING);

        return array_map('strval', $codes);
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
        );
        $stmt->execute(['t' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    }
};
