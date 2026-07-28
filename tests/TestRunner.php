<?php

declare(strict_types=1);

final class TestRunner
{
    /** @var list<array<string,mixed>> */
    private array $results = [];

    /**
     * @param callable():array<string,mixed> $assertion
     */
    public function run(string $id, string $description, callable $assertion): void
    {
        try {
            $result = $assertion();
            $result['id'] = $id;
            $result['description'] = $description;
            $result['ok'] = (bool) ($result['ok'] ?? false);
            $this->results[] = $result;
        } catch (Throwable $exception) {
            $this->results[] = [
                'id' => $id,
                'description' => $description,
                'ok' => false,
                'mismatches' => [[
                    'path' => 'exception',
                    'expected' => 'no exception',
                    'actual' => $exception->getMessage(),
                ]],
            ];
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function summary(): array
    {
        $passed = 0;
        $failed = 0;
        foreach ($this->results as $result) {
            if ((bool) ($result['ok'] ?? false)) {
                $passed++;
            } else {
                $failed++;
            }
        }

        return [
            'ok' => $failed === 0,
            'total' => count($this->results),
            'passed' => $passed,
            'failed' => $failed,
            'results' => $this->results,
        ];
    }
}
