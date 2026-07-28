<?php

declare(strict_types=1);

namespace BIomie\AdminConsole\Commands;

use BIomie\AdminConsole\AdminCommandContext;
use BIomie\AdminConsole\AdminCommandInterface;
use BIomie\AdminConsole\AdminCommandResult;
use BIomie\CnaeResolver;
use BIomie\Telemetry;
use PDO;
use Throwable;

final class CnaeDiagnoseSourcesCommand implements AdminCommandInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function name(): string        { return 'cnae:diagnose-sources'; }
    public function description(): string { return 'Diagnostica fontes de CNAE com analise de match por codigo e documento. Recomenda proxima acao.'; }
    public function group(): string       { return 'cnae'; }
    public function permission(): string  { return 'admin.console'; }
    public function isDestructive(): bool { return false; }
    public function requiresConfirmation(): bool { return false; }

    public function validateArgs(array $args): ?string { return null; }

    public function run(AdminCommandContext $context, array $args = []): AdminCommandResult
    {
        $telemetry = Telemetry::global();
        $span      = $telemetry?->startSpan('cnae.diagnose', ['actor_user_id' => $context->userId], 'cnae', 'cnae_diagnose');

        try { $telemetry?->event('cnae.diagnose.started', ['actor_user_id' => $context->userId], 'cnae'); } catch (Throwable) {}

        $resolver = new CnaeResolver($this->pdo);
        $lines    = [];
        $tables   = [];

        // ── 1. biomie_clientes_dim ──────────────────────────────────────────────
        $lines[] = '=== Fonte 1: biomie_clientes_dim ===';
        $totalDim = $comCnaeDim = $comCodigoDim = $comDocNormDim = 0;
        try {
            $totalDim    = (int) $this->pdo->query("SELECT COUNT(*) FROM biomie_clientes_dim")->fetchColumn();
            $comCnaeDim  = (int) $this->pdo->query("SELECT COUNT(*) FROM biomie_clientes_dim WHERE cnae_codigo IS NOT NULL AND cnae_codigo != ''")->fetchColumn();
            $comCodigoDim = (int) $this->pdo->query("SELECT COUNT(*) FROM biomie_clientes_dim WHERE codigo_cliente_omie IS NOT NULL AND codigo_cliente_omie > 0")->fetchColumn();

            $hasDimDocNorm = (bool) $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biomie_clientes_dim' AND COLUMN_NAME = 'documento_normalizado'"
            )->fetchColumn();
            if ($hasDimDocNorm) {
                $comDocNormDim = (int) $this->pdo->query("SELECT COUNT(*) FROM biomie_clientes_dim WHERE documento_normalizado IS NOT NULL AND documento_normalizado != ''")->fetchColumn();
            }

            $pct     = $totalDim > 0 ? round($comCnaeDim / $totalDim * 100, 1) : 0;
            $lines[] = "  Total clientes        : {$totalDim}";
            $lines[] = "  Com codigo_cliente_omie: {$comCodigoDim}";
            $lines[] = "  Com documento_norm    : {$comDocNormDim}" . ($hasDimDocNorm ? '' : ' (coluna ausente)');
            $lines[] = "  Com cnae_codigo       : {$comCnaeDim} ({$pct}%)";
            $lines[] = "  Sem cnae_codigo       : " . ($totalDim - $comCnaeDim);

            if ($comCnaeDim > 0) {
                $sample = $this->pdo->query(
                    "SELECT codigo_cliente_omie, cnae_codigo, cnae_descricao FROM biomie_clientes_dim
                     WHERE cnae_codigo IS NOT NULL AND cnae_codigo != '' LIMIT 3"
                )->fetchAll(PDO::FETCH_ASSOC);
                foreach ($sample as $r) {
                    $lines[] = sprintf('    [%s] CNAE=%s %s', $r['codigo_cliente_omie'], $r['cnae_codigo'], $r['cnae_descricao'] ?? '');
                }
            }
        } catch (Throwable $e) {
            $lines[] = "  ERRO: " . $e->getMessage();
        }

        // ── 2. biomie_omie_bronze (detailed match analysis) ─────────────────────
        $lines[]  = '';
        $lines[]  = '=== Fonte 2: biomie_omie_bronze — analise de match ===';
        $diag     = ['total_bronze_clientes' => 0, 'total_com_cnae' => 0, 'total_match_por_codigo' => 0, 'total_match_por_doc' => 0, 'total_sem_match' => 0, 'sample' => []];
        try {
            $diag = $resolver->diagnoseBronzeDetailed();

            $lines[] = "  Total payloads cliente    : {$diag['total_bronze_clientes']}";
            $lines[] = "  Payloads com CNAE valido  : {$diag['total_com_cnae']}";
            $lines[] = "  Match por codigo_omie     : {$diag['total_match_por_codigo']}";
            $lines[] = "  Match por documento_norm  : {$diag['total_match_por_doc']}";
            $lines[] = "  Sem match em clientes_dim : {$diag['total_sem_match']}";

            if ($diag['total_sem_match'] > 0 && $diag['total_com_cnae'] > 0) {
                $pctSem = round($diag['total_sem_match'] / $diag['total_com_cnae'] * 100, 0);
                $lines[] = "  ATENCAO: {$pctSem}% dos payloads com CNAE nao tem match → CNAE nao sera transferido.";
                $lines[] = "  Possivel causa: natural_key do bronze nao bate com codigo_cliente_omie no dim.";
            }
        } catch (Throwable $e) {
            $lines[] = "  ERRO: " . $e->getMessage();
        }

        // Sample table
        if (!empty($diag['sample'])) {
            $sampleRows = [];
            foreach ($diag['sample'] as $s) {
                $sampleRows[] = [
                    (string) ($s['codigo_cliente_omie'] ?? '-'),
                    (string) ($s['documento_mascarado'] ?? '-'),
                    (string) ($s['cnae_codigo'] ?? '-'),
                    mb_substr((string) ($s['cnae_descricao'] ?? '-'), 0, 30),
                    (string) ($s['match_por_codigo']    ?? '-'),
                    (string) ($s['match_por_documento'] ?? '-'),
                ];
            }
            $tables[] = [
                'title'   => 'Amostra bronze com CNAE (max 10)',
                'headers' => ['Codigo Omie', 'Documento', 'CNAE', 'Descricao', 'Match codigo', 'Match doc'],
                'rows'    => $sampleRows,
            ];
        }

        // ── 3. Known CNAE keys count ────────────────────────────────────────────
        $lines[]  = '';
        $lines[]  = '=== Chaves JSON com CNAE em bronze ===';
        $knownKeys = ['cnae_fiscal', 'cnae', 'cnae_principal', 'codigo_cnae', 'cnae_principal_codigo',
                      'cnaePrincipal', 'codigoCnae', 'atividade_principal', 'tipo_atividade',
                      'cnae_descricao', 'cnae_principal_descricao'];
        $foundAny = false;
        foreach ($knownKeys as $k) {
            try {
                $n = (int) $this->pdo->query(
                    "SELECT COUNT(*) FROM biomie_omie_bronze WHERE domain_name = 'clientes' AND payload_json LIKE '%" . addslashes($k) . "%'"
                )->fetchColumn();
                if ($n > 0) {
                    $lines[] = "  \"{$k}\" : {$n} payloads";
                    $foundAny = true;
                }
            } catch (Throwable) {}
        }
        if (!$foundAny) {
            $lines[] = "  Nenhuma chave CNAE encontrada. Omie nao retorna CNAE para estes clientes.";
        }

        // ── 4. canonical NF / pedidos ───────────────────────────────────────────
        $lines[]    = '';
        $lines[]    = '=== Fontes 3 e 4: NF canonicas / Pedidos canonicos ===';
        $notasCount = $pedidosCount = 0;
        foreach ([['biomie_notas_fiscais_canonicas', 'Notas', &$notasCount], ['biomie_pedidos_canonicos', 'Pedidos', &$pedidosCount]] as [$tbl, $lbl, &$cnt]) {
            try {
                $hasTable = (bool) $this->pdo->query(
                    "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$tbl}'"
                )->fetchColumn();
                if (!$hasTable) {
                    $lines[] = "  {$lbl}: tabela ausente.";
                    continue;
                }
                foreach (['cnae_codigo', 'cnae', 'cnae_principal'] as $col) {
                    $hasCol = (bool) $this->pdo->query(
                        "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$tbl}' AND COLUMN_NAME = '{$col}'"
                    )->fetchColumn();
                    if ($hasCol) {
                        $n = (int) $this->pdo->query("SELECT COUNT(*) FROM `{$tbl}` WHERE `{$col}` IS NOT NULL AND `{$col}` != ''")->fetchColumn();
                        $lines[] = "  {$lbl} coluna `{$col}`: {$n} registros com CNAE";
                        $cnt = max($cnt, $n);
                    }
                }
            } catch (Throwable $e) {
                $lines[] = "  {$lbl}: ERRO " . $e->getMessage();
            }
        }
        unset($cnt);

        // ── 5. Resumo e recomendação ────────────────────────────────────────────
        $lines[]     = '';
        $lines[]     = '=== Resumo e recomendacao ===';
        $melhorFonte = 'nenhuma';
        $recomendacao = '';
        $matchTotal   = ($diag['total_match_por_codigo'] ?? 0) + ($diag['total_match_por_doc'] ?? 0);

        if ($matchTotal > 0) {
            $melhorFonte  = 'bronze';
            $recomendacao = "Execute cnae:materialize — {$matchTotal} clientes tem CNAE no bronze com match em clientes_dim.";
        } elseif (($diag['total_com_cnae'] ?? 0) > 0) {
            $melhorFonte  = 'bronze_sem_match';
            $recomendacao = "Bronze tem CNAE mas nao bate com clientes_dim. Verifique se os natural_key do bronze correspondem ao codigo_cliente_omie no dim. Execute clientes:enrich-omie-all para re-sincronizar.";
        } elseif ($notasCount > 0) {
            $melhorFonte  = 'notas_fiscais';
            $recomendacao = "CNAE disponivel em notas canonicas. Execute cnae:materialize para tentar resolver via essa fonte.";
        } elseif ($pedidosCount > 0) {
            $melhorFonte  = 'pedidos';
            $recomendacao = "CNAE disponivel em pedidos canonicos. Execute cnae:materialize.";
        } else {
            $recomendacao = "Nenhuma fonte tem CNAE. Execute clientes:enrich-omie-all para buscar CNAE via API Omie.";
        }

        $lines[] = "  Melhor fonte detectada: {$melhorFonte}";
        $lines[] = "  Recomendacao: {$recomendacao}";

        $metrics = [
            'total_clientes_dim'     => $totalDim,
            'clientes_com_cnae'      => $comCnaeDim,
            'clientes_sem_cnae'      => $totalDim - $comCnaeDim,
            'clientes_com_codigo'    => $comCodigoDim,
            'clientes_com_doc_norm'  => $comDocNormDim,
            'bronze_com_payload'     => $diag['total_bronze_clientes'] ?? 0,
            'bronze_com_cnae'        => $diag['total_com_cnae']        ?? 0,
            'bronze_match_por_codigo'=> $diag['total_match_por_codigo'] ?? 0,
            'bronze_match_por_doc'   => $diag['total_match_por_doc']   ?? 0,
            'bronze_sem_match'       => $diag['total_sem_match']       ?? 0,
            'notas_com_cnae'         => $notasCount,
            'pedidos_com_cnae'       => $pedidosCount,
            'melhor_fonte'           => $melhorFonte,
        ];

        try { $telemetry?->event('cnae.diagnose.finished', array_merge($metrics, ['actor_user_id' => $context->userId]), 'cnae'); } catch (Throwable) {}
        $span?->finish();

        $summary = sprintf(
            '%d/%d clientes com CNAE. Bronze: %d com CNAE, %d com match. Melhor fonte: %s.',
            $comCnaeDim, $totalDim,
            $diag['total_com_cnae'] ?? 0,
            $matchTotal,
            $melhorFonte
        );

        return AdminCommandResult::ok(
            'CNAE Diagnose Sources',
            $summary,
            outputLines: $lines,
            metrics:     $metrics,
            tables:      $tables,
        );
    }
}
