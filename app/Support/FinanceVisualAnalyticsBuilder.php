<?php

declare(strict_types=1);

namespace BIomie\Support;

use BIomie\FinanceCollectionActionRepository;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use PDO;

final class FinanceVisualAnalyticsBuilder
{
    private const PAID_STATUSES = ['liquidado', 'recebido', 'pago'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function build(string $start, string $end, bool $includeDetails = true): array
    {
        $today = date('Y-m-d');
        $titles = $this->fetchTitles($start, $end);
        $actions = $this->fetchActions($start, $end);
        $actionsByTitle = $this->indexActionsByTitle($actions);
        $rows = [];

        foreach ($titles as $title) {
            $rows[] = $this->normalizeTitle($title, $today, $actionsByTitle[(string) ($title['titulo_key'] ?? '')] ?? []);
        }

        return [
            'ok' => true,
            'period' => [
                'start' => $start,
                'end' => $end,
                'reference_date' => $today,
            ],
            'summary' => $this->summary($rows),
            'composition' => $this->composition($rows),
            'evolution' => $this->evolution($rows, $start, $end),
            'aging' => $this->aging($rows),
            'vendors' => $this->vendors($rows),
            'clients_risk' => $this->clientsRisk($rows),
            'governance' => $this->governance($rows, $actions, $today),
            'action_mix' => $this->actionMix($actions),
            'details' => $includeDetails ? array_slice($rows, 0, 1000) : [],
            'diagnostics' => [
                'source' => 'canonical_finance_read_model',
                'titles_loaded' => count($titles),
                'actions_loaded' => count($actions),
                'generated_at' => date(DATE_ATOM),
            ],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchTitles(string $start, string $end): array
    {
        $tenantFilter = \BIomie\Support\TenantContext::sqlFilter();
        $stmt = $this->pdo->prepare(
            "SELECT
                titulo_key,
                titulo_id_omie,
                numero_documento,
                status_titulo,
                especie_documento,
                forma_pagamento,
                documento_origem_tipo,
                documento_origem_ref,
                nota_key,
                chave_nfe,
                pedido_id_omie,
                codigo_pedido_origem,
                codigo_cliente_omie,
                nome_cliente,
                documento_cliente,
                codigo_vendedor_omie,
                nome_vendedor,
                codigo_conta_corrente_omie,
                data_emissao,
                data_vencimento,
                data_liquidacao,
                data_competencia,
                valor_pago,
                valor_liquido,
                valor_aberto
             FROM biomie_titulos_financeiros_canonicos
             WHERE data_competencia BETWEEN :start AND :end
               AND {$tenantFilter}
             ORDER BY data_competencia ASC, data_vencimento ASC, titulo_key ASC
             LIMIT 5000"
        );
        $stmt->execute(['start' => $start, 'end' => $end]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchActions(string $start, string $end): array
    {
        $logStart = date('Y-m-d 00:00:00', strtotime($start . ' -180 days'));
        $logEnd = $end . ' 23:59:59';
        $repository = new FinanceCollectionActionRepository($this->pdo);
        if ($repository->available()) {
            return $repository->between($logStart, $logEnd);
        }

        $stmt = $this->pdo->prepare(
            "SELECT id, actor_user_id, event_type, details_json, created_at
             FROM biomie_admin_logs
             WHERE event_type = 'finance.collection_action'
               AND created_at BETWEEN :log_start AND :log_end
             ORDER BY id ASC
             LIMIT 10000"
        );
        $stmt->execute(['log_start' => $logStart, 'log_end' => $logEnd]);

        $rows = [];
        foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $details = json_decode((string) ($row['details_json'] ?? '{}'), true);
            if (!is_array($details)) {
                $details = [];
            }
            $rows[] = [
                'id' => (int) ($row['id'] ?? 0),
                'actor_user_id' => $row['actor_user_id'] !== null ? (int) $row['actor_user_id'] : null,
                'created_at' => (string) ($row['created_at'] ?? ''),
                'titulo_key' => (string) ($details['titulo_key'] ?? ''),
                'cliente_codigo_omie' => (string) ($details['cliente_codigo_omie'] ?? ''),
                'vendedor_codigo_omie' => (string) ($details['vendedor_codigo_omie'] ?? ''),
                'action_type' => (string) ($details['action_type'] ?? ''),
                'note' => (string) ($details['note'] ?? ''),
                'next_action_date' => (string) ($details['next_action_date'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * @param list<array<string,mixed>> $actions
     * @return array<string,list<array<string,mixed>>>
     */
    private function indexActionsByTitle(array $actions): array
    {
        $indexed = [];
        foreach ($actions as $action) {
            $key = (string) ($action['titulo_key'] ?? '');
            if ($key === '') {
                continue;
            }
            $indexed[$key][] = $action;
        }

        return $indexed;
    }

    /**
     * @param array<string,mixed> $title
     * @param list<array<string,mixed>> $actions
     * @return array<string,mixed>
     */
    private function normalizeTitle(array $title, string $today, array $actions): array
    {
        $status = strtolower(trim((string) ($title['status_titulo'] ?? '')));
        $isPaid = in_array($status, self::PAID_STATUSES, true);
        $amountOpen = (float) ($title['valor_aberto'] ?? 0);
        $amountPaid = (float) ($title['valor_liquido'] ?? $title['valor_pago'] ?? 0);
        $dueDate = (string) ($title['data_vencimento'] ?? '');
        $daysLate = $dueDate !== '' && $dueDate < $today ? (int) floor((strtotime($today) - strtotime($dueDate)) / 86400) : 0;
        $vendorCode = trim((string) ($title['codigo_vendedor_omie'] ?? ''));
        $vendorName = trim((string) ($title['nome_vendedor'] ?? ''));
        $vendorMissing = $vendorCode === '' && ($vendorName === '' || in_array(strtolower($vendorName), ['sem vendedor', 'sem vendedor definido', 'nao informado', 'não informado'], true));
        $lastAction = $actions !== [] ? $actions[array_key_last($actions)] : null;

        return [
            'titulo_key' => (string) ($title['titulo_key'] ?? ''),
            'document' => (string) ($title['numero_documento'] ?? ''),
            'document_label' => $this->documentLabel($title),
            'nf_ref' => (string) ($title['nota_key'] ?? $title['documento_origem_ref'] ?? ''),
            'order_ref' => (string) ($title['codigo_pedido_origem'] ?? $title['pedido_id_omie'] ?? ''),
            'cliente_codigo_omie' => (string) ($title['codigo_cliente_omie'] ?? ''),
            'cliente' => trim((string) ($title['nome_cliente'] ?? '')) ?: 'Cliente nao informado',
            'documento_cliente' => $this->maskDocument((string) ($title['documento_cliente'] ?? '')),
            'vendedor_codigo_omie' => $vendorMissing ? '__sem_vendedor__' : ($vendorCode !== '' ? $vendorCode : $vendorName),
            'vendedor' => $vendorMissing ? 'Sem vendedor definido' : ($vendorName !== '' ? $vendorName : 'Vendedor ' . $vendorCode),
            'vendor_missing' => $vendorMissing,
            'data_emissao' => (string) ($title['data_emissao'] ?? ''),
            'data_vencimento' => $dueDate,
            'data_liquidacao' => (string) ($title['data_liquidacao'] ?? ''),
            'data_competencia' => (string) ($title['data_competencia'] ?? ''),
            'status_titulo' => (string) ($title['status_titulo'] ?? ''),
            'status_operacional' => $isPaid ? 'received' : ($daysLate > 0 ? 'overdue' : 'due'),
            'aging_bucket' => $this->agingBucket($dueDate, $today),
            'dias_atraso' => $daysLate,
            'valor_aberto' => round($amountOpen, 2),
            'valor_recebido' => $isPaid ? round($amountPaid, 2) : 0.0,
            'is_paid' => $isPaid,
            'is_open' => !$isPaid && $amountOpen > 0.0001,
            'is_overdue' => !$isPaid && $amountOpen > 0.0001 && $daysLate > 0,
            'last_action_type' => $lastAction['action_type'] ?? '',
            'last_action_at' => $lastAction['created_at'] ?? '',
            'next_action_date' => $lastAction['next_action_date'] ?? '',
            'action_count' => count($actions),
            'acao_recomendada' => $this->recommendedAction($vendorMissing, $daysLate, (string) ($lastAction['action_type'] ?? '')),
        ];
    }

    /**
     * @param array<string,mixed> $title
     */
    private function documentLabel(array $title): string
    {
        $origin = trim((string) ($title['documento_origem_ref'] ?? ''));
        if ($origin !== '') {
            return $origin;
        }
        $number = trim((string) ($title['numero_documento'] ?? ''));
        return $number !== '' ? $number : (string) ($title['titulo_key'] ?? '');
    }

    private function maskDocument(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) === 14) {
            return substr($digits, 0, 2) . '.' . substr($digits, 2, 3) . '.' . substr($digits, 5, 3) . '/****-**';
        }
        if (strlen($digits) === 11) {
            return '***.***.***-**';
        }
        return '';
    }

    private function agingBucket(string $dueDate, string $today): string
    {
        if ($dueDate === '') {
            return 'sem_data';
        }
        if ($dueDate > $today) {
            return 'a_vencer';
        }
        if ($dueDate === $today) {
            return 'vence_hoje';
        }
        $days = (int) floor((strtotime($today) - strtotime($dueDate)) / 86400);
        return match (true) {
            $days <= 7 => '1_7',
            $days <= 15 => '8_15',
            $days <= 30 => '16_30',
            $days <= 60 => '31_60',
            default => '60_plus',
        };
    }

    private function recommendedAction(bool $vendorMissing, int $daysLate, string $lastAction): string
    {
        if ($vendorMissing) {
            return 'Revisar vinculo financeiro';
        }
        if ($lastAction === 'promessa_pagamento') {
            return 'Fazer follow-up da promessa';
        }
        if ($daysLate > 0) {
            return 'Cobrar hoje';
        }
        return 'Acompanhar vencimento';
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function summary(array $rows): array
    {
        $received = $open = $overdue = $due = $noVendorValue = 0.0;
        $openTitles = $overdueTitles = $noVendorTitles = $maxDelay = 0;
        $overdueClients = [];

        foreach ($rows as $row) {
            $received += (float) ($row['valor_recebido'] ?? 0);
            if ($row['is_open']) {
                $open += (float) ($row['valor_aberto'] ?? 0);
                $openTitles++;
                if ($row['vendor_missing']) {
                    $noVendorTitles++;
                    $noVendorValue += (float) ($row['valor_aberto'] ?? 0);
                }
                if ($row['is_overdue']) {
                    $overdue += (float) ($row['valor_aberto'] ?? 0);
                    $overdueTitles++;
                    $overdueClients[(string) ($row['cliente_codigo_omie'] ?: $row['cliente'])] = true;
                    $maxDelay = max($maxDelay, (int) ($row['dias_atraso'] ?? 0));
                } else {
                    $due += (float) ($row['valor_aberto'] ?? 0);
                }
            }
        }

        return [
            'receivable_total' => round($open, 2),
            'overdue_total' => round($overdue, 2),
            'due_total' => round($due, 2),
            'received_total' => round($received, 2),
            'overdue_clients' => count($overdueClients),
            'max_delay_days' => $maxDelay,
            'open_titles' => $openTitles,
            'overdue_titles' => $overdueTitles,
            'no_vendor_titles' => $noVendorTitles,
            'no_vendor_value' => round($noVendorValue, 2),
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function composition(array $rows): array
    {
        $summary = $this->summary($rows);
        return [
            ['key' => 'received', 'label' => 'Recebido', 'value' => $summary['received_total'], 'count' => count(array_filter($rows, static fn (array $row): bool => (bool) $row['is_paid']))],
            ['key' => 'overdue', 'label' => 'Vencido', 'value' => $summary['overdue_total'], 'count' => $summary['overdue_titles']],
            ['key' => 'due', 'label' => 'A vencer', 'value' => $summary['due_total'], 'count' => $summary['open_titles'] - $summary['overdue_titles']],
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function evolution(array $rows, string $start, string $end): array
    {
        $buckets = [];
        $startDate = new DateTimeImmutable($start);
        $endDate = new DateTimeImmutable($end);
        foreach (new DatePeriod($startDate, new DateInterval('P1D'), $endDate->modify('+1 day')) as $date) {
            $key = $date->format('Y-m-d');
            $buckets[$key] = ['date' => $key, 'received' => 0.0, 'open' => 0.0, 'overdue' => 0.0, 'due' => 0.0, 'expected' => 0.0];
        }

        foreach ($rows as $row) {
            $key = (string) ($row['data_competencia'] ?? '');
            if (!isset($buckets[$key])) {
                continue;
            }
            $buckets[$key]['received'] += (float) ($row['valor_recebido'] ?? 0);
            if ($row['is_open']) {
                $buckets[$key]['open'] += (float) ($row['valor_aberto'] ?? 0);
                $buckets[$key]['expected'] += (float) ($row['valor_aberto'] ?? 0);
                if ($row['is_overdue']) {
                    $buckets[$key]['overdue'] += (float) ($row['valor_aberto'] ?? 0);
                } else {
                    $buckets[$key]['due'] += (float) ($row['valor_aberto'] ?? 0);
                }
            }
        }

        return array_values(array_map(static function (array $row): array {
            foreach (['received', 'open', 'overdue', 'due', 'expected'] as $key) {
                $row[$key] = round((float) $row[$key], 2);
            }
            return $row;
        }, $buckets));
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function aging(array $rows): array
    {
        $labels = [
            'a_vencer' => 'A vencer',
            'vence_hoje' => 'Vence hoje',
            '1_7' => 'Vencido 1-7 dias',
            '8_15' => 'Vencido 8-15 dias',
            '16_30' => 'Vencido 16-30 dias',
            '31_60' => 'Vencido 31-60 dias',
            '60_plus' => 'Vencido 60+ dias',
        ];
        $buckets = [];
        foreach ($labels as $key => $label) {
            $buckets[$key] = ['bucket' => $key, 'label' => $label, 'value' => 0.0, 'count' => 0, 'max_delay' => 0];
        }
        foreach ($rows as $row) {
            if (!$row['is_open']) {
                continue;
            }
            $key = (string) ($row['aging_bucket'] ?? 'sem_data');
            if (!isset($buckets[$key])) {
                continue;
            }
            $buckets[$key]['value'] += (float) ($row['valor_aberto'] ?? 0);
            $buckets[$key]['count']++;
            $buckets[$key]['max_delay'] = max((int) $buckets[$key]['max_delay'], (int) ($row['dias_atraso'] ?? 0));
        }

        return array_values(array_map(static function (array $row): array {
            $row['value'] = round((float) $row['value'], 2);
            return $row;
        }, $buckets));
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function vendors(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            if (!$row['is_open']) {
                continue;
            }
            $key = (string) ($row['vendedor_codigo_omie'] ?? '__sem_vendedor__');
            if (!isset($map[$key])) {
                $map[$key] = [
                    'vendor_key' => $key,
                    'vendor_name' => (string) ($row['vendedor'] ?? 'Sem vendedor definido'),
                    'open_value' => 0.0,
                    'overdue_value' => 0.0,
                    'title_count' => 0,
                    'client_keys' => [],
                    'acted_titles' => 0,
                    'max_delay' => 0,
                    'status' => $key === '__sem_vendedor__' ? 'sem_vendedor' : 'ok',
                ];
            }
            $map[$key]['open_value'] += (float) ($row['valor_aberto'] ?? 0);
            $map[$key]['title_count']++;
            $map[$key]['client_keys'][(string) ($row['cliente_codigo_omie'] ?: $row['cliente'])] = true;
            $map[$key]['max_delay'] = max((int) $map[$key]['max_delay'], (int) ($row['dias_atraso'] ?? 0));
            if ($row['is_overdue']) {
                $map[$key]['overdue_value'] += (float) ($row['valor_aberto'] ?? 0);
            }
            if ((int) ($row['action_count'] ?? 0) > 0) {
                $map[$key]['acted_titles']++;
            }
        }
        $out = [];
        foreach ($map as $row) {
            $count = max(1, (int) $row['title_count']);
            $row['open_value'] = round((float) $row['open_value'], 2);
            $row['overdue_value'] = round((float) $row['overdue_value'], 2);
            $row['client_count'] = count($row['client_keys']);
            $row['action_rate'] = round(((int) $row['acted_titles'] / $count) * 100, 1);
            unset($row['client_keys']);
            if ($row['status'] !== 'sem_vendedor') {
                $row['status'] = $row['overdue_value'] > 0 ? 'atencao' : 'ok';
            }
            $out[] = $row;
        }
        usort($out, static fn (array $left, array $right): int => ((float) $right['overdue_value'] <=> (float) $left['overdue_value']) ?: ((float) $right['open_value'] <=> (float) $left['open_value']));

        return array_slice($out, 0, 20);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function clientsRisk(array $rows): array
    {
        $map = [];
        $total = 0.0;
        foreach ($rows as $row) {
            if (!$row['is_overdue']) {
                continue;
            }
            $key = (string) ($row['cliente_codigo_omie'] ?: $row['cliente']);
            if (!isset($map[$key])) {
                $map[$key] = [
                    'client_key' => $key,
                    'client_name' => (string) ($row['cliente'] ?? 'Cliente nao informado'),
                    'document_masked' => (string) ($row['documento_cliente'] ?? ''),
                    'overdue_value' => 0.0,
                    'title_count' => 0,
                    'max_delay' => 0,
                ];
            }
            $map[$key]['overdue_value'] += (float) ($row['valor_aberto'] ?? 0);
            $map[$key]['title_count']++;
            $map[$key]['max_delay'] = max((int) $map[$key]['max_delay'], (int) ($row['dias_atraso'] ?? 0));
            $total += (float) ($row['valor_aberto'] ?? 0);
        }
        $out = array_values($map);
        usort($out, static fn (array $left, array $right): int => (float) $right['overdue_value'] <=> (float) $left['overdue_value']);
        $running = 0.0;
        foreach ($out as &$row) {
            $row['overdue_value'] = round((float) $row['overdue_value'], 2);
            $running += (float) $row['overdue_value'];
            $row['cumulative_pct'] = $total > 0 ? round(($running / $total) * 100, 1) : 0.0;
        }
        unset($row);

        return array_slice($out, 0, 20);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<array<string,mixed>> $actions
     * @return array<string,mixed>
     */
    private function governance(array $rows, array $actions, string $today): array
    {
        $openTitles = array_values(array_filter($rows, static fn (array $row): bool => (bool) $row['is_open']));
        $actedOpen = count(array_filter($openTitles, static fn (array $row): bool => (int) ($row['action_count'] ?? 0) > 0));
        $actionsToday = count(array_filter($actions, static fn (array $action): bool => substr((string) ($action['created_at'] ?? ''), 0, 10) === $today));
        $returnsToday = 0;
        $returnsOverdue = 0;
        $promisesOverdue = 0;
        foreach ($openTitles as $row) {
            $next = (string) ($row['next_action_date'] ?? '');
            if ($next === '') {
                continue;
            }
            if ($next === $today) {
                $returnsToday++;
            }
            if ($next < $today) {
                $returnsOverdue++;
                if (($row['last_action_type'] ?? '') === 'promessa_pagamento') {
                    $promisesOverdue++;
                }
            }
        }

        return [
            'today_actions' => $actionsToday,
            'returns_today' => $returnsToday,
            'returns_overdue' => $returnsOverdue,
            'promises_overdue' => $promisesOverdue,
            'overdue_without_action' => count(array_filter($openTitles, static fn (array $row): bool => (bool) $row['is_overdue'] && (int) ($row['action_count'] ?? 0) === 0)),
            'no_vendor' => count(array_filter($openTitles, static fn (array $row): bool => (bool) $row['vendor_missing'])),
            'action_rate' => count($openTitles) > 0 ? round(($actedOpen / count($openTitles)) * 100, 1) : 0.0,
        ];
    }

    /**
     * @param list<array<string,mixed>> $actions
     * @return list<array<string,mixed>>
     */
    private function actionMix(array $actions): array
    {
        $map = [];
        foreach ($actions as $action) {
            $type = trim((string) ($action['action_type'] ?? ''));
            if ($type === '') {
                continue;
            }
            $map[$type] = ($map[$type] ?? 0) + 1;
        }
        $total = array_sum($map);
        $out = [];
        foreach ($map as $type => $count) {
            $out[] = [
                'action_type' => $type,
                'qtd' => $count,
                'percentual' => $total > 0 ? round(($count / $total) * 100, 1) : 0.0,
            ];
        }
        usort($out, static fn (array $left, array $right): int => (int) $right['qtd'] <=> (int) $left['qtd']);

        return $out;
    }
}
