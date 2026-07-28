<?php

declare(strict_types=1);

/**
 * Audita o contrato de recuperacao historica de NF-e sem acessar Omie, banco
 * ou qualquer destino de escrita.
 *
 * O objetivo e impedir que uma alteracao aparentemente pequena reintroduza os
 * riscos mais graves do backfill fiscal: envelope Omie incorreto, recorte por
 * data errada, paginacao truncada, delete de janela e mistura com pedidos.
 *
 * Uso:
 *   php tools/dev/nfe_backfill_contract_audit.php
 *   php tools/dev/nfe_backfill_contract_audit.php --pretty
 */

use BIomie\Bootstrap;
use BIomie\FiscalSync\FiscalCanonicalMapper;
use BIomie\FiscalSync\FiscalSupport;
use BIomie\OmieRepository;
use BIomie\Sync\FiscalSyncCoordinator;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$root = dirname(__DIR__, 2);
$pretty = in_array('--pretty', $_SERVER['argv'] ?? [], true);
$checks = [];

$paths = [
    'client' => $root . '/app/OmieClient.php',
    'omie_repository' => $root . '/app/OmieRepository.php',
    'coordinator' => $root . '/app/Sync/FiscalSyncCoordinator.php',
    'mapper' => $root . '/app/FiscalSync/FiscalCanonicalMapper.php',
    'fiscal_support' => $root . '/app/FiscalSync/FiscalSupport.php',
    'canonical_write' => $root . '/app/Canonical/CanonicalWriteRepository.php',
    'canonical_hashing' => $root . '/app/Canonical/Support/CanonicalHashing.php',
    'canonical_facade' => $root . '/app/CanonicalRepository.php',
    'backfill_facade' => $root . '/bin/omie_nfe_backfill.php',
    'cli' => $root . '/bin/biomie',
    'schema' => $root . '/database/migrations/sql/Version20260413050100_BaselineCanonicalSchema.sql',
    'operation_lineage_migration' => $root . '/database/migrations/Version20260719010000_InvoiceOrderOperationLineage.php',
];

$sources = [];
foreach ($paths as $id => $path) {
    $sources[$id] = nfbcaRead($path);
    nfbcaCheck($checks, 'file_' . $id, $sources[$id] !== '', 'Arquivo de contrato disponivel.', [
        'file' => nfbcaRelativePath($root, $path),
    ]);
}

$clientPost = nfbcaMethodBody($sources['client'], 'post');
$issueDateReader = nfbcaMethodBody($sources['omie_repository'], 'listInvoicesByIssueDate');
$baseFilters = nfbcaMethodBody($sources['omie_repository'], 'baseInvoiceFilters');
$pagination = nfbcaMethodBody($sources['omie_repository'], 'paginateInvoices');
$syncInvoices = nfbcaMethodBody($sources['coordinator'], 'syncInvoices');
$invoiceWindow = nfbcaMethodBody($sources['coordinator'], 'processInvoiceWindow');
$nfOnly = nfbcaMethodBody($sources['coordinator'], 'backfillInvoicesOnly');
$batchUpsert = nfbcaMethodBody($sources['canonical_write'], 'upsertInvoicesBatch');
$invoiceUpsert = nfbcaMethodBody($sources['canonical_write'], 'upsertInvoice');
$orderOperationUpdate = nfbcaMethodBody($sources['canonical_write'], 'tryUpdateInvoiceOrderOperation');
$manualVisibility = nfbcaMethodBody($sources['canonical_write'], 'existingManualInvoiceVisibility');
$canonicalBatchFacade = nfbcaMethodBody($sources['canonical_facade'], 'upsertInvoicesBatch');
$sourceNormalizer = nfbcaMethodBody($sources['mapper'], 'canonicalStorageSource');
$invoiceMapper = nfbcaMethodBody($sources['mapper'], 'normalizeInvoiceToCanonical');
$fiscalStatusClassifier = nfbcaMethodBody($sources['fiscal_support'], 'classifyInvoiceFiscalStatus');

// OmieClient: o campo param deve ser uma lista contendo exatamente o objeto de
// parametros. A API nao aceita o objeto solto nesse envelope.
nfbcaCheck(
    $checks,
    'omie_envelope_param_array',
    $clientPost !== null
        && preg_match('~[\'\"]param[\'\"]\s*=>\s*\[\s*\$params\s*\]~', $clientPost) === 1,
    'OmieClient envia param como lista com um objeto: param => [$params].',
);
nfbcaCheck(
    $checks,
    'omie_envelope_serialized_as_payload',
    $clientPost !== null
        && str_contains($clientPost, 'CURLOPT_POSTFIELDS')
        && preg_match('~json_encode\s*\(\s*\$payload~', $clientPost) === 1,
    'O payload completo, incluindo param, e serializado no POST.',
);

// Recorte documental: backfill de autorizadas e canceladas usa a emissao do
// documento durante o dia inteiro; status, ambiente e tipo ficam explicitos.
nfbcaCheck(
    $checks,
    'issue_date_full_day_filter',
    nfbcaContainsAll($issueDateReader, [
        "['dEmiInicial']",
        "['hEmiInicial'] = '00:00:00'",
        "['dEmiFinal']",
        "['hEmiFinal'] = '23:59:59'",
        'paginateInvoices',
    ]) && !str_contains((string) $issueDateReader, 'dCanInicial'),
    'A facade historica recorta ambos os status por dEmi, de 00:00:00 a 23:59:59.',
);
nfbcaCheck(
    $checks,
    'invoice_fixed_filters_static',
    nfbcaContainsAll($baseFilters, [
        "'filtrar_por_status'",
        "'tpNF' => '1'",
        "'tpAmb' => '1'",
        "'cDetalhesPedido' => 'S'",
        "'cApenasResumo' => 'N'",
    ]),
    'ListarNF fixa status, saida (tpNF=1), producao (tpAmb=1) e detalhe completo.',
);

// A travessia so e aceita quando todas as paginas e todos os registros
// anunciados pelo provedor foram recebidos.
nfbcaCheck(
    $checks,
    'pagination_walks_all_pages',
    nfbcaContainsAll($pagination, [
        "'ListarNF'",
        "'pagina' => \$page",
        "'registros_por_pagina' => \$pageSize",
        "['nfCadastro']",
        "['total_de_paginas']",
        '$totalPages = max(',
        '$page++',
        'while ($page <= $totalPages)',
    ]),
    'A leitura percorre nfCadastro ate a maior total_de_paginas observada.',
);
nfbcaCheck(
    $checks,
    'pagination_validates_total_records',
    nfbcaContainsAll($pagination, [
        "array_key_exists('total_de_registros'",
        "['total_de_registros']",
        'count($invoices) !== $expectedRecords',
        'RuntimeException',
    ]),
    'O lote e rejeitado quando recebidos difere de total_de_registros.',
);

// O caminho historico e diario consulta N e C pela mesma base de emissao. A
// persistencia fiscal e incremental em todos os fluxos: substituir uma janela
// e inseguro porque a passagem C operacional usa data de cancelamento, enquanto
// data_competencia permanece sendo a emissao.
nfbcaCheck(
    $checks,
    'backfill_daily_windows',
    nfbcaContainsAll($syncInvoices, [
        '$this->isBackfillSource($source) ? 1',
        '$this->windowRanges($start, $end, $windowDays)',
    ]),
    'Intervalos historicos sao quebrados em janelas de um dia.',
);
nfbcaCheck(
    $checks,
    'backfill_reads_both_statuses_by_issue_date',
    nfbcaContainsAll($invoiceWindow, [
        "foreach (['N', 'C'] as \$status)",
        '$isBackfill',
        'listInvoicesByIssueDate($start, $end, $status)',
    ]),
    'Backfill executa passes N e C por data de emissao.',
);
nfbcaCheck(
    $checks,
    'backfill_selects_incremental_upsert',
    str_contains($invoiceWindow, '$this->canonical->upsertInvoicesBatch($normalized)')
        && !str_contains($invoiceWindow, 'replaceInvoicesWindow('),
    'A persistencia fiscal usa batch upsert e nao substitui destrutivamente a janela.',
);

// O batch deve ser atomico e estritamente incremental. A analise e feita no
// corpo do metodo, portanto DELETE existente no metodo legado nao gera falso
// positivo.
nfbcaCheck(
    $checks,
    'batch_upsert_is_transactional',
    nfbcaContainsAll($batchUpsert, [
        'inTransaction()',
        'beginTransaction',
        '$this->upsertInvoice($invoice)',
        '$pdo->commit()',
        '$pdo->rollBack()',
    ]),
    'O lote chama o upsert natural dentro de transacao com rollback em falha.',
);
nfbcaCheck(
    $checks,
    'batch_upsert_never_deletes_or_replaces',
    $batchUpsert !== null
        && preg_match('~\bDELETE\s+FROM\b|replaceInvoicesWindow|replacePendingOrdersWindow~i', $batchUpsert) !== 1,
    'O batch historico nao contem delete nem substituicao de janela.',
);
nfbcaCheck(
    $checks,
    'backfill_preserves_manual_channel_override',
    nfbcaContainsAll($invoiceUpsert, [
        'existingManualInvoiceVisibility($row)',
        'resolveInvoiceVisibility($invoice)',
    ]) && nfbcaContainsAll($manualVisibility, [
        "channel_confidence'] ?? ''",
        "!== 'manual'",
        "'confidence' => 'manual'",
    ]),
    'Backfill fiscal preserva classificacao manual de canal ja existente.',
);
nfbcaCheck(
    $checks,
    'backfill_preserves_order_operation_lineage',
    nfbcaContainsAll($invoiceMapper, [
        "['pedido.opPedido']",
        "'pedido_operacao_omie'",
    ])
        && nfbcaContainsAll($invoiceUpsert, [
            'tryUpdateInvoiceOrderOperation($noteKey, $invoice)',
        ])
        && nfbcaContainsAll($orderOperationUpdate, [
            "checkColumnExists(",
            "'pedido_operacao_omie'",
            'SET pedido_operacao_omie = :order_operation',
        ])
        && nfbcaContainsAll($sources['canonical_hashing'], [
            "'pedido_operacao_omie'",
        ])
        && nfbcaContainsAll($sources['operation_lineage_migration'], [
            'ADD COLUMN pedido_operacao_omie VARCHAR(64)',
            'idx_nf_company_order_operation',
        ]),
    'pedido.opPedido permanece rastreavel em coluna canonica propria e participa do upsert idempotente.',
);
nfbcaCheck(
    $checks,
    'optional_invoice_lineage_is_zero_downtime_safe',
    !str_contains((string) $invoiceUpsert, ':pedido_operacao_omie')
        && !str_contains((string) $invoiceUpsert, ':projeto_id_omie')
        && str_contains((string) $invoiceUpsert, 'tryUpdateInvoiceProject($noteKey, $invoice)')
        && str_contains((string) $invoiceUpsert, 'tryUpdateInvoiceOrderOperation($noteKey, $invoice)'),
    'Código novo pode entrar antes das migrations de lineage; campos opcionais são enriquecidos após a coluna existir.',
);
nfbcaCheck(
    $checks,
    'canonical_facade_exposes_batch_upsert',
    nfbcaContainsAll($canonicalBatchFacade, [
        'withReconnect',
        'writeRepository()->upsertInvoicesBatch($invoices)',
    ]),
    'A facade canonica encaminha o batch incremental com reconexao.',
);

// Escopo de entidade: a entrada publica valida modo dry-run por padrao e chama
// uma facade que sincroniza apenas notas, sem carteira ou estagios de pedido.
nfbcaCheck(
    $checks,
    'nf_only_service_facade',
    nfbcaContainsAll($nfOnly, [
        '$this->isBackfillSource($source)',
        '$this->syncInvoices($start, $end, $source)',
    ]) && !nfbcaContainsAny($nfOnly, [
        'syncPendingOrders',
        'replacePendingOrdersWindow',
        'processPendingOrder',
    ]),
    'backfillInvoicesOnly limita a mutacao ao dominio fiscal.',
);
nfbcaCheck(
    $checks,
    'nf_only_cli_is_safe_by_default',
    nfbcaContainsAll($sources['backfill_facade'], [
        '$dryRun = !$confirmed || $explicitDryRun',
        "'entity_scope' => 'invoices_only'",
        "'mutation_strategy' => 'idempotent_upsert'",
        "'excluded_mutations' => ['pending_orders', 'order_events', 'order_stages']",
        '$service->backfillInvoicesOnly($start, $end, \'cron-backfill\')',
    ]) && nfbcaDryRunPrecedesRuntime($sources['backfill_facade']),
    'CLI exige --yes; sem confirmacao sai antes de criar cliente Omie ou gravar dados.',
);
nfbcaCheck(
    $checks,
    'biomie_cli_routes_to_nf_only_facade',
    str_contains($sources['cli'], "'omie:nfe:backfill' => runTool(")
        && str_contains($sources['cli'], "'bin/omie_nfe_backfill.php'"),
    'bin/biomie roteia omie:nfe:backfill para a facade NF-only.',
);

// O schema aceita somente tres origens. A origem operacional cron-backfill
// precisa ser normalizada antes do INSERT para nao violar o ENUM.
$invoiceSchema = nfbcaSqlTableBody($sources['schema'], 'biomie_notas_fiscais_canonicas');
nfbcaCheck(
    $checks,
    'invoice_source_enum_contract',
    $invoiceSchema !== null
        && preg_match("~fonte_sync\s+ENUM\(\s*'cron'\s*,\s*'manual'\s*,\s*'webhook'\s*\)~i", $invoiceSchema) === 1,
    'fonte_sync de NF aceita cron, manual ou webhook.',
);
nfbcaCheck(
    $checks,
    'backfill_source_is_normalized_before_write',
    $invoiceMapper !== null
        && str_contains($invoiceMapper, "'fonte_sync' => \$this->canonicalStorageSource(\$source)")
        && nfbcaContainsAll($sourceNormalizer, [
            "str_contains(\$normalized, 'webhook')",
            "return 'webhook'",
            "str_contains(\$normalized, 'manual')",
            "return 'manual'",
            "return 'cron'",
        ]),
    'cron-backfill e normalizado para cron antes do upsert.',
);

// O eixo historico da NF e sempre a emissao. Cancelamento e preservado em
// campo proprio e nunca desloca a competencia/receita para o dia do evento.
nfbcaCheck(
    $checks,
    'invoice_competence_is_issue_date',
    nfbcaContainsAll($invoiceMapper, [
        "['ide.dEmi', 'dEmi']",
        "['ide.dCan', 'dCan']",
        "'data_emissao' => \$issueDate",
        "'data_cancelamento' => \$cancelDate",
        "'data_competencia' => \$issueDate",
    ]),
    'Competencia fiscal permanece na emissao; cancelamento usa eixo proprio.',
);

// A autorizacao e fail-closed: codigo positivo ou evidencia fiscal valida sao
// obrigatorios. Sinais de cancelamento/denegacao/rejeicao vencem e o fallback
// final nunca promove um documento apenas porque o pedido foi faturado.
nfbcaCheck(
    $checks,
    'invoice_status_fail_closed_static',
    nfbcaContainsAll($fiscalStatusClassifier, [
        "self::INVOICE_CANCELLED",
        "self::INVOICE_NOT_AUTHORIZED",
        "['100', '150']",
        "['101', '151', '155']",
        "'compl.cChaveNFe', 'cChaveNFe', 'chave_nfe'",
        "return self::INVOICE_NOT_AUTHORIZED;",
    ]),
    'Classificador fiscal exige evidencia positiva e termina fechado como nao_autorizada.',
);

// Provas funcionais leves: invocam apenas funcoes puras privadas em objetos sem
// construtor. Nao ha credencial, conexao, rede ou escrita envolvida.
try {
    $repository = (new ReflectionClass(OmieRepository::class))->newInstanceWithoutConstructor();
    $filterMethod = new ReflectionMethod(OmieRepository::class, 'baseInvoiceFilters');
    $authorizedFilters = $filterMethod->invoke($repository, 'n');
    $cancelledFilters = $filterMethod->invoke($repository, 'C');
    $filtersOk = is_array($authorizedFilters)
        && is_array($cancelledFilters)
        && ($authorizedFilters['filtrar_por_status'] ?? null) === 'N'
        && ($cancelledFilters['filtrar_por_status'] ?? null) === 'C'
        && ($authorizedFilters['tpNF'] ?? null) === '1'
        && ($authorizedFilters['tpAmb'] ?? null) === '1';
    nfbcaCheck($checks, 'pure_filter_function_probe', $filtersOk, 'Probe puro confirma filtros N/C, tpNF e tpAmb.', [
        'authorized' => $authorizedFilters,
        'cancelled' => $cancelledFilters,
    ]);
} catch (Throwable $exception) {
    nfbcaCheck($checks, 'pure_filter_function_probe', false, 'Falha ao executar probe puro dos filtros.', [
        'error' => $exception->getMessage(),
    ]);
}

try {
    $statusSupport = new FiscalSupport();
    $failClosedUnknown = $statusSupport->classifyInvoiceFiscalStatus([], false, [])
        === FiscalSupport::INVOICE_NOT_AUTHORIZED;
    $failClosedOrderOnly = $statusSupport->classifyInvoiceFiscalStatus(
        ['compl' => ['nIdPedido' => '42']],
        false,
        ['id:42' => ['authorized' => true, 'cancelled' => false, 'invoiced' => true, 'denied' => false, 'devolved' => false]],
    ) === FiscalSupport::INVOICE_NOT_AUTHORIZED;
    $authorized = $statusSupport->classifyInvoiceFiscalStatus(
        ['protNFe' => ['infProt' => ['cStat' => '100']]],
        false,
        [],
    ) === FiscalSupport::INVOICE_AUTHORIZED;
    $cancelled = $statusSupport->classifyInvoiceFiscalStatus([], true, [])
        === FiscalSupport::INVOICE_CANCELLED;
    $unknownNumeric = $statusSupport->classifyInvoiceFiscalStatus(
        ['protNFe' => ['infProt' => ['cStat' => '110']]],
        false,
        [],
    ) === FiscalSupport::INVOICE_NOT_AUTHORIZED;
    nfbcaCheck(
        $checks,
        'pure_fiscal_status_fail_closed_probe',
        $failClosedUnknown && $failClosedOrderOnly && $authorized && $cancelled && $unknownNumeric,
        'Probe puro confirma autorizada/cancelada e fallback fiscal fail-closed.',
        [
            'unknown_is_not_authorized' => $failClosedUnknown,
            'order_only_is_not_authorized' => $failClosedOrderOnly,
            'sefaz_100_is_authorized' => $authorized,
            'cancelled_listing_wins' => $cancelled,
            'unknown_numeric_is_not_authorized' => $unknownNumeric,
        ],
    );
} catch (Throwable $exception) {
    nfbcaCheck($checks, 'pure_fiscal_status_fail_closed_probe', false, 'Falha no probe puro de status fiscal.', [
        'error' => $exception->getMessage(),
    ]);
}

try {
    $mapper = (new ReflectionClass(FiscalCanonicalMapper::class))->newInstanceWithoutConstructor();
    $normalizerMethod = new ReflectionMethod(FiscalCanonicalMapper::class, 'canonicalStorageSource');
    $normalization = [
        'cron-backfill' => $normalizerMethod->invoke($mapper, 'cron-backfill'),
        'manual-recovery' => $normalizerMethod->invoke($mapper, 'manual-recovery'),
        'omie-webhook' => $normalizerMethod->invoke($mapper, 'omie-webhook'),
    ];
    nfbcaCheck(
        $checks,
        'pure_source_enum_probe',
        $normalization === [
            'cron-backfill' => 'cron',
            'manual-recovery' => 'manual',
            'omie-webhook' => 'webhook',
        ],
        'Probe puro confirma a normalizacao para o ENUM persistido.',
        $normalization,
    );
} catch (Throwable $exception) {
    nfbcaCheck($checks, 'pure_source_enum_probe', false, 'Falha ao executar probe puro de fonte.', [
        'error' => $exception->getMessage(),
    ]);
}

try {
    $coordinator = (new ReflectionClass(FiscalSyncCoordinator::class))->newInstanceWithoutConstructor();
    $rangeMethod = new ReflectionMethod(FiscalSyncCoordinator::class, 'windowRanges');
    $ranges = $rangeMethod->invoke($coordinator, '2026-01-30', '2026-02-02', 1);
    $expectedRanges = [
        ['2026-01-30', '2026-01-30'],
        ['2026-01-31', '2026-01-31'],
        ['2026-02-01', '2026-02-01'],
        ['2026-02-02', '2026-02-02'],
    ];
    nfbcaCheck(
        $checks,
        'pure_daily_window_probe',
        $ranges === $expectedRanges,
        'Probe puro confirma janelas diarias inclusive na troca de mes.',
        ['ranges' => $ranges],
    );
} catch (Throwable $exception) {
    nfbcaCheck($checks, 'pure_daily_window_probe', false, 'Falha ao executar probe puro das janelas.', [
        'error' => $exception->getMessage(),
    ]);
}

$failed = array_values(array_filter(
    $checks,
    static fn (array $check): bool => !$check['ok'],
));
$report = [
    'ok' => $failed === [],
    'audit' => 'nfe_backfill_contract',
    'generated_at' => date(DATE_ATOM),
    'execution' => [
        'network_access' => false,
        'database_access' => false,
        'external_writes' => false,
        'mode' => 'static_and_pure_function_probes',
    ],
    'summary' => [
        'checks' => count($checks),
        'passed' => count($checks) - count($failed),
        'failed' => count($failed),
        'failed_ids' => array_values(array_map(
            static fn (array $check): string => (string) $check['id'],
            $failed,
        )),
    ],
    'checks' => $checks,
];

if ($pretty) {
    echo 'NF-e backfill contract audit' . PHP_EOL;
    foreach ($checks as $check) {
        echo sprintf(
            '[%s] %-42s %s',
            $check['ok'] ? 'OK' : 'FAIL',
            $check['id'],
            $check['message'],
        ) . PHP_EOL;
    }
    echo sprintf(
        'Resultado: %d/%d checks aprovados; rede=nao, banco=nao, writes=nao.',
        $report['summary']['passed'],
        $report['summary']['checks'],
    ) . PHP_EOL;
} else {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

exit($report['ok'] ? 0 : 1);

function nfbcaRead(string $path): string
{
    if (!is_file($path) || !is_readable($path)) {
        return '';
    }

    $contents = file_get_contents($path);
    return is_string($contents) ? $contents : '';
}

function nfbcaRelativePath(string $root, string $path): string
{
    $prefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
    $normalized = str_replace('\\', '/', $path);
    return str_starts_with($normalized, $prefix) ? substr($normalized, strlen($prefix)) : $normalized;
}

/**
 * Extrai o corpo de um metodo pelo tokenizador PHP, sem confundir chaves em
 * strings ou comentarios e sem depender de reflexao do codigo sob auditoria.
 */
function nfbcaMethodBody(string $source, string $method): ?string
{
    if ($source === '') {
        return null;
    }

    $tokens = token_get_all($source);
    $awaitingName = false;
    $awaitingBody = false;
    $depth = 0;
    $body = '';

    foreach ($tokens as $token) {
        $text = is_array($token) ? $token[1] : $token;
        $id = is_array($token) ? $token[0] : null;

        if ($depth > 0) {
            if ($text === '{') {
                $depth++;
            } elseif ($text === '}') {
                $depth--;
                if ($depth === 0) {
                    return $body;
                }
            }
            if ($depth > 0) {
                $body .= $text;
            }
            continue;
        }

        if ($awaitingBody) {
            if ($text === '{') {
                $depth = 1;
                $body = '';
            }
            continue;
        }

        if ($awaitingName) {
            if ($id === T_STRING) {
                $awaitingName = false;
                $awaitingBody = $text === $method;
                continue;
            }
            if ($text === '(') {
                // Closure/funcao anonima.
                $awaitingName = false;
            }
            continue;
        }

        if ($id === T_FUNCTION) {
            $awaitingName = true;
        }
    }

    return null;
}

/** @param list<string> $needles */
function nfbcaContainsAll(?string $haystack, array $needles): bool
{
    if ($haystack === null) {
        return false;
    }
    foreach ($needles as $needle) {
        if (!str_contains($haystack, $needle)) {
            return false;
        }
    }
    return true;
}

/** @param list<string> $needles */
function nfbcaContainsAny(?string $haystack, array $needles): bool
{
    if ($haystack === null) {
        return false;
    }
    foreach ($needles as $needle) {
        if (str_contains($haystack, $needle)) {
            return true;
        }
    }
    return false;
}

/**
 * @param list<array{id:string,ok:bool,message:string,evidence:array<string,mixed>}> $checks
 * @param array<string,mixed> $evidence
 */
function nfbcaCheck(array &$checks, string $id, bool $ok, string $message, array $evidence = []): void
{
    $checks[] = [
        'id' => $id,
        'ok' => $ok,
        'message' => $message,
        'evidence' => $evidence,
    ];
}

function nfbcaDryRunPrecedesRuntime(string $source): bool
{
    $dryRunPosition = strpos($source, 'if ($dryRun)');
    $clientPosition = strpos($source, 'new OmieRepository');
    $servicePosition = strpos($source, 'new OmieFiscalSyncService');

    return $dryRunPosition !== false
        && $clientPosition !== false
        && $servicePosition !== false
        && $dryRunPosition < $clientPosition
        && $dryRunPosition < $servicePosition;
}

function nfbcaSqlTableBody(string $source, string $table): ?string
{
    $pattern = '~CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+'
        . preg_quote($table, '~')
        . '\s*\((.*?)\)\s*ENGINE\s*=~is';
    if (preg_match($pattern, $source, $matches) !== 1) {
        return null;
    }

    return (string) $matches[1];
}
