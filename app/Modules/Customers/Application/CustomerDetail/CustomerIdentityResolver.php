<?php

declare(strict_types=1);

namespace BIomie\Modules\Customers\Application\CustomerDetail;

/**
 * Resolves the customer identity carried by map/customer drilldowns.
 *
 * The resolver is intentionally local and deterministic. It never calls Omie
 * and it never prefers a customer name when a complete document or Omie code
 * is available.
 */
final class CustomerIdentityResolver
{
    /**
     * @param array<string,mixed> $input
     * @return array{
     *   resolved:bool,
     *   resolved_by:string,
     *   customer_key:string,
     *   codigo_cliente_omie:string,
     *   documento_norm:string,
     *   cliente_nome:string,
     *   bairro_norm:string,
     *   cidade_norm:string,
     *   period_start:string,
     *   period_end:string,
     *   nota_key:string,
     *   pedido_codigo:string,
     *   warnings:list<string>
     * }
     */
    public function resolve(array $input): array
    {
        $warnings = [];

        $codigoCliente = $this->cleanScalar($input['codigo_cliente_omie'] ?? '');
        $documentoNorm = $this->firstCompleteDocument($input, [
            'documento_norm',
            'cliente_documento',
            'cnpj_cpf',
            'cpf_cnpj',
            'documento_cliente',
            'documento',
        ]);
        $clienteNome = $this->cleanScalar($input['cliente_nome'] ?? $input['nome_cliente'] ?? $input['customer_name'] ?? '');
        $bairroNorm = $this->normalizeKey($input['bairro_norm'] ?? $input['bairro'] ?? $input['neighborhood'] ?? '');
        $cidadeNorm = $this->normalizeKey($input['cidade_norm'] ?? $input['city'] ?? $input['cidade'] ?? '');
        $periodStart = $this->cleanScalar($input['period_start'] ?? $input['start'] ?? $input['date_from'] ?? '');
        $periodEnd = $this->cleanScalar($input['period_end'] ?? $input['end'] ?? $input['date_to'] ?? '');
        $notaKey = $this->cleanScalar($input['nota_key'] ?? '');
        $pedidoCodigo = $this->cleanScalar(
            $input['pedido_codigo']
            ?? $input['codigo_pedido']
            ?? $input['pedido']
            ?? ''
        );

        if ($codigoCliente !== '') {
            return $this->result(true, 'codigo_cliente_omie', 'omie:' . $codigoCliente, $codigoCliente, $documentoNorm, $clienteNome, $bairroNorm, $cidadeNorm, $periodStart, $periodEnd, $notaKey, $pedidoCodigo, $warnings);
        }

        if ($documentoNorm !== '') {
            return $this->result(true, 'documento_norm', 'doc:' . $documentoNorm, '', $documentoNorm, $clienteNome, $bairroNorm, $cidadeNorm, $periodStart, $periodEnd, $notaKey, $pedidoCodigo, $warnings);
        }

        if ($clienteNome !== '' && $periodStart !== '' && $periodEnd !== '') {
            $resolvedBy = $bairroNorm !== '' ? 'nome_bairro_periodo' : 'fallback';
            $warnings[] = $bairroNorm !== ''
                ? 'Cliente aberto por nome/bairro/periodo porque documento nao estava disponivel.'
                : 'Cliente aberto por nome/periodo porque documento e bairro nao estavam disponiveis.';

            return $this->result(
                true,
                $resolvedBy,
                'namegeo:' . sha1($clienteNome . '|' . $bairroNorm . '|' . $periodStart . '|' . $periodEnd),
                '',
                '',
                $clienteNome,
                $bairroNorm,
                $cidadeNorm,
                $periodStart,
                $periodEnd,
                $notaKey,
                $pedidoCodigo,
                $warnings
            );
        }

        if ($notaKey !== '') {
            return $this->result(true, 'fallback', 'nota:' . $notaKey, '', '', $clienteNome, $bairroNorm, $cidadeNorm, $periodStart, $periodEnd, $notaKey, $pedidoCodigo, $warnings);
        }

        if ($pedidoCodigo !== '') {
            return $this->result(true, 'fallback', 'pedido:' . $pedidoCodigo, '', '', $clienteNome, $bairroNorm, $cidadeNorm, $periodStart, $periodEnd, $notaKey, $pedidoCodigo, $warnings);
        }

        $warnings[] = 'Nao foi possivel resolver o cliente com os identificadores recebidos.';

        return $this->result(false, 'fallback', '', '', '', $clienteNome, $bairroNorm, $cidadeNorm, $periodStart, $periodEnd, $notaKey, $pedidoCodigo, $warnings);
    }

    public static function normalizeDocument(mixed $value): string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
        return in_array(strlen($digits), [11, 14], true) ? $digits : '';
    }

    /**
     * @param array<string,mixed> $input
     * @param list<string> $keys
     */
    private function firstCompleteDocument(array $input, array $keys): string
    {
        foreach ($keys as $key) {
            $doc = self::normalizeDocument($input[$key] ?? '');
            if ($doc !== '') {
                return $doc;
            }
        }

        return '';
    }

    private function cleanScalar(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    private function normalizeKey(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return '';
        }

        if (function_exists('iconv')) {
            $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($ascii) && $ascii !== '') {
                $value = $ascii;
            }
        }

        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9 ]+/', '', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * @param list<string> $warnings
     * @return array<string,mixed>
     */
    private function result(
        bool $resolved,
        string $resolvedBy,
        string $customerKey,
        string $codigoCliente,
        string $documentoNorm,
        string $clienteNome,
        string $bairroNorm,
        string $cidadeNorm,
        string $periodStart,
        string $periodEnd,
        string $notaKey,
        string $pedidoCodigo,
        array $warnings
    ): array {
        return [
            'resolved' => $resolved,
            'resolved_by' => $resolvedBy,
            'customer_key' => $customerKey,
            'codigo_cliente_omie' => $codigoCliente,
            'documento_norm' => $documentoNorm,
            'cliente_nome' => $clienteNome,
            'bairro_norm' => $bairroNorm,
            'cidade_norm' => $cidadeNorm,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'nota_key' => $notaKey,
            'pedido_codigo' => $pedidoCodigo,
            'warnings' => $warnings,
        ];
    }
}
