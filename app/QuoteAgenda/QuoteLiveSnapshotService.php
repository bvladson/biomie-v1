<?php

declare(strict_types=1);

namespace BIomie\QuoteAgenda;

use BIomie\CanonicalRepository;
use BIomie\Config;
use BIomie\FiscalSync\FiscalCanonicalMapper;
use BIomie\FiscalSync\FiscalPartyResolver;
use BIomie\FiscalSync\FiscalSupport;
use BIomie\OmieClient;
use BIomie\OmieRepository;
use BIomie\Support;
use BIomie\Support\HardDataExclusionPolicy;
use PDO;

/**
 * AGENDA DE ORÇAMENTOS — Fase 4: captura do snapshot VIVO da etapa de orçamento.
 *
 * Núcleo extraído (e já validado em produção) de tools/dev/quote_agenda_live_upsert.php.
 * O tool continua existindo como adaptador CLI fino de diagnóstico/dry-run e chama
 * EXATAMENTE este serviço — dry-run e produção rodam as mesmas regras.
 *
 * Corrige o blind spot estrutural: etapa comercial e data_previsao são dimensões
 * INDEPENDENTES. Filtrar por previsão oculta orçamentos vivos (9 de 40 na MAG).
 * A fonte da Agenda é ListarPedidos(etapa=10) — listagem em lote, nunca consulta
 * pedido a pedido.
 *
 * Responsabilidades: buscar/paginar · validar · gerar snapshot_hash · normalizar ·
 * aplicar HardDataExclusionPolicy · produzir elegíveis/excluídos/diferenças ·
 * executar o upsert canônico.
 *
 * A BUSCA acontece FORA de transação. O upsert é chamado pelo scanner DENTRO dela.
 */
final class QuoteLiveSnapshotService implements QuoteLiveSnapshotSource
{
    /** Campos comparados no diff (invariantes + as 3 datas da Fase 2). */
    public const DIFF_FIELDS = [
        'codigo_pedido', 'codigo_vendedor_omie', 'nome_vendedor',
        'codigo_cliente_omie', 'nome_cliente', 'documento_cliente',
        'etapa_omie', 'status_comercial', 'operation_scope',
        'valor_total', 'valor_mercadorias', 'valor_descontos', 'quantidade_itens',
        'data_emissao', 'data_faturamento', 'data_cancelamento',
        'data_inclusao_omie', 'data_alteracao_omie', 'data_previsao_faturamento',
    ];

    private const MONEY_FIELDS = ['valor_total', 'valor_mercadorias', 'valor_descontos'];

    private ?FiscalCanonicalMapper $mapper = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly OmieClient $client,
        private readonly OmieRepository $omie,
        private readonly Config $config,
        private readonly CanonicalRepository $canonical,
        private readonly int $companyId,
    ) {
    }

    /**
     * Captura o snapshot vivo. SOMENTE LEITURA (Omie + canônico) — nenhuma escrita.
     * Toda a paginação é concluída e validada antes de qualquer mutação.
     */
    public function capture(string $etapa = '10'): QuoteLiveSnapshot
    {
        $endpoint = (string) $this->config->get('omie.endpoints.orders');
        $page = 1;
        $raw = [];
        $totalRegistros = null;
        $totalPaginas = null;

        do {
            $resp = $this->client->post($endpoint, 'ListarPedidos', [
                'pagina' => $page,
                'registros_por_pagina' => 100,
                'apenas_importado_api' => 'N',
                'etapa' => $etapa, // contrato oficial pvpListarRequest
            ]);
            $totalRegistros ??= (int) ($resp['total_de_registros'] ?? 0);
            $totalPaginas   ??= (int) ($resp['total_de_paginas'] ?? 1);
            foreach ((array) ($resp['pedido_venda_produto'] ?? []) as $o) {
                if (is_array($o)) { $raw[] = $o; }
            }
            $page++;
        } while ($page <= (int) $totalPaginas);

        $problems = [];

        // ── Validações BLOQUEANTES ───────────────────────────────────────────────
        if (count($raw) !== (int) $totalRegistros) {
            $problems[] = sprintf('paginação incompleta: %d recebidos ≠ %d total_de_registros', count($raw), (int) $totalRegistros);
        }

        $etapasVistas = [];
        $rawById = [];
        $duplicados = [];
        foreach ($raw as $o) {
            $e = (string) Support::getByPaths($o, ['cabecalho.etapa'], '');
            $etapasVistas[$e] = ($etapasVistas[$e] ?? 0) + 1;
            $id = (string) Support::getByPaths($o, ['cabecalho.codigo_pedido'], '');
            if ($id === '' || $id === '0') {
                $problems[] = 'pedido sem codigo_pedido no retorno';
                continue;
            }
            if (isset($rawById[$id])) { $duplicados[] = $id; }
            $rawById[$id] = $o;
        }

        // PHP converte chave numérica em int — comparar como string.
        $etapasUnicas = array_map('strval', array_keys($etapasVistas));
        if ($etapasUnicas !== [$etapa]) {
            $problems[] = 'retorno contém etapa fora de ' . $etapa . ': ' . implode(',', $etapasUnicas);
        }
        if ($duplicados !== []) {
            $problems[] = 'IDs duplicados: ' . implode(',', array_unique($duplicados));
        }

        // ── Normalização (mesmo mapper e mesmos mapas do sync normal) ────────────
        $clientMap = $this->buildClientMap($raw);
        $vendorMap = $this->vendorMap();
        $normalized = [];
        $recusados = [];
        foreach ($rawById as $id => $o) {
            $row = $this->mapper()->normalizePendingOrderToCanonical($o, $clientMap, 'quote_agenda_live', $vendorMap);
            if ($row === null) {
                $recusados[] = $id;
                continue;
            }
            $normalized[(string) $row['pedido_id_omie']] = $row;
        }
        if ($recusados !== []) {
            $problems[] = 'mapper recusou pedidos vivos: ' . implode(',', $recusados);
        }

        // ── Exclusão dura (ex.: vendedor Licitação — Fase L) ─────────────────────
        $eligible = [];
        $excluded = [];
        foreach ($normalized as $id => $row) {
            if ($this->isHardExcluded($row)) {
                $excluded[$id] = $row;
                continue;
            }
            $eligible[$id] = $row;
        }

        return new QuoteLiveSnapshot(
            companyId: $this->companyId,
            etapa: $etapa,
            rawById: $rawById,
            normalizedById: $normalized,
            eligibleById: $eligible,
            excludedById: $excluded,
            totalRegistros: (int) $totalRegistros,
            totalPaginas: (int) $totalPaginas,
            problems: $problems,
            snapshotHash: self::hashOf($eligible),
        );
    }

    /**
     * Diff read-only do snapshot contra o canônico atual.
     *
     * @return array{existing:array<string,array<string,mixed>>,missing:list<string>,drifts:array<string,array<string,array{canonico:mixed,vivo:mixed}>>,emissao_suspeita:array<string,mixed>}
     */
    public function diffAgainstCanonical(QuoteLiveSnapshot $snap): array
    {
        $ids = array_keys($snap->eligibleById);
        if ($ids === []) {
            return ['existing' => [], 'missing' => [], 'drifts' => [], 'emissao_suspeita' => []];
        }

        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->pdo->prepare(
            "SELECT * FROM biomie_pedidos_canonicos WHERE company_id = ? AND pedido_id_omie IN ($ph)"
        );
        $st->execute([$this->companyId, ...$ids]);

        $existing = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $existing[(string) $r['pedido_id_omie']] = $r;
        }

        $drifts = [];
        $emissaoSuspeita = [];
        foreach ($existing as $id => $cur) {
            $new = $snap->eligibleById[$id];
            $changed = [];
            foreach (self::DIFF_FIELDS as $f) {
                $a = $cur[$f] ?? null;
                $b = $new[$f] ?? null;
                if (in_array($f, self::MONEY_FIELDS, true)) {
                    $a = number_format((float) $a, 2, '.', '');
                    $b = number_format((float) $b, 2, '.', '');
                }
                if ((string) $a !== (string) $b) {
                    $changed[$f] = ['canonico' => $a, 'vivo' => $b];
                }
            }
            if ($changed === []) { continue; }
            $drifts[$id] = $changed;

            // data_emissao SÓ pode mudar seguindo a regra legada (== previsão do payload).
            if (isset($changed['data_emissao'])) {
                $prev = (string) ($new['data_previsao_faturamento'] ?? '');
                if ((string) $changed['data_emissao']['vivo'] !== $prev) {
                    $emissaoSuspeita[$id] = $changed['data_emissao'];
                }
            }
        }

        return [
            'existing' => $existing,
            'missing'  => array_values(array_diff($ids, array_keys($existing))),
            'drifts'   => $drifts,
            'emissao_suspeita' => $emissaoSuspeita,
        ];
    }

    /**
     * Upsert canônico dos elegíveis pelo writer normal (mantém hash coerente).
     * SEM DELETE. O caller (scanner) é dono da transação.
     *
     * @return array{inserted:int,updated:int,unchanged:int,skipped:int}
     */
    public function upsertEligible(QuoteLiveSnapshot $snap): array
    {
        $stats = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];
        foreach ($snap->eligibleById as $row) {
            $res = $this->canonical->upsertOrder($row);
            if (isset($stats[$res])) { $stats[$res]++; }
        }

        return $stats;
    }

    /** Hash estável do snapshot: mesmo conteúdo ⇒ mesmo hash. */
    /** @param array<string,array<string,mixed>> $eligibleById */
    public static function hashOf(array $eligibleById): string
    {
        $keys = array_keys($eligibleById);
        sort($keys, SORT_STRING);
        $buffer = '';
        foreach ($keys as $id) {
            $r = $eligibleById[$id];
            $buffer .= implode('|', [
                $id,
                (string) ($r['etapa_omie'] ?? ''),
                (string) ($r['codigo_vendedor_omie'] ?? ''),
                (string) ($r['codigo_cliente_omie'] ?? ''),
                number_format((float) ($r['valor_total'] ?? 0), 2, '.', ''),
                (string) ($r['data_inclusao_omie'] ?? ''),
                (string) ($r['data_alteracao_omie'] ?? ''),
                (string) ($r['data_previsao_faturamento'] ?? ''),
            ]) . "\n";
        }

        return hash('sha256', $buffer);
    }

    // ── Internos ─────────────────────────────────────────────────────────────────

    private function mapper(): FiscalCanonicalMapper
    {
        return $this->mapper ??= new FiscalCanonicalMapper(new FiscalSupport(), new FiscalPartyResolver());
    }

    /**
     * @param list<array<string,mixed>> $orders
     * @return array<string,array<string,mixed>>
     */
    private function buildClientMap(array $orders): array
    {
        $codes = (new FiscalPartyResolver())->collectClientCodes($orders);

        return $codes === [] ? [] : $this->omie->listClientsSummaryByCodes($codes);
    }

    /**
     * Roster local (já sincronizado). Evita uma chamada Omie por execução e é o
     * mesmo mapa que o sync normal usa quando o cadastro está fresco.
     *
     * @return array<string,string>
     */
    private function vendorMap(): array
    {
        $map = [];
        foreach ($this->canonical->getVendorMap() as $codigo => $vendor) {
            $map[(string) $codigo] = (string) $vendor['nome'];
        }

        return $map;
    }

    /** @param array<string,mixed> $row */
    private function isHardExcluded(array $row): bool
    {
        return HardDataExclusionPolicy::matches($this->pdo, [
            'vendor_code'    => $row['codigo_vendedor_omie'] ?? null,
            'pedido_id_omie' => $row['pedido_id_omie'] ?? null,
            'codigo_pedido'  => $row['codigo_pedido'] ?? null,
        ], $this->companyId);
    }
}
