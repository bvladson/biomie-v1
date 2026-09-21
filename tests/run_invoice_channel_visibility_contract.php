<?php

declare(strict_types=1);

/**
 * Contrato do domínio fiscal usado pelo classificador e pela escrita canônica.
 *
 * Impede que um membro aceito pelo banco/regras seja rejeitado durante o
 * upsert e garante falha fechada para valores fora do domínio.
 */

require_once __DIR__ . '/../app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Canonical\CanonicalWriteRepository;
use BIomie\Support\ChannelClassifierService;

$checks = [];
$check = static function (string $id, bool $ok, mixed $actual = null) use (&$checks): void {
    $checks[] = ['id' => $id, 'ok' => $ok, 'actual' => $actual];
};

$repositoryReflection = new ReflectionClass(CanonicalWriteRepository::class);
$resolveVisibility = $repositoryReflection->getMethod('resolveInvoiceVisibility');
$resolveVisibility->setAccessible(true);
$preserveManual = $repositoryReflection->getMethod('existingManualInvoiceVisibility');
$preserveManual->setAccessible(true);

$makeRepository = static function (mixed $classifiedChannel) use ($repositoryReflection): CanonicalWriteRepository {
    $classifierReflection = new ReflectionClass(ChannelClassifierService::class);
    /** @var ChannelClassifierService $classifier */
    $classifier = $classifierReflection->newInstanceWithoutConstructor();
    $classifierReflection->getProperty('rules')->setValue($classifier, [[
        'id' => 900,
        'rule_type' => 'default',
        'match_value' => '',
        'target_channel' => $classifiedChannel,
        'confidence' => 'alta',
    ]]);
    $classifierReflection->getProperty('rulesLoaded')->setValue($classifier, true);

    /** @var CanonicalWriteRepository $repository */
    $repository = $repositoryReflection->newInstanceWithoutConstructor();
    $repositoryReflection->getProperty('vendorScopeRules')->setValue($repository, []);
    $repositoryReflection->getProperty('channelClassifier')->setValue($repository, $classifier);
    return $repository;
};

$expected = [
    'varejo' => ['scope' => 'comercial', 'visible' => 1],
    'licitacao' => ['scope' => 'licitacao', 'visible' => 0],
    'outros' => ['scope' => 'outros', 'visible' => 0],
];

foreach ($expected as $channel => $contract) {
    $repository = $makeRepository($channel);
    $result = $resolveVisibility->invoke($repository, []);
    $check(
        "classifier_accepts_{$channel}",
        $result['channel'] === $channel
            && $result['scope'] === $contract['scope']
            && $result['visible'] === $contract['visible'],
        $result,
    );
}

$explicitOther = $resolveVisibility->invoke(
    $makeRepository('varejo'),
    ['business_channel' => ' OUTROS '],
);
$check(
    'explicit_outros_is_normalized_and_accepted',
    $explicitOther['channel'] === 'outros'
        && $explicitOther['scope'] === 'outros'
        && $explicitOther['visible'] === 0,
    $explicitOther,
);

$preservedOther = $preserveManual->invoke($makeRepository('varejo'), [
    'business_channel' => 'outros',
    'business_unit_scope' => '',
    'is_biomie_commercial_visible' => 0,
    'channel_confidence' => 'manual',
    'visibility_rule_source' => 'manual_test',
]);
$check(
    'manual_outros_is_preserved',
    is_array($preservedOther)
        && $preservedOther['channel'] === 'outros'
        && $preservedOther['scope'] === 'outros'
        && $preservedOther['visible'] === 0,
    $preservedOther,
);

foreach (['unknown' => 'desconhecido', 'null' => null] as $id => $invalidChannel) {
    $thrown = null;
    try {
        $resolveVisibility->invoke($makeRepository($invalidChannel), []);
    } catch (Throwable $exception) {
        $thrown = $exception;
    }
    $check(
        "classifier_rejects_{$id}",
        $thrown instanceof RuntimeException
            && str_contains($thrown->getMessage(), 'canal fiscal invalido'),
        $thrown?->getMessage(),
    );
}

$check(
    'domain_normalizer_rejects_unknown_and_null',
    ChannelClassifierService::normalizeChannel('desconhecido') === null
        && ChannelClassifierService::normalizeChannel(null) === null,
);

$failed = array_values(array_filter($checks, static fn (array $row): bool => !$row['ok']));
$result = [
    'ok' => $failed === [],
    'passed' => count($checks) - count($failed),
    'total' => count($checks),
    'checks' => $checks,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($result['ok'] ? 0 : 1);
