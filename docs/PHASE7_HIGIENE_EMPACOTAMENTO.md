# Fase 7 - Higiene de Empacotamento e Operacao

## Objetivo

Fazer o BIomie se comportar como plataforma previsível, com runtime claro, legado isolado, ferramentas operacionais convencionadas e empacotamento reprodutível.

## Classificacao Atual

### Produto

- `app/`
- `api/`
- `assets/`
- `bin/`
- `config/`
- `cron/`
- `database/`
- `images/`
- `views/`
- raiz com entrypoints oficiais `.php`
- `offline.html`
- `site.webmanifest`
- `sw.js`

### Operacao

- `admin.php`
- `reconciliacao.php`
- `observabilidade.php`
- `healthz.php`
- `check.php`
- `cronjobs/` como camada de compatibilidade para hPanel/custom cron
- `tools/ops/`
- `tools/dev/`
- `composer.json`

### Experimento / Debug

- `tools/debug/`
- `.vscode/`
- `.claude/`

### Legado

- `docs/legacy/root-html/`
- `docs/legacy/patches/`
- `docs/legacy/PATCH_NOTES_COMPETENCIA_COMERCIAL.md`
- rotas `/*.html` na raiz agora existem apenas como redirecionamento de compatibilidade

### Temporario

- `storage/cache/`
- `storage/logs/`
- `storage/releases/`

## Politica de Layout

### Runtime oficial

- UI oficial: `*.php` na raiz + `views/*.html`
- APIs: `api/`
- jobs agendados: `cron/`
- cron wrappers para painel legado: `cronjobs/`

### Ferramentas

- `tools/dev/`: inventário, utilitários locais de inspeção
- `tools/ops/`: empacotamento, release, runbooks executáveis
- `tools/debug/`: probes manuais e artefatos de diagnóstico

### Legado de tela

- A view oficial mora em `views/`
- O controlador oficial mora em `*.php`
- O `*.html` da raiz não é mais fonte de verdade; ele só preserva compatibilidade de URL

## Mapa Oficial de Telas

| Rota oficial | Template oficial | Status |
| --- | --- | --- |
| `index.php` | `views/index.html` | oficial |
| `vendedores.php` | `views/vendedores.html` | oficial |
| `pedidos.php` | `views/pedidos.html` | oficial |
| `mercadorias.php` | `views/mercadorias.html` | oficial |
| `financeiro.php` | `views/financeiro.html` | oficial |
| `plano_voo.php` | `views/plano_voo.html` | oficial |
| `plano_voo_metas.php` | `views/plano_voo_metas.html` | oficial |
| `integracoes.php` | `views/integracoes.html` | oficial |
| `observabilidade.php` | `views/observabilidade.html` | oficial |
| `reconciliacao.php` | pagina standalone | oficial |
| `admin.php` | pagina standalone | oficial |

## Politica de Empacotamento

### Entra no deploy

- runtime (`app`, `api`, `assets`, `bin`, `config`, `cron`, `cronjobs`, `database`, `images`, `views`)
- entrypoints oficiais da raiz
- artefatos PWA e healthcheck
- diretórios vazios mínimos de `storage/cache`, `storage/cache/jobs` e `storage/logs`

### Nunca entra no deploy

- `docs/`
- `tests/`
- `tools/`
- `.vscode/`
- `.claude/`
- caches e logs reais de `storage/`
- configs de análise estática (`phpstan*.neon`, `phpstan-bootstrap.php`)
- notas e documentos de bancada da raiz

## Comandos

### Prontidao local

```bash
php tools/dev/lint_all.php
php tools/dev/runtime_readiness.php
```

### Inventario

```bash
php tools/dev/project_inventory.php
php tools/dev/project_inventory.php --json
```

### Manifesto de release

```bash
php tools/ops/build_release.php --dry-run
php tools/ops/build_release.php --dry-run --json
```

### Build do pacote

```bash
php tools/ops/build_release.php
```

## Checklist de Release

1. Rodar `php tests/run_phase2_coverage.php`.
2. Conferir `php tools/dev/project_inventory.php`.
3. Validar `php tools/ops/build_release.php --dry-run`.
4. Gerar o pacote final.
5. Confirmar que o ZIP não carrega `docs/`, `tests/`, `tools/` nem caches reais.
