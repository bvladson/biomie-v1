<?php

declare(strict_types=1);

namespace BIomie\SystemHealth\Checks;

use BIomie\Support\OmieOperationScope;
use BIomie\SystemHealth\CheckInterface;
use BIomie\SystemHealth\SystemHealthCheck;
use PDO;
use Throwable;

/**
 * Fase H — H8/T10: Verifica integridade do campo operation_scope nas tabelas canônicas.
 *
 * 14 critérios (agrupados por severidade):
 *
 * CRITICAL:
 *   1. Colunas operation_scope existem em biomie_notas_fiscais_canonicas
 *   2. Colunas operation_scope existem em biomie_pedidos_canonicos
 *   3. Enforce mode ativo com colunas ausentes
 *   4. Percentual de NFs unknown > UNKNOWN_THRESHOLD_PCT (20%)
 *   5. Nenhuma NF classificada como commercial
 *   13. NFs commercial classificadas pela regra finnfe_normal_insufficient (Fase H.3 bug)
 *
 * WARNING:
 *   6. Colunas de lineage (confidence/rule/source) ausentes
 *   7. NFs autorizadas com unknown > threshold
 *   8. Nenhuma NF classificada como remittance (incomum mas possível)
 *   9. Tabela biomie_operation_scope_rules ausente
 *   10. Regras DB inativas (tabela vazia ou sem regras ativas)
 *   11. GoldMarts com scope_unknown — indica mart contaminado
 *   12. Sync não populou scope — NFs recentes (7d) com scope=unknown > 50%
 *   14. NFs unknown com regra finnfe_normal_insufficient — candidatas a backfill CFOP
 */
final class OperationScopeHealthCheck implements CheckInterface
{
    private const UNKNOWN_THRESHOLD_PCT    = 20.0;
    private const RECENT_UNKNOWN_ALERT_PCT = 50.0;

    public function __construct(
        private readonly PDO $pdo,
    ) {}

    public function id(): string    { return 'operation_scope'; }
    public function label(): string { return 'Escopo de Operação Omie'; }

    public function run(): SystemHealthCheck
    {
        $start = microtime(true);

        try {
            return $this->doRun($start);
        } catch (Throwable $e) {
            $ms = (int) round((microtime(true) - $start) * 1000);
            return SystemHealthCheck::warning(
                $this->id(),
                $this->label(),
                'Erro ao verificar escopo de operação: ' . $e->getMessage(),
                [],
                ['Execute backfill_operation_scope.php --status para diagnóstico.'],
                $ms,
            );
        }
    }

    private function doRun(float $start): SystemHealthCheck
    {
        $enforced = (bool) (getenv('BIOMIE_OPERATION_SCOPE_ENFORCED') ?: false);
        $metrics  = ['enforce_mode' => $enforced];
        $warnings = [];
        $critical = false;

        // ── Check 1 & 2: Colunas operation_scope ─────────────────────────────
        $nfHasScope    = $this->columnExists('biomie_notas_fiscais_canonicas', 'operation_scope');
        $nfHasName     = $this->columnExists('biomie_notas_fiscais_canonicas', 'omie_operation_name');
        $nfHasConf     = $this->columnExists('biomie_notas_fiscais_canonicas', 'operation_scope_confidence');
        $ordHasScope   = $this->columnExists('biomie_pedidos_canonicos', 'operation_scope');
        $ordHasName    = $this->columnExists('biomie_pedidos_canonicos', 'omie_operation_name');

        $metrics['columns'] = [
            'nfc_operation_scope'          => $nfHasScope,
            'nfc_omie_operation_name'      => $nfHasName,
            'nfc_operation_scope_confidence' => $nfHasConf,
            'pc_operation_scope'           => $ordHasScope,
            'pc_omie_operation_name'       => $ordHasName,
        ];

        // Check 1+2: Critical if scope column missing
        if (!$nfHasScope || !$ordHasScope) {
            $ms  = (int) round((microtime(true) - $start) * 1000);
            $msg = 'Colunas operation_scope ausentes. Migration H3 não aplicada.';
            // Check 3: enforce mode amplification
            if ($enforced) {
                $msg .= ' ENFORCE MODE ATIVO — APIs comerciais retornam erro 503.';
            }
            return SystemHealthCheck::critical(
                $this->id(),
                $this->label(),
                $msg,
                $metrics,
                ['Execute: php tools/dev/backfill_operation_scope.php --migrate'],
                $ms,
            );
        }

        // Check 6: Lineage columns (warning only)
        if (!$nfHasConf || !$nfHasName) {
            $warnings[] = 'Colunas de lineage ausentes (operation_scope_confidence / omie_operation_name). Backfill completo não aplicado.';
        }

        // ── Check 4+5: Distribuição de escopos — NFs ─────────────────────────
        $nfDist = $this->scopeDistribution('biomie_notas_fiscais_canonicas');
        $metrics['nf_scope_distribution'] = $nfDist;

        $totalNf      = array_sum($nfDist);
        $unknownNf    = (int) ($nfDist[OmieOperationScope::UNKNOWN] ?? 0);
        $commercialNf = (int) ($nfDist[OmieOperationScope::COMMERCIAL] ?? 0);
        $remittanceNf = (int) ($nfDist[OmieOperationScope::REMITTANCE] ?? 0);

        if ($totalNf > 0) {
            $unknownPct = round(($unknownNf / $totalNf) * 100, 1);
            $metrics['nf_unknown_pct'] = $unknownPct;

            // Check 4: unknown threshold
            if ($unknownPct > self::UNKNOWN_THRESHOLD_PCT) {
                $critical   = true;
                $warnings[] = sprintf('%.1f%% das NFs (%d/%d) com scope=unknown. Backfill incompleto.', $unknownPct, $unknownNf, $totalNf);
                $warnings[] = 'Execute: php tools/dev/backfill_operation_scope.php --apply';
            }

            // Check 5: no commercial NFs
            if ($commercialNf === 0) {
                $critical   = true;
                $warnings[] = 'Nenhuma NF classificada como commercial. Verifique OmieOperationScope::COMMERCIAL_NAMES.';
            }

            // Check 8: no remittance NFs — unusual but not critical
            if ($remittanceNf === 0 && $totalNf >= 10) {
                $warnings[] = 'Nenhuma NF de remessa encontrada. Se operações de remessa existem no Omie, o backfill pode estar incompleto.';
            }
        }

        // ── Check 7: NFs autorizadas com unknown ─────────────────────────────
        $authUnknown = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas
             WHERE status_fiscal = 'autorizada' AND operation_scope = 'unknown'"
        )->fetchColumn();
        $metrics['authorized_nf_unknown_scope'] = $authUnknown;

        if ($authUnknown > 0 && $totalNf > 0 && (($authUnknown / $totalNf) * 100) > self::UNKNOWN_THRESHOLD_PCT) {
            $critical   = true;
            $warnings[] = "$authUnknown NFs autorizadas sem scope. Rode backfill novamente.";
        }

        // ── Check 13+14: Regra finnfe_normal_insufficient (Fase H.3) ────────────
        // Check 13 (CRITICAL): commercial NFs classified via old finNFe=1 fallback.
        // These are wrong — finNFe=1 does not prove commercial origin.
        // Fix: php tools/dev/backfill_operation_origin_scope.php --apply
        if ($nfHasConf && $this->columnExists('biomie_notas_fiscais_canonicas', 'operation_scope_rule')) {
            try {
                $finNfe1Commercial = (int) $this->pdo->query(
                    "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas
                     WHERE operation_scope = 'commercial'
                       AND operation_scope_rule = 'finnfe_normal_insufficient'"
                )->fetchColumn();
                $metrics['finnfe1_commercial_misclassified'] = $finNfe1Commercial;

                if ($finNfe1Commercial > 0) {
                    $critical   = true;
                    $warnings[] = "$finNfe1Commercial NF(s) com scope=commercial via regra finnfe_normal_insufficient. "
                        . 'finNFe=1 não prova origem comercial — execute: php tools/dev/backfill_operation_origin_scope.php --apply';
                }

                // Check 14 (WARNING): unknown NFs still holding finnfe_normal_insufficient rule.
                // These are correct (unknown), but may benefit from CFOP backfill.
                $finNfe1Unknown = (int) $this->pdo->query(
                    "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas
                     WHERE operation_scope = 'unknown'
                       AND operation_scope_rule = 'finnfe_normal_insufficient'"
                )->fetchColumn();
                $metrics['finnfe1_unknown_pending_cfop'] = $finNfe1Unknown;

                if ($finNfe1Unknown > 0) {
                    $warnings[] = "$finNfe1Unknown NF(s) com scope=unknown via finnfe_normal_insufficient — sem CFOP no bronze. "
                        . 'Verifique operation_origin_debug.php para identificar CFOPs faltantes.';
                }
            } catch (Throwable) {
                // operation_scope_rule column may not exist on older installations
            }
        }

        // ── Check 12: Sync populando scope — NFs recentes (7d) ───────────────
        try {
            $recent7d = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas
                 WHERE sincronizado_em >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
            )->fetchColumn();
            $recentUnknown = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas
                 WHERE sincronizado_em >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                   AND operation_scope = 'unknown'"
            )->fetchColumn();
            $metrics['recent_7d_nfs'] = $recent7d;
            $metrics['recent_7d_unknown'] = $recentUnknown;

            if ($recent7d > 0) {
                $recentUnknownPct = round(($recentUnknown / $recent7d) * 100, 1);
                $metrics['recent_7d_unknown_pct'] = $recentUnknownPct;
                if ($recentUnknownPct > self::RECENT_UNKNOWN_ALERT_PCT) {
                    $warnings[] = sprintf(
                        '%.1f%% das NFs sincronizadas nos últimos 7 dias têm scope=unknown. Sync não está populando o campo.',
                        $recentUnknownPct
                    );
                    $warnings[] = 'Verifique FiscalCanonicalMapper e a migration H3.';
                }
            }
        } catch (Throwable) {
            // sincronizado_em may not exist on all installations
        }

        // ── Check 9: biomie_operation_scope_rules ────────────────────────────
        $rulesTableExists = $this->tableExists('biomie_operation_scope_rules');
        $metrics['rules_table_exists'] = $rulesTableExists;

        if (!$rulesTableExists) {
            $warnings[] = 'Tabela biomie_operation_scope_rules ausente. Execute operation_scope_rules_setup.php --create --seed.';
        } else {
            // Check 10: rules active
            $activeRules = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM biomie_operation_scope_rules WHERE is_active = 1"
            )->fetchColumn();
            $metrics['active_db_rules'] = $activeRules;

            if ($activeRules === 0) {
                $warnings[] = 'Tabela biomie_operation_scope_rules existe mas não tem regras ativas. Execute operation_scope_rules_setup.php --seed.';
            }
        }

        // ── Check 11: GoldMarts com contaminação ─────────────────────────────
        $metrics['mart_contamination'] = $this->checkMartContamination();

        // ── Distribuição — pedidos ────────────────────────────────────────────
        $ordDist = $this->scopeDistribution('biomie_pedidos_canonicos');
        $metrics['order_scope_distribution'] = $ordDist;

        // ── Result ────────────────────────────────────────────────────────────
        $ms = (int) round((microtime(true) - $start) * 1000);

        if ($critical) {
            return SystemHealthCheck::critical(
                $this->id(),
                $this->label(),
                'Escopo de operação Omie com cobertura insuficiente.',
                $metrics,
                $warnings,
                $ms,
            );
        }

        if (!empty($warnings)) {
            return SystemHealthCheck::warning(
                $this->id(),
                $this->label(),
                'Escopo de operação com alertas menores.',
                $metrics,
                $warnings,
                $ms,
            );
        }

        return SystemHealthCheck::ok(
            $this->id(),
            $this->label(),
            sprintf(
                'Escopo OK. NFs: %d commercial, %d remittance, %d unknown.',
                $commercialNf,
                $remittanceNf,
                $unknownNf
            ),
            $metrics,
            $ms,
        );
    }

    /** @return array<string,int> */
    private function scopeDistribution(string $table): array
    {
        $rows = $this->pdo->query(
            "SELECT operation_scope, COUNT(*) as n FROM `$table` GROUP BY operation_scope"
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_column($rows, 'n', 'operation_scope');
    }

    /** @return array<string,int|string> */
    private function checkMartContamination(): array
    {
        $result = [];
        $nfHasScope = $this->columnExists('biomie_notas_fiscais_canonicas', 'operation_scope');
        if (!$nfHasScope) {
            return ['status' => 'skipped', 'reason' => 'scope_column_missing'];
        }

        $marts = [
            'mart_geo_buyer'     => "SELECT COUNT(*) FROM mart_geo_buyer gb JOIN biomie_notas_fiscais_canonicas n ON n.nota_key = gb.nota_key WHERE n.operation_scope != 'commercial'",
            'mart_produto_periodo' => "SELECT COUNT(*) FROM mart_produto_periodo mp JOIN biomie_notas_fiscais_itens_canonicos i ON i.codigo_produto = mp.codigo_produto JOIN biomie_notas_fiscais_canonicas n ON n.nota_key = i.nota_key WHERE n.operation_scope != 'commercial' AND n.status_fiscal = 'autorizada' LIMIT 1",
        ];

        foreach ($marts as $mart => $sql) {
            if (!$this->tableExists($mart)) {
                $result[$mart] = 'table_missing';
                continue;
            }
            try {
                $cnt = (int) $this->pdo->query($sql)->fetchColumn();
                $result[$mart] = $cnt > 0 ? "contaminated:$cnt" : 'clean';
            } catch (Throwable) {
                $result[$mart] = 'check_failed';
            }
        }

        return $result;
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    }
}
