# Dívida Técnica D58 — Normalização do tipo de `company_id`

**Aberta em:** 2026-07-22
**Origem:** Agenda de Orçamentos — Fase 2 (revisão de aval da migration do log de decisão).
**Guard relacionado:** D55 (teto de `company_id` TINYINT) — já emite WARN e detecta truncamento.
**Status:** aceita provisoriamente. NÃO bloqueia o piloto da Fase 2.

---

## O problema

Coexistem, hoje, **três realidades de tipo** para a mesma chave lógica `company_id`:

| Origem                                             | Tipo                    | Teto     |
|----------------------------------------------------|-------------------------|----------|
| `biomie_companies.id` (a fonte de verdade)         | `TINYINT(3) UNSIGNED`   | 255      |
| Canônicas (`biomie_pedidos_canonicos`, `biomie_notas_fiscais_canonicas`, …) | `TINYINT(3) UNSIGNED` | 255 |
| Família Agenda + `biomie_quote_order_refresh_log`  | `BIGINT`                | 9.2e18   |

Nenhum tipo único satisfaz as duas necessidades ao mesmo tempo:

- Casar por **igualdade** com `biomie_companies.id` pediria TINYINT.
- Casar (JOIN) com as **tabelas irmãs da Agenda**, que já são BIGINT, pede BIGINT.

Na Fase 2, `biomie_quote_order_refresh_log.company_id` foi mantido **BIGINT** para
casar com as irmãs da Agenda (é com elas que ele faz join), **não** com
`biomie_companies`. Não há FK para `biomie_companies` — a empresa é validada em
runtime pelo **TenantContext** (decisão explícita do usuário: "mantenha BIGINT;
não crie foreign key para biomie_companies; valide a empresa pelo TenantContext").

## Por que é dívida, e não bug

- MySQL/MariaDB **tolera** o join entre TINYINT e BIGINT (converte implicitamente),
  então nada quebra funcionalmente hoje. O custo é ausência de integridade
  referencial declarada e um schema internamente inconsistente.
- O risco REAL e mensurável é o **D55**: as colunas TINYINT truncam em silêncio no
  256º onboarding (o `sql_mode` deste servidor não é estrito). Hoje o maior
  `company_id` é 5 — folga enorme —, mas é uma bomba-relógio silenciosa.

## O que NÃO fazer agora (decisões já tomadas)

- ❌ Não alterar o `sql_mode` global do servidor (efeito colateral amplo demais).
- ❌ Não criar FK de `company_id` → `biomie_companies`.
- ❌ Não estreitar o log da Agenda para TINYINT só para "casar" com a fonte.

## Remediação futura (quando o teto se aproximar, ou numa janela de manutenção)

Opção recomendada: **unificar tudo em `BIGINT`** (ou ao menos `INT UNSIGNED`),
migrando as canônicas TINYINT→maior ANTES de o `company_id` chegar perto de 255.
Ordem sugerida:

1. `ALTER` das colunas estreitas listadas pelo D55 (`biomie_pedidos_canonicos`,
   `biomie_notas_fiscais_canonicas`, bridges, etc.) para o tipo unificado.
2. Padronizar `biomie_companies.id` no mesmo tipo (fonte de verdade coerente).
3. Reavaliar FK/coerência referencial após a unificação.
4. Enquanto isso, o **D55 guard** vigia o teto e alerta se algum `company_id`
   chegar ao limite ou se surgirem linhas com `company_id` órfão (truncado).

## Gatilho de priorização

Elevar de "provisória" para "fazer agora" quando **qualquer** um ocorrer:
- `company_id` máximo ≥ ~200 (aproximação do teto TINYINT), **ou**
- D55 detectar linhas truncadas (órfãs), **ou**
- um novo módulo precisar de FK real sobre `company_id`.
