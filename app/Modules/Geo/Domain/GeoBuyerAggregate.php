<?php

declare(strict_types=1);

namespace BIomie\Modules\Geo\Domain;

final readonly class GeoBuyerAggregate
{
    public function __construct(
        public int    $codigoClienteOmie,
        public string $competencia,
        public string $uf,
        public string $cidadeNorm,
        public string $bairroNorm,
        public string $regiaoNome,
        public string $cnae,
        public string $segmentoMacro,
        public int    $qtdNotas,
        public float  $faturamento,
    ) {}
}
