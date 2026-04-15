# Patch aplicado — competência comercial + plano de voo

## Objetivo
Alinhar as páginas comerciais ao recorte por `data_emissao` sem mexer no núcleo de sync canônico já estabilizado.

## Arquivos alterados
- `app/CommercialPlanning.php` (novo)
- `api/bootstrap.php`
- `api/overview.php`
- `api/vendors.php`
- `api/orders.php`
- `api/products.php`
- `api/flight_plan.php`
- `api/flight_plan_vendor_detail.php`
- `api/flight_plan_targets_editor.php`
- `assets/js/flight-plan.js`
- `views/plano_voo.html`
- `views/plano_voo_metas.html`

## O que mudou
1. **Resumo comercial canônico**
   - `overview`, `vendors`, `orders` e `products` passaram a usar a leitura comercial por `data_emissao`.
   - O `overview` não depende mais do `lastSyncAt($start, $end)` como gate para entrar no canônico.

2. **Metas e projeções**
   - Novo helper `CommercialPlanning` centraliza:
     - meses do período
     - meta proporcional do período
     - projeção provável/base/commit
     - gap para meta
     - novo diário necessário
   - `overview`, `vendors`, `orders` e editor de metas passam a usar a mesma base de metas/projeções.

3. **Plano de Voo**
   - `flight_plan.php`, `flight_plan_vendor_detail.php` e `flight_plan_targets_editor.php` não exigem mais `lastSyncAt($start, $end)` para habilitar o caminho canônico.
   - Isso evita zerar a página em períodos novos com emissão já gravada, mas sem um estado de sync do recorte completo.

4. **Editor de metas**
   - Agora retorna também:
     - `forecast_period`
     - `gap_to_target`
     - `attainment_pct`
     - `new_daily_target`
     - `risk_status`
   - A tabela ficou simples e mais útil para ajuste de meta mensal.

5. **UX / concorrência de requests**
   - `api/bootstrap.php` chama `session_write_close()` após validar autenticação.
   - Isso reduz bloqueio de sessão entre chamadas paralelas da interface.

## Observações
- O ranking detalhado de produtos ainda continua híbrido quando precisar do snapshot legado.
- O patch não altera DDL nem rotina de sync canônico.
