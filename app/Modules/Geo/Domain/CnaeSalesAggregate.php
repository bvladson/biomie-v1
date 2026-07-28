<?php

declare(strict_types=1);

namespace BIomie\Modules\Geo\Domain;

final readonly class CnaeSalesAggregate
{
    public function __construct(
        public int    $codigoClienteOmie,
        public string $competencia,
        public string $cnaeCodigo,
        public string $cnaeDescricao,
        public string $segmentoMacro,
        public int    $qtdNotas,
        public float  $faturamento,
    ) {}
}
