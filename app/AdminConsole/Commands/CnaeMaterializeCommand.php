<?php

declare(strict_types=1);

namespace BIomie\AdminConsole\Commands;

use BIomie\AdminConsole\AdminCommandContext;
use BIomie\AdminConsole\AdminCommandInterface;
use BIomie\AdminConsole\AdminCommandResult;
use BIomie\CnaeResolver;
use BIomie\GeoCnaeMaterializationService;
use BIomie\Logger;
use BIomie\Shared\Database\ColumnResolver;
use BIomie\TableHealthService;
use BIomie\Telemetry;
use PDO;
use Throwable;

/**
 * Preenche cnae_codigo/cnae_descricao em biomie_clientes_dim a partir de:
 *  1. Bronze por codigo_cliente_omie
 *  2. Bronze por documento_normalizado (fallback)
 *  3. NF/pedido canônico (per-client resolver)
 * Não sobrescreve CNAE já preenchido.
 * Reconstrói mart_vendas_por_cnae_cnpj após atualizar clientes_dim.
 */
final class CnaeMaterializeCommand implements AdminCommandInterface
{
    private readonly ColumnResolver $resolver;

    public function __construct(
        private readonly PDO    $pdo,
        private readonly Logger $logger,
    ) {
        $this->resolver = new ColumnResolver($pdo);
    }

    public function name(): string        { return 'cnae:materialize'; }
    public function description(): string { return 'Preenche cnae_codigo em clientes_dim (bronze→doc→NF→pedido, nao sobrescreve) e reconstroi mart_vendas_por_cnae_cnpj.'; }
    public function group(): string       { return 'cnae'; }
    public function permission(): string  { return 'admin.console'; }
    public function isDestructive(): bool { return false; }
    public function requiresConfirmation(): bool { return false; }

    public function validateArgs(array $args): ?string { return null; }

    public function run(AdminCommandContext $context, array $args = []): AdminCommandResult
    {
        $dryRun   = in_array($args['dry_run'] ?? '0', ['1', 'true'], true);
        $resolver = new CnaeResolver($this->pdo);

        $telemetry = Telemetry::global();
        $span      = $telemetry?->startSpan('cnae.materialize', ['dry_run' => $dryRun, 'actor_user_id' => $context->userId], 'cnae', 'cnae_materialize');
        try { $telemetry?->event('cnae.materialize.started', ['dry_run' => $dryRun, 'actor_user_id' => $context->userId], 'cnae'); } catch (Throwable) {}

        $lines   = [];
        $lines[] = ($dryRun ? '[DRY RUN] Nenhum dado sera alterado.' : '[REAL] Dados serao atualizados.');
        $lines[] = '';

        // Count clients missing CNAE
        $totalSemCnae = 0;
        try {
            $totalSemCnae = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM biomie_clientes_dim WHERE cnae_codigo IS NULL OR cnae_codigo = ''"
            )->fetchColumn();
        } catch (Throwable) {}

        $lines[] = "Clientes sem CNAE em clientes_dim: {$totalSemCnae}";

        if ($totalSemCnae === 0) {
            $lines[] = "Nenhuma acao necessaria — todos os clientes ja possuem CNAE.";
            $vendasCnae = 0;
            if (!$dryRun) {
                $vendasCnae = $this->rebuildVendasCnaeMart();
                $lines[]    = "mart_vendas_por_cnae_cnpj reconstruido: {$vendasCnae} linhas.";
            }
            $span?->finish();
            return AdminCommandResult::ok(
                'CNAE Materialize',
                'Todos os clientes ja possuem CNAE. Mart reconstruido.',
                outputLines: $lines,
                metrics: ['total_sem_cnae_antes' => 0, 'atualizados' => 0, 'nao_encontrados' => 0, 'erros' => 0, 'vendas_cnae_linhas' => $vendasCnae],
            );
        }

        // ── Phase 1: bulk bronze scan (code + document) ─────────────────────────
        $lines[] = 'Fase 1: buscando CNAE no bronze (por codigo e documento)...';

        $bronzeRows = $resolver->resolveAllFromBronzeWithDocFallback();
        $lines[]    = sprintf('  Payloads bronze com CNAE valido: %d', count($bronzeRows));

        // Build two lookup maps: by codigo_omie and by doc_digits
        $byOmieCode = [];
        $byDocDigits = [];
        foreach ($bronzeRows as $r) {
            if ($r['natural_key'] > 0) {
                $byOmieCode[$r['natural_key']] = $r;
            }
            if ($r['doc_digits'] !== '') {
                $byDocDigits[$r['doc_digits']] = $r;
            }
        }

        $lines[] = sprintf('  Por codigo_cliente_omie: %d entradas', count($byOmieCode));
        $lines[] = sprintf('  Por documento_norm     : %d entradas', count($byDocDigits));

        // Detect schema
        $hasEnrichedAt   = $this->resolver->hasColumn('biomie_clientes_dim', 'enriched_at');
        $hasEnrichSource = $this->resolver->hasColumn('biomie_clientes_dim', 'enrichment_source');
        $hasCnaeDesc     = $this->resolver->hasColumn('biomie_clientes_dim', 'cnae_descricao');
        $hasCnaeLabel    = $this->resolver->hasColumn('biomie_clientes_dim', 'cnae_label');
        $hasDocNorm      = $this->resolver->hasColumn('biomie_clientes_dim', 'documento_normalizado');

        // Fetch all clients missing CNAE
        $missing = [];
        try {
            $cols = 'id, codigo_cliente_omie';
            if ($hasDocNorm) {
                $cols .= ', documento_normalizado';
            }
            $stmt = $this->pdo->query(
                "SELECT {$cols} FROM biomie_clientes_dim
                 WHERE cnae_codigo IS NULL OR cnae_codigo = ''
                 ORDER BY codigo_cliente_omie ASC"
            );
            $missing = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable) {}

        $updated      = 0;
        $notFound     = 0;
        $errors       = 0;
        $unmatchedBronze = 0;
        $bySource     = ['bronze_codigo' => 0, 'bronze_documento' => 0, 'canonical_nf' => 0, 'canonical_pedido' => 0];

        foreach ($missing as $row) {
            $codigoOmie = (int) ($row['codigo_cliente_omie'] ?? 0);
            $docNorm    = (string) ($row['documento_normalizado'] ?? '');

            // Match 1: by codigo_cliente_omie
            $resolved   = null;
            $matchSource = '';
            if ($codigoOmie > 0 && isset($byOmieCode[$codigoOmie])) {
                $r = $byOmieCode[$codigoOmie];
                $resolved   = ['cnae_codigo' => $r['cnae_codigo'], 'cnae_descricao' => $r['cnae_descricao'], 'source' => 'bronze_cliente'];
                $matchSource = 'bronze_codigo';
            }

            // Match 2: by documento_normalizado
            if ($resolved === null && $docNorm !== '' && isset($byDocDigits[$docNorm])) {
                $r = $byDocDigits[$docNorm];
                $resolved   = ['cnae_codigo' => $r['cnae_codigo'], 'cnae_descricao' => $r['cnae_descricao'], 'source' => 'bronze_cliente'];
                $matchSource = 'bronze_documento';
            }

            // Match 3: canonical sources (per-client, only if bronze failed)
            if ($resolved === null && $codigoOmie > 0) {
                try {
                    $fromCanonical = $resolver->resolve($codigoOmie);
                    if ($fromCanonical['cnae_codigo'] !== null
                        && !in_array($fromCanonical['source'], ['clientes_dim', 'nao_encontrado'], true)) {
                        $resolved    = $fromCanonical;
                        $matchSource = $fromCanonical['source'];
                    }
                } catch (Throwable) {}
            }

            if ($resolved === null || ($resolved['cnae_codigo'] ?? '') === '') {
                $notFound++;
                continue;
            }

            $cnaeCode = (string) $resolved['cnae_codigo'];
            $cnaeDesc = $resolved['cnae_descricao'] ?? null;

            if (!$dryRun) {
                try {
                    $whereId = $row['id'] ?? null;
                    $updateWhere = $whereId
                        ? 'WHERE id = :id AND (cnae_codigo IS NULL OR cnae_codigo = \'\')'
                        : 'WHERE codigo_cliente_omie = :cod AND (cnae_codigo IS NULL OR cnae_codigo = \'\')';

                    $sets   = ["cnae_codigo = :cnae"];
                    $params = ['cnae' => $cnaeCode];
                    if ($whereId) {
                        $params['id'] = $whereId;
                    } elseif ($codigoOmie > 0) {
                        $params['cod'] = $codigoOmie;
                    } else {
                        $notFound++;
                        continue;
                    }

                    if ($hasCnaeDesc && $cnaeDesc !== null) {
                        $sets[] = 'cnae_descricao = :cnae_desc';
                        $params['cnae_desc'] = $cnaeDesc;
                    }
                    if ($hasCnaeLabel && $cnaeDesc !== null) {
                        $sets[] = 'cnae_label = :cnae_label';
                        $params['cnae_label'] = $cnaeCode . ' - ' . $cnaeDesc;
                    }
                    if ($hasEnrichSource) {
                        $sets[] = 'enrichment_source = :src';
                        $params['src'] = $matchSource ?: $resolved['source'];
                    }
                    if ($hasEnrichedAt) {
                        $sets[] = 'enriched_at = NOW()';
                    }

                    $upd = $this->pdo->prepare(
                        'UPDATE biomie_clientes_dim SET ' . implode(', ', $sets) . ' ' . $updateWhere
                    );
                    $upd->execute($params);
                    if ($upd->rowCount() > 0) {
                        $updated++;
                        $bySource[$matchSource] = ($bySource[$matchSource] ?? 0) + 1;
                    }
                } catch (Throwable $e) {
                    $errors++;
                    $this->logger->warning('CnaeMaterializeCommand: falha ao atualizar.', [
                        'codigo_cliente_omie' => $codigoOmie,
                        'message'             => $e->getMessage(),
                    ]);
                }
            } else {
                $updated++;
                $bySource[$matchSource] = ($bySource[$matchSource] ?? 0) + 1;
            }
        }

        // Check how many bronze rows had no match in clientes_dim at all
        $unmatchedBronze = max(0, count($bronzeRows) - ($bySource['bronze_codigo'] + $bySource['bronze_documento']));

        $lines[] = '';
        $lines[] = 'Resultado clientes_dim:';
        $lines[] = "  Clientes sem CNAE antes   : {$totalSemCnae}";
        $lines[] = "  Resolvidos/atualizados    : {$updated}";
        $lines[] = "  Nao encontrados           : {$notFound}";
        $lines[] = "  Erros de escrita          : {$errors}";
        $lines[] = '';
        $lines[] = 'Por fonte:';
        foreach ($bySource as $src => $cnt) {
            if ($cnt > 0) {
                $lines[] = "  {$src}: {$cnt}";
            }
        }

        if ($updated === 0 && count($bronzeRows) > 0) {
            $lines[] = '';
            $lines[] = '=== Por que 0 atualizados apesar de ' . count($bronzeRows) . ' payloads com CNAE? ===';
            $lines[] = "  Bronze com CNAE (por codigo): " . count($byOmieCode);
            $lines[] = "  Bronze com CNAE (por doc)   : " . count($byDocDigits);
            $lines[] = "  Clientes sem CNAE no dim    : {$totalSemCnae}";
            $lines[] = "  Interseccao codigo→dim      : {$bySource['bronze_codigo']}";
            $lines[] = "  Interseccao doc→dim         : {$bySource['bronze_documento']}";
            $lines[] = '';
            $lines[] = "  CAUSA PROVAVEL: natural_key no bronze nao corresponde a codigo_cliente_omie no dim,";
            $lines[] = "  E documento do payload nao bate com documento_normalizado no dim.";
            $lines[] = "  Execute cnae:diagnose-sources para ver a amostra detalhada de match/nao-match.";
            $lines[] = "  Execute clientes:enrich-omie-all para re-sincronizar clientes e unificar os codigos.";
        }

        // ── Rebuild mart ────────────────────────────────────────────────────────
        $vendasCnae = 0;
        if (!$dryRun) {
            $lines[] = '';
            $lines[] = 'Reconstruindo mart_vendas_por_cnae_cnpj...';
            try {
                $vendasCnae = $this->rebuildVendasCnaeMart();
                $lines[]    = "  Linhas inseridas: {$vendasCnae}";
                if ($vendasCnae === 0) {
                    $lines[] = "  0 linhas — motivos possiveis:";
                    $lines[] = "    * Clientes com CNAE nao tem venda em mart_cliente_periodo.";
                    $lines[] = "    * Nenhum cliente tem cnae_codigo preenchido (updated=" . $updated . ").";
                    $lines[] = "    * mart_cliente_periodo/mart_fiscal_nfe sem periodo sobreposto.";
                }
            } catch (Throwable $e) {
                $lines[] = "  ERRO ao reconstruir mart: " . $e->getMessage();
                $this->logger->warning('CnaeMaterializeCommand: erro ao reconstruir mart.', ['message' => $e->getMessage()]);
            }
        } else {
            $lines[] = '';
            $lines[] = '[DRY RUN] mart_vendas_por_cnae_cnpj nao foi alterado.';
        }

        $metrics = [
            'total_sem_cnae_antes'   => $totalSemCnae,
            'atualizados'            => $updated,
            'nao_encontrados'        => $notFound,
            'erros'                  => $errors,
            'de_bronze_codigo'       => $bySource['bronze_codigo']    ?? 0,
            'de_bronze_documento'    => $bySource['bronze_documento']  ?? 0,
            'de_canonical_nf'        => $bySource['canonical_nf']     ?? 0,
            'de_canonical_pedido'    => $bySource['canonical_pedido']  ?? 0,
            'unmatched_bronze_cnae'  => $unmatchedBronze,
            'vendas_cnae_linhas'     => $vendasCnae,
        ];

        try { $telemetry?->event($errors > 0 ? 'cnae.materialize.failed' : 'cnae.materialize.finished', array_merge($metrics, ['actor_user_id' => $context->userId]), 'cnae'); } catch (Throwable) {}
        $span?->finish();

        $summary = $dryRun
            ? "[DRY RUN] {$updated}/{$totalSemCnae} clientes teriam CNAE preenchido."
            : "{$updated} clientes atualizados com CNAE. mart_vendas_por_cnae_cnpj: {$vendasCnae} linhas.";

        if ($errors > 0) {
            return AdminCommandResult::warning(
                'CNAE Materialize',
                "{$updated} atualizados com {$errors} erro(s). Verifique os logs.",
                outputLines: $lines,
                warnings:    ["Houve {$errors} erro(s) ao atualizar clientes_dim."],
            );
        }

        return AdminCommandResult::ok(
            'CNAE Materialize',
            $summary,
            outputLines: $lines,
            metrics:     $metrics,
        );
    }

    private function rebuildVendasCnaeMart(): int
    {
        try {
            $tableHealth = new TableHealthService($this->pdo, $this->logger);
            $service     = new GeoCnaeMaterializationService($this->pdo, $this->logger, $tableHealth);
            return $service->buildVendasPorCnae();
        } catch (Throwable $e) {
            $this->logger->warning('CnaeMaterializeCommand: rebuildVendasCnaeMart falhou.', ['message' => $e->getMessage()]);
            return 0;
        }
    }

}
