<?php

declare(strict_types=1);

/**
 * GUARD — token CSRF enviado no CORPO JSON.
 *
 * ─── O BUG QUE ISTO IMPEDE DE VOLTAR ────────────────────────────────────────
 * Em 2026-08-04 o "marcar como lida" das notificações nunca funcionava. O log
 * dizia `CSRF inválido`, o que parecia token expirado — mas era token NUNCA
 * LIDO.
 *
 * `Http::enforceCsrf()` (chamado em api/bootstrap.php para todo método de
 * escrita) lia o token só de duas fontes: o header `X-CSRF-Token` e
 * `$_POST['csrf_token']`. Acontece que um POST com `Content-Type:
 * application/json` NÃO popula `$_POST` — o PHP só preenche esse array para
 * form-urlencoded e multipart. Todo fetch que mandava o token apenas dentro do
 * JSON chegava ao guard sem token nenhum e levava 419 ANTES de o endpoint rodar
 * uma linha.
 *
 * Não era um recurso quebrado, eram SETE — notificações (marcar lida e marcar
 * todas), Admin › Acessos & Empresas, visibilidade por vendedor, vínculo de
 * vendedor, provisionamento de usuário, beacon de performance e o "Atualizar"
 * da agenda. Todos passavam nos testes por CLI, que não exercitam o guard.
 *
 * A defesa é dupla, e este guard cobre as duas:
 *   1. SERVIDOR — enforceCsrf também aceita o token vindo do corpo JSON, com
 *      gate por content-type (em multipart o `php://input` não é relegível e
 *      lê-lo quebraria uploads);
 *   2. CLIENTE — todo POST JSON manda o token no header, que é o padrão.
 *
 * A parte 1 é provada por HTTP DE VERDADE, via `php -S` + a sonda
 * probe_csrf_json_body.php: `php://input` não é populado no SAPI de linha de
 * comando, então um teste em CLI puro provaria nada.
 *
 *   php tests/run_csrf_json_body_contract.php
 */

require_once __DIR__ . '/TestSupport.php';

$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$root = dirname(__DIR__);

// ── Parte 1: comportamento real do guard, sobre HTTP ────────────────────────

/** Porta livre: abre em 0, lê a atribuída, devolve. Corrida mínima e aceitável. */
$freePort = static function (): int {
    $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($sock === false) {
        return 8799;
    }
    $name = (string) stream_socket_get_name($sock, false);
    fclose($sock);
    return (int) substr($name, (int) strrpos($name, ':') + 1);
};

$port = $freePort();
$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root],
    $descriptors,
    $pipes,
    $root
);

$serverUp = is_resource($server);
if ($serverUp) {
    // Espera a porta aceitar conexão em vez de dormir um tempo fixo.
    $deadline = microtime(true) + 10.0;
    $ready = false;
    while (microtime(true) < $deadline) {
        $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.3);
        if (is_resource($probe)) {
            fclose($probe);
            $ready = true;
            break;
        }
        usleep(100_000);
    }
    $serverUp = $ready;
}

$check('servidor de teste no ar (php -S)', $serverUp, 'porta=' . $port);

/**
 * @param array<string,string> $headers
 * @return array{status:int,body:string}
 */
$request = static function (string $token, string $contentType, string $body, array $headers = []) use ($port): array {
    $handle = curl_init();
    $headerLines = ['Content-Type: ' . $contentType];
    foreach ($headers as $name => $value) {
        $headerLines[] = $name . ': ' . $value;
    }
    curl_setopt_array($handle, [
        CURLOPT_URL => 'http://127.0.0.1:' . $port . '/tests/probe_csrf_json_body.php?expected=' . rawurlencode($token),
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headerLines,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);

    return ['status' => $status, 'body' => is_string($response) ? $response : ''];
};

if ($serverUp) {
    $token = 'TOKEN-CANARIO-' . bin2hex(random_bytes(8));

    // O caso do bug: token SÓ no corpo JSON, sem header, sem $_POST.
    $r = $request($token, 'application/json', json_encode(['csrf_token' => $token], JSON_THROW_ON_ERROR));
    $check('🔴 token só no corpo JSON é ACEITO (era o bug: 419)',
        $r['status'] === 200 && str_contains($r['body'], 'ACEITOU'),
        'status=' . $r['status']);

    // O guard continua guardando: token errado no corpo é recusado.
    $r = $request($token, 'application/json', json_encode(['csrf_token' => 'TOKEN-ERRADO'], JSON_THROW_ON_ERROR));
    $check('token ERRADO no corpo JSON é recusado (419)', $r['status'] === 419, 'status=' . $r['status']);

    // Sem token nenhum continua recusado.
    $r = $request($token, 'application/json', '{}');
    $check('corpo JSON sem token é recusado (419)', $r['status'] === 419, 'status=' . $r['status']);

    // Gate por content-type: em multipart o corpo NÃO pode ser consumido, senão
    // uploads quebram. Mesmo com o token no corpo, tem que recusar.
    $r = $request($token, 'multipart/form-data', json_encode(['csrf_token' => $token], JSON_THROW_ON_ERROR));
    $check('🔴 multipart NÃO tem o corpo lido (protege uploads)', $r['status'] === 419, 'status=' . $r['status']);

    // Os dois caminhos que já funcionavam não podem regredir.
    $r = $request($token, 'application/json', '{}', ['X-CSRF-Token' => $token]);
    $check('header X-CSRF-Token continua aceito', $r['status'] === 200, 'status=' . $r['status']);

    $r = $request($token, 'application/x-www-form-urlencoded', http_build_query(['csrf_token' => $token]));
    $check('$_POST[csrf_token] continua aceito', $r['status'] === 200, 'status=' . $r['status']);
}

if (is_resource($server)) {
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_terminate($server);
    proc_close($server);
}

// ── Parte 2: o cliente manda o token no header ──────────────────────────────
// Defesa em profundidade. Se um dia o servidor voltar a ler só header/$_POST,
// o front não pode ser o elo que some com o token.

$sources = [];
foreach (['/assets/js', '/views'] as $dir) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . $dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        $path = $file->getPathname();
        if (!preg_match('~\.(js|php)$~', $path) || str_contains($path, '.min.')) {
            continue;
        }
        $sources[] = $path;
    }
}

$check('há fontes para varrer', $sources !== [], count($sources) . ' arquivos');

$offenders = [];
foreach ($sources as $path) {
    $src = (string) file_get_contents($path);
    // Interessa quem monta corpo JSON COM token: é esse o padrão que morria.
    if (!str_contains($src, 'JSON.stringify') || !str_contains($src, 'csrf_token')) {
        continue;
    }
    if (!str_contains($src, 'X-CSRF-Token')) {
        $offenders[] = str_replace($root, '', $path);
    }
}
$check('🔴 todo POST JSON com csrf_token manda o header X-CSRF-Token',
    $offenders === [],
    implode(', ', $offenders));

// ── Parte 3: o servidor mantém a leitura do corpo JSON ──────────────────────
$httpSource = (string) file_get_contents($root . '/app/Http.php');
$check('enforceCsrf consulta o corpo JSON',
    str_contains($httpSource, 'csrfTokenFromJsonBody'));
$check('a leitura do corpo é gateada por content-type json',
    preg_match('~csrfTokenFromJsonBody.*?str_contains\(\$contentType, \'json\'\)~s', $httpSource) === 1);

// ── Parte 4: o minificado não pode servir a versão antiga ──────────────────
// Armadilha conhecida: Asset::preferMinified só serve o .min se ele for MAIS
// NOVO que o fonte. Um .min velho reintroduz o bug em produção com o fonte já
// corrigido — foi exatamente assim que o form.action voltou uma vez.
foreach (['notifications', 'performance-monitor'] as $name) {
    $sourcePath = $root . '/assets/js/' . $name . '.js';
    $minPath = $root . '/assets/js/' . $name . '.min.js';
    if (!is_file($minPath)) {
        continue;
    }
    $check($name . '.min.js manda X-CSRF-Token',
        str_contains((string) file_get_contents($minPath), 'X-CSRF-Token'));
    $check($name . '.min.js mais novo (ou igual) que o fonte',
        filemtime($minPath) >= filemtime($sourcePath),
        'min=' . date('c', (int) filemtime($minPath)) . ' src=' . date('c', (int) filemtime($sourcePath)));
}

$passed = count(array_filter($checks, static fn (array $r): bool => $r['ok']));
$result = [
    'ok' => $passed === count($checks),
    'total' => count($checks),
    'passed' => $passed,
    'failed' => count($checks) - $passed,
    'checks' => $checks,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($result['ok'] ? 0 : 1);
