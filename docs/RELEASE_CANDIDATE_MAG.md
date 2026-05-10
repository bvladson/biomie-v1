# Release Candidate MAG — BIomie RC-1

**Data de auditoria:** 2026-05-09
**Ambiente:** Windows 10 Pro / PHP 8.5.4 / MariaDB (Hostinger)
**Responsável:** VLADLUCKMAN
**Branch:** fase-3-system-health-geo

---

## Resultado da Auditoria Pré-Release (Etapa 10.0)

| Ferramenta | Resultado | Detalhe |
|---|---|---|
| `lint_all.php` | ✅ PASS | 369 arquivos, 0 erros de sintaxe |
| `check_encoding.php` | ✅ PASS | Nenhum BOM ou sequência suspeita |
| `rbac_audit.php` | ✅ PASS | 49/49 endpoints protegidos, 0 missing |
| `nav_rbac_sim.php` | ✅ PASS | 43/43 assertions, retrocompat OK |
| `menu_audit.php` | ✅ PASS | 17 itens, 0 erros, 3 avisos pré-existentes |
| `semantic_audit.php` | ✅ PASS | 29 KPIs, 22 ativos, 7 draft, 0 erros |
| `large_file_audit.php` | ✅ PASS | 0 critical, 20 high (todos pré-existentes) |
| `run_golden_endpoints.php` | ✅ PASS | 6/6 golden |
| `run_phase2_coverage.php` | ✅ PASS | 5 suites, 49/49 testes |
| `system_health_check.php` | ❌ BLOCKED | DB: auth_gssapi_client incompatível com PDO |
| `runtime_readiness.php` | ❌ BLOCKED | DB + composer.lock + vendor/ ausentes |
| `schema_migrate.php status` | ❌ BLOCKED | 25 migrations, 0 aplicadas (connection_error) |

**Avisos de menu (pré-existentes, não bloqueadores):**
- `index.php` duplicado em itens 0, 8, 9, 10 — são âncoras diferentes: `index.php`, `index.php#cards`, `index.php#graficos`, `index.php#tabelas`. Comportamento esperado.

---

## Bloqueadores para RC-1

### BLOQUEADOR 1 — auth_gssapi_client (CRÍTICO)

**Causa:** O usuário `u106688109_Comercial_user` no servidor MariaDB da Hostinger usa o plugin de autenticação `auth_gssapi_client` (Kerberos/GSSAPI), que é incompatível com a extensão PDO MySQL do PHP. Toda conexão PHP → banco falha com:

```
SQLSTATE[HY000] [2054] The server requested authentication method unknown to the client [auth_gssapi_client]
```

**Impacto:** Nenhuma operação de banco funciona (migrations, queries, system health, golden tests em modo live).

**Ação necessária (no painel Hostinger):**
```sql
-- Opção A: alterar plugin do usuário existente (requer privilégio SUPER)
ALTER USER 'u106688109_Comercial_user'@'localhost'
  IDENTIFIED WITH mysql_native_password BY 'SENHA_ATUAL';
FLUSH PRIVILEGES;

-- Opção B: criar usuário novo com auth nativa
CREATE USER 'biomie_app'@'localhost' IDENTIFIED BY 'SENHA_FORTE';
GRANT ALL PRIVILEGES ON u106688109_Comercial.* TO 'biomie_app'@'localhost';
FLUSH PRIVILEGES;
-- Depois atualizar config/env.php com as novas credenciais
```

**Verificar depois:**
```bash
php tools/dev/runtime_readiness.php
php bin/schema_migrate.php status
```

### BLOQUEADOR 2 — Composer / vendor/ ausentes (ALTO)

**Causa:** `composer.lock` e `vendor/autoload.php` não existem. Sem eles, `phpstan` (dev) não roda.

**Importante:** A aplicação em si NÃO tem dependências de terceiros em produção — todas as `require` do `composer.json` são extensões PHP nativas (`pdo`, `pdo_mysql`, `curl`, `mbstring`, `json`). O autoload usa Bootstrap.php customizado. A aplicação funciona sem `vendor/`. Mas `composer.lock` deve existir para processo de release reproduzível.

**Ação necessária:**
```bash
# Baixar composer
curl -sS https://getcomposer.org/installer | php
# Instalar dependências (produção, sem phpstan)
php composer.phar install --no-dev --optimize-autoloader
# Versionar o lock (nunca versionar vendor/)
git add composer.lock
```

---

## Checklist de Backup (Etapa 10.3)

A ser preenchida antes de rodar `schema_migrate.php migrate`:

```
[ ] Backup do banco exportado:
    mysqldump -u u106688109_Comercial_user -p u106688109_Comercial > backup_pre_rc1_$(date +%Y%m%d_%H%M).sql

[ ] Backup dos arquivos do /biomie:
    cp -r /biomie /biomie.backup_$(date +%Y%m%d_%H%M)

[ ] Versão atual registrada: HEAD do git =
    git log --oneline -1

[ ] Horário registrado: 2026-05-09 __:__

[ ] Responsável: VLADLUCKMAN
```

---

## Checklist de Rollback (Etapa 10.13)

```bash
# 1. Parar worker
crontab -e  # comentar as linhas do worker

# 2. Restaurar arquivos
cp -r /biomie.backup_YYYYMMDD /biomie

# 3. Restaurar banco
mysql -u u106688109_Comercial_user -p u106688109_Comercial < backup_pre_rc1_YYYYMMDD.sql

# 4. Validar após rollback
php tools/dev/runtime_readiness.php
php tests/run_golden_endpoints.php
php tools/dev/system_health_check.php --json
```

---

## Config Produção (Etapa 10.5)

| Parâmetro | Status | Valor |
|---|---|---|
| `APP_ENV` | ✅ | `production` (default no env.php) |
| `APP_DEBUG` | ✅ | `false` / `0` (default) |
| `OMIE_WEBHOOK_TOKEN` | ⚠️ VERIFICAR | Deve estar preenchido no `.env` real |
| `BIOMIE_ALLOW_INSECURE_WEBHOOK_LOCAL` | ✅ | `0` (default) |
| `BIOMIE_ALLOW_SYNC_WEB` | ✅ | `0` (default) |
| `.htaccess — paths bloqueados` | ✅ | `app/`, `bin/`, `config/`, `cron/`, `database/`, `docs/`, `install/`, `storage/`, `tests/`, `tools/`, `vendor/` |
| `.htaccess — extensões bloqueadas` | ✅ | `.env`, `.ini`, `.log`, `.jsonl`, `.lock`, `.sql`, `.bak`, `.md` |
| `X-Frame-Options` | ✅ | DENY |
| `X-Content-Type-Options` | ✅ | nosniff |
| `HSTS` | ✅ | max-age=31536000 includeSubDomains |
| `CSP` | ✅ | Report-Only (não bloqueia, registra) |
| `composer.json` | ✅ | bloqueado via .htaccess (`*.lock`) |
| `composer.lock` | ✅ | bloqueado via .htaccess (`*.lock`) |

**Ação pendente:** Verificar no `.env` real que `OMIE_WEBHOOK_TOKEN` está preenchido com valor não-vazio.

---

## Workers e Cron (Etapa 10.6)

Cron recomendado para `/etc/crontab` ou Hostinger cron:

```cron
*/5  * * * * php /home/u106688109/domains/comercial.mag.com.br/public_html/cron/worker.php --queue=high,default --limit=50 --sleep=1 --max-runtime=270 >> /home/u106688109/domains/.../storage/logs/worker.log 2>&1

*/15 * * * * php /home/u106688109/domains/comercial.mag.com.br/public_html/cron/worker.php --queue=low --limit=20 --sleep=2 --max-runtime=270 >> /home/u106688109/domains/.../storage/logs/worker_low.log 2>&1

0 */6 * * * php /home/u106688109/domains/comercial.mag.com.br/public_html/cron/materialize_marts.php >> /home/u106688109/domains/.../storage/logs/materialize.log 2>&1
```

Validar que worker não dá fatal error (requer banco conectado):
```bash
php cron/worker.php --queue=high,default --limit=1
```

---

## Migrations (Etapa 10.4)

**Migrations disponíveis:** 25 versioned + 2 loose SQL files

| Arquivo | Status |
|---|---|
| Version20260413050100_BaselineCanonicalSchema | Pendente (aguarda DB fix) |
| Version20260413050200_CompetenciaSnapshots | Pendente |
| Version20260413050300_FlightPlanTargets | Pendente |
| Version20260413050400_AuthSchema | Pendente |
| Version20260413050500_ReconciliationTables | Pendente |
| Version20260413050600_OperationalObservability | Pendente |
| Version20260413050700_GoldMarts | Pendente |
| Version20260416190000_GeographicAndClientDimensions | Pendente |
| Version20260417090000_PipelineJobs | Pendente |
| Version20260417150000_GovernanceEnrichment | Pendente |
| Version20260417170000_OpportunityMart | Pendente |
| Version20260417190000_ForecastMart | Pendente |
| Version20260417210000_PerformanceIndexes | Pendente |
| Version20260417250000_ObservabilitySchema | Pendente |
| Version20260418000000_AccessControl | Pendente |
| Version20260419000000_CompradoresPorGeo | Pendente |
| Version20260419010000_VendasPorCnaeCnpj | Pendente |
| Version20260501090000_AdminRbac | Pendente |
| Version20260501220000_OmieStatusChecks | Pendente |
| Version20260502120000_CustomerDimensionEnrichment | Pendente |
| Version20260502130000_BairrosFortalezaDim | Pendente |
| Version20260503000000_EnrichmentAuditColumns | Pendente |
| Version20260504010000_CnaeSourceMetadata | Pendente |
| Version20260506210000_RbacPhase3Permissions | Pendente |
| Version20260506223000_BiomieJobs | Pendente |
| `phase5_compradores_geo.sql` | LOOSE — fora do sistema de versioning |
| `phase6_vendas_cnae_cnpj.sql` | LOOSE — fora do sistema de versioning |

**Nota sobre loose SQL files:** Estes dois arquivos estão em `database/migrations/` mas não seguem o padrão `Version*.php`. Verificar se são aplicados por outro mecanismo ou se devem ser migrados para versioned migrations.

**Sequência obrigatória (após backup):**
```bash
php bin/schema_migrate.php status
php bin/schema_migrate.php migrate
php bin/schema_migrate.php status  # confirmar 0 pending
php bin/schema_migrate.php verify  # confirmar 0 checksum mismatch
```

---

## OPcache (Etapa 10.10)

| Contexto | Status |
|---|---|
| `opcache.enable` no php.ini | `1` (configurado) |
| Extensão carregada (PHP CLI) | ❌ Não carregada no CLI |
| Extensão carregada (PHP Web) | ⚠️ Verificar no phpinfo() via browser |

OPcache frequentemente está desabilitado no CLI e habilitado no SAPI web (Apache mod_php ou PHP-FPM). Verificar via `index.php?phpinfo=1` se disponível, ou criando temporariamente um `phpinfo.php` (remover após verificação).

Após qualquer deploy:
```bash
# Se OPcache estiver ativo no servidor:
# Opção 1: via PHP (em script protegido por admin)
opcache_reset();
# Opção 2: reiniciar o processo PHP-FPM
sudo systemctl restart php8.x-fpm
```

---

## Logging e Monitoramento (Etapa 10.11)

Logs gravados em `storage/logs/biomie.log` (JSONL estruturado).

Eventos críticos que DEVEM aparecer nos logs:
- Login negado → `auth.login_failed`
- Acesso 403 → `auth.access_denied`
- Erro webhook → `webhook.error`
- Erro job → `job.failed`
- Erro banco → nível ERROR no Logger
- Erro Omie → `omie.error`
- System health critical → via `system_health_check.php --json`

**O que NÃO deve aparecer nos logs:**
- Senhas, APP_SECRET, OMIE_APP_SECRET
- Token do webhook completo
- Dados pessoais completos (CPF, CNPJ em texto claro em nível DEBUG)

---

## RC-1 Checklist Final (Etapa 10.14)

```
[x] composer.lock resolvido              — PENDENTE: composer.phar não instalado
[x] vendor instalado                     — PENDENTE: vendor/ ausente
[x] banco conectado                      — BLOQUEADO: auth_gssapi_client
[x] migrations aplicadas                 — BLOQUEADO: aguarda banco
[x] backup feito                         — A FAZER antes de aplicar migrations
[x] rollback documentado                 — ✅ neste documento
[x] workers configurados                 — A CONFIGURAR no cron Hostinger
[x] webhook validado                     — A TESTAR após banco conectar
[x] APP_ENV=production                   — ✅ (default)
[x] APP_DEBUG=false                      — ✅ (default 0)
[x] system_health OK ou warnings doc.    — BLOQUEADO: aguarda banco
[x] semantic_audit OK                    — ✅ 29 KPIs, 0 erros
[x] rbac_audit OK                        — ✅ 49/49
[x] golden 6/6                           — ✅ (modo fixture, sem banco live)
[x] phase2 coverage OK                   — ✅ 49/49
[x] browser manual OK                    — A FAZER após banco
[x] usuários reais testados              — A FAZER após banco
[x] deploy package limpo                 — build_release.php disponível
```

---

## Decisão RC-1

**Status: NÃO APROVADO — aguardando resolução de 2 bloqueadores.**

Todos os critérios de qualidade de código estão verdes. Os bloqueadores são puramente de infraestrutura do ambiente de hospedagem.
