<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Semantic;

final class SemanticAuditResult
{
    public bool  $ok           = true;
    public int   $metricsTotal = 0;
    public int   $active       = 0;
    public int   $draft        = 0;
    public int   $deprecated   = 0;
    /** @var list<string> */
    public array $errors       = [];
    /** @var list<string> */
    public array $warnings     = [];

    public function addError(string $msg): void
    {
        $this->errors[] = $msg;
        $this->ok       = false;
    }

    public function addWarning(string $msg): void
    {
        $this->warnings[] = $msg;
    }

    public function hasErrors(): bool   { return !empty($this->errors); }
    public function hasWarnings(): bool { return !empty($this->warnings); }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'ok'            => $this->ok,
            'metrics_total' => $this->metricsTotal,
            'active'        => $this->active,
            'draft'         => $this->draft,
            'deprecated'    => $this->deprecated,
            'errors'        => $this->errors,
            'warnings'      => $this->warnings,
        ];
    }
}
