<?php

declare(strict_types=1);

namespace BIomie\Modules\Customers\Infrastructure;

use BIomie\GeoNameNormalizer;

final class CustomerNormalizer
{
    /**
     * Extrai campos de endereço do payload flat retornado pelo Omie.
     * Aceita cnae_fiscal (preferencial) ou cnae (fallback).
     *
     * @param array<string,mixed> $raw
     * @return array{cidade:string,cidade_norm:string,uf:string,bairro:string,cep:string,endereco:string,numero:string,complemento:string,cnae_codigo:string,cnae_source:string}
     */
    public function normalize(array $raw): array
    {
        $cidade      = trim((string) ($raw['cidade'] ?? ''));
        $uf          = strtoupper(trim((string) ($raw['estado'] ?? '')));
        $bairro      = trim((string) ($raw['bairro'] ?? ''));
        $cep         = preg_replace('/\D/', '', (string) ($raw['cep'] ?? '')) ?? '';
        $endereco    = trim((string) ($raw['endereco'] ?? ''));
        $numero      = trim((string) ($raw['endereco_numero'] ?? ''));
        $complemento = trim((string) ($raw['complemento'] ?? ''));

        $cnaeFiscal = preg_replace('/\D/', '', (string) ($raw['cnae_fiscal'] ?? '')) ?? '';
        $cnaeAlt    = preg_replace('/\D/', '', (string) ($raw['cnae'] ?? '')) ?? '';
        $cnae       = $cnaeFiscal !== '' ? $cnaeFiscal : $cnaeAlt;
        $cnaeSource = $cnaeFiscal !== '' ? 'cnae_fiscal' : ($cnaeAlt !== '' ? 'cnae' : '');

        return [
            'cidade'      => $cidade,
            'cidade_norm' => $cidade !== '' ? GeoNameNormalizer::normalizeCity($cidade) : '',
            'uf'          => $uf,
            'bairro'      => $bairro,
            'cep'         => $cep,
            'endereco'    => $endereco,
            'numero'      => $numero,
            'complemento' => $complemento,
            'cnae_codigo' => $cnae,
            'cnae_source' => $cnaeSource,
        ];
    }
}
