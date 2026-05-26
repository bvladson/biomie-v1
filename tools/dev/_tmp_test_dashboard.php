<?php
declare(strict_types=1);

// Mock the request environment
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/api/commercial_dashboard.php';
$_GET['start'] = '2026-05-01';
$_GET['end'] = '2026-05-25';

// Mock session and auth
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['biomie_auth'] = [
    'user_id' => 1,
    'last_activity' => time(),
    'logged_at' => time(),
];
$_SESSION['biomie_user_cache'] = [
    'ts' => time(),
    'data' => [
        'id' => 1,
        'username' => 'admin',
        'role' => 'admin',
        'is_active' => 1,
        'modules_json' => null,
    ],
];

// Capture output since the endpoint exits
ob_start();
try {
    require 'api/commercial_dashboard.php';
} catch (\Throwable $e) {
    ob_end_clean();
    echo "ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

$output = ob_get_clean();
echo "--- DASHBOARD API RESPONSE ---\n";
echo $output . "\n";
