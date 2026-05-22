# Checklist de Produção — BIomie MAG

> Executar este checklist antes de qualquer deploy em produção.
> Todos os itens obrigatórios (🔴) devem estar marcados antes do go-live.

---

## 1. Pré-deploy — QA Suite

```bash
# Executar suíte completa de QA
php tools/dev/qa_final_suite.php

# Checks individuais (caso precise detalhar)
php tools/dev/lint_all.php
php tools/dev/rbac_audit.php
php tools/dev/semantic_audit.php --fail-on-warning
php tools/dev/system_health_check.php
```

| # | Check | Resultado esperado | Obrigatorio |
|---|-------|--------------------|-------------|
| 1 | PHP Syntax Lint (lint_all.php) | 0 erros, 369+ arquivos | 🔴 |
| 2 | RBAC Audit (rbac_audit.php) | 49+/49 endpoints protegidos | 🔴 |
| 3 | Semantic Audit (semantic_audit.php) | 0 erros, 22+ KPIs ativos | 🔴 |
| 4 | System Health Check | status ok ou warning | 🔴 |
| 5 | Golden Endpoints (tests/run_golden_endpoints.php) | 6/6 pass | 🔴 |
| 6 | Data Coverage Audit | cobertura geo >= 60% | 🟡 |
| 7 | Geo Quality Report | match rate >= 70% | 🟡 |

---

## 2. Banco de dados

```bash
# Verificar migrações pendentes
php bin/schema_migrate.php verify

# Backup antes do deploy
mysqldump -u USER -p DATABASE > backup_$(date +%Y%m%d_%H%M%S).sql
```

| # | Item | Obrigatorio |
|---|------|-------------|
| 8 | Backup do banco realizado | 🔴 |
| 9 | Migrações pendentes listadas e revisadas | 🔴 |
| 10 | Migrações testadas em staging | 🔴 |
| 11 | `php bin/schema_migrate.php run` executado em produção | 🔴 |
| 12 | Tabelas críticas existem: biomie_users, biomie_notas_fiscais_canonicas, mart_cliente_periodo, mart_compradores_por_geo | 🔴 |

---

## 3. Configuração e segredos

| # | Item | Obrigatorio |
|---|------|-------------|
| 13 | `config/env.php` presente e não versionado | 🔴 |
| 14 | Credenciais Omie (app_key, app_secret) configuradas | 🔴 |
| 15 | String de conexão MySQL configurada e testada | 🔴 |
| 16 | `app.base_path` configurado corretamente | 🔴 |
| 17 | `observability.enabled` ativo | 🟡 |
| 18 | `app.debug` = false em produção | 🔴 |

---

## 4. Permissões de arquivo

```bash
chmod 750 storage/
chmod 750 storage/logs/
chmod 750 storage/cache/
chmod 640 config/env.php
```

| # | Item | Obrigatorio |
|---|------|-------------|
| 19 | Diretório `storage/` com write pelo servidor web | 🔴 |
| 20 | `storage/logs/` gravável | 🔴 |
| 21 | `storage/cache/` gravável | 🔴 |
| 22 | `config/env.php` não legível publicamente | 🔴 |

---

## 5. Cron jobs

```bash
# Adicionar ao crontab do servidor
crontab -e
```

```cron
# Materializacao marts (diario, 2h AM)
0 2 * * * php /caminho/biomie/bin/materialize_marts.php >> /var/log/biomie/marts.log 2>&1

# Enriquecimento de clientes (diario, 1h30 AM)
30 1 * * * php /caminho/biomie/bin/enrich_clients_governance.php >> /var/log/biomie/enrich.log 2>&1

# Oportunidades (diario, 3h AM)
0 3 * * * php /caminho/biomie/bin/compute_opportunities.php >> /var/log/biomie/opportunities.log 2>&1

# Previsoes (diario, 3h30 AM)
30 3 * * * php /caminho/biomie/bin/compute_forecasts.php >> /var/log/biomie/forecasts.log 2>&1

# Avaliacao de saude (horario)
0 * * * * php /caminho/biomie/bin/evaluate_health.php >> /var/log/biomie/health.log 2>&1
```

| # | Item | Obrigatorio |
|---|------|-------------|
| 23 | Cron de materializacao de marts ativo | 🔴 |
| 24 | Cron de enriquecimento de clientes ativo | 🟡 |
| 25 | Cron de avaliacao de saude ativo | 🟡 |
| 26 | Diretorio `/var/log/biomie/` criado e gravavel | 🔴 |

---

## 6. Deploy

```bash
# 1. Colocar em manutencao (opcional)
touch storage/maintenance.lock

# 2. Pull do codigo
git fetch origin && git checkout main && git pull

# 3. Instalar dependencias
composer install --no-dev --optimize-autoloader

# 4. Executar migracoes
php bin/schema_migrate.php run

# 5. Limpar cache
php app/AdminConsole/Commands/CacheClearCommand.php  # ou via console admin

# 6. Remover manutencao
rm -f storage/maintenance.lock

# 7. Verificar saude
php tools/dev/system_health_check.php
```

| # | Item | Obrigatorio |
|---|------|-------------|
| 27 | Deploy realizado fora do horario de pico | 🟡 |
| 28 | Dependencias instaladas (composer install --no-dev) | 🔴 |
| 29 | Cache limpo pos-deploy | 🔴 |
| 30 | System health OK pos-deploy | 🔴 |

---

## 7. Validação pós-deploy

```bash
# Verificar saude geral
php tools/dev/system_health_check.php

# Verificar endpoint principal
curl -s -o /dev/null -w "%{http_code}" https://SEU_DOMINIO/api/overview.php

# Verificar geo mart
php tools/dev/geo_quality_report.php

# Smoke test de páginas
php tools/dev/page_smoke_test.php
```

| # | Item | Obrigatorio |
|---|------|-------------|
| 31 | Login funcionando | 🔴 |
| 32 | Dashboard carregando com dados | 🔴 |
| 33 | Sincronizacao Omie funcionando (api/omie_test_connection.php) | 🔴 |
| 34 | Mapa de vendas carregando | 🟡 |
| 35 | Financeiro carregando | 🔴 |
| 36 | System health com status ok/warning (nao critical) | 🔴 |

---

## 8. Comunicação

| # | Item | Obrigatorio |
|---|------|-------------|
| 37 | Versao/data do deploy registrada em `docs/RELEASE_CANDIDATE_MAG.md` | 🟡 |
| 38 | Time notificado do deploy | 🟡 |
| 39 | Plano de rollback revisado (ver `docs/ROLLBACK.md`) | 🔴 |

---

## Legenda

- 🔴 **Obrigatorio** — deploy nao pode prosseguir sem este item
- 🟡 **Recomendado** — falhar pode gerar degradacao de funcionalidade

---

*Atualizado em: 2026-05-14*
