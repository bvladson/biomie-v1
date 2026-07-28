# BIomie — Debug Playbook

## Tela antiga continua aparecendo após alterar código

**Diagnóstico passo a passo:**

1. **Verificar se o PHP veio do Service Worker**
   - F12 → Network → recarregar a página
   - Clique na requisição de `index.php` (ou outra página PHP)
   - Se "Size" mostrar "(Service Worker)": SW está servindo conteúdo antigo → ver passo 3
   - Se "Size" mostrar tamanho normal: SW não é o culpado → ver passo 2

2. **Verificar se o JS/CSS está com versão nova**
   - No Network, clique em `app.js` ou `app.css`
   - A URL deve ter `?v=` com o `filemtime` atual do arquivo alterado
   - Se a URL não mudou: arquivo novo não chegou ao servidor ou `Asset::url()` não está sendo usado → verificar deploy

3. **Service Worker com cache antigo**
   - F12 → Application → Service Workers → clique em "Update" ou "Unregister"
   - Recarregue a página
   - O `cache-refresh.js` faz isso automaticamente quando detecta mudança de `biomie-build`; se não funcionou, pode ser que `config/build.php` não foi atualizado no deploy

4. **Limpar cache interno do servidor**
   - System Health → Manutenção de Cache → "Limpar todos os caches"
   - Ou via SSH: `php tools/dev/cache_clear.php --all --json`

5. **Resetar OPcache**
   - System Health → Manutenção de Cache → "Resetar OPcache"
   - Ou via CLI: `php tools/dev/cache_clear.php --opcache --json`
   - Necessário se `opcache.validate_timestamps = 0` no servidor

6. **Verificar System Health**
   - Acesse `system_health.php`
   - Checar: OPcache PHP, Versão de assets, Service Worker / Cache, Cache de APIs
   - Qualquer warning/critical nesses checks indica a causa raiz

**Aceite:** Não deve ser necessário Ctrl+F5 depois de resolver o item apontado no passo 1 ou 2.

---

## Pós-login lento

1. Abrir Network e medir `index.php`, `dashboard_boot.php`, `overview.php`.
2. Confirmar que `index.php` não chama Omie.
3. Conferir cache hit/miss de overview.

---

## Mix Faturado vazio

1. Rodar `php tools/dev/products_mix_debug.php --start=YYYY-MM-01 --end=YYYY-MM-DD --explain`.
2. Se NFs > 0 e itens = 0: reprocessar sync fiscal de itens.
3. Se itens > 0 e produtos vazios: conferir `codigo_produto`/`descricao_produto`.

---

## Bairro com resumo, mas sem compradores

1. Rodar `geo_sales_debug.php` com `--bairro` e `--city`.
2. Conferir `bairro_key`, `bairro_normalizado` e `city_normalized`.
3. Reprocessar `mart_compradores_por_geo` se fallback canônico encontrar comprador.

---

## System Health mostra CRITICAL para sync/worker/gold

1. Verificar se os crons estão configurados no Hostinger (hPanel → Advanced → Cron Jobs).
2. Verificar se os scripts .sh estão em `cron/hostinger/` com permissão `+x`.
3. Checar logs: `tail -20 storage/logs/cron_sync_commercial.log`.
4. Rodar manualmente: `php tools/dev/omie_sync_status.php`.
5. Consultar `docs/hostinger-cron-setup.md` para configuração completa.

---

## API retornando dado antigo (suspeita de cache HTTP)

1. F12 → Network → clique na requisição da API → aba "Headers"
2. Na seção "Response Headers", verificar `Cache-Control`
3. Deve mostrar `no-store, no-cache, must-revalidate, max-age=0`
4. Se não mostrar: `Http::json()` está sendo bypassado ou o endpoint usa `Http::jsonWithEtag()` — que é intencional para dados raramente alterados
5. Limpar cache do browser: Ctrl+Shift+Delete → "Imagens e arquivos em cache"
