# Procedimento de Rollback — BIomie MAG

> Use este documento quando um deploy causar falhas críticas em produção.
> Objetivo: restaurar o sistema ao último estado estável em menos de 30 minutos.

---

## 1. Quando acionar o rollback

Acione o rollback imediatamente se qualquer um destes critérios for atendido:

| Critério | Threshold | Ação |
|----------|-----------|------|
| Login quebrado / 401/403 em massa | Qualquer ocorrência | Rollback imediato |
| API principal retornando 500 | > 5 erros em 2 min | Rollback imediato |
| Perda de dados confirmada | Qualquer ocorrência | Rollback imediato + DBA |
| `system_health_check.php` com status `critical` | Após 3 tentativas de fix | Rollback |
| Sincronização Omie completamente parada | > 15 min após deploy | Rollback |
| Dashboard sem dados após sync | > 30 min após deploy | Avaliar rollback |

**Dúvida?** Acione o rollback. É sempre mais seguro reverter e investigar do que tentar corrigir com o sistema em produção instável.

---

## 2. Diagnóstico rápido (antes de decidir rollback)

```bash
# 1. Verificar saúde geral (< 2 min)
php tools/dev/system_health_check.php

# 2. Ver últimas linhas do log
php tools/dev/error_log_tail.php

# 3. Verificar se é problema de DB
php tools/dev/db_test_remote.php

# 4. Verificar RBAC (se login quebrou)
php tools/dev/rbac_audit.php
```

Se o diagnóstico indicar problema de **código** (não de dados/infra), prossiga com rollback de código.
Se o problema for de **dados** (migração mal aplicada), vá para a seção 4.

---

## 3. Rollback de código (Git)

### 3.1 Identificar o commit estável

```bash
# Ver histórico recente
git log --oneline -10

# Verificar qual commit estava em produção antes do deploy
# (geralmente o penúltimo commit em main, ou a tag anterior)
git tag --sort=-creatordate | head -5
```

### 3.2 Reverter para o commit anterior

```bash
# Opção A: Reverter para commit específico (mais seguro)
git checkout <COMMIT_HASH> -- .
git add -A
git commit -m "rollback: revert to <COMMIT_HASH> due to [motivo]"
git push origin main

# Opção B: Git revert (mantém histórico limpo)
git revert HEAD --no-edit
git push origin main

# Opção C: Hard reset (APENAS em emergência extrema — destrói histórico)
# git reset --hard <COMMIT_HASH>
# git push origin main --force
```

### 3.3 Reinstalar dependências após rollback

```bash
composer install --no-dev --optimize-autoloader
```

### 3.4 Limpar cache

```bash
# Manualmente
find storage/cache/ -name "*.json" -delete
find storage/cache/ -name "*.tmp" -delete
find storage/cache/ -name "*.lock" -delete

# Ou via admin console (se acessível):
# Admin → Console → CacheClear
```

---

## 4. Rollback de banco de dados (migrações)

### 4.1 Antes de reverter: fazer backup do estado atual

```bash
mysqldump -u USER -p DATABASE > rollback_backup_$(date +%Y%m%d_%H%M%S).sql
```

### 4.2 Restaurar backup pré-deploy

```bash
# Restaurar o backup tirado antes do deploy
mysql -u USER -p DATABASE < backup_YYYYMMDD_HHMMSS.sql
```

### 4.3 Se não houver backup (emergência)

```bash
# 1. Verificar quais migrações foram aplicadas
php bin/schema_migrate.php status

# 2. Reverter a última migração manualmente
# Consultar o arquivo de migração em database/migrations/
# e executar os comandos DROP/ALTER inversos
```

> ⚠️ **NUNCA** execute `DROP TABLE` sem backup confirmado.
> Se não tiver backup do banco, **NÃO** reverta a migração — acione o suporte de banco de dados.

---

## 5. Verificação pós-rollback

Execute estes passos em ordem após o rollback:

```bash
# 1. System health deve voltar a ok/warning
php tools/dev/system_health_check.php

# 2. RBAC sem regressões
php tools/dev/rbac_audit.php

# 3. Login funcionando
# Testar manualmente no browser

# 4. Dashboard com dados
# Testar manualmente: index.php

# 5. Sync Omie funcionando
# api/omie_test_connection.php via browser (usuário admin)
```

Checklist de confirmação:

- [ ] `system_health_check.php` retorna `ok` ou `warning`
- [ ] Login funcionando para todos os perfis
- [ ] Dashboard carregando com dados
- [ ] Nenhum erro 500 nos logs após rollback
- [ ] Sincronização Omie retomada (se estava ativa antes)

---

## 6. Comunicação durante rollback

1. **Notificar** o time imediatamente ao identificar o problema
2. **Registrar** no canal de operações: data/hora do problema, sintomas, ação tomada
3. **Atualizar** status do deploy em `docs/RELEASE_CANDIDATE_MAG.md` com o resultado
4. **Fazer post-mortem** após resolução: causa raiz, lição aprendida, ação corretiva

---

## 7. Tempos esperados de rollback

| Tipo | Tempo esperado |
|------|----------------|
| Rollback de código (git revert + push) | < 5 min |
| Reinstalar dependências (composer) | < 3 min |
| Limpar cache | < 1 min |
| Restaurar backup de banco (< 1GB) | 5–15 min |
| Verificação pós-rollback | < 10 min |
| **Total estimado** | **< 30 min** |

---

## 8. Contatos de emergência

> Preencher com os contatos reais antes do go-live.

| Papel | Nome | Contato |
|-------|------|---------|
| Responsável técnico | — | — |
| DBA (emergência de banco) | — | — |
| Suporte de infra/servidor | — | — |

---

*Atualizado em: 2026-05-14*
