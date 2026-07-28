# BIomie — Estratégia de Cache

## Problema resolvido

O usuário precisava usar Ctrl+F5 repetidas vezes porque havia cache de browser, Service Worker e possivelmente OPcache entregando versões antigas.

## Camadas de cache no BIomie

| Camada | O que é | Regra |
|--------|---------|-------|
| **Browser HTTP cache** | Cache nativo do navegador via headers HTTP | PHP/API: `no-store`. Assets: `public, max-age=2592000, immutable` com `?v=filemtime` |
| **Service Worker / CacheStorage** | Cache programável no browser via `sw.js` | Nunca cacheia `.php` nem `/api/*`. Cacheia só assets versionados (`?v=`) |
| **OPcache** | Cache de opcode PHP em memória | Monitorado em System Health. Reset pelo admin após deploy se `validate_timestamps=0` |
| **Cache interno** | `storage/cache/api/*.json`, `storage/cache/dashboard/*.json` | Limpável via admin ou CLI |

---

## Regras por tipo de resposta

### HTML autenticado (páginas PHP)

```http
Cache-Control: no-store, no-cache, must-revalidate, max-age=0
Pragma: no-cache
Expires: 0
```

Implementado em: `app/Support/HttpCache::noStoreHtml()` → chamado por `PageRenderer::renderDocument()`.
Reforçado em: `.htaccess` via `<FilesMatch "\.(php)$">`.

### APIs dinâmicas (`/api/*.php`)

```http
Cache-Control: no-store, no-cache, must-revalidate, max-age=0
Content-Type: application/json; charset=utf-8
```

Implementado em: `app/Http::json()` (parâmetro `$cacheable = false` por padrão).
Reforçado em: `.htaccess` via regra `.php`.

### Assets estáticos com versão (`/assets/*.css?v=...`, `/assets/*.js?v=...`)

```http
Cache-Control: public, max-age=2592000, immutable
```

Implementado em: `.htaccess` via `<FilesMatch "\.(?:css|js|png|svg|...)$">`.
A URL muda via `Asset::url()` → `filemtime` do arquivo. Quando o arquivo muda, a URL muda e o navegador baixa a versão nova.

### Service Worker (`sw.js`) e manifest

```http
Cache-Control: no-cache, no-store, must-revalidate
```

Implementado em: `.htaccess`. Garante que o SW sempre seja reavaliado.

---

## Versionamento automático de assets

`Asset::url('assets/js/app.js')` → `assets/js/app.js?v=1737048820`

- Versão = `filemtime()` do arquivo no disco.
- Fallback = `config/build.php['version']` ou `BIOMIE_BUILD_VERSION` env.
- Implementado em `app/Support/Asset.php`.
- Usado por `PageRenderer`, `PageAssetRegistry` e `withMagDesignSystem()`.
- Meta `biomie-build` injetada no `<head>` de toda página autenticada.

---

## Service Worker — regras do `sw.js`

```js
// Nunca cacheia:
// - request.mode === 'navigate' (navegação de página)
// - URL termina em .php
// - URL contém /api/
// - requests não-GET

// Cacheia com cache-first:
// - /assets/*.css?v=, /assets/*.js?v=
// - /assets/img/*, /images/*
// - fontes, SVG, PNG, WebP
```

O `cache-refresh.js` detecta mudança de versão via meta `biomie-build` e pede atualização do SW + limpa caches antigos automaticamente.

---

## Como limpar cache operacional

### Painel admin (System Health → Manutenção de Cache)

Botões disponíveis para admins:
- **Limpar cache API** → POST `api/admin_clear_cache.php` com `clear=api`
- **Limpar cache Dashboard** → `clear=dashboard`
- **Limpar wallpaper** → `clear=wallpaper`
- **Limpar todos os caches** → `clear=all`
- **Resetar OPcache** → POST `api/admin_opcache_reset.php`

Todos exigem autenticação + permissão `settings.manage` + CSRF token.

### CLI (SSH no servidor)

```bash
# Limpar tudo + resetar OPcache
php tools/dev/cache_clear.php --all --opcache --json

# Só API
php tools/dev/cache_clear.php --api

# Dry-run (lista sem remover)
php tools/dev/cache_clear.php --all --dry-run --json

# Saída humana
php tools/dev/cache_clear.php --all --opcache
```

---

## Como validar no DevTools

1. **F12 → Network → aba Fetch/XHR**
   - `index.php`: coluna "Size" deve mostrar tamanho real, não "(Service Worker)" nem "(disk cache)"
   - `/api/overview.php`: idem — não pode vir de ServiceWorker
   - `app.js?v=XXXXX`: pode vir de "(disk cache)" ou "(memory cache)" — é esperado

2. **F12 → Application → Service Workers**
   - SW deve estar ativo com a versão atual
   - CacheStorage deve conter só assets (`assets/css/...`, `assets/js/...`, `images/...`)
   - Não deve conter `.php` nem `/api/`

3. **F12 → Application → Cache Storage**
   - Só deve existir `biomie-static-VERSION`
   - Chaves devem ser URLs de assets estáticos versionados

---

## System Health — checks de cache

| Check | O que verifica |
|-------|---------------|
| `OPcache PHP` | Ativo, `validate_timestamps`, hit rate, restart_pending |
| `Versão de assets` | app.js, CSS principal, cache-refresh.js, sw.js existem e têm versão |
| `Service Worker / Cache` | sw.js existe, não cacheia PHP/API, usa `cache: 'no-store'` para dinâmicos |
| `Cache de APIs` | Http::json() define no-store, api/bootstrap.php define Content-Type, .htaccess define no-store para .php |

---

## OPcache — cuidados após deploy

Se `opcache.validate_timestamps = 0` (para máxima performance), o PHP não detecta mudanças em arquivos automaticamente. Após fazer deploy:

1. Use o botão "Resetar OPcache" no painel admin → System Health.
2. Ou execute via CLI: `php tools/dev/cache_clear.php --opcache`.
3. Ou reinicie o PHP-FPM no servidor.

O System Health alerta quando `validate_timestamps = 0`.
