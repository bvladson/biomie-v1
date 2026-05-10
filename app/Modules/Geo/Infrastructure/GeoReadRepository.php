<?php

declare(strict_types=1);

namespace BIomie\Modules\Geo\Infrastructure;

use BadMethodCallException;
use BIomie\Canonical\CanonicalReadRepository;
use Closure;

final class GeoReadRepository
{
    public function __construct(
        private readonly Closure $pdoResolver,
    ) {
    }

    public function __call(string $method, array $arguments): mixed
    {
        $repository = $this->canonicalReadRepository();
        if (!is_callable([$repository, $method])) {
            throw new BadMethodCallException(sprintf('Geo read method not supported: %s', $method));
        }

        return $repository->{$method}(...$arguments);
    }

    private function canonicalReadRepository(): CanonicalReadRepository
    {
        return new CanonicalReadRepository($this->pdoResolver);
    }
}
