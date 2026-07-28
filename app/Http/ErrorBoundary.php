<?php

declare(strict_types=1);

namespace BIomie\Http;

use BIomie\Auth;
use BIomie\Bootstrap;
use BIomie\Http\HasReasonCode;
use BIomie\RequestContext;
use Throwable;

final class ErrorBoundary
{
    /** @var array<string,mixed> */
    private static array $context = [];
    private static bool $handling = false;

    /**
     * @param array<string,mixed> $context
     */
    public static function registerPageHandlers(array $context = []): void
    {
        self::$context = array_merge(self::routeContext(), $context);
        set_exception_handler(static function (Throwable $e): void {
            self::renderPageError($e, self::$context);
        });
        self::registerShutdown(false);
    }

    /**
     * @param array<string,mixed> $context
     */
    public static function registerApiHandlers(array $context = []): void
    {
        self::$context = array_merge(self::routeContext(), $context);
        set_exception_handler(static function (Throwable $e): void {
            self::renderJsonError($e, self::$context);
        });
        self::registerShutdown(true);
    }

    /**
     * @param callable():void $callback
     * @param array<string,mixed> $context
     */
    public static function runPage(callable $callback, array $context = []): void
    {
        self::registerPageHandlers($context);
        try {
            $callback();
        } catch (Throwable $e) {
            self::renderPageError($e, array_merge(self::$context, $context));
        }
    }

    /**
     * @param callable():void $callback
     * @param array<string,mixed> $context
     */
    public static function runApi(callable $callback, array $context = []): void
    {
        self::registerApiHandlers($context);
        try {
            $callback();
        } catch (Throwable $e) {
            self::renderJsonError($e, array_merge(self::$context, $context));
        }
    }

    public static function requestId(): string
    {
        try {
            $id = RequestContext::id();
            if ($id !== '') {
                return $id;
            }
        } catch (Throwable) {
        }

        try {
            return 'req_' . bin2hex(random_bytes(8));
        } catch (Throwable) {
            return 'req_' . sha1(uniqid('', true));
        }
    }

    /**
     * @param array<string,mixed> $context
     */
    public static function log(Throwable $e, array $context = []): string
    {
        $requestId = self::requestId();
        $entry = [
            'ts' => date(DATE_ATOM),
            'request_id' => $requestId,
            'level' => 'error',
            'route' => self::routeLabel($context),
            'user_id' => self::currentUserField('id'),
            'user_email' => self::currentUserField('email'),
            'exception_class' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'context' => ErrorResponse::safeContext($context),
            'trace_hash' => substr(hash('sha256', $e->getTraceAsString()), 0, 16),
            'trace' => $e->getTraceAsString(),
        ];

        $path = self::logPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents(
            $path,
            json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
        error_log('BIomie error ' . $requestId . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

        return $requestId;
    }

    /**
     * @param array<string,mixed> $context
     */
    public static function renderJsonError(Throwable $e, array $context = []): void
    {
        if (self::$handling) {
            exit;
        }
        self::$handling = true;

        $status = self::statusFromThrowable($e);
        $requestId = self::log($e, $context);
        $errorCode = $e instanceof HasReasonCode
            ? $e->getReasonCode()
            : ($status >= 500 ? 'internal_error' : 'request_error');
        $payload = ErrorResponse::json(
            $requestId,
            $status,
            $errorCode,
            ErrorResponse::safeMessageForStatus($status),
            $context
        );
        [$payload, $contextHeaders] = \BIomie\Http::contextualizePayload($payload);

        if (self::canExposeDiagnostics()) {
            $payload['diagnostics'] = [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => basename($e->getFile()),
                'line' => $e->getLine(),
            ];
        }

        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Request-Id: ' . $requestId);
            header('Vary: Accept, X-BIOMIE-Context');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            foreach ($contextHeaders as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    /**
     * @param array<string,mixed> $context
     */
    public static function renderPageError(Throwable $e, array $context = []): void
    {
        if (self::$handling) {
            exit;
        }
        self::$handling = true;

        $status = self::statusFromThrowable($e);
        if ($status < 400 || $status > 599) {
            $status = 500;
        }
        $requestId = self::log($e, $context);
        self::renderErrorShell(
            $status,
            $status >= 500 ? 'Erro interno controlado' : 'Acesso nao concluido',
            ErrorResponse::safeMessageForStatus($status),
            $requestId,
            $context,
            $e
        );
    }

    /**
     * @param array<string,mixed> $context
     */
    public static function renderForbiddenPage(string $message, array $context = []): void
    {
        self::renderErrorShell(403, 'Acesso nao autorizado', $message, self::requestId(), $context, null);
    }

    /**
     * @param array<string,mixed> $context
     */
    private static function renderErrorShell(int $status, string $title, string $message, string $requestId, array $context, ?Throwable $e): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=utf-8');
            header('X-Request-Id: ' . $requestId);
        }

        $route = htmlspecialchars(self::routeLabel($context), ENT_QUOTES, 'UTF-8');
        $titleEsc = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $messageEsc = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $requestEsc = htmlspecialchars($requestId, ENT_QUOTES, 'UTF-8');
        $showDiagnostics = self::canExposeDiagnostics();
        $diag = '';
        if ($showDiagnostics && $e !== null) {
            $diag = '<details class="bmie-error__details" open><summary>Diagnostico tecnico</summary>'
                . '<dl><dt>Classe</dt><dd>' . htmlspecialchars($e::class, ENT_QUOTES, 'UTF-8') . '</dd>'
                . '<dt>Mensagem segura</dt><dd>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</dd>'
                . '<dt>Arquivo</dt><dd>' . htmlspecialchars(basename($e->getFile()) . ':' . $e->getLine(), ENT_QUOTES, 'UTF-8') . '</dd>'
                . '<dt>Rota</dt><dd>' . $route . '</dd></dl></details>';
        }

        echo <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$titleEsc} | BIomie</title>
  <style>
    :root { color-scheme: light; --brand:#96140a; --border:#e5e7eb; --muted:#64748b; --bg:#f6f7fb; --card:#fff; }
    body { margin:0; min-height:100vh; display:grid; place-items:center; background:var(--bg); font-family:system-ui,-apple-system,Segoe UI,sans-serif; color:#172033; }
    .bmie-error { width:min(720px, calc(100vw - 32px)); background:var(--card); border:1px solid var(--border); border-radius:18px; padding:28px; box-shadow:0 18px 60px rgba(15,23,42,.08); }
    .bmie-error__eyebrow { margin:0 0 8px; color:var(--brand); font-size:12px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; }
    h1 { margin:0 0 8px; font-size:24px; }
    p { margin:0; color:var(--muted); line-height:1.55; }
    .bmie-error__grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:10px; margin:20px 0; }
    .bmie-error__pill { border:1px solid var(--border); border-radius:10px; padding:10px 12px; background:#f8fafc; }
    .bmie-error__pill span { display:block; color:var(--muted); font-size:11px; text-transform:uppercase; letter-spacing:.06em; }
    .bmie-error__pill strong { display:block; margin-top:4px; font-family:ui-monospace,SFMono-Regular,Consolas,monospace; font-size:12px; word-break:break-all; }
    .bmie-error__details { margin-top:16px; border-top:1px solid var(--border); padding-top:14px; color:#334155; }
    .bmie-error__details dl { display:grid; grid-template-columns:120px 1fr; gap:8px 12px; font-size:13px; }
    .bmie-error__details dt { color:var(--muted); }
    .bmie-error__actions { display:flex; gap:10px; flex-wrap:wrap; margin-top:22px; }
    .bmie-error__actions a, .bmie-error__actions button { border:1px solid var(--border); border-radius:10px; padding:10px 14px; background:#fff; color:#172033; text-decoration:none; cursor:pointer; font-weight:700; }
    .bmie-error__actions a:first-child { background:var(--brand); color:#fff; border-color:var(--brand); }
  </style>
</head>
<body>
  <main class="bmie-error" role="alert">
    <p class="bmie-error__eyebrow">BIomie MAG</p>
    <h1>{$titleEsc}</h1>
    <p>{$messageEsc}</p>
    <div class="bmie-error__grid">
      <div class="bmie-error__pill"><span>Status</span><strong>{$status}</strong></div>
      <div class="bmie-error__pill"><span>Request ID</span><strong>{$requestEsc}</strong></div>
      <div class="bmie-error__pill"><span>Rota</span><strong>{$route}</strong></div>
    </div>
    {$diag}
    <div class="bmie-error__actions">
      <a href="index.php">Ir para o painel</a>
      <button type="button" onclick="window.location.reload()">Tentar novamente</button>
    </div>
  </main>
</body>
</html>
HTML;
        exit;
    }

    private static function registerShutdown(bool $api): void
    {
        register_shutdown_function(static function () use ($api): void {
            $error = error_get_last();
            if ($error === null) {
                return;
            }
            if (!in_array((int) ($error['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }

            $e = new \ErrorException(
                (string) ($error['message'] ?? 'Fatal PHP error'),
                0,
                (int) ($error['type'] ?? E_ERROR),
                (string) ($error['file'] ?? 'unknown'),
                (int) ($error['line'] ?? 0)
            );
            if ($api) {
                self::renderJsonError($e, self::$context);
            }
            self::renderPageError($e, self::$context);
        });
    }

    private static function statusFromThrowable(Throwable $e): int
    {
        $code = (int) $e->getCode();
        return $code >= 400 && $code <= 599 ? $code : 500;
    }

    private static function canExposeDiagnostics(): bool
    {
        try {
            return Auth::hasAnyCapability(['logs.view', 'admin.view']);
        } catch (Throwable) {
            return false;
        }
    }

    private static function currentUserField(string $field): mixed
    {
        try {
            $user = Auth::currentUser();
            return is_array($user) ? ($user[$field] ?? null) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private static function routeContext(): array
    {
        $route = PHP_SAPI === 'cli'
            ? implode(' ', $_SERVER['argv'] ?? [])
            : (string) ($_SERVER['SCRIPT_NAME'] ?? '');

        return [
            'route' => basename($route ?: 'unknown'),
            'request_uri' => PHP_SAPI === 'cli' ? $route : (string) ($_SERVER['REQUEST_URI'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $context
     */
    private static function routeLabel(array $context): string
    {
        return (string) ($context['route'] ?? $context['page'] ?? $context['api'] ?? RequestContext::route());
    }

    private static function logPath(): string
    {
        try {
            return Bootstrap::rootPath('storage/logs/biomie_errors.jsonl');
        } catch (Throwable) {
            return dirname(__DIR__, 2) . '/storage/logs/biomie_errors.jsonl';
        }
    }
}
