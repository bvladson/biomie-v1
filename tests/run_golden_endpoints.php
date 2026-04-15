<?php

declare(strict_types=1);

use BIomie\LegacyEndpointPayloadFactory;

require_once __DIR__ . '/TestSupport.php';
require_once __DIR__ . '/TestRunner.php';

/**
 * @return array<string,mixed>
 */
function run_golden_endpoints_suite(bool $update = false): array
{
    $runner = new TestRunner();
    $fixturePaths = glob(__DIR__ . '/golden/fixtures/*.php') ?: [];
    sort($fixturePaths);

    foreach ($fixturePaths as $fixturePath) {
        $fixtureId = basename($fixturePath, '.php');
        $fixture = load_fixture($fixturePath);
        $description = (string) ($fixture['description'] ?? $fixtureId);

        $runner->run('golden:' . $fixtureId, $description, static function () use ($fixture, $fixtureId, $update): array {
            $payload = build_golden_endpoint_payload($fixture);
            $normalized = golden_normalize($payload, is_array($fixture['normalize_paths'] ?? null) ? $fixture['normalize_paths'] : []);
            $expectedPath = __DIR__ . '/golden/expected/' . $fixtureId . '.json';

            if ($update) {
                file_put_contents($expectedPath, pretty_json($normalized) . PHP_EOL);
                return [
                    'ok' => true,
                    'updated' => true,
                    'expected_path' => $expectedPath,
                ];
            }

            if (!is_file($expectedPath)) {
                return [
                    'ok' => false,
                    'mismatches' => [[
                        'path' => 'expected',
                        'expected' => 'snapshot file',
                        'actual' => 'missing: ' . $expectedPath,
                    ]],
                ];
            }

            $expected = load_expected_json($expectedPath);
            $mismatches = [];
            compare_golden_values($expected, $normalized, 'payload', $mismatches);

            return [
                'ok' => $mismatches === [],
                'mismatches' => array_slice($mismatches, 0, 20),
            ];
        });
    }

    return $runner->summary();
}

/**
 * @param array<string,mixed> $fixture
 * @return array<string,mixed>
 */
function build_golden_endpoint_payload(array $fixture): array
{
    $dataset = (string) ($fixture['dataset'] ?? '');
    $snapshot = is_array($fixture['snapshot'] ?? null) ? $fixture['snapshot'] : [];
    $freshness = make_freshness_service(is_array($fixture['freshness_caches'] ?? null) ? $fixture['freshness_caches'] : []);

    return match ($dataset) {
        'overview' => LegacyEndpointPayloadFactory::overview($snapshot, $freshness),
        'orders' => LegacyEndpointPayloadFactory::orders($snapshot, $freshness, (string) ($fixture['status'] ?? '')),
        'vendors' => $freshness->attach('vendors', LegacyEndpointPayloadFactory::vendors(
            $snapshot,
            is_array($fixture['range'] ?? null) ? $fixture['range'] : [
                'start' => '',
                'end' => '',
                'label' => '',
                'competencia' => null,
            ],
            (string) ($fixture['source'] ?? 'legacy'),
            isset($fixture['canonical_error']) ? (string) $fixture['canonical_error'] : null
        )),
        'finance_overview' => LegacyEndpointPayloadFactory::financeOverview($snapshot, $freshness),
        'finance_titles' => LegacyEndpointPayloadFactory::financeTitles($snapshot, $freshness, (string) ($fixture['bucket'] ?? 'open')),
        'reconciliation' => LegacyEndpointPayloadFactory::reconciliation(
            $snapshot,
            $freshness,
            (string) ($fixture['start'] ?? ''),
            (string) ($fixture['end'] ?? '')
        ),
        default => throw new RuntimeException('Dataset golden nao suportado: ' . $dataset),
    };
}

/**
 * @param list<array<string,mixed>> $mismatches
 */
function compare_golden_values(mixed $expected, mixed $actual, string $path, array &$mismatches): void
{
    if (count($mismatches) >= 20) {
        return;
    }

    if (is_array($expected) && is_array($actual)) {
        $expectedKeys = array_keys($expected);
        $actualKeys = array_keys($actual);
        if ($expectedKeys !== $actualKeys) {
            $mismatches[] = [
                'path' => $path . '.__keys__',
                'expected' => $expectedKeys,
                'actual' => $actualKeys,
            ];
            if (count($mismatches) >= 20) {
                return;
            }
        }

        foreach ($expectedKeys as $key) {
            compare_golden_values(
                $expected[$key] ?? null,
                $actual[$key] ?? null,
                $path . '.' . (string) $key,
                $mismatches
            );
            if (count($mismatches) >= 20) {
                return;
            }
        }

        return;
    }

    if (is_numeric($expected) && is_numeric($actual)) {
        if (abs((float) $expected - (float) $actual) <= 0.00001) {
            return;
        }
    }

    if ($expected !== $actual) {
        $mismatches[] = [
            'path' => $path,
            'expected' => $expected,
            'actual' => $actual,
        ];
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    /** @var list<string> $args */
    $args = isset($GLOBALS['argv']) && is_array($GLOBALS['argv']) ? $GLOBALS['argv'] : [];
    $update = in_array('--update', $args, true);
    $result = run_golden_endpoints_suite($update);
    echo pretty_json($result) . PHP_EOL;
    exit(($result['ok'] ?? false) ? 0 : 1);
}
