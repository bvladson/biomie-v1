<?php

declare(strict_types=1);

namespace BIomie\SystemHealth\Checks;

use BIomie\SystemHealth\CheckInterface;
use BIomie\SystemHealth\SystemHealthCheck;

final class LoginWallpaperHealthCheck implements CheckInterface
{
    private const CACHE_FILE  = __DIR__ . '/../../../storage/cache/login_wallpaper.json';
    private const TTL         = 43200; // 12h default
    private const WARN_BEFORE = 3600;  // warn when < 1h left

    public function id(): string    { return 'login_wallpaper'; }
    public function label(): string { return 'Wallpaper do Login'; }

    public function run(): SystemHealthCheck
    {
        $start = microtime(true);

        if (!file_exists(self::CACHE_FILE)) {
            return SystemHealthCheck::unknown(
                $this->id(), $this->label(),
                new \RuntimeException('Cache ausente — api/login_wallpaper.php ainda não foi chamada neste ciclo.'),
                $this->ms($start),
            );
        }

        $raw = @file_get_contents(self::CACHE_FILE);
        if ($raw === false) {
            return SystemHealthCheck::critical(
                $this->id(), $this->label(),
                'Arquivo de cache ilegível.',
                [],
                ['Verificar permissões de storage/cache/login_wallpaper.json.'],
                $this->ms($start),
            );
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return SystemHealthCheck::critical(
                $this->id(), $this->label(),
                'Cache corrompido (JSON inválido).',
                [],
                ['Remover storage/cache/login_wallpaper.json para forçar renovação.'],
                $this->ms($start),
            );
        }

        $source   = (string) ($data['source']   ?? 'unknown');
        $provider = (string) ($data['provider'] ?? 'unknown');
        $cachedAt = (string) ($data['cached_at'] ?? '');
        $expiresAt = (string) ($data['expires_at'] ?? '');
        $url      = (string) ($data['url']      ?? '');
        $query    = (string) ($data['query']    ?? '');
        $photographerName = (string) ($data['photographer_name'] ?? '');

        // Resolve timestamp — support both ISO string (new) and Unix int (legacy).
        $cachedTs = 0;
        if (isset($data['_cached_at_ts'])) {
            $cachedTs = (int) $data['_cached_at_ts'];
        } elseif ($cachedAt !== '') {
            $cachedTs = (int) strtotime($cachedAt) ?: (int) $cachedAt;
        }

        $age = $cachedTs > 0 ? (time() - $cachedTs) : PHP_INT_MAX;

        $effectiveTtl = ($source === 'fallback') ? 3600 : self::TTL;
        $expiresIn    = $effectiveTtl - $age;

        $urlDomain = '';
        if ($url !== '' && !str_starts_with($url, 'assets/')) {
            $urlDomain = (string) (parse_url($url, PHP_URL_HOST) ?: '(parse error)');
        } elseif ($url !== '') {
            $urlDomain = 'local (fallback)';
        }

        $accessKeySet = (string) getenv('UNSPLASH_ACCESS_KEY') !== '';

        $metrics = [
            'provider'            => $provider,
            'source'              => $source,
            'cache_criado'        => $cachedTs > 0 ? date('d/m H:i', $cachedTs)   : '—',
            'idade'               => $this->fmtAge($age),
            'proxima_atualizacao' => $cachedTs > 0 ? date('d/m H:i', $cachedTs + $effectiveTtl) : '—',
            'expira_em'           => $expiresIn > 0 ? $this->fmtAge($expiresIn)    : 'expirado',
            'url_dominio'         => $urlDomain ?: '(vazio)',
            'photographer'        => $photographerName ?: '(fallback)',
            'query'               => $query !== '' ? substr($query, 0, 60) : '(nenhuma)',
            'access_key_configurada' => $accessKeySet ? 'sim' : 'não',
        ];

        if (!empty($data['_last_error'])) {
            $metrics['ultimo_erro'] = (string) $data['_last_error'];
        }

        $ms = $this->ms($start);

        // Critical states.
        if ($url === '' || $cachedTs <= 0) {
            return SystemHealthCheck::critical(
                $this->id(), $this->label(),
                'Cache sem URL ou timestamp — estrutura inválida.',
                $metrics,
                ['Remover storage/cache/login_wallpaper.json para forçar renovação.'],
                $ms,
            );
        }

        // No access key.
        if (!$accessKeySet) {
            return SystemHealthCheck::warning(
                $this->id(), $this->label(),
                'UNSPLASH_ACCESS_KEY não configurada — usando fallback local.',
                $metrics,
                ['Configurar UNSPLASH_ACCESS_KEY no .env para ativar imagens dinâmicas.'],
                $ms,
            );
        }

        // Fallback or empty URL with key set.
        if ($source === 'fallback' || str_starts_with($url, 'assets/')) {
            return SystemHealthCheck::warning(
                $this->id(), $this->label(),
                'Usando imagem de fallback local — Unsplash não retornou URL válida na última tentativa.',
                $metrics,
                [
                    'Verificar conectividade do servidor com https://api.unsplash.com.',
                    'Verificar validade da UNSPLASH_ACCESS_KEY.',
                    'Remover storage/cache/login_wallpaper.json para forçar nova tentativa.',
                ],
                $ms,
            );
        }

        // Cache expiring soon (< 1h).
        if ($expiresIn < self::WARN_BEFORE && $expiresIn > 0) {
            return SystemHealthCheck::warning(
                $this->id(), $this->label(),
                'Cache próximo do vencimento — atualiza em ' . $this->fmtAge($expiresIn) . '.',
                $metrics,
                [],
                $ms,
            );
        }

        // Cache already expired (will renew on next login visit).
        if ($age > $effectiveTtl) {
            return SystemHealthCheck::warning(
                $this->id(), $this->label(),
                'Cache expirado há ' . $this->fmtAge($age - $effectiveTtl) . '. Renova automaticamente na próxima visita ao login.',
                $metrics,
                [],
                $ms,
            );
        }

        return SystemHealthCheck::ok(
            $this->id(), $this->label(),
            'Wallpaper ativo via ' . $provider . ' (' . $source . '), cache válido por mais ' . $this->fmtAge($expiresIn) . '.',
            $metrics,
            $ms,
        );
    }

    private function ms(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }

    private function fmtAge(int $seconds): string
    {
        if ($seconds < 0)    return '0s';
        if ($seconds < 60)   return $seconds . 's';
        if ($seconds < 3600) return (int) ($seconds / 60) . 'min';
        $h = (int) ($seconds / 3600);
        $m = (int) (($seconds % 3600) / 60);
        return $h . 'h' . ($m > 0 ? ' ' . $m . 'min' : '');
    }
}
