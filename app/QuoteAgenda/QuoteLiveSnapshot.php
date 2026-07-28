<?php

declare(strict_types=1);

namespace BIomie\QuoteAgenda;

/**
 * Retrato imutável do estado VIVO da etapa de orçamento no Omie.
 *
 * `live_total`  = tudo que veio na etapa (ex.: MAG 40)
 * `eligible`    = após HardDataExclusionPolicy (ex.: MAG 39 — Licitação fora)
 * `excluded`    = exclusão dura deliberada; NÃO é perda nem ausência
 *
 * `isComplete()` falso ⇒ snapshot NÃO confiável: o scanner deve marcar `failed`
 * e NÃO pode marcar nenhum pedido como ausente.
 */
final class QuoteLiveSnapshot
{
    /**
     * @param array<string,array<string,mixed>> $rawById
     * @param array<string,array<string,mixed>> $normalizedById
     * @param array<string,array<string,mixed>> $eligibleById
     * @param array<string,array<string,mixed>> $excludedById
     * @param list<string> $problems
     */
    public function __construct(
        public readonly int $companyId,
        public readonly string $etapa,
        public readonly array $rawById,
        public readonly array $normalizedById,
        public readonly array $eligibleById,
        public readonly array $excludedById,
        public readonly int $totalRegistros,
        public readonly int $totalPaginas,
        public readonly array $problems,
        public readonly string $snapshotHash,
    ) {
    }

    /** Sem problemas bloqueantes: paginação completa, etapa única, sem duplicidade. */
    public function isComplete(): bool
    {
        return $this->problems === [];
    }

    public function liveTotal(): int
    {
        return count($this->normalizedById);
    }

    public function eligibleTotal(): int
    {
        return count($this->eligibleById);
    }

    /** @return list<string> */
    public function eligibleIds(): array
    {
        return array_map('strval', array_keys($this->eligibleById));
    }

    /** @return list<string> */
    public function excludedIds(): array
    {
        return array_map('strval', array_keys($this->excludedById));
    }

    public function problemsAsText(): string
    {
        return implode(' · ', $this->problems);
    }
}
