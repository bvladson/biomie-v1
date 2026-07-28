<?php

declare(strict_types=1);

namespace BIomie\Company;

/**
 * Immutable value object representing a company's package manifest.
 * Loaded from companies/{slug}/manifest.json by CompanyPackageLoader.
 */
final class CompanyManifest
{
    /** @param array<string,mixed> $data */
    public function __construct(private readonly array $data) {}

    public static function empty(string $slug): self
    {
        return new self(['slug' => $slug, 'active' => false, '_exists' => false]);
    }

    public function exists(): bool
    {
        return (bool) ($this->data['_exists'] ?? true);
    }

    public function matchesIdentity(string $slug, int $companyId): bool
    {
        return $this->exists()
            && $this->slug() === strtolower(trim($slug))
            && $this->companyId() === $companyId;
    }

    public function slug(): string
    {
        return strtolower((string) ($this->data['slug'] ?? ''));
    }

    public function code(): string
    {
        return strtoupper((string) ($this->data['code'] ?? $this->data['slug'] ?? ''));
    }

    public function name(): string
    {
        return (string) ($this->data['name'] ?? '');
    }

    public function companyId(): int
    {
        return (int) ($this->data['company_id'] ?? 0);
    }

    public function isActive(): bool
    {
        return (bool) ($this->data['active'] ?? true);
    }

    public function defaultChannel(): string
    {
        return (string) ($this->data['default_channel'] ?? 'varejo');
    }

    /** @return string[] */
    public function channels(): array
    {
        return (array) ($this->data['channels'] ?? ['varejo']);
    }

    public function hasChannel(string $channel): bool
    {
        return in_array($channel, $this->channels(), true);
    }

    /** Square/icon logo path relative to web root (e.g. "images/expert.png") */
    public function logoIconPath(): string
    {
        return (string) ($this->data['brand']['logo_icon'] ?? $this->data['brand']['logo'] ?? '');
    }

    /** Wide/wordmark logo path relative to web root */
    public function logoPath(): string
    {
        return (string) ($this->data['brand']['logo_wide'] ?? $this->data['brand']['logo'] ?? '');
    }

    public function brandName(): string
    {
        return (string) ($this->data['brand']['name'] ?? $this->name());
    }

    /** "MAG Filial" → "MF", "Expert" → "EX", "ErgoOffice" → "ER" */
    public function initials(): string
    {
        $name = trim($this->name());
        if ($name === '') {
            return strtoupper(mb_substr($this->slug(), 0, 2, 'UTF-8'));
        }
        $words = array_values(array_filter(preg_split('/\s+/', $name) ?: []));
        if (count($words) === 1) {
            return strtoupper(mb_substr($words[0], 0, 2, 'UTF-8'));
        }
        return strtoupper(
            mb_substr($words[0], 0, 1, 'UTF-8') .
            mb_substr($words[count($words) - 1], 0, 1, 'UTF-8')
        );
    }

    /** Primary brand color hex, e.g. "#1B4F72". Empty string if not set. */
    public function brandColor(): string
    {
        return (string) ($this->data['brand']['color'] ?? '');
    }

    /** Darker variant for hover/shadow. Falls back to brandColor(). */
    public function brandColorDark(): string
    {
        return (string) ($this->data['brand']['color_dark'] ?? $this->brandColor());
    }

    /** CNPJ formatted string, e.g. "40.914.338/0001-73". Empty string if not set. */
    public function cnpj(): string
    {
        return (string) ($this->data['legal']['cnpj'] ?? '');
    }

    /** "matriz" | "filial" | "" */
    public function companyType(): string
    {
        return strtolower((string) ($this->data['legal']['type'] ?? ''));
    }

    /** Formatted CNPJ label for display, e.g. "CNPJ 40.914.338/0001-73". */
    public function cnpjLabel(): string
    {
        $cnpj = $this->cnpj();
        return $cnpj !== '' ? 'CNPJ ' . $cnpj : '';
    }

    public function isFeatureEnabled(string $feature): bool
    {
        return (bool) ($this->data['features'][$feature] ?? true);
    }

    /** @return array{rate_limit_per_minute?: int, from_date?: string} */
    public function syncPolicy(): array
    {
        return (array) ($this->data['sync'] ?? []);
    }

    public function syncRateLimit(): int
    {
        return (int) ($this->data['sync']['rate_limit_per_minute'] ?? 10);
    }

    public function syncFromDate(): string
    {
        return (string) ($this->data['sync']['from_date'] ?? '2024-01');
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->data;
    }
}
