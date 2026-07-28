<?php

declare(strict_types=1);

namespace BIomie\Ops;

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Logger;
use BIomie\Modules\Sales\Application\Board\BoardConsolidatedService;
use PDO;
use Throwable;

/**
 * Alertas de NEGOCIO para gestao/diretoria (via Telegram/webhook do AlertNotifier).
 *
 * Reusa os MESMOS números do Painel da Diretoria (BoardConsolidatedService —
 * faturamento + meta + atingimento, respeitando o canal autorizado por empresa)
 * e os titulos canonicos (recebiveis em aberto/vencidos).
 *
 * Modos:
 *   - digest: resumo diario (sempre envia) — faturamento MTD, % meta, projecao,
 *             faturamento de ontem, recebiveis vencidos.
 *   - events: alertas pontuais com dedup — meta batida, gap de faturamento
 *             (projecao abaixo da meta), inadimplencia alta.
 */
final class ManagementAlerts
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly AlertNotifier $notifier,
        private readonly BoardConsolidatedService $board,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function run(string $mode, bool $notify = true): array
    {
        $userId = max(1, (int) $this->config->get('ops.board_user_id', 1));
        $monthStart = date('Y-m-01');
        $today = date('Y-m-d');

        $board = $this->board->build($userId, $monthStart, $today);
        $companies = is_array($board['companies'] ?? null) ? $board['companies'] : [];
        $group = is_array($board['group'] ?? null) ? $board['group'] : [];
        $overdue = $this->overdueByCompany();

        return $mode === 'digest'
            ? $this->digest($board, $companies, $group, $overdue, $userId, $notify)
            : $this->events($companies, $group, $overdue, $notify);
    }

    // ── DIGEST diario ─────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $board
     * @param list<array<string,mixed>> $companies
     * @param array<string,mixed> $group
     * @param array<int,array<string,mixed>> $overdue
     * @return array<string,mixed>
     */
    private function digest(array $board, array $companies, array $group, array $overdue, int $userId, bool $notify): array
    {
        $periodLabel = (string) ($board['period']['label'] ?? date('m/Y'));
        $groupRevenue = (float) ($group['revenue'] ?? 0);
        $groupTarget = (float) ($group['target'] ?? 0);
        $targetCoverage = is_array($group['target_coverage'] ?? null)
            ? $group['target_coverage']
            : [];
        $coverageComplete = (bool) ($targetCoverage['complete'] ?? false);
        $groupComparableRevenue = (float) ($group['comparable_revenue'] ?? $groupRevenue);
        $groupAttain = (float) (
            $coverageComplete
                ? ($group['attainment_pct'] ?? 0)
                : ($group['paired_attainment_pct'] ?? 0)
        );

        [$projRevenue, $projPct] = $this->projection(
            $coverageComplete ? $groupRevenue : $groupComparableRevenue,
            $groupTarget,
        );
        $yesterday = $this->revenueForDay($userId, date('Y-m-d', strtotime('yesterday')));

        $totalOverdue = 0.0;
        $totalOverdueCount = 0;
        foreach ($overdue as $o) {
            $totalOverdue += (float) $o['overdue_value'];
            $totalOverdueCount += (int) $o['overdue_count'];
        }

        $lines = [];
        $lines[] = sprintf('Faturamento %s: %s', $periodLabel, $this->brl($groupRevenue));
        if ($groupTarget > 0) {
            if ($coverageComplete) {
                $lines[] = sprintf('Meta: %s (%s%% atingido)', $this->brl($groupTarget), $this->pct($groupAttain));
                $lines[] = sprintf('Projecao do mes: %s (%s%% da meta)', $this->brl($projRevenue), $this->pct($projPct));
            } else {
                $lines[] = sprintf(
                    'Meta parcial: %s (%s%% no recorte coberto; %d/%d empresas)',
                    $this->brl($groupTarget),
                    $this->pct($groupAttain),
                    (int) ($targetCoverage['companies_with_target'] ?? 0),
                    (int) ($targetCoverage['companies_total'] ?? 0),
                );
            }
        }
        $lines[] = sprintf('Faturado ontem: %s', $this->brl($yesterday));
        $lines[] = '';
        $lines[] = 'Por empresa (mes):';
        $medals = ['🥇', '🥈', '🥉'];
        foreach ($companies as $i => $c) {
            $medal = $medals[$i] ?? '▫️';
            $companyTarget = (float) ($c['target'] ?? 0);
            $companyTargetPartial = (string) ($c['target_status'] ?? '') === 'partial';
            $companyAttainment = (float) (
                $companyTargetPartial
                    ? ($c['paired_attainment_pct'] ?? 0)
                    : ($c['attainment_pct'] ?? 0)
            );
            $lines[] = sprintf(
                '%s %s: %s%s',
                $medal,
                (string) ($c['name'] ?? $c['slug'] ?? '?'),
                $this->brl((float) ($c['revenue'] ?? 0)),
                $companyTarget > 0
                    ? ' (' . $this->pct($companyAttainment) . '% meta'
                        . ($companyTargetPartial ? ' parcial' : '') . ')'
                    : '',
            );
        }
        $lines[] = '';
        $lines[] = sprintf('🔴 Recebiveis vencidos: %s (%d titulos)', $this->brl($totalOverdue), $totalOverdueCount);
        $worstOverdue = $this->topOverdue($overdue, $companies);
        if ($worstOverdue !== null) {
            $lines[] = sprintf('   Maior atraso: %s — %s', $worstOverdue['name'], $this->brl($worstOverdue['value']));
        }

        $result = ['mode' => 'digest', 'sent' => false, 'group_revenue' => $groupRevenue, 'group_attainment' => $groupAttain, 'total_overdue' => $totalOverdue];
        if ($notify) {
            $result['delivery'] = $this->notifier->send('info', '📊 Resumo Diretoria — ' . $periodLabel, $lines);
            $result['sent'] = true;
            $state = $this->readState();
            $state['digest_last'] = date('Y-m-d');
            $this->writeState($state);
        } else {
            $result['preview_lines'] = $lines;
        }
        return $result;
    }

    // ── EVENTS (deduped) ──────────────────────────────────────────────────────

    /**
     * @param list<array<string,mixed>> $companies
     * @param array<string,mixed> $group
     * @param array<int,array<string,mixed>> $overdue
     * @return array<string,mixed>
     */
    private function events(array $companies, array $group, array $overdue, bool $notify): array
    {
        $month = date('Y-m');
        $state = $this->readState();
        $sent = [];

        $hitPct = (float) $this->config->get('ops.target_hit_pct', 100);
        $gapPct = (float) $this->config->get('ops.target_gap_pct', 85);
        $gapMinDay = (int) $this->config->get('ops.target_gap_min_day', 10);
        $overdueThreshold = (float) $this->config->get('ops.overdue_alert_value', 50000);
        $cooldownDays = max(1, (int) $this->config->get('ops.overdue_cooldown_days', 7));
        $dayOfMonth = (int) date('j');

        // ── Meta batida (por empresa + grupo) — 1x/mes ────────────────────────
        $targets = [];
        foreach ($companies as $c) {
            if ((string) ($c['target_status'] ?? '') !== 'ok') {
                continue;
            }
            $targets[] = ['key' => 'C' . (int) ($c['company_id'] ?? 0), 'name' => (string) ($c['name'] ?? $c['slug'] ?? '?'),
                'attain' => (float) ($c['attainment_pct'] ?? 0), 'revenue' => (float) ($c['revenue'] ?? 0), 'target' => (float) ($c['target'] ?? 0)];
        }
        $targetCoverage = is_array($group['target_coverage'] ?? null)
            ? $group['target_coverage']
            : [];
        if ((bool) ($targetCoverage['complete'] ?? false)) {
            $targets[] = [
                'key' => 'GROUP',
                'name' => 'GRUPO (consolidado)',
                'attain' => (float) ($group['attainment_pct'] ?? 0),
                'revenue' => (float) ($group['comparable_revenue'] ?? $group['revenue'] ?? 0),
                'target' => (float) ($group['target'] ?? 0),
            ];
        }

        foreach ($targets as $t) {
            if ($t['target'] <= 0) {
                continue;
            }
            $stateKey = 'meta_hit:' . $t['key'] . ':' . $month;
            if ($t['attain'] >= $hitPct && !isset($state[$stateKey])) {
                if ($notify) {
                    $this->notifier->send('ok', '🎉 META BATIDA — ' . $t['name'], [
                        sprintf('%s atingiu %s%% da meta do mes!', $t['name'], $this->pct($t['attain'])),
                        sprintf('Faturado: %s de %s', $this->brl($t['revenue']), $this->brl($t['target'])),
                        'Parabens ao time! 🚀',
                    ]);
                    $state[$stateKey] = time();
                }
                $sent[] = $stateKey;
            }
        }

        // ── Gap de faturamento (projecao abaixo da meta) — 1x/mes/empresa ─────
        if ($dayOfMonth >= $gapMinDay) {
            foreach ($targets as $t) {
                if ($t['target'] <= 0 || $t['attain'] >= $hitPct) {
                    continue;
                }
                [, $projPct] = $this->projection($t['revenue'], $t['target']);
                $stateKey = 'meta_gap:' . $t['key'] . ':' . $month;
                if ($projPct < $gapPct && !isset($state[$stateKey])) {
                    if ($notify) {
                        $this->notifier->send('warning', '⚠️ Risco de meta — ' . $t['name'], [
                            sprintf('%s: no ritmo atual, o mes deve fechar em ~%s%% da meta.', $t['name'], $this->pct($projPct)),
                            sprintf('Faturado ate agora: %s de %s (%s%%).', $this->brl($t['revenue']), $this->brl($t['target']), $this->pct($t['attain'])),
                            'Falta acelerar para bater a meta.',
                        ]);
                        $state[$stateKey] = time();
                    }
                    $sent[] = $stateKey;
                }
            }
        }

        // ── Inadimplencia alta (vencidos acima do limite) — dedup cooldown/+25% ─
        $nameByCid = [];
        foreach ($companies as $c) {
            $nameByCid[(int) ($c['company_id'] ?? 0)] = (string) ($c['name'] ?? $c['slug'] ?? '?');
        }
        foreach ($overdue as $cid => $o) {
            $value = (float) $o['overdue_value'];
            if ($value < $overdueThreshold) {
                continue;
            }
            $prev = is_array($state['inadimplencia'][$cid] ?? null) ? $state['inadimplencia'][$cid] : null;
            $prevValue = (float) ($prev['value'] ?? 0);
            $prevTs = (int) ($prev['ts'] ?? 0);
            $cooldownElapsed = (time() - $prevTs) >= $cooldownDays * 86400;
            $grewMaterially = $value > $prevValue * 1.25;
            if ($prev === null || $cooldownElapsed || $grewMaterially) {
                $name = $nameByCid[(int) $cid] ?? ('Empresa ' . $cid);
                if ($notify) {
                    $this->notifier->send('critical', '🔴 Inadimplencia — ' . $name, [
                        sprintf('%s: %s em titulos VENCIDOS em aberto (%d titulos).', $name, $this->brl($value), (int) $o['overdue_count']),
                        'Acionar cobranca/recuperacao de credito.',
                    ]);
                    $state['inadimplencia'][$cid] = ['value' => $value, 'ts' => time()];
                }
                $sent[] = 'inadimplencia:' . $cid;
            }
        }

        if ($notify) {
            $this->writeState($state);
        }

        return ['mode' => 'events', 'alerts_sent' => $sent, 'count' => count($sent)];
    }

    // ── Data helpers ──────────────────────────────────────────────────────────

    /**
     * Recebiveis vencidos em aberto por empresa.
     * @return array<int,array{overdue_value:float,overdue_count:int}>
     */
    private function overdueByCompany(): array
    {
        try {
            $rows = $this->pdo->query(
                "SELECT company_id,
                        COUNT(*) AS overdue_count,
                        COALESCE(SUM(valor_aberto), 0) AS overdue_value
                   FROM biomie_titulos_financeiros_canonicos
                  WHERE valor_aberto > 0.005
                    AND data_vencimento < CURDATE()
                    AND data_liquidacao IS NULL
                  GROUP BY company_id"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $this->logger->warning('ManagementAlerts: vencidos indisponiveis.', ['message' => $e->getMessage()]);
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['company_id']] = [
                'overdue_value' => (float) $row['overdue_value'],
                'overdue_count' => (int) $row['overdue_count'],
            ];
        }
        return $out;
    }

    private function revenueForDay(int $userId, string $date): float
    {
        try {
            $board = $this->board->build($userId, $date, $date);
            return (float) ($board['group']['revenue'] ?? 0);
        } catch (Throwable) {
            return 0.0;
        }
    }

    /**
     * Projecao linear do mes a partir do realizado ate hoje.
     * @return array{0:float,1:float} [valor_projetado, pct_da_meta]
     */
    private function projection(float $revenue, float $target): array
    {
        $day = max(1, (int) date('j'));
        $daysInMonth = (int) date('t');
        $projected = $revenue / $day * $daysInMonth;
        $pct = $target > 0 ? round($projected / $target * 100, 1) : 0.0;
        return [$projected, $pct];
    }

    /**
     * @param array<int,array<string,mixed>> $overdue
     * @param list<array<string,mixed>> $companies
     * @return array{name:string,value:float}|null
     */
    private function topOverdue(array $overdue, array $companies): ?array
    {
        $names = [];
        foreach ($companies as $c) {
            $names[(int) ($c['company_id'] ?? 0)] = (string) ($c['name'] ?? $c['slug'] ?? '?');
        }
        $best = null;
        foreach ($overdue as $cid => $o) {
            $value = (float) $o['overdue_value'];
            if ($best === null || $value > $best['value']) {
                $best = ['name' => $names[(int) $cid] ?? ('Empresa ' . $cid), 'value' => $value];
            }
        }
        return $best;
    }

    private function brl(float $value): string
    {
        return 'R$ ' . number_format($value, 0, ',', '.');
    }

    private function pct(float $value): string
    {
        return number_format($value, 1, ',', '.');
    }

    // ── State ─────────────────────────────────────────────────────────────────

    private function statePath(): string
    {
        $dir = Bootstrap::rootPath('storage/ops');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir . '/management_state.json';
    }

    /** @return array<string,mixed> */
    private function readState(): array
    {
        $path = $this->statePath();
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) @file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    /** @param array<string,mixed> $state */
    private function writeState(array $state): void
    {
        @file_put_contents($this->statePath(), json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
}
