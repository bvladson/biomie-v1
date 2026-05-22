<?php

declare(strict_types=1);

namespace BIomie;

use RuntimeException;

final class Config
{
    private array $config;

    public function __construct(?string $configFile = null)
    {
        $configPath = $configFile ?? Bootstrap::rootPath('config/env.php');
        if (!is_file($configPath)) {
            throw new RuntimeException('Arquivo de configuracao nao encontrado: ' . $configPath);
        }

        $config = require $configPath;
        if (!is_array($config)) {
            throw new RuntimeException('O arquivo de configuracao deve retornar um array.');
        }

        $this->config = $config;

        $timezone = $this->get('app.timezone', 'America/Fortaleza');
        if (is_string($timezone) && $timezone !== '') {
            date_default_timezone_set($timezone);
        }
    }

    public function all(): array
    {
        return $this->config;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = $this->config;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function hasOmieCredentials(): bool
    {
        return trim((string) $this->get('omie.app_key', '')) !== ''
            && trim((string) $this->get('omie.app_secret', '')) !== '';
    }

    public function isCanonicalEnabled(string $module = 'vendors'): bool
    {
        return (bool) $this->get("features.canonical_reads_{$module}", false);
    }

    public function operationScopeEnforced(): bool
    {
        $fromConfig = $this->get('features.operation_scope_enforced');
        if ($fromConfig !== null) {
            return (bool) $fromConfig;
        }

        return (bool) (getenv('BIOMIE_OPERATION_SCOPE_ENFORCED') ?: false);
    }

    public static function fromEnvironment(): static
    {
        return new static();
    }
}
