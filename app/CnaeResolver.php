<?php

declare(strict_types=1);

namespace BIomie;

use PDO;
use Throwable;

/**
 * Resolve CNAE para um cliente buscando em múltiplas fontes, na ordem de prioridade.
 *
 * Fontes (em ordem):
 *  1. biomie_clientes_dim.cnae_codigo
 *  2. biomie_omie_bronze (payload ConsultarCliente/ListarClientes — múltiplas chaves)
 *  3. biomie_notas_fiscais_canonicas (se coluna cnae existir)
 *  4. biomie_pedidos_canonicos (se coluna cnae existir)
 */
final class CnaeResolver
{
    /** Chaves CNAE conhecidas em payloads JSON do Omie (ordem de preferência) */
    private const BRONZE_CNAE_KEYS = [
        'cnae_fiscal',
        'cnae',
        'cnae_principal',
        'codigo_cnae',
        'cnae_principal_codigo',
        'cnaePrincipal',
        'codigoCnae',
        'atividade_principal',
        'tipo_atividade',
    ];

    /** Campos de documento em payloads do Omie */
    private const BRONZE_DOC_KEYS = ['cnpj_cpf', 'cpf_cnpj', 'documento', 'cpf', 'cnpj'];

    /** Valores inválidos de CNAE a descartar */
    private const CNAE_INVALID_VALUES = ['0', '00', '000', '0000', '0000000', '00000000', 'sem cnae', 'nao informado'];

    public function __construct(private readonly PDO $pdo) {}

    // ── Public resolution API ─────────────────────────────────────────────────

    /**
     * @return array{
     *   cnae_codigo:string|null,
     *   cnae_descricao:string|null,
     *   source:string,
     * }
     */
    public function resolve(int $codigoOmie): array
    {
        $from_dim = $this->resolveFromDim($codigoOmie);
        if ($from_dim['cnae_codigo'] !== null) {
            return $from_dim;
        }

        $from_bronze = $this->resolveFromBronze($codigoOmie);
        if ($from_bronze['cnae_codigo'] !== null) {
            return $from_bronze;
        }

        $from_nf = $this->resolveFromCanonicalNf($codigoOmie);
        if ($from_nf['cnae_codigo'] !== null) {
            return $from_nf;
        }

        $from_pedido = $this->resolveFromCanonicalPedido($codigoOmie);
        if ($from_pedido['cnae_codigo'] !== null) {
            return $from_pedido;
        }

        return ['cnae_codigo' => null, 'cnae_descricao' => null, 'source' => 'nao_encontrado'];
    }

    /**
     * Bulk bronze scan keyed by codigo_cliente_omie (integer).
     *
     * @return array<int,array{cnae_codigo:string,cnae_descricao:string|null,source:string}>
     */
    public function resolveAllFromBronze(): array
    {
        $result = [];

        try {
            $coalesceChain = $this->buildBronzeCoalesceChain();

            $stmt = $this->pdo->query(
                "SELECT natural_key, {$coalesceChain} AS cnae_raw
                 FROM biomie_omie_bronze
                 WHERE domain_name = 'clientes'
                   AND {$coalesceChain} IS NOT NULL
                 GROUP BY natural_key
                 ORDER BY ingested_at DESC"
            );

            if ($stmt === false) {
                return $result;
            }

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $codigo  = (int) ($row['natural_key'] ?? 0);
                $cnaeRaw = (string) ($row['cnae_raw'] ?? '');
                if ($codigo === 0 || $cnaeRaw === '') {
                    continue;
                }
                [$cnaeCode, $cnaeDesc] = self::parseCnaeRaw($cnaeRaw);
                if ($cnaeCode !== '') {
                    $result[$codigo] = [
                        'cnae_codigo'    => $cnaeCode,
                        'cnae_descricao' => $cnaeDesc,
                        'source'         => 'bronze_cliente',
                    ];
                }
            }
        } catch (Throwable) {}

        return $result;
    }

    /**
     * Extended bronze scan that also extracts the document field from the JSON payload,
     * so the caller can attempt document-based matching as a fallback.
     *
     * Returns a list (not keyed) because a bronze row may not have a valid codigo_cliente_omie.
     *
     * @return list<array{
     *   natural_key: int,
     *   cnae_codigo: string,
     *   cnae_descricao: string|null,
     *   doc_raw: string,
     *   doc_digits: string,
     *   source: string,
     * }>
     */
    public function resolveAllFromBronzeWithDocFallback(): array
    {
        $result = [];

        try {
            $coalesceChain = $this->buildBronzeCoalesceChain();
            $docCoalesce   = $this->buildBronzeDocCoalesceChain();

            $stmt = $this->pdo->query(
                "SELECT natural_key,
                        {$coalesceChain} AS cnae_raw,
                        {$docCoalesce}   AS doc_raw
                 FROM biomie_omie_bronze
                 WHERE domain_name = 'clientes'
                   AND {$coalesceChain} IS NOT NULL
                 GROUP BY natural_key
                 ORDER BY ingested_at DESC"
            );

            if ($stmt === false) {
                return $result;
            }

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $codigo  = (int) ($row['natural_key'] ?? 0);
                $cnaeRaw = (string) ($row['cnae_raw'] ?? '');
                $docRaw  = (string) ($row['doc_raw']  ?? '');
                if ($cnaeRaw === '') {
                    continue;
                }
                [$cnaeCode, $cnaeDesc] = self::parseCnaeRaw($cnaeRaw);
                if ($cnaeCode === '') {
                    continue;
                }
                $result[] = [
                    'natural_key'    => $codigo,
                    'cnae_codigo'    => $cnaeCode,
                    'cnae_descricao' => $cnaeDesc,
                    'doc_raw'        => $docRaw,
                    'doc_digits'     => preg_replace('/\D/', '', $docRaw) ?? '',
                    'source'         => 'bronze_cliente',
                ];
            }
        } catch (Throwable) {}

        return $result;
    }

    /**
     * Detailed diagnostic of the bronze table — shows match coverage.
     *
     * @return array{
     *   total_bronze_clientes: int,
     *   total_com_cnae: int,
     *   total_match_por_codigo: int,
     *   total_match_por_doc: int,
     *   total_sem_match: int,
     *   sample: list<array<string,mixed>>,
     * }
     */
    public function diagnoseBronzeDetailed(): array
    {
        $rows = $this->resolveAllFromBronzeWithDocFallback();

        $totalComCnae     = count($rows);
        $matchPorCodigo   = 0;
        $matchPorDoc      = 0;
        $semMatch         = 0;
        $sample           = [];

        $hasDimDocNorm = $this->columnExists('biomie_clientes_dim', 'documento_normalizado');

        foreach ($rows as $r) {
            $codigo    = $r['natural_key'];
            $docDigits = $r['doc_digits'];

            $matchedByCodigo = false;
            $matchedByDoc    = false;

            if ($codigo > 0) {
                try {
                    $s = $this->pdo->prepare(
                        "SELECT 1 FROM biomie_clientes_dim WHERE codigo_cliente_omie = :cod LIMIT 1"
                    );
                    $s->execute(['cod' => $codigo]);
                    $matchedByCodigo = (bool) $s->fetchColumn();
                } catch (Throwable) {}
            }

            if (!$matchedByCodigo && $docDigits !== '' && $hasDimDocNorm) {
                try {
                    $s = $this->pdo->prepare(
                        "SELECT 1 FROM biomie_clientes_dim WHERE documento_normalizado = :doc LIMIT 1"
                    );
                    $s->execute(['doc' => $docDigits]);
                    $matchedByDoc = (bool) $s->fetchColumn();
                } catch (Throwable) {}
            }

            if ($matchedByCodigo) {
                $matchPorCodigo++;
            } elseif ($matchedByDoc) {
                $matchPorDoc++;
            } else {
                $semMatch++;
            }

            if (count($sample) < 10) {
                $sample[] = [
                    'codigo_cliente_omie'  => $codigo ?: null,
                    'documento_mascarado'  => self::maskDocument($docDigits),
                    'cnae_raw'             => $r['cnae_codigo'] . ($r['cnae_descricao'] ? ' ' . $r['cnae_descricao'] : ''),
                    'cnae_codigo'          => $r['cnae_codigo'],
                    'cnae_descricao'       => $r['cnae_descricao'],
                    'match_por_codigo'     => $matchedByCodigo ? 'sim' : 'nao',
                    'match_por_documento'  => $matchedByDoc    ? 'sim' : 'nao',
                ];
            }
        }

        $bronzeTotal = 0;
        try {
            $bronzeTotal = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM biomie_omie_bronze WHERE domain_name = 'clientes'"
            )->fetchColumn();
        } catch (Throwable) {}

        return [
            'total_bronze_clientes'   => $bronzeTotal,
            'total_com_cnae'          => $totalComCnae,
            'total_match_por_codigo'  => $matchPorCodigo,
            'total_match_por_doc'     => $matchPorDoc,
            'total_sem_match'         => $semMatch,
            'sample'                  => $sample,
        ];
    }

    /**
     * Conta quantos payloads bronze têm alguma chave CNAE preenchida.
     *
     * @return array{count:int, sample:list<array{natural_key:string,cnae_raw:string,source_key:string}>}
     */
    public function diagnoseBronze(): array
    {
        $count  = 0;
        $sample = [];

        try {
            foreach (self::BRONZE_CNAE_KEYS as $key) {
                $expr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$." . $key . "')), '')";
                $stmt = $this->pdo->query(
                    "SELECT COUNT(*) FROM biomie_omie_bronze
                     WHERE domain_name = 'clientes' AND {$expr} IS NOT NULL"
                );
                $n = $stmt !== false ? (int) $stmt->fetchColumn() : 0;
                if ($n > 0) {
                    $count += $n;
                    if (count($sample) < 10) {
                        $sStmt = $this->pdo->query(
                            "SELECT natural_key, {$expr} AS cnae_raw
                             FROM biomie_omie_bronze
                             WHERE domain_name = 'clientes' AND {$expr} IS NOT NULL
                             LIMIT 5"
                        );
                        if ($sStmt !== false) {
                            foreach ($sStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                                $sample[] = [
                                    'natural_key' => (string) ($r['natural_key'] ?? ''),
                                    'cnae_raw'    => (string) ($r['cnae_raw']    ?? ''),
                                    'source_key'  => $key,
                                ];
                            }
                        }
                    }
                }
            }
        } catch (Throwable) {}

        return ['count' => $count, 'sample' => $sample];
    }

    // ── Per-source resolvers ──────────────────────────────────────────────────

    /** @return array{cnae_codigo:string|null,cnae_descricao:string|null,source:string} */
    private function resolveFromDim(int $codigoOmie): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT cnae_codigo, cnae_descricao FROM biomie_clientes_dim
                 WHERE codigo_cliente_omie = :cod
                   AND cnae_codigo IS NOT NULL AND cnae_codigo != ''
                 LIMIT 1"
            );
            $stmt->execute(['cod' => $codigoOmie]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && ($row['cnae_codigo'] ?? '') !== '') {
                return [
                    'cnae_codigo'    => (string) $row['cnae_codigo'],
                    'cnae_descricao' => ($row['cnae_descricao'] ?? null) ?: null,
                    'source'         => 'clientes_dim',
                ];
            }
        } catch (Throwable) {}
        return ['cnae_codigo' => null, 'cnae_descricao' => null, 'source' => 'clientes_dim'];
    }

    /** @return array{cnae_codigo:string|null,cnae_descricao:string|null,source:string} */
    private function resolveFromBronze(int $codigoOmie): array
    {
        try {
            $coalesceChain = $this->buildBronzeCoalesceChain();

            $stmt = $this->pdo->prepare(
                "SELECT {$coalesceChain} AS cnae_raw
                 FROM biomie_omie_bronze
                 WHERE domain_name = 'clientes' AND natural_key = :key
                   AND {$coalesceChain} IS NOT NULL
                 ORDER BY ingested_at DESC
                 LIMIT 1"
            );
            $stmt->execute(['key' => (string) $codigoOmie]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && ($row['cnae_raw'] ?? '') !== '') {
                [$cnaeCode, $cnaeDesc] = self::parseCnaeRaw((string) $row['cnae_raw']);
                if ($cnaeCode !== '') {
                    return ['cnae_codigo' => $cnaeCode, 'cnae_descricao' => $cnaeDesc, 'source' => 'bronze_cliente'];
                }
            }
        } catch (Throwable) {}
        return ['cnae_codigo' => null, 'cnae_descricao' => null, 'source' => 'bronze_cliente'];
    }

    /** @return array{cnae_codigo:string|null,cnae_descricao:string|null,source:string} */
    private function resolveFromCanonicalNf(int $codigoOmie): array
    {
        try {
            foreach (['cnae_codigo', 'cnae', 'cnae_principal'] as $col) {
                $stmt = $this->pdo->prepare(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biomie_notas_fiscais_canonicas' AND COLUMN_NAME = :col"
                );
                $stmt->execute(['col' => $col]);
                if ((int) $stmt->fetchColumn() === 0) {
                    continue;
                }

                $stmt = $this->pdo->prepare(
                    "SELECT `{$col}` FROM biomie_notas_fiscais_canonicas
                     WHERE codigo_cliente_omie = :cod AND `{$col}` IS NOT NULL AND `{$col}` != ''
                     LIMIT 1"
                );
                $stmt->execute(['cod' => $codigoOmie]);
                $val = $stmt->fetchColumn();
                if ($val !== false && $val !== '') {
                    [$cnaeCode, $cnaeDesc] = self::parseCnaeRaw((string) $val);
                    if ($cnaeCode !== '') {
                        return ['cnae_codigo' => $cnaeCode, 'cnae_descricao' => $cnaeDesc, 'source' => 'canonical_nf'];
                    }
                }
            }
        } catch (Throwable) {}
        return ['cnae_codigo' => null, 'cnae_descricao' => null, 'source' => 'canonical_nf'];
    }

    /** @return array{cnae_codigo:string|null,cnae_descricao:string|null,source:string} */
    private function resolveFromCanonicalPedido(int $codigoOmie): array
    {
        try {
            foreach (['cnae_codigo', 'cnae', 'cnae_principal'] as $col) {
                $stmt = $this->pdo->prepare(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biomie_pedidos_canonicos' AND COLUMN_NAME = :col"
                );
                $stmt->execute(['col' => $col]);
                if ((int) $stmt->fetchColumn() === 0) {
                    continue;
                }

                $stmt = $this->pdo->prepare(
                    "SELECT `{$col}` FROM biomie_pedidos_canonicos
                     WHERE codigo_cliente_omie = :cod AND `{$col}` IS NOT NULL AND `{$col}` != ''
                     LIMIT 1"
                );
                $stmt->execute(['cod' => $codigoOmie]);
                $val = $stmt->fetchColumn();
                if ($val !== false && $val !== '') {
                    [$cnaeCode, $cnaeDesc] = self::parseCnaeRaw((string) $val);
                    if ($cnaeCode !== '') {
                        return ['cnae_codigo' => $cnaeCode, 'cnae_descricao' => $cnaeDesc, 'source' => 'canonical_pedido'];
                    }
                }
            }
        } catch (Throwable) {}
        return ['cnae_codigo' => null, 'cnae_descricao' => null, 'source' => 'canonical_pedido'];
    }

    // ── CNAE payload extraction ───────────────────────────────────────────────

    /**
     * Extract CNAE from a decoded Omie payload (PHP array).
     * Handles flat keys, nested objects, and atividade_principal arrays.
     *
     * @param array<string,mixed> $payload
     * @return array{codigo:string,descricao:string|null,raw:string,source_path:string}|null
     */
    public static function extractCnaeFromPayload(array $payload): ?array
    {
        // Flat keys — try in preference order
        $flatKeys = [
            'cnae_fiscal', 'cnae', 'cnae_principal', 'codigo_cnae',
            'cnae_principal_codigo', 'cnaePrincipal', 'codigoCnae',
            'atividade_principal', 'tipo_atividade',
        ];

        foreach ($flatKeys as $key) {
            $val = $payload[$key] ?? null;

            // If it's an array (e.g., atividade_principal = [{"codigo": "X", "descricao": "Y"}])
            if (is_array($val)) {
                $item = isset($val[0]) ? $val[0] : $val;
                $cod  = (string) ($item['codigo'] ?? $item['cnae_codigo'] ?? $item['code'] ?? '');
                $desc = ($item['descricao'] ?? $item['cnae_descricao'] ?? $item['description'] ?? null) ?: null;
                if ($cod !== '') {
                    $result = self::validateAndBuildCnae($cod, is_string($desc) ? $desc : null, $key);
                    if ($result !== null) {
                        return $result;
                    }
                }
                continue;
            }

            // String or int value
            if ($val !== null && $val !== '') {
                $raw    = (string) $val;
                $result = self::validateAndBuildCnaeFromRaw($raw, $key);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        // Nested paths: fiscal.cnae, cliente.cnae, cadastro.cnae
        $nestedPaths = [
            ['fiscal', 'cnae'],
            ['fiscal', 'cnae_fiscal'],
            ['cliente', 'cnae'],
            ['cadastro', 'cnae'],
        ];
        foreach ($nestedPaths as [$parent, $child]) {
            $sub = $payload[$parent] ?? null;
            if (is_array($sub)) {
                $val = $sub[$child] ?? null;
                if ($val !== null && $val !== '') {
                    $result = self::validateAndBuildCnaeFromRaw((string) $val, "{$parent}.{$child}");
                    if ($result !== null) {
                        return $result;
                    }
                }
            }
        }

        // clientes_cadastro[].cnae
        $cadastros = $payload['clientes_cadastro'] ?? null;
        if (is_array($cadastros)) {
            $item = isset($cadastros[0]) ? $cadastros[0] : $cadastros;
            if (is_array($item)) {
                foreach (['cnae', 'cnae_fiscal', 'cnae_codigo'] as $key) {
                    $val = $item[$key] ?? null;
                    if ($val !== null && $val !== '') {
                        $result = self::validateAndBuildCnaeFromRaw((string) $val, "clientes_cadastro[].{$key}");
                        if ($result !== null) {
                            return $result;
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return array{codigo:string,descricao:string|null,raw:string,source_path:string}|null
     */
    private static function validateAndBuildCnaeFromRaw(string $raw, string $sourcePath): ?array
    {
        [$codigo, $descricao] = self::parseCnaeRaw($raw);
        return self::validateAndBuildCnae($codigo, $descricao, $sourcePath, $raw);
    }

    /**
     * @return array{codigo:string,descricao:string|null,raw:string,source_path:string}|null
     */
    private static function validateAndBuildCnae(string $codigo, ?string $descricao, string $sourcePath, string $raw = ''): ?array
    {
        if ($codigo === '' || strlen($codigo) < 4) {
            return null;
        }
        if (in_array(strtolower($codigo), self::CNAE_INVALID_VALUES, true)) {
            return null;
        }
        // Reject all-zero codes
        if (ltrim($codigo, '0') === '') {
            return null;
        }
        return [
            'codigo'      => $codigo,
            'descricao'   => $descricao,
            'raw'         => $raw !== '' ? $raw : $codigo,
            'source_path' => $sourcePath,
        ];
    }

    // ── CNAE normalization ────────────────────────────────────────────────────

    /**
     * Normaliza string CNAE que pode vir em formatos:
     *   "5510801"  |  "55.10-8-01"  |  "55.10-8/01"  |  "5510801 Hotéis"
     *
     * @return array{0:string,1:string|null}  [cnae_codigo, cnae_descricao]
     */
    public static function parseCnaeRaw(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return ['', null];
        }

        $lc = strtolower($raw);
        if (in_array($lc, self::CNAE_INVALID_VALUES, true)) {
            return ['', null];
        }

        // Extract digits only from the leading numeric/punctuation block
        $digits = preg_replace('/\D/', '', $raw) ?? '';

        if ($digits === '' || strlen($digits) < 4) {
            return ['', null];
        }

        // Reject all-zero codes
        if (ltrim($digits, '0') === '') {
            return ['', null];
        }

        // Description = everything after the leading digits/punctuation block
        $desc = null;
        if (preg_match('/^[\d\s.\-\/]+\s+(.+)$/', $raw, $m)) {
            $d = trim($m[1]);
            if ($d !== '' && !is_numeric($d)) {
                $desc = $d;
            }
        }

        return [$digits, $desc];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function buildBronzeCoalesceChain(): string
    {
        $exprs = [];
        foreach (self::BRONZE_CNAE_KEYS as $key) {
            // For atividade_principal/tipo_atividade which may be arrays, extract nested codigo
            if (in_array($key, ['atividade_principal', 'tipo_atividade'], true)) {
                $exprs[] = "NULLIF(JSON_UNQUOTE(COALESCE("
                    . "JSON_EXTRACT(payload_json, '$." . $key . "[0].codigo'),"
                    . "JSON_EXTRACT(payload_json, '$." . $key . ".codigo'),"
                    . "JSON_EXTRACT(payload_json, '$." . $key . "')"
                    . ")), '')";
            } else {
                $exprs[] = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$." . $key . "')), '')";
            }
        }
        return 'COALESCE(' . implode(', ', $exprs) . ')';
    }

    private function buildBronzeDocCoalesceChain(): string
    {
        $exprs = [];
        foreach (self::BRONZE_DOC_KEYS as $key) {
            $exprs[] = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$." . $key . "')), '')";
        }
        return 'COALESCE(' . implode(', ', $exprs) . ", '')";
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
            );
            $stmt->execute([$table, $column]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    public static function maskDocument(string $digits): string
    {
        $len = strlen($digits);
        if ($len === 11) {
            // CPF: ***.***.XXX-XX
            return '***.***.XXX-' . substr($digits, -2);
        }
        if ($len === 14) {
            // CNPJ: XX.XXX.XXX/XXXX-XX — mask middle
            return substr($digits, 0, 2) . '.***.***/****-' . substr($digits, -2);
        }
        if ($len > 4) {
            return str_repeat('*', $len - 4) . substr($digits, -4);
        }
        return $digits !== '' ? '***' : 'Nao informado';
    }
}
