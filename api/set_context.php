<?php

declare(strict_types=1);

/**
 * Adaptador temporário para assets antigos.
 *
 * Toda autorização/criação/auditoria vive em context_switch.php. Este arquivo
 * não grava sessão e nunca reutiliza o context_id anterior.
 */
$legacyBody = json_decode((string) file_get_contents('php://input'), true);
$legacyBody = is_array($legacyBody) ? $legacyBody : [];
$action = strtolower(trim((string) ($legacyBody['action'] ?? 'switch')));

if ($action === 'clone_context') {
    $target = [
        'target_type' => 'clone',
        'csrf_token' => $legacyBody['csrf_token'] ?? null,
    ];
} elseif ($action === 'switch') {
    $canal = strtolower(trim((string) ($legacyBody['canal'] ?? 'varejo')));
    if ($canal === 'consolidado') {
        $target = [
            'target_type' => 'group',
            'company_ids' => is_array($legacyBody['allowed_ids'] ?? null)
                ? $legacyBody['allowed_ids']
                : [],
            'csrf_token' => $legacyBody['csrf_token'] ?? null,
        ];
    } else {
        $target = [
            'target_type' => 'company',
            'company_id' => $legacyBody['company_id'] ?? null,
            'business_scope' => $canal,
            'csrf_token' => $legacyBody['csrf_token'] ?? null,
        ];
    }
} else {
    $target = [
        'target_type' => 'invalid_legacy_action',
        'csrf_token' => $legacyBody['csrf_token'] ?? null,
    ];
}

$GLOBALS['biomie_context_switch_body'] = $target;
$GLOBALS['biomie_context_switch_legacy_response'] = true;
require __DIR__ . '/context_switch.php';
