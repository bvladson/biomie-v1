<?php

declare(strict_types=1);

namespace BIomie\AdminConsole\Commands;

use BIomie\AdminConsole\AdminCommandContext;
use BIomie\AdminConsole\AdminCommandInterface;
use BIomie\AdminConsole\AdminCommandResult;
use BIomie\DataPlatform\Gold\Builders\CustomerPeriodMartBuilder;
use BIomie\DataPlatform\Gold\Builders\GeoBuyerMartBuilder;
use BIomie\Logger;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Rematerializa mart_cliente_periodo + mart_compradores_por_geo para um período específico.
 * Não exige competência fechada; usa ON DUPLICATE KEY UPDATE (não destrutivo).
 */
final class GeoRebuildPeriodCommand implements AdminCommandInterface
{
    public function __construct(
        private readonly PDO    $pdo,
        private readonly Logger $logger,
    ) {}

    public function name(): string        { return 'geo:rebuild-period'; }
    public function description(): string { return 'Rematerializa mart_cliente_periodo e mart_compradores_por_geo para um periodo especifico (start/end). Funciona com o mes aberto.'; }
    public function group(): string       { return 'geo'; }
    public function permission(): string  { return 'admin.console'; }
    public function isDestructive(): bool { return false; }
    public function requiresConfirmation(): bool { return false; }

    public function validateArgs(array $args): ?string
    {
        $start = trim($args['start'] ?? '');
        $end   = trim($args['end']   ?? '');

        if ($start === '' || $end === '') {
            return 'Informe start e end (YYYY-MM-DD). Ex: start=2026-05-01 end=2026-05-31';
        }
        foreach (['start' => $start, 'end' => $end] as $name => $val) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                return "Parâmetro {$name} inválido: deve ser YYYY-MM-DD.";
            }
        }
        if ($start > $end) {
            return 'start deve ser anterior ou igual a end.';
        }
        $diffMonths = $this->diffMonths($start, $end);
        if ($diffMonths > 3) {
            return 'Máximo de 3 meses por rebuild. Divida o período.';
        }
        return null;
    }

    public function run(AdminCommandContext $context, array $args = []): AdminCommandResult
    {
        $startDate = trim($args['start'] ?? '');
        $endDate   = trim($args['end']   ?? '');
        $startedAt = microtime(true);

        // Derive competências
        $startDt      = new DateTimeImmutable($startDate);
        $endDt        = new DateTimeImmutable($endDate);
        $cursor       = $startDt->modify('first day of this month');
        $competencias = [];
        while ($cursor <= $endDt) {
            $competencias[] = $cursor->format('Y-m');
            $cursor = $cursor->modify('+1 month');
        }
        $startComp = substr($startDate, 0, 7);
        $endComp   = substr($endDate, 0, 7);

        // Before snapshot
        $before = $this->periodCount('mart_cliente_periodo', 'competencia', $startComp, $endComp);
        $beforeGeo = $this->periodCount('mart_compradores_por_geo', 'competencia', $startComp, $endComp);

        // Canonical NFs for context
        $canonicalNfs  = 0;
        $canonicalMerc = 0.0;
        try {
            $cs = $this->pdo->prepare(
                "SELECT COUNT(*) AS n, COALESCE(SUM(valor_mercadorias), 0) AS merc
                 FROM biomie_notas_fiscais_canonicas
                 WHERE COALESCE(data_competencia, data_emissao) BETWEEN :s AND :e
                   AND status_fiscal = 'autorizada'"
            );
            $cs->execute(['s' => $startDate, 'e' => $endDate]);
            $cr = $cs->fetch(PDO::FETCH_ASSOC) ?: [];
            $canonicalNfs  = (int) ($cr['n']    ?? 0);
            $canonicalMerc = (float) ($cr['merc'] ?? 0);
        } catch (Throwable) {}

        $lines = [];
        $lines[] = "Período  : {$startDate} → {$endDate}";
        $lines[] = "Competências: " . implode(', ', $competencias);
        $lines[] = "Canonical NFs autorizadas: {$canonicalNfs}  Valor merc: R$" . number_format($canonicalMerc, 2, ',', '.');
        $lines[] = "Before — mart_cliente_periodo: {$before} rows | mart_compradores_por_geo: {$beforeGeo} rows";
        $lines[] = '';

        // ── Step 1: mart_cliente_periodo ─────────────────────────────────────
        $step1Rows  = 0;
        $step1Error = null;
        $martBuilder = new CustomerPeriodMartBuilder($this->pdo, $this->logger);
        foreach ($competencias as $comp) {
            $compStart = $comp . '-01';
            $compEnd   = date('Y-m-t', strtotime($compStart));
            if ($compEnd > $endDate) {
                $compEnd = $endDate;
            }
            try {
                $rows = $martBuilder->refresh($comp, $compStart, $compEnd);
                $step1Rows += $rows;
                $lines[] = "  mart_cliente_periodo [{$comp}] : {$rows} rows upserted";
            } catch (Throwable $e) {
                $step1Error = $e->getMessage();
                $lines[] = "  mart_cliente_periodo [{$comp}] ERRO: {$step1Error}";
                $this->logger->error('geo:rebuild-period: CustomerPeriodMartBuilder falhou.', [
                    'competencia' => $comp, 'message' => $step1Error, 'actor' => $context->userId,
                ]);
                break;
            }
        }

        // ── Step 2: mart_compradores_por_geo ─────────────────────────────────
        $step2Rows  = 0;
        $step2Error = null;
        if ($step1Error === null) {
            try {
                $geoBuilder = new GeoBuyerMartBuilder($this->pdo, $this->logger);
                $step2Rows  = $geoBuilder->refresh($startDate, $endDate);
                $lines[] = "  mart_compradores_por_geo  : {$step2Rows} rows upserted";
            } catch (Throwable $e) {
                $step2Error = $e->getMessage();
                $lines[] = "  mart_compradores_por_geo  ERRO: {$step2Error}";
                $this->logger->error('geo:rebuild-period: GeoBuyerMartBuilder falhou.', [
                    'message' => $step2Error, 'actor' => $context->userId,
                ]);
            }
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $hasError   = $step1Error !== null || $step2Error !== null;

        // After snapshot + faturamento
        $after    = $this->periodCount('mart_cliente_periodo', 'competencia', $startComp, $endComp);
        $afterGeo = $this->periodCount('mart_compradores_por_geo', 'competencia', $startComp, $endComp);
        $geoFat     = 0.0;
        $geoBairros = 0;
        try {
            $gs = $this->pdo->prepare(
                "SELECT COALESCE(SUM(faturamento), 0) AS fat,
                        COUNT(DISTINCT CASE WHEN bairro_norm IS NOT NULL AND bairro_norm != '' AND UPPER(bairro_norm) != 'NAO INFORMADO'
                                            THEN bairro_norm END) AS bairros
                 FROM mart_compradores_por_geo WHERE competencia BETWEEN :sc AND :ec"
            );
            $gs->execute(['sc' => $startComp, 'ec' => $endComp]);
            $gr = $gs->fetch(PDO::FETCH_ASSOC) ?: [];
            $geoFat     = (float) ($gr['fat']    ?? 0);
            $geoBairros = (int)   ($gr['bairros'] ?? 0);
        } catch (Throwable) {}

        $lines[] = '';
        $lines[] = "After — mart_cliente_periodo: {$after} rows | mart_compradores_por_geo: {$afterGeo} rows";
        $lines[] = "Faturamento no período: R$" . number_format($geoFat, 2, ',', '.');
        $lines[] = "Bairros distintos     : {$geoBairros}";

        $this->logger->info('geo:rebuild-period: concluido.', [
            'start' => $startDate, 'end' => $endDate,
            'step1_rows' => $step1Rows, 'step2_rows' => $step2Rows,
            'faturamento' => $geoFat, 'bairros' => $geoBairros,
            'duration_ms' => $durationMs, 'ok' => !$hasError,
            'actor' => $context->userId,
        ]);

        $metrics = [
            'mart_cliente_periodo_upserted'    => $step1Rows,
            'mart_compradores_geo_upserted'    => $step2Rows,
            'geo_mart_rows_no_periodo'         => $afterGeo,
            'faturamento_no_periodo'           => $geoFat,
            'bairros_distintos'                => $geoBairros,
            'canonical_nfs'                    => $canonicalNfs,
            'duration_ms'                      => $durationMs,
        ];

        if ($hasError) {
            return (new AdminCommandResult(
                success: false,
                status: 'error',
                title: 'Geo Rebuild Period',
                message: 'Rebuild falhou. Verifique os detalhes acima.',
                outputLines: $lines,
                metrics: $metrics,
                errors: array_values(array_filter([$step1Error, $step2Error])),
                durationMs: $durationMs,
            ));
        }

        return AdminCommandResult::ok(
            'Geo Rebuild Period',
            "{$step1Rows} rows em mart_cliente_periodo, {$step2Rows} em mart_compradores_por_geo, R$" . number_format($geoFat, 2, ',', '.') . " faturamento, {$geoBairros} bairros.",
            outputLines: $lines,
            metrics: $metrics,
        )->withDurationMs($durationMs);
    }

    private function periodCount(string $table, string $col, string $start, string $end): int
    {
        try {
            $s = $this->pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$col}` BETWEEN :s AND :e");
            $s->execute(['s' => $start, 'e' => $end]);
            return (int) $s->fetchColumn();
        } catch (Throwable) {
            return -1;
        }
    }

    private function diffMonths(string $start, string $end): int
    {
        $s = new DateTimeImmutable($start);
        $e = new DateTimeImmutable($end);
        $diff = $s->diff($e);
        return $diff->y * 12 + $diff->m;
    }
}
