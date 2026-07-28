<?php

declare(strict_types=1);

namespace BIomie\AdminConsole\Commands;

use BIomie\AdminConsole\AdminCommandContext;
use BIomie\AdminConsole\AdminCommandInterface;
use BIomie\AdminConsole\AdminCommandResult;
use PDO;
use Throwable;

/**
 * Validates the GEO data pipeline after clientes:enrich-omie-all.
 *
 * Checks each stage in order:
 *  1. biomie_clientes_dim → biomie_clientes_geo_dim gap (bairro_raw sync)
 *  2. biomie_clientes_geo_dim coverage (bairro_norm, match oficial)
 *  3. mart_compradores_por_geo coverage (sem_bairro, bairros_com_venda)
 *  4. mart_vendas_por_cnae_cnpj — CNAE diagnostic when empty
 *
 * Acceptance criteria evaluated and reported as PASS / FAIL / WARN.
 */
final class GeoValidateEnrichmentCommand implements AdminCommandInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function name(): string        { return 'geo:validate-enrichment'; }
    public function description(): string { return 'Valida a propagacao pos-enriquecimento: geo_dim gap, bairro_norm, match oficial, mart cobertura, CNAE diagnostico.'; }
    public function group(): string       { return 'geo'; }
    public function permission(): string  { return 'admin.console'; }
    public function isDestructive(): bool { return false; }
    public function requiresConfirmation(): bool { return false; }

    public function validateArgs(array $args): ?string { return null; }

    public function run(AdminCommandContext $context, array $args = []): AdminCommandResult
    {
        $startedAt = microtime(true);
        $lines     = [];
        $warnings  = [];
        $failures  = [];

        $pass = static fn(string $label): string => sprintf('  [PASS] %s', $label);
        $fail = static fn(string $label): string => sprintf('  [FAIL] %s', $label);
        $warn = static fn(string $label): string => sprintf('  [WARN] %s', $label);
        $info = static fn(string $label): string => sprintf('         %s', $label);

        // ── 1. Gap: biomie_clientes_dim → biomie_clientes_geo_dim ─────────────
        $lines[] = '── [1] Gap dim → geo_dim ────────────────────────────────────────';

        try {
            $dimRow = $this->pdo->query(
                "SELECT
                    COUNT(*) AS total_dim,
                    SUM(CASE WHEN bairro IS NOT NULL AND bairro <> '' THEN 1 ELSE 0 END) AS dim_com_bairro,
                    SUM(CASE WHEN cidade IS NOT NULL AND cidade <> '' THEN 1 ELSE 0 END) AS dim_com_cidade,
                    SUM(CASE WHEN uf IS NOT NULL AND uf <> '' THEN 1 ELSE 0 END) AS dim_com_uf
                 FROM biomie_clientes_dim"
            )?->fetch(PDO::FETCH_ASSOC) ?: [];

            $dimTotal     = (int) ($dimRow['total_dim']    ?? 0);
            $dimComBairro = (int) ($dimRow['dim_com_bairro'] ?? 0);
            $dimComCidade = (int) ($dimRow['dim_com_cidade'] ?? 0);
            $dimComUf     = (int) ($dimRow['dim_com_uf']     ?? 0);

            $lines[] = sprintf('  biomie_clientes_dim    total       : %d', $dimTotal);
            $lines[] = sprintf('  biomie_clientes_dim    com bairro  : %d', $dimComBairro);
            $lines[] = sprintf('  biomie_clientes_dim    com cidade  : %d', $dimComCidade);
            $lines[] = sprintf('  biomie_clientes_dim    com uf      : %d', $dimComUf);
        } catch (Throwable $e) {
            $lines[] = '  ERRO ao ler biomie_clientes_dim: ' . $e->getMessage();
            $dimComBairro = 0;
        }

        try {
            $gapRow = $this->pdo->query(
                "SELECT
                    COUNT(*) AS total_geo,
                    SUM(CASE WHEN bairro_raw IS NOT NULL AND bairro_raw <> '' THEN 1 ELSE 0 END) AS geo_com_bairro_raw,
                    SUM(CASE WHEN bairro_norm IS NOT NULL AND bairro_norm <> '' THEN 1 ELSE 0 END) AS geo_com_bairro_norm,
                    SUM(CASE WHEN bairro_oficial_id_fortaleza IS NOT NULL THEN 1 ELSE 0 END) AS geo_com_match,
                    SUM(CASE WHEN cidade_raw IS NOT NULL AND cidade_raw <> '' THEN 1 ELSE 0 END) AS geo_com_cidade_raw,
                    SUM(CASE WHEN uf IS NOT NULL AND uf <> '' THEN 1 ELSE 0 END) AS geo_com_uf
                 FROM biomie_clientes_geo_dim"
            )?->fetch(PDO::FETCH_ASSOC) ?: [];

            $geoTotal        = (int) ($gapRow['total_geo']           ?? 0);
            $geoComBairroRaw = (int) ($gapRow['geo_com_bairro_raw']  ?? 0);
            $geoComBairroNorm = (int) ($gapRow['geo_com_bairro_norm'] ?? 0);
            $geoComMatch     = (int) ($gapRow['geo_com_match']        ?? 0);
            $geoComCidadeRaw = (int) ($gapRow['geo_com_cidade_raw']   ?? 0);
            $geoComUf        = (int) ($gapRow['geo_com_uf']           ?? 0);

            $lines[] = '';
            $lines[] = sprintf('  biomie_clientes_geo_dim total      : %d', $geoTotal);
            $lines[] = sprintf('  biomie_clientes_geo_dim bairro_raw : %d', $geoComBairroRaw);
            $lines[] = sprintf('  biomie_clientes_geo_dim bairro_norm: %d', $geoComBairroNorm);
            $lines[] = sprintf('  biomie_clientes_geo_dim match      : %d', $geoComMatch);
            $lines[] = sprintf('  biomie_clientes_geo_dim cidade_raw : %d', $geoComCidadeRaw);
            $lines[] = sprintf('  biomie_clientes_geo_dim uf         : %d', $geoComUf);
        } catch (Throwable $e) {
            $lines[] = '  ERRO ao ler biomie_clientes_geo_dim: ' . $e->getMessage();
            $geoComBairroRaw = $geoComBairroNorm = $geoComMatch = 0;
        }

        // Gap: clientes_dim has bairro but geo_dim.bairro_raw is empty
        try {
            $gapCount = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM biomie_clientes_dim AS d
                 JOIN biomie_clientes_geo_dim AS g ON g.codigo_cliente_omie = d.codigo_cliente_omie
                 WHERE d.bairro IS NOT NULL AND d.bairro <> ''
                   AND (g.bairro_raw IS NULL OR g.bairro_raw = '')"
            )?->fetchColumn();

            $notInGeo = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM biomie_clientes_dim AS d
                 LEFT JOIN biomie_clientes_geo_dim AS g ON g.codigo_cliente_omie = d.codigo_cliente_omie
                 WHERE g.codigo_cliente_omie IS NULL"
            )?->fetchColumn();

            $lines[] = '';
            if ($gapCount === 0) {
                $lines[] = $pass('Sem gap dim→geo_dim: todos com bairro em dim tambem tem bairro_raw em geo_dim');
            } else {
                $lines[] = $fail("Gap dim→geo_dim: {$gapCount} cliente(s) tem bairro em dim mas bairro_raw vazio em geo_dim");
                $lines[] = $info('Execute geo:materialize para sincronizar (Stage 1 agora faz refresh automatico)');
                $failures[] = "Gap dim→geo_dim: {$gapCount} clientes sem bairro_raw em geo_dim";
            }

            if ($notInGeo === 0) {
                $lines[] = $pass('Todos os clientes de dim tem skeleton em geo_dim');
            } else {
                $lines[] = $warn("{$notInGeo} clientes em dim sem skeleton em geo_dim — execute geo:materialize");
                $warnings[] = "{$notInGeo} clientes sem skeleton em geo_dim";
            }
        } catch (Throwable $e) {
            $lines[] = '  ERRO ao calcular gap: ' . $e->getMessage();
        }

        // ── 2. biomie_clientes_geo_dim coverage ──────────────────────────────
        $lines[] = '';
        $lines[] = '── [2] biomie_clientes_geo_dim cobertura ────────────────────────';

        // bairro_norm >> 25
        if ($geoComBairroNorm > 25) {
            $lines[] = $pass("bairro_norm = {$geoComBairroNorm} (acima de 25)");
        } elseif ($geoComBairroNorm > 0) {
            $lines[] = $warn("bairro_norm = {$geoComBairroNorm} (muito baixo — esperado > 25 apos enriquecimento)");
            $warnings[] = "bairro_norm = {$geoComBairroNorm}, esperado > 25";
        } else {
            $lines[] = $fail('bairro_norm = 0 — geo_dim sem normalizacao. Execute geo:materialize');
            $failures[] = 'bairro_norm = 0 em geo_dim';
        }

        // match oficial ~116+
        if ($geoComMatch >= 116) {
            $lines[] = $pass("Match oficial = {$geoComMatch} (>= 116 esperado)");
        } elseif ($geoComMatch > 0) {
            $lines[] = $warn("Match oficial = {$geoComMatch} (esperado >= 116 apos 176 clientes enriquecidos)");
            $warnings[] = "Match oficial = {$geoComMatch}, esperado >= 116";
        } else {
            $lines[] = $fail('Match oficial = 0 — Stage 3 nao rodou ou bairros nao batem com biomie_bairros_fortaleza_dim');
            $failures[] = 'Sem matches oficiais em geo_dim';
        }

        // bairro_raw sync check
        if ($geoComBairroRaw >= $dimComBairro && $dimComBairro > 0) {
            $lines[] = $pass("bairro_raw em geo_dim ({$geoComBairroRaw}) >= bairro em dim ({$dimComBairro})");
        } elseif ($dimComBairro > 0) {
            $delta = $dimComBairro - $geoComBairroRaw;
            $lines[] = $warn("bairro_raw em geo_dim ({$geoComBairroRaw}) < bairro em dim ({$dimComBairro}) — delta {$delta}");
            $warnings[] = "Delta bairro_raw: {$delta} clientes em dim com bairro mas sem bairro_raw em geo_dim";
        }

        // Clients with bairro_norm but no match (candidates for alias)
        try {
            $semMatchComNorm = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM biomie_clientes_geo_dim
                 WHERE bairro_norm IS NOT NULL AND bairro_norm <> ''
                   AND (bairro_oficial_id_fortaleza IS NULL OR bairro_oficial_id_fortaleza = 0)"
            )?->fetchColumn();

            if ($semMatchComNorm > 0) {
                $lines[] = $warn("{$semMatchComNorm} registros em geo_dim com bairro_norm mas sem match oficial (alias pendentes)");

                // Show top 10 unmatched bairros
                $unmatched = $this->pdo->query(
                    "SELECT bairro_norm, COUNT(*) AS cnt
                     FROM biomie_clientes_geo_dim
                     WHERE bairro_norm IS NOT NULL AND bairro_norm <> ''
                       AND (bairro_oficial_id_fortaleza IS NULL OR bairro_oficial_id_fortaleza = 0)
                     GROUP BY bairro_norm ORDER BY cnt DESC LIMIT 10"
                )?->fetchAll(PDO::FETCH_ASSOC) ?: [];

                if ($unmatched !== []) {
                    $lines[] = $info('Top bairros sem match (candidatos para alias):');
                    foreach ($unmatched as $r) {
                        $lines[] = $info(sprintf('    %-35s — %d clientes', $r['bairro_norm'], (int) $r['cnt']));
                    }
                }
            } else {
                $lines[] = $pass('Todos os registros com bairro_norm tem match oficial');
            }
        } catch (Throwable) {}

        // ── 3. mart_compradores_por_geo coverage ─────────────────────────────
        $lines[] = '';
        $lines[] = '── [3] mart_compradores_por_geo cobertura ───────────────────────';

        try {
            $martRow = $this->pdo->query(
                "SELECT
                    COUNT(*) AS total_rows,
                    SUM(CASE WHEN bairro_norm IS NOT NULL AND bairro_norm <> '' THEN 1 ELSE 0 END) AS com_bairro,
                    SUM(CASE WHEN bairro_norm IS NULL OR bairro_norm = '' THEN 1 ELSE 0 END) AS sem_bairro,
                    COUNT(DISTINCT CASE WHEN bairro_norm IS NOT NULL AND bairro_norm <> '' THEN bairro_norm END) AS bairros_distintos,
                    COUNT(DISTINCT codigo_cliente_omie) AS clientes,
                    COALESCE(SUM(faturamento), 0) AS faturamento_total,
                    MAX(refreshed_at) AS ultimo_refresh
                 FROM mart_compradores_por_geo"
            )?->fetch(PDO::FETCH_ASSOC) ?: [];

            $martTotal       = (int) ($martRow['total_rows']       ?? 0);
            $martComBairro   = (int) ($martRow['com_bairro']        ?? 0);
            $martSemBairro   = (int) ($martRow['sem_bairro']        ?? 0);
            $martBairros     = (int) ($martRow['bairros_distintos'] ?? 0);
            $martClientes    = (int) ($martRow['clientes']          ?? 0);
            $martFaturamento = (float) ($martRow['faturamento_total'] ?? 0);
            $martRefresh     = (string) ($martRow['ultimo_refresh']  ?? 'nunca');

            $lines[] = sprintf('  Total rows              : %d', $martTotal);
            $lines[] = sprintf('  Com bairro_norm         : %d', $martComBairro);
            $lines[] = sprintf('  Sem bairro_norm         : %d', $martSemBairro);
            $lines[] = sprintf('  Bairros distintos       : %d', $martBairros);
            $lines[] = sprintf('  Clientes                : %d', $martClientes);
            $lines[] = sprintf('  Faturamento total       : R$ %s', number_format($martFaturamento, 2, ',', '.'));
            $lines[] = sprintf('  Ultimo refresh          : %s', $martRefresh);

            // Acceptance: mart populated
            if ($martTotal > 0) {
                $lines[] = $pass("mart_compradores_por_geo populado ({$martTotal} rows)");
            } else {
                $lines[] = $fail('mart_compradores_por_geo VAZIO — execute geo:materialize');
                $failures[] = 'mart_compradores_por_geo vazio';
            }

            // Acceptance: bairros > 10
            if ($martBairros > 10) {
                $lines[] = $pass("Bairros com venda = {$martBairros} (acima de 10)");
            } elseif ($martBairros > 0) {
                $lines[] = $warn("Bairros com venda = {$martBairros} (esperado > 10 apos enriquecimento de 116 matches)");
                $warnings[] = "Bairros com venda = {$martBairros}, esperado > 10";
            } else {
                $lines[] = $fail('Bairros com venda = 0 — mart sem bairro_norm preenchido');
                $failures[] = 'Bairros com venda = 0 no mart';
            }

            // Acceptance: sem_bairro caiu
            if ($martSemBairro < 200) {
                $lines[] = $pass("Sem bairro caiu para {$martSemBairro} (anterior era ~257)");
            } elseif ($martSemBairro < 257) {
                $lines[] = $warn("Sem bairro = {$martSemBairro} (reduziu mas ainda alto — fallback cd.bairro_normalizado ativo)");
            } else {
                $lines[] = $fail("Sem bairro = {$martSemBairro} (nao reduziu — fallback nao ativou ou geo:materialize nao foi executado)");
                $failures[] = "sem_bairro = {$martSemBairro}, nao reduziu";
            }

            // Breakdown by bairro: top 10
            try {
                $topBairros = $this->pdo->query(
                    "SELECT bairro_norm, COUNT(DISTINCT codigo_cliente_omie) AS clientes, SUM(faturamento) AS rev
                     FROM mart_compradores_por_geo
                     WHERE bairro_norm IS NOT NULL AND bairro_norm <> ''
                     GROUP BY bairro_norm ORDER BY rev DESC LIMIT 10"
                )?->fetchAll(PDO::FETCH_ASSOC) ?: [];

                if ($topBairros !== []) {
                    $lines[] = '';
                    $lines[] = $info('Top 10 bairros no mart:');
                    foreach ($topBairros as $r) {
                        $lines[] = $info(sprintf(
                            '    %-30s  %3d clientes  R$ %s',
                            mb_substr((string) ($r['bairro_norm'] ?? ''), 0, 30),
                            (int) $r['clientes'],
                            number_format((float) ($r['rev'] ?? 0), 2, ',', '.')
                        ));
                    }
                }
            } catch (Throwable) {}

            // Clients with bairro in clientes_dim but sem_bairro in mart (fallback coverage check)
            try {
                $fallbackMissed = (int) $this->pdo->query(
                    "SELECT COUNT(*) FROM mart_compradores_por_geo AS m
                     JOIN biomie_clientes_dim AS cd ON cd.codigo_cliente_omie = m.codigo_cliente_omie
                     WHERE (m.bairro_norm IS NULL OR m.bairro_norm = '')
                       AND cd.bairro IS NOT NULL AND cd.bairro <> ''"
                )?->fetchColumn();

                if ($fallbackMissed === 0) {
                    $lines[] = $pass('Fallback cd.bairro_normalizado funcionou: nenhum cliente com bairro em dim esta sem bairro no mart');
                } else {
                    $lines[] = $fail("{$fallbackMissed} clientes tem bairro em dim mas bairro_norm vazio no mart — fallback nao propagou");
                    $lines[] = $info('Causa: geo:materialize nao foi executado apos o patch, ou Stage 7 nao gerou o fallback corretamente');
                    $failures[] = "Fallback perdido: {$fallbackMissed} clientes";
                }
            } catch (Throwable $e) {
                $lines[] = $warn('Nao foi possivel verificar fallback: ' . $e->getMessage());
            }
        } catch (Throwable $e) {
            $lines[] = '  ERRO ao ler mart_compradores_por_geo: ' . $e->getMessage();
            $failures[] = 'Erro ao ler mart_compradores_por_geo: ' . $e->getMessage();
        }

        // ── 4. mart_vendas_por_cnae_cnpj — CNAE diagnostic ──────────────────
        $lines[] = '';
        $lines[] = '── [4] mart_vendas_por_cnae_cnpj (CNAE) ────────────────────────';

        try {
            $cnaeTotal = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM mart_vendas_por_cnae_cnpj"
            )?->fetchColumn();

            if ($cnaeTotal > 0) {
                $lines[] = $pass("mart_vendas_por_cnae_cnpj populado ({$cnaeTotal} rows)");
            } else {
                $lines[] = $warn('mart_vendas_por_cnae_cnpj VAZIO — diagnóstico detalhado:');

                // Clientes with cnae_codigo in dim
                try {
                    $comCnae = (int) $this->pdo->query(
                        "SELECT COUNT(*) FROM biomie_clientes_dim
                         WHERE cnae_codigo IS NOT NULL AND cnae_codigo <> ''"
                    )?->fetchColumn();
                    $lines[] = $info("biomie_clientes_dim com cnae_codigo preenchido: {$comCnae}");
                } catch (Throwable) {}

                // Clientes with cnae + sales in mart_cliente_periodo
                try {
                    $comCnaeEVendas = $this->pdo->query(
                        "SELECT cd.cnae_codigo, cd.cnae_descricao,
                                COUNT(DISTINCT cd.codigo_cliente_omie) AS clientes,
                                COALESCE(SUM(cp.valor_nfe), 0) AS faturamento
                         FROM biomie_clientes_dim cd
                         JOIN mart_cliente_periodo cp ON cp.codigo_cliente_omie = cd.codigo_cliente_omie
                         WHERE cd.cnae_codigo IS NOT NULL AND cd.cnae_codigo <> ''
                           AND (cp.nfes_emitidas > 0 OR cp.valor_nfe > 0)
                         GROUP BY cd.cnae_codigo, cd.cnae_descricao
                         ORDER BY faturamento DESC
                         LIMIT 10"
                    )?->fetchAll(PDO::FETCH_ASSOC) ?: [];

                    if ($comCnaeEVendas !== []) {
                        $lines[] = $info('Clientes com CNAE + vendas em mart_cliente_periodo (candidatos ao mart):');
                        foreach ($comCnaeEVendas as $r) {
                            $lines[] = $info(sprintf(
                                '    %-12s  %-35s  %2d cli  R$ %s',
                                (string) ($r['cnae_codigo'] ?? 'SEM_CNAE'),
                                mb_substr((string) ($r['cnae_descricao'] ?? ''), 0, 35),
                                (int) $r['clientes'],
                                number_format((float) ($r['faturamento'] ?? 0), 2, ',', '.')
                            ));
                        }
                        $lines[] = $info('Execute cnae:materialize para popular o mart.');
                    } else {
                        $lines[] = $info('Nenhum cliente com cnae_codigo tem vendas em mart_cliente_periodo.');
                        $lines[] = $info('Execute clientes:enrich-omie-all target=cnae para preencher CNAE dos clientes.');
                    }
                } catch (Throwable $e) {
                    $lines[] = $info('Erro ao cruzar CNAE com mart_cliente_periodo: ' . $e->getMessage());
                }

                // Check canonical sources for CNAE
                foreach (['biomie_notas_fiscais_canonicas', 'biomie_pedidos_canonicos'] as $canonTable) {
                    try {
                        $cnaeCol = null;
                        foreach (['cnae_codigo', 'cnae', 'cnae_principal'] as $candidate) {
                            $exists = (int) $this->pdo->query(
                                "SELECT COUNT(*) FROM information_schema.COLUMNS
                                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$canonTable}' AND COLUMN_NAME = '{$candidate}'"
                            )?->fetchColumn();
                            if ($exists > 0) { $cnaeCol = $candidate; break; }
                        }
                        if ($cnaeCol !== null) {
                            $cnaeInCanon = (int) $this->pdo->query(
                                "SELECT COUNT(*) FROM `{$canonTable}` WHERE `{$cnaeCol}` IS NOT NULL AND `{$cnaeCol}` <> ''"
                            )?->fetchColumn();
                            $lines[] = $info("{$canonTable}.{$cnaeCol}: {$cnaeInCanon} linhas com CNAE");
                        } else {
                            $lines[] = $info("{$canonTable}: sem coluna CNAE detectada");
                        }
                    } catch (Throwable) {
                        $lines[] = $info("{$canonTable}: tabela ausente ou inacessivel");
                    }
                }

                $warnings[] = 'mart_vendas_por_cnae_cnpj vazio — execute cnae:materialize';
            }
        } catch (Throwable $e) {
            $lines[] = $warn('Erro ao ler mart_vendas_por_cnae_cnpj: ' . $e->getMessage());
        }

        // ── 5. Summary ────────────────────────────────────────────────────────
        $lines[] = '';
        $lines[] = '── Resumo dos critérios de aceite ───────────────────────────────';

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($failures === [] && $warnings === []) {
            $lines[] = '  TODOS OS CRITERIOS ATENDIDOS.';
            return AdminCommandResult::ok(
                'Validacao GEO pos-enriquecimento',
                'Todos os criterios de aceite atendidos.',
                outputLines: $lines,
                metrics: ['failures' => 0, 'warnings' => 0, 'duration_ms' => $durationMs],
            )->withDurationMs($durationMs);
        }

        foreach ($failures as $f) {
            $lines[] = $fail($f);
        }
        foreach ($warnings as $w) {
            $lines[] = $warn($w);
        }

        if ($failures !== []) {
            return AdminCommandResult::error(
                'Validacao GEO pos-enriquecimento',
                count($failures) . ' falha(s) encontrada(s). Verifique os detalhes acima.',
                errors: $failures,
            )->withDurationMs($durationMs);
        }

        return AdminCommandResult::warning(
            'Validacao GEO pos-enriquecimento',
            count($warnings) . ' aviso(s). Pipeline OK mas com pontos de atencao.',
            outputLines: $lines,
            warnings: $warnings,
        )->withDurationMs($durationMs);
    }
}
