<?php

declare(strict_types=1);

namespace BIomie\QuoteAgenda;

/**
 * Fonte do snapshot vivo consumida pelo scanner.
 *
 * Existe para que o QuoteLiveSnapshotScanner dependa de um contrato, não da
 * implementação concreta — permitindo testar os caminhos NEGATIVOS (paginação
 * incompleta, falha, reaparecimento, preservação de contadores) sem tocar no
 * Omie nem na rede.
 */
interface QuoteLiveSnapshotSource
{
    public function capture(string $etapa = '10'): QuoteLiveSnapshot;

    /**
     * @return array{existing:array<string,array<string,mixed>>,missing:list<string>,drifts:array<string,array<string,array{canonico:mixed,vivo:mixed}>>,emissao_suspeita:array<string,mixed>}
     */
    public function diffAgainstCanonical(QuoteLiveSnapshot $snap): array;

    /** @return array{inserted:int,updated:int,unchanged:int,skipped:int} */
    public function upsertEligible(QuoteLiveSnapshot $snap): array;
}
