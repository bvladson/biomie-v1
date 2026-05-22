# Login Wallpaper — Checklist de Aceite

Arquivo: `docs/login-wallpaper-checklist.md`  
Feature: Wallpaper dinâmico via Unsplash API  
Migrado de Bing → Unsplash em: 2026-05-16

---

## Arquivos envolvidos

| Arquivo | Papel |
|---|---|
| `login.php` | Ponto de entrada — PHP auth intacto, CSP para Unsplash CDN |
| `api/login_wallpaper.php` | Proxy público; sem autenticação |
| `app/Services/LoginUnsplashWallpaperService.php` | Fetch Unsplash API + cache 12h |
| `app/Services/LoginWallpaperService.php` | Wrapper legacy → delega a LoginUnsplashWallpaperService |
| `app/SystemHealth/Checks/LoginWallpaperHealthCheck.php` | Check de monitoramento |
| `assets/css/login-wallpaper.css` | Layout 2 painéis + glassmorphism + floating cards + animações |
| `assets/js/login-wallpaper.js` | sessionStorage (90min), preload, fallback, atribuição via DOM |
| `assets/img/login-fallback.svg` | Fundo local quando API falha |
| `storage/cache/login_wallpaper.json` | Cache PHP de 12h (criado na primeira chamada) |

---

## Cenários de teste obrigatórios

### 1. Sem UNSPLASH_ACCESS_KEY → fallback aparece

- [ ] Remover ou comentar `UNSPLASH_ACCESS_KEY` do `.env`.
- [ ] Apagar `storage/cache/login_wallpaper.json`.
- [ ] Acessar `login.php`.
- [ ] **Esperado**: fundo SVG de fallback exibido; formulário completamente funcional.
- [ ] **Verificar**: `#lw-debug` exibe `server_fallback`; nenhum erro PHP no log.
- [ ] Restaurar a chave após o teste.

### 2. Com key válida → imagem Unsplash aparece

- [ ] Confirmar `UNSPLASH_ACCESS_KEY` configurada e `UNSPLASH_ENABLED=1` no `.env`.
- [ ] Apagar `storage/cache/login_wallpaper.json`.
- [ ] Acessar `login.php`.
- [ ] **Esperado**: imagem de `images.unsplash.com` ou `plus.unsplash.com` exibida no fundo.
- [ ] **Verificar**: `#lw-debug` exibe `unsplash` ou `session`; crédito do fotógrafo visível na parte inferior esquerda.

### 3. Timeout da API → fallback

- [ ] Definir `UNSPLASH_ENABLED=0` no `.env`.
- [ ] Apagar `storage/cache/login_wallpaper.json`.
- [ ] Acessar `login.php`.
- [ ] **Esperado**: fundo SVG exibido; `#lw-debug` exibe `server_fallback`.
- [ ] Restaurar `UNSPLASH_ENABLED=1` após o teste.

### 4. Cache PHP válido → nenhuma chamada à API

- [ ] Com cache válido em `storage/cache/login_wallpaper.json` (source=`unsplash`, não expirado).
- [ ] Acessar `api/login_wallpaper.php` diretamente.
- [ ] **Esperado**: resposta imediata com `source: "cache"`; sem chamada a `api.unsplash.com`.
- [ ] **Verificar**: timestamp `cached_at` no JSON é o mesmo do arquivo de cache.

### 5. Cache PHP corrompido → deletar e usar fallback

- [ ] Escrever JSON inválido em `storage/cache/login_wallpaper.json`:
  ```bash
  echo "INVALID" > storage/cache/login_wallpaper.json
  ```
- [ ] Acessar `login.php`.
- [ ] **Esperado**: `LoginUnsplashWallpaperService` detecta JSON inválido, tenta buscar API (ou usa fallback se sem chave), regrava cache.
- [ ] **Verificar**: `storage/cache/login_wallpaper.json` é um JSON válido ao final.

### 6. sessionStorage corrompido → limpar e buscar do endpoint

- [ ] No DevTools console: `sessionStorage.setItem('biomie_login_wp_v2', 'CORRUPTED')`.
- [ ] Recarregar `login.php`.
- [ ] **Esperado**: JS captura erro no `JSON.parse`, remove a entrada corrompida, faz nova chamada ao endpoint.
- [ ] **Verificar**: `#lw-debug` exibe `unsplash` ou `server_fallback` (não `session`); nenhum erro não tratado no console.

### 7. CSP sem erros no console

- [ ] Abrir `login.php` com DevTools → Console.
- [ ] **Esperado**: zero erros de `Content Security Policy`.
- [ ] **Verificar**: `img-src` permite `images.unsplash.com` e `plus.unsplash.com`; `connect-src 'self'` impede chamada direta ao `api.unsplash.com` pelo browser.

### 8. Crédito do fotógrafo visível e correto

- [ ] Com imagem Unsplash carregada (não fallback), verificar parte inferior esquerda da tela.
- [ ] **Esperado**: texto "Foto por [Nome] no Unsplash" com links funcionais.
- [ ] **Verificar**: links abrem nova aba; href inclui `utm_source=biomie_mag&utm_medium=referral`; sem `innerHTML` com dados externos (inspecionar via Sources no DevTools).

### 9. Login funciona sem JavaScript

- [ ] Desativar JS no navegador (DevTools → Settings → Debugger → Disable JavaScript).
- [ ] Acessar `login.php`.
- [ ] **Esperado**: página renderiza com layout original (sem fundo, sem glassmorphism); formulário 100% funcional.
- [ ] **Verificar**: submissão do formulário redireciona corretamente; `body.lw-active` nunca adicionado.

### 10. Mobile ≤ 860px

- [ ] Abrir DevTools → Toggle Device Toolbar → iPhone 14 (390px).
- [ ] **Esperado**: painel esquerdo (`lw-panel-left`) oculto; card centralizado com max-width 460px; sem scroll horizontal.
- [ ] **Verificar**: formulário legível e botão acessível.

### 11. prefers-reduced-motion — sem animações

- [ ] Ativar: DevTools → Rendering → Emulate CSS media feature → `prefers-reduced-motion: reduce`.
- [ ] **Esperado**: orbs ocultos (`display: none`); card aparece sem animação; backdrop sem fade; floating cards sem flutuação.
- [ ] **Verificar**: nenhum deslocamento visual; formulário acessível de imediato.

### 12. Health check exibe informações de Unsplash

- [ ] Acessar `system_health.php` (como admin).
- [ ] Localizar card "Wallpaper do Login".
- [ ] **Esperado**: métricas mostram `provider: unsplash`, `source`, idade do cache, próxima atualização.
- [ ] **Verificar**: `UNSPLASH_ACCESS_KEY` **não aparece** em nenhum campo; URL completa não está visível — apenas o domínio.

### 13. Textos comerciais visíveis no painel esquerdo (desktop)

- [ ] Acessar em tela ≥ 861px com JS ativo.
- [ ] **Esperado** (painel esquerdo):
  - Badge "BIOMIE MAG"
  - Título "Gestão comercial em uma só visão."
  - Subtítulo "Acompanhe vendas, metas, clientes e resultados com clareza."
  - 4 floating cards: Vendas, Metas, Clientes, Produtos
  - Flow line: Omie → BIomie → Decisão
- [ ] **Verificar**: sem texto "BIomie" com I maiúsculo isolado, sem "Blomie".

### 14. Sem typos críticos

- [ ] Inspecionar HTML renderizado de `login.php`.
- [ ] **Verificar**: "BIomie" correto (BI maiúsculo, omie minúsculo); "MAG" correto; sem "Blomie" ou "Biomiê".

### 15. Nenhuma chamada externa ocorre no frontend além da imagem hotlinkada

- [ ] Abrir DevTools → aba Network → filtrar por tipo "Fetch/XHR".
- [ ] Carregar `login.php` com cache PHP e sessionStorage limpos.
- [ ] **Esperado**: única chamada XHR é para `api/login_wallpaper.php` (origem própria); nenhuma chamada a `api.unsplash.com`.
- [ ] **Verificar** na aba Network → tipo "Img": apenas `images.unsplash.com` ou `plus.unsplash.com` (a imagem hotlinkada).

---

## Diagnóstico rápido

```bash
# Forçar renovação do cache PHP
rm storage/cache/login_wallpaper.json

# Inspecionar cache atual (formatado)
php -r "echo json_encode(json_decode(file_get_contents('storage/cache/login_wallpaper.json')), JSON_PRETTY_PRINT);"

# Testar endpoint manualmente
curl -s http://localhost/api/login_wallpaper.php | python -m json.tool

# Ver campos internos removidos antes do envio ao frontend
# (campos _cached_at_ts, _last_error, _last_error_at aparecem no arquivo mas não na resposta HTTP)
```

Para ver a origem sem abrir DevTools:
```js
// Console do browser na página de login
document.getElementById('lw-debug').removeAttribute('hidden');
```

Valores possíveis de `#lw-debug`:

| Valor | Significa |
|---|---|
| `unsplash` | Cache PHP miss → nova imagem Unsplash |
| `cache` | Cache PHP hit → sem chamada à API |
| `session` | sessionStorage hit no browser (90min) |
| `session_fallback` | sessionStorage tinha o SVG de fallback |
| `server_fallback` | Servidor retornou fallback; JS usa SVG local |
| `fallback` | Fallback genérico |
| `fetch_error` | Falha de rede ao chamar o endpoint PHP |
| `api_bad_shape` | Endpoint retornou JSON com estrutura inválida |
| `no_fetch` | Browser não suporta `fetch()` |

---

## Checklist de segurança

- [ ] CSP do login bloqueia scripts inline e origens externas não listadas.
- [ ] `img-src` permite apenas `images.unsplash.com`, `plus.unsplash.com` e `data:`.
- [ ] `connect-src 'self'` — browser nunca chama `api.unsplash.com` diretamente.
- [ ] `form-action 'self'` impede redirect do formulário para URLs externas.
- [ ] `frame-ancestors 'none'` impede clickjacking via iframe.
- [ ] `UNSPLASH_ACCESS_KEY` não aparece em resposta JSON, logs, HTML ou sessionStorage.
- [ ] `photographer_url` e `unsplash_url` renderizados via `setAttribute('href', ...)` (sem `innerHTML`).
- [ ] `photographer_name` renderizado via `textContent` (sem `innerHTML`).
- [ ] URL de wallpaper validada tanto no PHP (whitelist do serviço) quanto no JS (whitelist do cliente).

---

## Monitoramento em produção

O check `LoginWallpaperHealthCheck` está registrado em `app/SystemHealth/SystemHealthService.php` e aparece na página **Saúde do Sistema** (`system_health.php`).

Métricas exibidas no card:

| Métrica | Descrição |
|---|---|
| `provider` | `unsplash` |
| `source` | `unsplash` / `cache` / `fallback` / `no_key` |
| `cache_criado` | Timestamp de quando o cache foi gerado |
| `idade` | Tempo desde a criação (ex: `2h 15min`) |
| `proxima_atualizacao` | Previsão de expiração do cache |
| `expira_em` | Tempo restante |
| `url_dominio` | Domínio da URL (`images.unsplash.com`) — nunca URL completa |
| `photographer` | Nome do fotógrafo |
| `query` | Termo de busca usado |
| `access_key_configurada` | `sim` / `não` — nunca expõe o valor |
| `ultimo_erro` | Última mensagem de erro (se houver) |

Status do card:

| Status | Condição |
|---|---|
| `ok` | Cache válido, URL Unsplash ativa, expira em > 1h |
| `warning` | Cache próximo do vencimento, expirado, source=fallback, ou sem access key |
| `critical` | Arquivo ilegível ou JSON corrompido |
| `unknown` | Cache não existe (nunca houve visita ao login) |

---

## Registro de produção — 2026-05-16

**Migração Bing → Unsplash concluída**

Mudanças críticas:
- `LoginUnsplashWallpaperService` substitui `LoginBingWallpaperService`
- `LoginWallpaperService` mantido como wrapper para retrocompatibilidade
- sessionStorage cache key bumped para `biomie_login_wp_v2` (invalida entradas Bing)
- CSP atualizada: `img-src` troca `bing.com` por `images.unsplash.com` e `plus.unsplash.com`
- Atribuição obrigatória do fotógrafo implementada via DOM (sem `innerHTML`)
- Painel esquerdo remodelado com copy comercial e floating cards animados
- Health check atualizado com métricas de provider, photographer, query e access_key_configurada
- `UNSPLASH_ACCESS_KEY` protegida em todas as camadas
