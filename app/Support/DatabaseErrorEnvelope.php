<?php

declare(strict_types=1);

namespace BIomie\Support;

use PDOException;
use Throwable;

/**
 * P2 — Etapa 1: preserva a identidade real do erro antes que ele vire texto.
 *
 * ─── O PROBLEMA QUE ISTO RESOLVE ────────────────────────────────────────────
 * Todo catch externo do sync gravava `SecretMask::sanitizeErrorMessage()`, e
 * essa função troca o trecho `SQLSTATE[...]: ...` por seis palavras:
 *
 *     SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded
 *     →  "Erro de banco de dados"
 *
 * Deadlock, conexão recusada, coluna truncada e violação de UNIQUE viravam
 * exatamente a mesma frase. Com ~60% dos runs falhando, era impossível saber
 * qual das quatro causas responder — investigação por suposição.
 *
 * Este envelope extrai o que o PDO já entrega e ninguém guardava:
 *   sqlstate, driver_code, driver_message, classe da exceção,
 *   mais quem/onde/quando: company_id, run_id, mode, release, hostname.
 *
 * ─── O QUE ELE NÃO FAZ (de propósito) ───────────────────────────────────────
 * Não classifica (deadlock vs lock_timeout vs connection_lost) e não decide
 * retry. Classificar antes de medir é o mesmo erro de novo, e retry mal
 * coordenado durante recuperação amplia a falha em vez de contorná-la — a Omie
 * limita por IP, por IP+App Key+método e por concorrência. Primeiro observar
 * dados reais agrupados por SQLSTATE; depois decidir política.
 *
 * ─── CONTRATO ───────────────────────────────────────────────────────────────
 * NUNCA lança. É chamado dentro de catch: se ele explodir, engole o erro
 * original e o incidente fica pior do que estava.
 */
final class DatabaseErrorEnvelope
{
    /** Profundidade máxima ao caminhar a cadeia de getPrevious(). */
    private const MAX_CHAIN_DEPTH = 10;

    /** Mensagem do driver é diagnóstico, não narrativa: cabe em uma linha. */
    private const MAX_MESSAGE = 240;

    /** Chaves de contexto aceitas do call site (Etapa 2 alimenta stage/operation/table). */
    private const CONTEXT_KEYS = [
        'run_id',
        'mode',
        'domain',
        'endpoint_group',
        'trigger_source',
        'stage',
        'operation',
        'table',
        'inbox_id',
    ];

    /**
     * Monta o envelope estruturado de uma exceção.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public static function fromThrowable(Throwable $exception, array $context = []): array
    {
        try {
            return self::build($exception, $context);
        } catch (Throwable $selfFailure) {
            // Degradar é obrigatório: um envelope quebrado não pode apagar o erro real.
            return [
                'error_category' => 'unknown',
                'sqlstate' => null,
                'driver_code' => null,
                'message_safe' => SecretMask::sanitizeErrorMessage($exception->getMessage(), self::MAX_MESSAGE),
                'exception_class' => $exception::class,
                'envelope_error' => $selfFailure::class,
            ];
        }
    }

    /**
     * Linha única para `error_summary` / `last_error`.
     *
     * Formato `[SQLSTATE/code] mensagem` — deliberadamente SEM o literal
     * "SQLSTATE[", para não ser reapagado pelo sanitizador na hora de exibir.
     *
     * @param array<string,mixed> $envelope
     */
    public static function summarize(array $envelope, int $maxLength = 300): string
    {
        $message = trim((string) ($envelope['message_safe'] ?? ''));
        if ($message === '') {
            $message = 'Erro sem mensagem.';
        }

        $sqlstate = $envelope['sqlstate'] ?? null;
        if (is_string($sqlstate) && $sqlstate !== '') {
            $code = $envelope['driver_code'] ?? null;
            $prefix = is_int($code)
                ? sprintf('[%s/%d] ', $sqlstate, $code)
                : sprintf('[%s] ', $sqlstate);
            $message = $prefix . $message;
        }

        return mb_strlen($message) > $maxLength
            ? mb_substr($message, 0, $maxLength - 3) . '...'
            : $message;
    }

    /**
     * Atalho para os dois usos juntos no catch: detalhe + resumo.
     *
     * @param array<string,mixed> $context
     * @return array{detail:array<string,mixed>,summary:string}
     */
    public static function capture(Throwable $exception, array $context = []): array
    {
        $detail = self::fromThrowable($exception, $context);

        return ['detail' => $detail, 'summary' => self::summarize($detail)];
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private static function build(Throwable $exception, array $context): array
    {
        $cause = self::findDatabaseException($exception);
        $parts = $cause !== null ? self::extractParts($cause) : null;

        $rawMessage = $parts['driver_message'] ?? null;
        if ($rawMessage === null || $rawMessage === '') {
            $rawMessage = $exception->getMessage();
        }

        $envelope = [
            'error_category' => $parts !== null ? 'database' : 'application',
            'sqlstate' => $parts['sqlstate'] ?? null,
            'driver_code' => $parts['driver_code'] ?? null,
            // collapse=false: é exatamente o dado que a P2 existe para não perder.
            'message_safe' => SecretMask::sanitizeErrorMessage($rawMessage, self::MAX_MESSAGE, false),
            'exception_class' => $exception::class,
        ];

        if ($cause !== null && $cause !== $exception) {
            $envelope['cause_class'] = $cause::class;
        }

        $envelope += self::runtimeFacts();

        foreach (self::CONTEXT_KEYS as $key) {
            if (!array_key_exists($key, $context)) {
                continue;
            }
            $value = $context[$key];
            if ($value === null || $value === '' || is_array($value) || is_object($value)) {
                continue;
            }
            $envelope[$key] = is_scalar($value) ? $value : (string) $value;
        }

        if (!isset($envelope['company_id'])) {
            $envelope['company_id'] = self::companyId($context);
        }

        return SecretMask::redactArray($envelope);
    }

    /**
     * Caminha a cadeia de causas atrás do erro de banco de verdade.
     *
     * A exceção lançada raramente é a PDOException: services embrulham em
     * RuntimeException e o SQLSTATE fica no getPrevious().
     */
    private static function findDatabaseException(Throwable $exception): ?Throwable
    {
        $current = $exception;
        $fallback = null;

        for ($depth = 0; $current !== null && $depth < self::MAX_CHAIN_DEPTH; $depth++) {
            if ($current instanceof PDOException) {
                return $current;
            }
            // Wrapper que reemitiu a mensagem do PDO sem preservar a classe.
            if ($fallback === null && self::looksLikeDatabaseMessage($current->getMessage())) {
                $fallback = $current;
            }
            $current = $current->getPrevious();
        }

        return $fallback;
    }

    private static function looksLikeDatabaseMessage(string $message): bool
    {
        return stripos($message, 'SQLSTATE[') !== false;
    }

    /**
     * @return array{sqlstate:?string,driver_code:?int,driver_message:?string}
     */
    private static function extractParts(Throwable $exception): array
    {
        $sqlstate = null;
        $driverCode = null;
        $driverMessage = null;

        // 1. errorInfo é a fonte canônica: [SQLSTATE, código do driver, mensagem].
        if ($exception instanceof PDOException && is_array($exception->errorInfo)) {
            $info = $exception->errorInfo;
            if (isset($info[0]) && is_string($info[0]) && $info[0] !== '') {
                $sqlstate = strtoupper($info[0]);
            }
            if (isset($info[1]) && is_numeric($info[1])) {
                $driverCode = (int) $info[1];
            }
            if (isset($info[2]) && is_string($info[2]) && trim($info[2]) !== '') {
                $driverMessage = trim($info[2]);
            }
        }

        // 2. Falha de conexão não popula errorInfo — sobra parsear a mensagem.
        $parsed = self::parseMessage($exception->getMessage());
        $sqlstate ??= $parsed['sqlstate'];
        $driverCode ??= $parsed['driver_code'];
        $driverMessage ??= $parsed['driver_message'];

        // 3. PDOException::getCode() devolve o SQLSTATE como string.
        if ($sqlstate === null) {
            $code = $exception->getCode();
            if (is_string($code) && preg_match('/^[0-9A-Za-z]{5}$/', $code) === 1) {
                $sqlstate = strtoupper($code);
            }
        }

        return [
            'sqlstate' => $sqlstate,
            'driver_code' => $driverCode,
            'driver_message' => $driverMessage,
        ];
    }

    /**
     * Formatos reais do PDO/MySQL que precisam ser reconhecidos:
     *   SQLSTATE[HY000] [2002] Connection refused
     *   SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded
     *   SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry
     *   SQLSTATE[01000]: Warning: 1265 Data truncated for column 'tipo_pessoa'
     *   SQLSTATE[HY093]: Invalid parameter number
     *
     * @return array{sqlstate:?string,driver_code:?int,driver_message:?string}
     */
    private static function parseMessage(string $message): array
    {
        $empty = ['sqlstate' => null, 'driver_code' => null, 'driver_message' => null];

        if (preg_match('/SQLSTATE\[([0-9A-Za-z]{5})\](?:\s*\[(\d+)\])?:?\s*(.*)$/s', $message, $m) !== 1) {
            return $empty;
        }

        // O grupo 2 é opcional, mas o 3 sempre casa: o PHP preenche grupos
        // intermediários não casados com '' em vez de omiti-los.
        $sqlstate = strtoupper($m[1]);
        $driverCode = $m[2] !== '' ? (int) $m[2] : null;
        $rest = trim($m[3]);

        // "General error: 1205 Lock wait timeout" → código embutido depois do rótulo.
        if ($driverCode === null && preg_match('/^[^:]{0,60}:\s*(\d+)\s+(.+)$/s', $rest, $inner) === 1) {
            $driverCode = (int) $inner[1];
            $rest = trim($inner[2]);
        }

        return [
            'sqlstate' => $sqlstate,
            'driver_code' => $driverCode,
            'driver_message' => $rest !== '' ? $rest : null,
        ];
    }

    /**
     * Onde o erro aconteceu — sem isso, agrupar por SQLSTATE não separa
     * "uma empresa quebrada" de "o banco inteiro sob pressão".
     *
     * @return array<string,mixed>
     */
    private static function runtimeFacts(): array
    {
        $facts = [
            'captured_at' => gmdate('c'),
            'hostname' => gethostname() ?: null,
        ];

        try {
            $release = Asset::buildVersion();
            $facts['release'] = $release;
            $facts['release_sha'] = self::extractSha($release);
        } catch (Throwable) {
            $facts['release'] = null;
            $facts['release_sha'] = null;
        }

        return $facts;
    }

    /**
     * O deploy carimba `config/build.php` com `<sha>-<AAAAMMDD>_<HHMMSS>`
     * (ex.: `6452289-20260803_194500`). O formato inteiro é exigido de
     * propósito: aceitar só o prefixo hex faria a versão de desenvolvimento
     * `20260727-notificacoes-ricas` devolver "20260727" como se fosse um SHA —
     * dígito é hex válido. SHA errado é pior que SHA ausente: manda comparar
     * o incidente com um commit que não existe.
     */
    private static function extractSha(string $release): ?string
    {
        if (preg_match('/^([0-9a-f]{7,40})-\d{8}_\d{6}$/i', $release, $m) === 1) {
            return strtolower($m[1]);
        }

        return null;
    }

    /**
     * @param array<string,mixed> $context
     */
    private static function companyId(array $context): ?int
    {
        if (isset($context['company_id']) && is_numeric($context['company_id'])) {
            return (int) $context['company_id'];
        }

        try {
            return TenantContext::companyIdOrNull();
        } catch (Throwable) {
            return null;
        }
    }
}
