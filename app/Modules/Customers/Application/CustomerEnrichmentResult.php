<?php

declare(strict_types=1);

namespace BIomie\Modules\Customers\Application;

/**
 * Resultado da persistência de um único cliente na dimensão.
 * Retornado por CustomerDimensionRepository::persist().
 */
final class CustomerEnrichmentResult
{
    public bool    $changed        = false;
    public string  $bairroResult   = 'none';
    public bool    $cidadeUpdated  = false;
    public bool    $bairroUpdated  = false;
    public bool    $cnaeUpdated    = false;
    public bool    $bronzeSaved    = false;
    public ?string $bairroNorm     = null;
    /** @var array{id:int,nome:string}|null */
    public ?array  $oficialBairro  = null;
}
