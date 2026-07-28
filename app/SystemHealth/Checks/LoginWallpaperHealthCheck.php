<?php

declare(strict_types=1);

namespace BIomie\SystemHealth\Checks;

use BIomie\SystemHealth\CheckInterface;
use BIomie\SystemHealth\SystemHealthCheck;

final class LoginWallpaperHealthCheck implements CheckInterface
{
    private const CACHE_FILE = __DIR__ . '/../../../storage/cache/login_wallpaper.json';
    private const TTL = 43200; // 12h default
    private const WARN_BEFORE = 3600; // warn when < 1h left

    public function id(): string { return 'login_wallpaper'; }
    public function label(): string { return 'Wallpaper do Login'; }

    public function run(): SystemHealthCheck
    {
        $start = microtime(true);
        $this->loadLocalUnsplashEnv();

        if (!file_exists(self::CACHE_FILE)) {
            return SystemHealthCheck::warning(
                $this->id(),
                $this->label(),
                'Cache ausente; o wallpaper sera gerado na proxima chamada ao login.',
                ['cache_exists' => false, 'auth_impact' => 'none'],
                ['Aquecer cache com php tools/dev/login_wallpaper_debug.php --fetch --json.'],
                $this->ms($start),
            );
        }

        $raw = @file_get_contents(self::CACHE_FILE);
        if ($raw === false) {
            return SystemHealthCheck::critical(
                $this->id(),
                $this->label(),
                'Arquivo de cache ilegivel.',
                [],
                ['Verificar permissoes de storage/cache/login_wallpaper.json.'],
                $this->ms($start),
            );
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return SystemHealthCheck::critical(
                $this->id(),
                $this->label(),
                'Cache corrompido (JSON invalido).',
                [],
                ['Remover storage/cache/login_wallpaper.json para forcar renovacao.'],
                $this->ms($start),
            );
        }

        $source = (string) ($data['source'] ?? 'unknown');
        $provider = (string) ($data['provider'] ?? 'unknown');
        $cachedAt = (string) ($data['cached_at'] ?? '');
        $url = (string) ($data['url'] ?? '');
        $query = (string) ($data['query'] ?? '');
        $photographerName = (string) ($data['photographer_name'] ?? '');

        $cachedTs = 0;
        if (isset($data['_cached_at_ts'])) {
            $cachedTs = (int) $data['_cached_at_ts'];
        } elseif ($cachedAt !== '') {
            $cachedTs = (int) strtotime($cachedAt) ?: (int) $cachedAt;
        }

        $age = $cachedTs > 0 ? (time() - $cachedTs) : PHP_INT_MAX;
        $effectiveTtl = $source === 'fallback' ? 3600 : self::TTL;
        $expiresIn = $effectiveTtl - $age;

        $urlDomain = '';
        if ($url !== '' && !str_starts_with($url, 'assets/')) {
            $urlDomain = (string) (parse_url($url, PHP_URL_HOST) ?: '(parse error)');
        } elseif ($url !== '') {
            $urlDomain = 'local (fallback)';
        }

        $accessKeySet = (string) getenv('UNSPLASH_ACCESS_KEY') !== '';
        $metrics = [
            'provider' => $provider,
            'source' => $source,
            'cache_criado' => $cachedTs > 0 ? date('d/m H:i', $cachedTs) : '-',
            'idade' => $this->fmtAge($age),
            'proxima_atualizacao' => $cachedTs > 0 ? date('d/m H:i', $cachedTs + $effectiveTtl) : '-',
            'expira_em' => $expiresIn > 0 ? $this->fmtAge($expiresIn) : 'expirado',
            'url_dominio' => $urlDomain ?: '(vazio)',
            'photographer' => $photographerName ?: '(fallback)',
            'query' => $query !== '' ? substr($query, 0, 60) : '(nenhuma)',
            'access_key_configurada' => $accessKeySet ? 'sim' : 'nao',
            'auth_impact' => 'none',
        ];

        if (!empty($data['_last_error'])) {
            $metrics['ultimo_erro'] = (string) $data['_last_error'];
        }

        $ms = $this->ms($start);

        if ($url === '' || $cachedTs <= 0) {
            return SystemHealthCheck::critical(
                $this->id(),
                $this->label(),
                'Cache sem URL ou timestamp; estrutura invalida.',
                $metrics,
                ['Remover storage/cache/login_wallpaper.json para forcar renovacao.'],
                $ms,
            );
        }

        if (!$accessKeySet) {
            return SystemHealthCheck::warning(
                $this->id(),
                $this->label(),
                'UNSPLASH_ACCESS_KEY nao configurada; usando fallback local.',
                $metrics,
                ['Configurar UNSPLASH_ACCESS_KEY no .env para ativar imagens dinamicas.'],
                $ms,
            );
        }

        if ($source === 'fallback' || str_starts_with($url, 'assets/')) {
            // Se o fallback ja expirou (>1h), apaga o cache para forcar nova tentativa
            // no proximo carregamento do login — sem necessitar intervencao manual.
            if ($age >= 3600) {
                @unlink(self::CACHE_FILE);
                return SystemHealthCheck::warning(
                    $this->id(),
                    $this->label(),
                    'Cache fallback expirado; removido para forcar nova tentativa no proximo login.',
                    array_merge($metrics, ['auto_cleared' => true]),
                    ['Verificar conectividade com https://api.unsplash.com e validade da UNSPLASH_ACCESS_KEY.'],
                    $ms,
                );
            }

            return SystemHealthCheck::warning(
                $this->id(),
                $this->label(),
                'Usando imagem de fallback local; Unsplash nao retornou URL valida na ultima tentativa.',
                $metrics,
                [
                    'Verificar conectividade do servidor com https://api.unsplash.com.',
                    'Verificar validade da UNSPLASH_ACCESS_KEY.',
                    'Cache sera removido automaticamente quando expirar (1h).',
                ],
                $ms,
            );
        }

        if ($expiresIn < self::WARN_BEFORE && $expiresIn > 0) {
            return SystemHealthCheck::warning(
                $this->id(),
                $this->label(),
                'Cache proximo do vencimento; atualiza em ' . $this->fmtAge($expiresIn) . '.',
                $metrics,
                [],
                $ms,
            );
        }

        if ($age > $effectiveTtl) {
            return SystemHealthCheck::warning(
                $this->id(),
                $this->label(),
                'Cache expirado ha ' . $this->fmtAge($age - $effectiveTtl) . '. Renova automaticamente na proxima visita ao login.',
                $metrics,
                [],
                $ms,
            );
        }

        return SystemHealthCheck::ok(
            $this->id(),
            $this->label(),
            'Wallpaper ativo via ' . $provider . ' (' . $source . '), cache valido por mais ' . $this->fmtAge($expiresIn) . '.',
            $metrics,
            $ms,
        );
    }

    private function ms(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }

    private function loadLocalUnsplashEnv(): void
    {
        $localConfig = dirname(__DIR__, 3) . '/config/.env.local.php';
        if (!is_file($localConfig)) {
            return;
        }

        $values = (array) include $localConfig;
        foreach (['UNSPLASH_ACCESS_KEY', 'UNSPLASH_ENABLED', 'UNSPLASH_UTM_SOURCE', 'UNSPLASH_QUERY', 'UNSPLASH_CACHE_TTL_HOURS'] as $key) {
            $current = getenv($key);
            if (isset($values[$key]) && $values[$key] !== '' && ($current === false || $current === '')) {
                putenv($key . '=' . $values[$key]);
            }
        }
    }

    private function fmtAge(int $seconds): string
    {
        if ($seconds < 0) {
            return '0s';
        }
        if ($seconds < 60) {
            return $seconds . 's';
        }
        if ($seconds < 3600) {
            return (int) ($seconds / 60) . 'min';
        }

        $hours = (int) ($seconds / 3600);
        $minutes = (int) (($seconds % 3600) / 60);
        return $hours . 'h' . ($minutes > 0 ? ' ' . $minutes . 'min' : '');
    }
}
