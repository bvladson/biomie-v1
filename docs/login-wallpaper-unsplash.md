# Login Wallpaper — Integração Unsplash

Arquivo: `docs/login-wallpaper-unsplash.md`  
Feature: Wallpaper dinâmico via Unsplash API (substitui Bing)  
Implementado em: 2026-05-16

---

## Visão geral

O BIomie exibe um wallpaper dinâmico na tela de login via **Unsplash API**. O PHP faz a chamada à API e armazena o resultado em cache local (12h). O browser nunca chama `api.unsplash.com` diretamente — apenas carrega a imagem hotlinkada de `images.unsplash.com`.

Fluxo:

```
Browser → api/login_wallpaper.php → LoginUnsplashWallpaperService
                                          ├── cache válido? → retorna JSON
                                          └── cache expirado → GET api.unsplash.com/photos/random
                                                                    ↓
                                               storage/cache/login_wallpaper.json (12h)
```

---

## Criar conta na Unsplash Developers

1. Acesse **https://unsplash.com/developers** e faça login com sua conta Unsplash (ou crie uma).
2. Clique em **"Your apps"** → **"New Application"**.
3. Aceite os termos. Preencha nome e descrição (ex.: "BIomie MAG — login wallpaper").
4. Em **Redirect URI**: `urn:ietf:wg:oauth:2.0:oob` (sem OAuth, só acesso público).
5. Copie o **Access Key** (não o Secret Key — não é necessário para leitura pública).

> Em conta demo (gratuita) o limite é **50 requisições/hora**. Com cache de 12h o BIomie faz no máximo **2 req/dia**, bem abaixo do limite.

Para produção com mais instâncias, solicite upgrade para **Production** no painel Unsplash (requer aprovação + submissão de guidelines de atribuição).

---

## Configurar UNSPLASH_ACCESS_KEY

Adicione ao seu `.env` (ou ao ambiente do servidor — **nunca comite este arquivo**):

```dotenv
UNSPLASH_ACCESS_KEY=seu_access_key_aqui
UNSPLASH_UTM_SOURCE=biomie_mag
UNSPLASH_QUERY=modern office,corporate,minimalist
UNSPLASH_CACHE_TTL_HOURS=12
UNSPLASH_ENABLED=1
```

| Variável | Padrão | Descrição |
|---|---|---|
| `UNSPLASH_ACCESS_KEY` | *(sem padrão)* | Access Key da sua app Unsplash. **Obrigatória** para imagens dinâmicas. |
| `UNSPLASH_UTM_SOURCE` | `biomie_mag` | UTM source para links de atribuição (obrigatório pelas guidelines Unsplash). |
| `UNSPLASH_QUERY` | `modern office,corporate` | Termos de busca separados por vírgula. |
| `UNSPLASH_CACHE_TTL_HOURS` | `12` | Horas de validade do cache PHP. |
| `UNSPLASH_ENABLED` | `1` | `0` desativa chamadas à API e usa fallback local imediatamente. |

**A chave NUNCA deve aparecer em:**
- Resposta JSON do endpoint
- Logs de aplicação
- HTML da página de login
- sessionStorage do browser
- Arquivo de cache `login_wallpaper.json`

---

## Rate limits

| Plano | Limite | Cobertura com cache 12h |
|---|---|---|
| Demo (gratuito) | 50 req/hora | ~2 req/dia por instância → OK |
| Production | 5.000 req/hora | OK para qualquer escala |

Se o limite for atingido, a API retorna HTTP 429. O `LoginUnsplashWallpaperService` captura o erro e serve o fallback local sem exception visível para o usuário.

---

## Cache local (servidor)

**Arquivo**: `storage/cache/login_wallpaper.json`

Estrutura do cache (campos públicos retornados ao frontend):

```json
{
  "provider": "unsplash",
  "source": "unsplash",
  "url": "https://images.unsplash.com/photo-xxx?w=1920&q=85&auto=format&fit=crop",
  "blur_hash": "LKO2?U%2Tw=w]~RBVZRi};RPxuwH",
  "color": "#1a1a2e",
  "description": "Modern office interior",
  "alt_description": "white wooden table near window",
  "photographer_name": "Nome do Fotógrafo",
  "photographer_url": "https://unsplash.com/@usuario?utm_source=biomie_mag&utm_medium=referral",
  "unsplash_url": "https://unsplash.com/?utm_source=biomie_mag&utm_medium=referral",
  "download_location": "https://api.unsplash.com/photos/xxx/download",
  "cached_at": "2026-05-16T10:00:00-03:00",
  "expires_at": "2026-05-16T22:00:00-03:00",
  "query": "modern office,corporate,minimalist"
}
```

Campos internos (armazenados no JSON mas removidos antes de enviar ao frontend):

| Campo | Propósito |
|---|---|
| `_cached_at_ts` | Unix timestamp para cálculo de TTL sem `strtotime()` |
| `_last_error` | Última mensagem de erro (para health check) |
| `_last_error_at` | Timestamp do último erro |

**Forçar renovação do cache:**

```bash
rm storage/cache/login_wallpaper.json
```

Na próxima visita ao login, o endpoint busca uma nova imagem da API.

---

## Fallback

O sistema degrada graciosamente em toda situação de erro:

| Situação | Comportamento |
|---|---|
| `UNSPLASH_ACCESS_KEY` não configurada | Retorna `source='no_key'`, SVG local |
| `UNSPLASH_ENABLED=0` | Retorna `source='disabled'`, SVG local |
| Timeout de rede (>3s) | Retorna `source='fallback'`, SVG local |
| HTTP 429 (rate limit) | Retorna `source='fallback'`, SVG local |
| Resposta JSON malformada | Retorna `source='fallback'`, SVG local |
| URL fora da whitelist | Retorna `source='fallback'`, SVG local |
| sessionStorage corrompido | JS limpa a entrada e busca do endpoint |
| Imagem não carrega no browser | JS usa SVG local como `src` |

O arquivo de fallback é `assets/img/login-fallback.svg` — um SVG local que não requer rede.

---

## Atribuição (obrigatória pelas guidelines Unsplash)

O Unsplash **exige** atribuição nas imagens usadas. O BIomie exibe créditos na forma:

> Foto por **[Nome do Fotógrafo]** no **Unsplash**

Onde ambos os nomes são links com `?utm_source=biomie_mag&utm_medium=referral`.

**Implementação segura** (sem `innerHTML` com dados externos):

```js
var pLink = document.createElement('a');
pLink.textContent = name;            // DOM text — sem XSS
pLink.setAttribute('href', photographerUrl);
pLink.setAttribute('target', '_blank');
pLink.setAttribute('rel', 'noopener noreferrer');
el.appendChild(pLink);
```

Os links só são renderizados se `photographer_name` estiver presente. Em modo fallback (`source='fallback'` ou `source='error'`), o crédito é ocultado.

---

## Hotlinking de imagens

As imagens são servidas diretamente de `images.unsplash.com` e `plus.unsplash.com` — o PHP **não** faz proxy da imagem. Isso:

- Reduz uso de banda e CPU do servidor
- Aciona o registro de download no Unsplash (exigido pelas guidelines)
- É explicitamente permitido pelas guidelines de hotlinking do Unsplash

O browser carrega a imagem via `new Image(); img.src = url` e só aplica ao `background-image` após `onload`.

---

## CSP (Content Security Policy)

```
img-src 'self' data: https://images.unsplash.com https://plus.unsplash.com;
connect-src 'self';
```

- `img-src` permite os dois CDNs do Unsplash para hotlinking.
- `connect-src 'self'` — o browser **nunca** chama `api.unsplash.com`; apenas o PHP servidor faz isso.
- Sem `script-src 'unsafe-inline'` — nenhum handler inline no HTML.

---

## Debug node

Para inspecionar a origem da imagem sem abrir DevTools:

```js
// Console do browser na página de login
document.getElementById('lw-debug').removeAttribute('hidden');
```

Valores possíveis:

| Valor | Significa |
|---|---|
| `unsplash` | Imagem carregada da Unsplash API (cache PHP miss) |
| `cache` | Servida do cache PHP (sem chamada à API) |
| `session` | Carregada do sessionStorage do browser (90min) |
| `server_fallback` | Servidor retornou fallback; JS usa SVG local |
| `session_fallback` | sessionStorage tinha o SVG de fallback |
| `fallback` | Fallback genérico (sem estado específico) |
| `fetch_error` | Falha de rede ou timeout ao chamar o endpoint PHP |
| `api_bad_shape` | Endpoint retornou JSON com estrutura inválida |
| `no_fetch` | Browser não suporta `fetch()` |

---

## Health Check

O check `LoginWallpaperHealthCheck` aparece na página **Saúde do Sistema** (`system_health.php`).

Métricas exibidas:

| Métrica | Descrição |
|---|---|
| `provider` | `unsplash` |
| `source` | `unsplash` / `cache` / `fallback` / `no_key` |
| `cache_criado` | Data/hora de criação do cache |
| `idade` | Tempo desde a criação |
| `proxima_atualizacao` | Previsão de expiração |
| `expira_em` | Tempo restante (ou "expirado") |
| `url_dominio` | Domínio da URL (`images.unsplash.com`) — nunca a URL completa |
| `photographer` | Nome do fotógrafo (ou `(fallback)`) |
| `query` | Termo de busca usado |
| `access_key_configurada` | `sim` / `não` — nunca expõe o valor da chave |
| `ultimo_erro` | Última mensagem de erro armazenada (se houver) |

**A chave `UNSPLASH_ACCESS_KEY` nunca aparece no health check.**

Status:

| Status | Condição |
|---|---|
| `ok` | Cache válido, URL Unsplash ativa, expira em > 1h |
| `warning` | Cache < 1h para expirar, expirado, source=fallback, ou sem access key |
| `critical` | Arquivo de cache ilegível ou JSON corrompido |
| `unknown` | Cache não existe (nunca houve visita ao login) |

---

## Testar sem internet

Para simular falha de conectividade com Unsplash:

**Opção 1 — Desabilitar via env:**
```dotenv
UNSPLASH_ENABLED=0
```
O serviço retorna fallback imediatamente sem nenhuma chamada de rede.

**Opção 2 — Remover a chave:**
```dotenv
# UNSPLASH_ACCESS_KEY=  (comentar ou remover)
```
Retorna `source='no_key'` e usa fallback.

**Opção 3 — Cache com source=fallback:**
```bash
echo '{"provider":"unsplash","source":"fallback","url":"assets/img/login-fallback.svg","blur_hash":"","color":"#0f172a","description":"","alt_description":"","photographer_name":"","photographer_url":"","unsplash_url":"","download_location":"","cached_at":"2026-05-16T10:00:00-03:00","expires_at":"2099-01-01T00:00:00-03:00","query":"","_cached_at_ts":1747393200}' > storage/cache/login_wallpaper.json
```

---

## Limpar cache

```bash
# Servidor — força nova busca na próxima visita ao login
rm storage/cache/login_wallpaper.json

# Browser — sessionStorage (console do browser)
sessionStorage.removeItem('biomie_login_wp_v2');
```

---

## Checklist de guidelines Unsplash

- [x] Atribuição visível ao fotógrafo e ao Unsplash com links
- [x] Links de atribuição incluem `utm_source` e `utm_medium`
- [x] Imagens carregadas via hotlink (não proxy de imagem)
- [x] `download_location` armazenado no cache (para chamada de download quando aplicável)
- [x] Access Key nunca exposta no frontend
- [x] `Accept-Version: v1` enviado em todas as requisições à API
- [x] Degradação gracioso com fallback local quando API indisponível
