<?php

declare(strict_types=1);

namespace BIomie\Canonical;

use BIomie\Support;

/**
 * Normaliza os clientes trazidos da Omie aplicando o dicionário
 * de metadados geográficos e fiscais.
 */
final class ClientGeoMapper
{
    /**
     * @param array<string,mixed> $rawClient
     * @return array{dim:array<string,mixed>,geo:array<string,mixed>}
     */
    public function mapClient(array $rawClient): array
    {
        $code = (int) ($rawClient['codigo_cliente_omie'] ?? 0);
        $document = trim((string) ($rawClient['cnpj_cpf'] ?? ''));
        $documentType = $this->resolveDocumentType($document);

        $cnaeCode = trim((string) ($rawClient['cnae'] ?? ''));
        $natureza = trim((string) ($rawClient['natureza_juridica'] ?? ''));
        $macroSegment = $this->resolveMacroSegment($documentType, $cnaeCode, $natureza);

        $tags = array_map(fn($t) => strtolower(trim((string)($t['tag'] ?? ''))), $rawClient['tags'] ?? []);

        // Dimensao
        $dim = [
            'codigo_cliente_omie' => $code,
            'cpf_cnpj' => $document !== '' ? preg_replace('/[^0-9]/', '', $document) : null,
            'tipo_documento' => $documentType,
            'nome_cliente' => mb_substr(trim((string) ($rawClient['nome_fantasia'] ?? $rawClient['razao_social'] ?? 'Cliente sem nome')), 0, 255),
            'nome_fantasia' => mb_substr(trim((string) ($rawClient['nome_fantasia'] ?? '')), 0, 255),
            'natureza_cliente' => mb_substr($natureza, 0, 100),
            'cnae_codigo' => mb_substr($cnaeCode, 0, 20),
            'cnae_descricao' => null, // Would map from a dict if available
            'segmento_macro' => mb_substr($macroSegment, 0, 50),
        ];

        // Geo
        $uf = trim((string) ($rawClient['estado'] ?? ''));
        $cidadeRaw = trim((string) ($rawClient['cidade'] ?? ''));
        $bairroRaw = trim((string) ($rawClient['bairro'] ?? ''));
        $cidadeNorm = $this->normalizeGeoString($cidadeRaw);
        $bairroNorm = $this->normalizeGeoString($bairroRaw);

        $geo = [
            'codigo_cliente_omie' => $code,
            'cep' => mb_substr(preg_replace('/[^0-9]/', '', (string) ($rawClient['cep'] ?? '')), 0, 20),
            'uf' => mb_substr(strtoupper($uf), 0, 2),
            'municipio_ibge' => mb_substr(trim((string) ($rawClient['cidade_ibge'] ?? '')), 0, 20),
            'codigo_cidade_omie' => mb_substr(trim((string) ($rawClient['codigo_cidade'] ?? '')), 0, 20),
            'cidade_raw' => mb_substr($cidadeRaw, 0, 255),
            'cidade_norm' => mb_substr($cidadeNorm, 0, 120),
            'bairro_raw' => mb_substr($bairroRaw, 0, 255),
            'bairro_norm' => mb_substr($bairroNorm, 0, 120),
            'logradouro' => mb_substr(trim((string) ($rawClient['endereco'] ?? '')), 0, 255),
            'numero' => mb_substr(trim((string) ($rawClient['endereco_numero'] ?? '')), 0, 50),
            'complemento' => mb_substr(trim((string) ($rawClient['complemento'] ?? '')), 0, 255),
            // lat and lng can be enriched by a geocoder proxy later
        ];

        return ['dim' => $dim, 'geo' => $geo];
    }

    private function resolveDocumentType(string $doc): string
    {
        $clean = preg_replace('/[^0-9]/', '', $doc);
        if (strlen($clean) === 11) {
            return 'PF';
        }
        if (strlen($clean) === 14) {
            return 'PJ';
        }
        return 'OUTRO';
    }

    private function resolveMacroSegment(string $type, string $cnae, string $natureza): string
    {
        if ($type === 'PF') {
            return 'PF';
        }

        $cnaeClean = preg_replace('/[^0-9]/', '', $cnae);
        $naturezaLower = strtolower($natureza);

        if (
            str_contains($naturezaLower, 'publica') || 
            str_contains($naturezaLower, 'prefeitura') || 
            str_contains($naturezaLower, 'ministerio') ||
            str_starts_with($cnaeClean, '84') // Administração pública, defesa e seguridade social
        ) {
            return 'GOVERNO';
        }

        if (str_starts_with($cnaeClean, '85')) {
            return 'EDUCACAO';
        }

        if (str_starts_with($cnaeClean, '86') || str_starts_with($cnaeClean, '87')) {
            return 'SAUDE';
        }

        if (str_starts_with($cnaeClean, '41') || str_starts_with($cnaeClean, '42') || str_starts_with($cnaeClean, '43')) {
            return 'CONSTRUCAO';
        }
        
        if (str_starts_with($cnaeClean, '9491')) {
            return 'RELIGIOSO';
        }

        // Industria (10 a 33)
        $cnaePrefix2 = (int) substr($cnaeClean, 0, 2);
        if ($cnaePrefix2 >= 10 && $cnaePrefix2 <= 33) {
            return 'INDUSTRIA';
        }

        // Comercio (45, 46, 47)
        if (in_array($cnaePrefix2, [45, 46, 47], true)) {
            return 'COMERCIO';
        }

        if ($type === 'PJ' && $cnaeClean === '') {
            return 'PJ_SEM_CNAE';
        }

        return 'SERVICOS';
    }

    private function normalizeGeoString(string $raw): string
    {
        $str = mb_strtolower(trim($raw), 'UTF-8');
        // Basic unaccent
        $unwanted_array = [
            'š'=>'s', 'ž'=>'z', 'À'=>'a', 'Á'=>'a', 'Â'=>'a', 'Ã'=>'a', 'Ä'=>'a', 'Å'=>'a', 'Æ'=>'a', 'Ç'=>'c', 'È'=>'e', 'É'=>'e',
            'Ê'=>'e', 'Ë'=>'e', 'Ì'=>'i', 'Í'=>'i', 'Î'=>'i', 'Ï'=>'i', 'Ñ'=>'n', 'Ò'=>'o', 'Ó'=>'o', 'Ô'=>'o', 'Õ'=>'o', 'Ö'=>'o', 'Ø'=>'o',
            'Ù'=>'u', 'Ú'=>'u', 'Û'=>'u', 'Ü'=>'u', 'Ý'=>'y', 'Þ'=>'b', 'ß'=>'ss', 'à'=>'a', 'á'=>'a', 'â'=>'a', 'ã'=>'a', 'ä'=>'a', 'å'=>'a',
            'æ'=>'a', 'ç'=>'c', 'è'=>'e', 'é'=>'e', 'ê'=>'e', 'ë'=>'e', 'ì'=>'i', 'í'=>'i', 'î'=>'i', 'ï'=>'i', 'ð'=>'o', 'ñ'=>'n', 'ò'=>'o',
            'ó'=>'o', 'ô'=>'o', 'õ'=>'o', 'ö'=>'o', 'ø'=>'o', 'ù'=>'u', 'ú'=>'u', 'û'=>'u', 'ý'=>'y', 'þ'=>'b', 'ÿ'=>'y', 'ü'=>'u'
        ];
        $str = strtr($str, $unwanted_array);
        // Remove special chars, keep spaces
        $str = preg_replace('/[^a-z0-9 ]+/', '', $str);
        return trim(preg_replace('/\s+/', ' ', (string)$str));
    }
}
