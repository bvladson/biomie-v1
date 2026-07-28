<?php

declare(strict_types=1);

namespace BIomie\AdminConsole\Commands;

use BIomie\AdminConsole\AdminCommandContext;
use BIomie\AdminConsole\AdminCommandInterface;
use BIomie\AdminConsole\AdminCommandResult;
use BIomie\Bootstrap;
use BIomie\Cache;
use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;
use BIomie\OmieClient;
use BIomie\Omie\OmieClientFactory;
use BIomie\Support\SecretMask;
use BIomie\Support\TenantContext;
use PDO;
use Throwable;

/**
 * Diagnóstico do payload Omie para clientes.
 *
 * Seção 1 — Diagnóstico geral da API (página 1, N registros):
 *   confirma quais campos o Omie devolve e se extractFields() está correto.
 *
 * Seção 2 — Amostra local consultada (ConsultarCliente por código):
 *   para cada cliente real de biomie_clientes_dim, faz lookup direto na Omie
 *   e mostra se está apto para geo:materialize.
 *   Nunca compara com página 1 — sem falso alarme.
 */
final class ClientesOmieSampleCommand implements AdminCommandInterface
{
    private const ENDPOINT = 'https://app.omie.com.br/api/v1/geral/clientes/';

    /**
     * Campos que extractFields() lê da resposta Omie.
     * cnae_fiscal é preferencial; cnae é fallback aceito.
     */
    private const EXPECTED_ADDRESS_KEYS = [
        'cidade', 'estado', 'bairro', 'cep',
        'endereco', 'endereco_numero', 'complemento',
    ];

    /** cnae_fiscal OU cnae — pelo menos um deve existir */
    private const SENSITIVE_KEYS = ['app_key', 'app_secret', 'password', 'token', 'secret'];

    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly string $connectionName,
    ) {}

    public function name(): string        { return 'clientes:omie-sample'; }
    public function description(): string { return 'Inspeciona payload Omie de clientes e diagnostica aptidao para geo:materialize.'; }
    public function group(): string       { return 'clientes'; }
    public function permission(): string  { return 'admin.console'; }
    public function isDestructive(): bool { return false; }
    public function requiresConfirmation(): bool { return false; }

    /** @param array<string,string> $args */
    public function validateArgs(array $args): ?string
    {
        $limit = (int) ($args['limit'] ?? 3);
        if ($limit < 1 || $limit > 5) {
            return 'Parametro limit deve ser entre 1 e 5.';
        }
        return null;
    }

    /** @param array<string,string> $args */
    public function run(AdminCommandContext $context, array $args = []): AdminCommandResult
    {
        $limit     = max(1, min(5, (int) ($args['limit'] ?? 3)));
        $startedAt = microtime(true);

        $this->logger->info('clientes.omie_sample.started', [
            'user_id'    => $context->userId,
            'request_id' => $context->requestId,
            'limit'      => $limit,
        ]);

        try {
            $pdo   = Database::connect($this->config, $this->connectionName);
            $cache = new Cache(Bootstrap::rootPath('storage/cache'));
            $client = (new OmieClientFactory($pdo, $this->logger, $this->config, $cache))
                ->forCompany(TenantContext::companyId());

            $lines    = [];
            $warnings = [];
            $metrics  = [];

            // ══════════════════════════════════════════════════════════════════
            // SEÇÃO 1 — Diagnóstico geral da API (página 1)
            // ══════════════════════════════════════════════════════════════════
            $lines[] = str_repeat('═', 60);
            $lines[] = "Secao 1 — Diagnostico geral da API Omie (pagina 1, limit {$limit})";
            $lines[] = str_repeat('═', 60);

            $sec1Result = $this->runSection1($client, $limit);

            $lines   = array_merge($lines, $sec1Result['lines']);
            $warnings = array_merge($warnings, $sec1Result['warnings']);
            $metrics['total_omie']    = $sec1Result['total_omie'];
            $metrics['total_paginas'] = $sec1Result['total_paginas'];
            $metrics['api_returned']  = $sec1Result['returned'];

            // ══════════════════════════════════════════════════════════════════
            // SEÇÃO 2 — Amostra local consultada via ConsultarCliente
            // ══════════════════════════════════════════════════════════════════
            $lines[] = '';
            $lines[] = str_repeat('═', 60);
            $lines[] = "Secao 2 — Amostra local consultada ({$limit} clientes de biomie_clientes_dim)";
            $lines[] = str_repeat('═', 60);

            $localClients = $this->fetchLocalClients($pdo, $limit);

            if ($localClients === []) {
                $lines[]  = 'Nenhum cliente com codigo_cliente_omie encontrado em biomie_clientes_dim.';
                $warnings[] = 'biomie_clientes_dim vazia ou sem codigo_cliente_omie preenchido.';
            } else {
                $sec2Stats = $this->runSection2($client, $localClients, $lines, $warnings);
                $metrics = array_merge($metrics, $sec2Stats);
            }

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            $lines[] = '';
            $lines[] = 'Integracao: tenant:' . TenantContext::slug();

            $metrics['duration_ms'] = $durationMs;

            $this->logger->info('clientes.omie_sample.finished', [
                'user_id'     => $context->userId,
                'request_id'  => $context->requestId,
                'metrics'     => $metrics,
            ]);

            if ($warnings !== []) {
                return AdminCommandResult::warning(
                    'Amostra Omie Clientes',
                    "API OK — {$metrics['api_returned']} amostrados; {$metrics['local_found']} locais encontrados na Omie.",
                    outputLines: $lines,
                    warnings: $warnings,
                )->withDurationMs($durationMs);
            }

            return AdminCommandResult::ok(
                'Amostra Omie Clientes',
                "API OK — {$metrics['api_returned']} amostrados; {$metrics['local_found']} locais encontrados na Omie.",
                outputLines: $lines,
                metrics: $metrics,
            )->withDurationMs($durationMs);

        } catch (Throwable $e) {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            $this->logger->error('clientes.omie_sample.failed', [
                'user_id'     => $context->userId,
                'request_id'  => $context->requestId,
                'message'     => $e->getMessage(),
                'duration_ms' => $durationMs,
            ]);

            return AdminCommandResult::error(
                'Amostra Omie Clientes',
                'Nao foi possivel buscar a amostra da API Omie.',
                errors: [SecretMask::sanitizeErrorMessage($e->getMessage(), 300)],
            );
        }
    }

    // ── Section 1: general API diagnostic ────────────────────────────────────

    /**
     * @return array{lines:list<string>,warnings:list<string>,total_omie:int,total_paginas:int,returned:int}
     */
    private function runSection1(OmieClient $client, int $limit): array
    {
        $lines    = [];
        $warnings = [];

        try {
            $response = $client->post(self::ENDPOINT, 'ListarClientes', [
                'pagina'               => 1,
                'registros_por_pagina' => $limit,
                'apenas_importado_api' => 'N',
            ]);
        } catch (Throwable $e) {
            $warnings[] = 'Falha ao chamar ListarClientes: ' . SecretMask::sanitizeErrorMessage($e->getMessage(), 200);
            return ['lines' => $lines, 'warnings' => $warnings, 'total_omie' => 0, 'total_paginas' => 0, 'returned' => 0];
        }

        $clientes   = $response['clientes_cadastro'] ?? [];
        $totalPages = (int) ($response['total_de_paginas'] ?? 0);
        $totalReg   = (int) ($response['total_de_registros'] ?? count($clientes));

        $lines[] = "Total Omie: {$totalReg} clientes em {$totalPages} paginas";
        $lines[] = '';

        if ($clientes === []) {
            $warnings[] = 'ListarClientes nao retornou registros. Verifique as credenciais.';
            return ['lines' => $lines, 'warnings' => $warnings, 'total_omie' => $totalReg, 'total_paginas' => $totalPages, 'returned' => 0];
        }

        $allKeys = [];

        foreach ($clientes as $idx => $raw) {
            $num        = $idx + 1;
            $total      = count($clientes);
            $codigoOmie = (int) ($raw['codigo_cliente_omie'] ?? 0);

            $lines[] = str_repeat('─', 50);
            $lines[] = "[{$num}/{$total}] codigo_omie: {$codigoOmie}";

            foreach (array_keys($raw) as $k) {
                $allKeys[$k] = true;
            }

            $cidade      = trim((string) ($raw['cidade'] ?? ''));
            $estado      = trim((string) ($raw['estado'] ?? ''));
            $bairro      = trim((string) ($raw['bairro'] ?? ''));
            $endereco    = trim((string) ($raw['endereco'] ?? ''));
            $numero      = trim((string) ($raw['endereco_numero'] ?? ''));
            $complemento = trim((string) ($raw['complemento'] ?? ''));
            $cep         = trim((string) ($raw['cep'] ?? ''));
            $cnae        = trim((string) ($raw['cnae_fiscal'] ?? ''));

            $lines[] = '  Razao Social   : ' . $this->strOrEmpty($raw['razao_social'] ?? '');
            $lines[] = '  Nome Fantasia  : ' . $this->strOrEmpty($raw['nome_fantasia'] ?? '');
            $lines[] = '  CNPJ/CPF       : ' . $this->maskDocument($raw['cnpj_cpf'] ?? '');
            $lines[] = '  Cidade/UF      : ' . ($cidade !== '' ? "{$cidade} / {$estado}" : '(vazio)');
            $lines[] = '  Bairro         : ' . $this->strOrEmpty($bairro);
            $lines[] = '  Endereco       : ' . $this->strOrEmpty($endereco);
            $lines[] = '  Numero         : ' . $this->strOrEmpty($numero);
            $lines[] = '  Complemento    : ' . $this->strOrEmpty($complemento);
            $lines[] = '  CEP            : ' . $this->maskCep($cep);
            $lines[] = '  CNAE           : ' . $this->strOrEmpty($cnae);

            foreach ($this->geoStatusLines($cidade, $bairro, $cnae) as $statusLine) {
                $lines[] = '  ' . $statusLine;
            }
            $lines[] = '';
        }

        // Key inventory
        $lines[] = str_repeat('─', 50);
        $lines[] = 'Chaves disponiveis no objeto retornado pela Omie:';
        $safeKeys = array_filter(array_keys($allKeys), fn (string $k): bool => !$this->isSensitiveKey($k));
        $lines[]  = '  ' . implode(', ', $safeKeys);
        $lines[]  = '';

        $missingExpected = array_diff(self::EXPECTED_ADDRESS_KEYS, array_keys($allKeys));
        if ($missingExpected !== []) {
            $warnings[] = 'Campos esperados por extractFields() NAO encontrados na resposta Omie: '
                . implode(', ', $missingExpected);
            $lines[] = '[ATENCAO] Campos ausentes: ' . implode(', ', $missingExpected);
        } else {
            $lines[] = 'Todos os campos de endereco esperados estao presentes.';
        }

        // CNAE: accept cnae_fiscal or cnae
        $hasCnaeFiscal = isset($allKeys['cnae_fiscal']);
        $hasCnae       = isset($allKeys['cnae']);
        if (!$hasCnaeFiscal && !$hasCnae) {
            $warnings[] = 'Nenhum campo CNAE encontrado (cnae_fiscal ou cnae). CNAE nao sera preenchido.';
            $lines[] = '[ATENCAO] Nenhum campo CNAE retornado pela Omie.';
        } elseif (!$hasCnaeFiscal) {
            $lines[] = 'Campo cnae_fiscal ausente, usando cnae como fallback.';
        } else {
            $lines[] = 'Campo CNAE: cnae_fiscal presente.';
        }

        return [
            'lines'        => $lines,
            'warnings'     => $warnings,
            'total_omie'   => $totalReg,
            'total_paginas'=> $totalPages,
            'returned'     => count($clientes),
        ];
    }

    // ── Section 2: local sample via ConsultarCliente ──────────────────────────

    /**
     * Modifies $lines and $warnings by reference; returns stats array.
     *
     * @param list<array<string,mixed>> $localClients
     * @param list<string>              $lines
     * @param list<string>              $warnings
     * @return array<string,int>
     */
    private function runSection2(OmieClient $client, array $localClients, array &$lines, array &$warnings): array
    {
        $stats = [
            'local_found'      => 0,
            'local_not_found'  => 0,
            'local_api_error'  => 0,
            'local_no_address' => 0,
            'local_sem_bairro' => 0,
            'local_sem_cnae'   => 0,
            'local_geo_ok'     => 0,
        ];

        $total = count($localClients);

        foreach ($localClients as $idx => $local) {
            $num        = $idx + 1;
            $codigoOmie = (int) $local['codigo_cliente_omie'];
            $nomeLocal  = $this->strOrEmpty((string) ($local['nome_cliente'] ?? ''));

            $lines[] = str_repeat('─', 50);
            $lines[] = "[{$num}/{$total}] codigo_omie: {$codigoOmie} — {$nomeLocal}";

            // Show local dim state
            $localCidade = $this->strOrEmpty((string) ($local['cidade'] ?? ''));
            $localBairro = $this->strOrEmpty((string) ($local['bairro'] ?? ''));
            $localCnae   = $this->strOrEmpty((string) ($local['cnae_codigo'] ?? ''));
            $localStatus = $this->strOrEmpty((string) ($local['enrichment_status'] ?? ''));
            $lines[] = "  Local dim: cidade={$localCidade} bairro={$localBairro} cnae={$localCnae} status={$localStatus}";

            // ConsultarCliente
            $lookup = $this->consultarCliente($client, $codigoOmie);

            if (!$lookup['ok']) {
                // API error (not "not found")
                $stats['local_api_error']++;
                $safeErr = SecretMask::sanitizeErrorMessage($lookup['error'], 150);
                $lines[]  = "  encontrado_na_omie  : erro — {$safeErr}";
                $warnings[] = "Erro ao consultar cliente {$codigoOmie}: {$safeErr}";
                $lines[]  = '';
                continue;
            }

            if (!$lookup['found']) {
                $stats['local_not_found']++;
                $lines[] = '  encontrado_na_omie  : nao';
                $lines[] = '  Codigo Omie nao localizado na API de clientes.';
                $warnings[] = "Cliente {$codigoOmie} ({$nomeLocal}) nao encontrado na API Omie.";
                $lines[]  = '';
                continue;
            }

            $stats['local_found']++;
            $raw = $lookup['data'];

            $cidade      = trim((string) ($raw['cidade'] ?? ''));
            $estado      = trim((string) ($raw['estado'] ?? ''));
            $bairro      = trim((string) ($raw['bairro'] ?? ''));
            $cnae        = trim((string) ($raw['cnae_fiscal'] ?? ''));
            $cep         = trim((string) ($raw['cep'] ?? ''));

            $lines[] = '  encontrado_na_omie  : sim';
            $lines[] = '  Omie cidade/UF      : ' . ($cidade !== '' ? "{$cidade} / {$estado}" : '(vazio)');
            $lines[] = '  Omie bairro         : ' . $this->strOrEmpty($bairro);
            $lines[] = '  Omie CEP            : ' . $this->maskCep($cep);
            $lines[] = '  Omie CNAE           : ' . $this->strOrEmpty($cnae);
            $lines[] = '  Omie CNPJ/CPF       : ' . $this->maskDocument($raw['cnpj_cpf'] ?? '');

            // Geo status prediction
            [$geoStatus, $geoLines] = $this->geoStatusPrediction($cidade, $bairro, $cnae);
            $lines[] = "  status_geo_previsto : {$geoStatus}";
            foreach ($geoLines as $gl) {
                $lines[] = '  ' . $gl;
            }

            // Stats
            if ($cidade === '' && $bairro === '') {
                $stats['local_no_address']++;
            } elseif ($bairro === '') {
                $stats['local_sem_bairro']++;
            }
            if ($cnae === '') {
                $stats['local_sem_cnae']++;
            }
            if ($geoStatus === 'OK') {
                $stats['local_geo_ok']++;
            }

            $lines[] = '';
        }

        return $stats;
    }

    // ── ConsultarCliente direct lookup ────────────────────────────────────────

    /**
     * @return array{ok:bool,found:bool,data:array<string,mixed>,error:string}
     */
    private function consultarCliente(OmieClient $client, int $codigoOmie): array
    {
        try {
            $response = $client->post(self::ENDPOINT, 'ConsultarCliente', [
                'codigo_cliente_omie'          => $codigoOmie,
                'codigo_cliente_integracao'    => '',
            ]);

            // ConsultarCliente returns the client object directly at root level
            if (!isset($response['codigo_cliente_omie'])) {
                // Unexpected structure — treat as not found
                return ['ok' => true, 'found' => false, 'data' => [], 'error' => ''];
            }

            return ['ok' => true, 'found' => true, 'data' => $response, 'error' => ''];

        } catch (Throwable $e) {
            $msg        = $e->getMessage();
            $normalized = strtolower(@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $msg) ?: $msg);

            $isNotFound = str_contains($normalized, 'nao encontrado')
                || str_contains($normalized, 'nao localizado')
                || str_contains($normalized, 'nao existe')
                || str_contains($normalized, 'not found')
                || str_contains($normalized, 'registro nao encontrado')
                || str_contains($normalized, 'nao existem registros');

            if ($isNotFound) {
                return ['ok' => true, 'found' => false, 'data' => [], 'error' => ''];
            }

            return ['ok' => false, 'found' => false, 'data' => [], 'error' => $msg];
        }
    }

    // ── Geo status helpers ────────────────────────────────────────────────────

    /**
     * Returns lines describing geo readiness for the general API section.
     * @return list<string>
     */
    private function geoStatusLines(string $cidade, string $bairro, string $cnae): array
    {
        if ($cidade === '' && $bairro === '') {
            return ['[ATENCAO] Cliente retornado pela Omie, mas sem endereco no cadastro.'];
        }
        $lines = [];
        if ($cidade !== '' && $bairro !== '') {
            $lines[] = '[OK] Endereco encontrado — cidade + bairro presentes';
        } elseif ($cidade !== '') {
            $lines[] = '[PARCIAL] Cidade presente mas sem bairro — geo:materialize nao vai colorir bairro';
        } else {
            $lines[] = '[PARCIAL] Bairro presente mas cidade ausente';
        }
        return $lines;
    }

    /**
     * Returns [status, lines] for the local sample section.
     * Status: 'OK' | 'PARCIAL' | 'ATENCAO'
     *
     * @return array{0:string, 1:list<string>}
     */
    private function geoStatusPrediction(string $cidade, string $bairro, string $cnae): array
    {
        $lines = [];

        if ($cidade === '' && $bairro === '') {
            $lines[] = '[ATENCAO] Cliente retornado pela Omie, mas sem endereco no cadastro.';
            if ($cnae === '') {
                $lines[] = '[ATENCAO] Cliente sem CNAE no cadastro Omie.';
            }
            return ['ATENCAO', $lines];
        }

        if ($cidade !== '' && $bairro !== '') {
            $lines[] = '[OK] Este cliente esta apto para geo:materialize.';
            if ($cnae === '') {
                $lines[] = '[ATENCAO] Cliente sem CNAE no cadastro Omie.';
                return ['PARCIAL', $lines];
            }
            return ['OK', $lines];
        }

        if ($cidade !== '') {
            $lines[] = '[PARCIAL] Cliente precisa de bairro para colorir o mapa.';
        } else {
            $lines[] = '[PARCIAL] Bairro presente mas cidade ausente — preencha cidade/UF no Omie.';
        }
        if ($cnae === '') {
            $lines[] = '[ATENCAO] Cliente sem CNAE no cadastro Omie.';
        }
        return ['PARCIAL', $lines];
    }

    // ── Data fetch ────────────────────────────────────────────────────────────

    /**
     * Fetches up to $limit local clients with enrichment state.
     * Falls back gracefully if newer columns don't exist yet.
     *
     * @return list<array<string,mixed>>
     */
    private function fetchLocalClients(PDO $pdo, int $limit): array
    {
        // Full query with enrichment columns (post-migration 20260502120000)
        try {
            $stmt = $pdo->query(
                "SELECT codigo_cliente_omie, nome_cliente,
                        COALESCE(cidade, '') AS cidade,
                        COALESCE(uf, '') AS uf,
                        COALESCE(bairro, '') AS bairro,
                        COALESCE(cnae_codigo, '') AS cnae_codigo,
                        COALESCE(enrichment_status, 'pending_enrichment') AS enrichment_status
                 FROM biomie_clientes_dim
                 WHERE codigo_cliente_omie IS NOT NULL
                 ORDER BY id DESC
                 LIMIT {$limit}"
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {}

        // Fallback: only base columns (pre-enrichment migration)
        try {
            $stmt = $pdo->query(
                "SELECT codigo_cliente_omie, nome_cliente,
                        '' AS cidade, '' AS uf, '' AS bairro,
                        COALESCE(cnae_codigo, '') AS cnae_codigo,
                        'pending_enrichment' AS enrichment_status
                 FROM biomie_clientes_dim
                 WHERE codigo_cliente_omie IS NOT NULL
                 ORDER BY id DESC
                 LIMIT {$limit}"
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    // ── Masking / formatting ──────────────────────────────────────────────────

    private function maskDocument(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '(vazio)';
        }
        $digits = preg_replace('/\D/', '', $value) ?? '';
        $len    = strlen($digits);
        if ($len === 14) {
            return substr($digits, 0, 2) . '.' . substr($digits, 2, 3) . '.' . substr($digits, 5, 3) . '/****-**';
        }
        if ($len === 11) {
            return substr($digits, 0, 3) . '.***.***-**';
        }
        return SecretMask::maskSecret($value, 3, 2) ?? '***';
    }

    private function maskCep(string $value): string
    {
        $value  = trim($value);
        if ($value === '') {
            return '(vazio)';
        }
        $digits = preg_replace('/\D/', '', $value) ?? '';
        return strlen($digits) >= 5 ? substr($digits, 0, 5) . '-***' : '***-***';
    }

    private function strOrEmpty(string $value): string
    {
        $v = trim($value);
        return $v !== '' ? $v : '(vazio)';
    }

    private function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);
        foreach (self::SENSITIVE_KEYS as $s) {
            if (str_contains($lower, $s)) {
                return true;
            }
        }
        return false;
    }
}
