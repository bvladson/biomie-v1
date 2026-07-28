# Agenda de Orçamentos — Fase 2: ENCERRAMENTO

**Data:** 2026-07-16
**Status:** ✅ **CONCLUÍDA** — código implantado, dados preenchidos e persistência
comprovada por **duas execuções reais** do `replacePendingOrdersWindow`.

---

## 1. O que a Fase 2 entregou

Três colunas de data no canônico de pedidos, preenchidas a partir do payload Omie
e **resistentes ao fluxo normal de DELETE+reinsert do cron**:

| Coluna | Tipo | Fonte | Fuso |
|---|---|---|---|
| `data_inclusao_omie` | DATETIME | `infoCadastro.dInc` + `hInc` | Fortaleza (wall-clock original) |
| `data_alteracao_omie` | DATETIME | `infoCadastro.dAlt` + `hAlt` | Fortaleza |
| `data_previsao_faturamento` | DATE | `cabecalho.data_previsao` | dia civil |

**Regra de ouro respeitada:** `data_emissao` **não mudou de significado** — segue a
regra legada (previsão primeiro, fallback `dInc`). A Fase 2 é aditiva.

**Problema que resolve:** `data_emissao` guardava a *previsão*, não a *inclusão*.
Medido em produção: **22%** do histórico e **30–35%** dos orçamentos vivos têm
`dInc ≠ previsão` — ou seja, "aberto há X dias" e criticidade sairiam errados em
~1 de cada 3 orçamentos.

---

## 2. Arquivos implantados (8) e checksums

Deploy sob **lock global do pipeline** (as 5 empresas na mesma sessão MySQL).

| Arquivo | md5 (local = remoto validado) |
|---|---|
| `app/Support/OmieDateParser.php` *(novo)* | `09d482deaf98d12487fd96aa6e071c39` |
| `app/DataPlatform/Silver/Schema/CanonicalSchemaManager.php` | `3edf5363f6ca7433991fc10c2b83aa0a` |
| `app/Canonical/Support/CanonicalHashing.php` | `0472471ea2c2c2599d63b5e28eca4e33` |
| `app/Canonical/CanonicalWriteRepository.php` | `2f2dc952e0d4223514b1cbd4ed1377cd` |
| `app/FiscalSync/FiscalCanonicalMapper.php` | `18bdcfd2aa26013d5e14fc07736501ca` |
| `app/Sync/CommercialSyncCoordinator.php` | `ec89444ba582216373d2bcf2108120b9` |
| `database/migrations/Version20260715000000_QuoteAgendaOrderDates.php` *(novo)* | `6e871cc478b1ac6f1240cdd1a662aa75` |
| `config/env.php` (3 flags, todas OFF) | `6bd958fa98f21df4e8ddf3a61087cf13` |

**Backup dos 6 arquivos pré-existentes:**
`storage/backups/fase2_20260716_202825`

**Due diligence antes de sobrescrever:** as versões remotas foram baixadas e diffadas
com `--strip-trailing-cr` (CRLF×LF poluíam o diff). `grep '^<'` (linhas exclusivas do
servidor) = **vazio nos 6** → as versões locais eram superset puro.
*Isso prova que nenhuma customização do servidor foi perdida — não prova "risco zero"
de execução. Essa prova veio dos testes, da sentinela e dos ciclos do cron (§4).*

**Checks pós-promoção no servidor:** 8/8 `php -l` OK · autoload encontra
`BIomie\Support\OmieDateParser` · writer com as 3 colunas · mapper com a regra legada
intacta · migração fisicamente presente · flags presentes · `opcache_reset` executado ·
lock liberado **só depois** dos checks.

---

## 3. Testes

| Teste | Resultado |
|---|---|
| `tests/run_quote_order_dates.php` | **30/30 PASS** (parser estrito, mapper, contrato do writer, hash) |
| `tools/dev/lint_all.php` | **683 arquivos, 0 falhas** |
| Migração `20260715000000` | aplicada · idempotente (re-run = 0) · `verify` 71/0 mismatch |

---

## 4. Prova de durabilidade (o gate)

**Critério:** poll não é ciclo. Só conta quando o `sincronizado_em` **da própria
sentinela** avança — ela tem `data_emissao = hoje`, logo pertence à janela do cron;
dois valores distintos e crescentes provam duas passagens reais do
`replacePendingOrdersWindow`.

**Sentinela:** pedido `8897507832` (MAG), `data_inclusao_omie = 2026-07-16 09:52:09`.

| Evento | `sincronizado_em` (UTC) | `data_inclusao_omie` |
|---|---|---|
| Cron **antigo** (pré-deploy, 23:16) | `23:16:01` | **NULL** ❌ *(apagava)* |
| Sync controlada (código novo, 23:30) | `23:30:25` | `2026-07-16 09:52:09` ✅ |
| **Ciclo 1** — cron `sync_commercial.sh` das :45 | `23:46:02` | `2026-07-16 09:52:09` ✅ |
| **Ciclo 2** — cron `sync_commercial.sh` das :00 | `2026-07-17 00:01:02` | `2026-07-16 09:52:09` ✅ |

Corroboração (`biomie_sync_state.completed_at`, Fortaleza — coluna própria, nunca
cruzada com a de cima): `20:30:46 → 20:46:22 → 21:01:23`.

O ciclo 2 **atravessou a virada do dia em UTC** sem quebrar.

**Em cada ciclo, revalidado:**
- ✅ MAG `39/39` · EXPERT `12/12` · UNI `11/11` (3 datas preenchidas)
- ✅ Licitação `8896509853` permanece excluída
- ✅ zero datas voltando a NULL · zero duplicidade
- ✅ flags desligadas

---

## 5. Baseline autorizado da Agenda

| Empresa | Vivos (etapa 10) | Elegíveis | Observação |
|---|---|---|---|
| MAG_FILIAL (1) | 40 | **39** | 1 excluído: `8896509853`, vendedor **Licitação** (`HardDataExclusionPolicy`, Fase L) |
| EXPERT (2) | 12 | **12** | |
| UNI_DESIGN (3) | 11 | **11** | |

Os **~103** registros etapa-10 antigos do canônico da MAG são **"não presentes no
snapshot vivo"** — não entram na Agenda, **não** foram apagados e **não** recebem
`source_deleted` (podem ter convertido, mudado de etapa ou sido cancelados).

---

## 6. Dívidas registradas (fora do escopo da Fase 2)

1. **🔴 Blind spot de previsão futura.** `listPendingOrders` filtra por
   `data_previsao` dentro da janela sincronizada (que termina "hoje") ⇒ **orçamentos
   com previsão futura nunca são buscados**. Provado com pares do mesmo dia:
   `#2181` (previsão=hoje) presente × `#2180` (previsão=21/07) ausente.
   **9 de 40 vivos da MAG (22,5%)** têm previsão futura — justamente os mais novos e
   acionáveis. **Requisito bloqueante do scanner (Fase 4):** a fonte da Agenda deve
   ser `ListarPedidos(etapa=10)` (estado vivo), nunca a janela de previsão.
   A tela **nunca** consulta o Omie direto.
2. **Assimetria de relógio.** Banco = **UTC**; app = **America/Fortaleza**.
   Política fechada para a Fase 3+: técnicos/auditoria `_at` em UTC ·
   `quote_created_at` no horário comercial de Fortaleza · cadência via
   `next_followup_on` **DATE** · exibição convertida · nunca comparar `NOW()` UTC com
   DATETIME local sem conversão explícita.
3. **`pending_orders_sync` morto desde 2026-04-03.** Não serve de base para frescor.
   A Agenda terá estado próprio: `biomie_quote_live_snapshot_sync`.
4. **Circuit breaker conta erro de negócio como falha.** "Pedido não cadastrado"
   (resposta legítima) abre o circuito (≥3 falhas, TTL 300s). Só timeout/5xx deveria
   contar.

---

## 7. Runbook operacional (aprendizados)

- **`crontab -l` vazio ≠ ausência de agendamento.** A fonte real é o **painel
  Hostinger** (`hosting_listAccountCronJobsV1`, user `u106688109`).
  `sync_commercial.sh` roda **`*/15`** (:00 :15 :30 :45) ⇒ 2 ciclos ≈ 20–30 min.
- **Caminho de produção:** `/home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie`
  (o `domains/` é obrigatório). SSH com **BatchMode** (chave instalada), sem senha.
- **Lock do pipeline é por empresa** (`GET_LOCK`, por sessão). Para bloquear tudo,
  segurar as 5 chaves na **mesma** sessão.
- **Armadilha:** `$(...)` aninhado com aspas escapadas dentro de string PHP quebra
  comandos ssh **silenciosamente** — foi o que abortou o primeiro deploy. Prefira um
  comando simples (`md5sum <lista>`) e parseie a saída.
- **O abort do primeiro deploy foi um sucesso de processo:** checksum inválido impediu
  a promoção, removeu os temporários e preservou a versão anterior intacta.

---

## 8. Ferramentas criadas

| Ferramenta | Papel |
|---|---|
| `tools/dev/quote_agenda_gate_diagnosis.php` | Gate inicial (volume/escopo/qualidade) + baseline versionado |
| `tools/dev/quote_order_dates_dryrun.php` | Dry-run de impacto das datas sobre o bronze |
| `tools/dev/quote_agenda_invariant_snapshot.php` | Snapshot/compare de invariantes (hash exclui as datas novas) |
| `tools/dev/quote_agenda_resync_company.php` | Ressync focada só-carteira por empresa |
| `tools/dev/quote_agenda_live_snapshot.php` | Snapshot vivo `ListarPedidos(etapa=10)` |
| `tools/dev/quote_agenda_live_upsert.php` | Upsert do snapshot vivo com **gate de diff** |
| `tools/dev/quote_agenda_deploy_fase2.php` | Deploy sob lock global (backup → checksum → promoção → checks) |
| `tools/dev/quote_agenda_durability_gate.php` | Gate de durabilidade (exige ciclos reais do cron) |

---

## 9. Governança de classificação (vigente)

| Classificação | Pode aplicar? |
|---|---|
| `source_drift` comprovado (dAlt/NF/etapa na origem) | ✅ |
| `reference_resolution_correction` determinística | ✅ |
| Apenas datas novas (NULL → valor) | ✅ |
| Regressão do mapper | ❌ |
| Diferença sem explicação | ❌ |

`source_missing_confirmed` / `source_deleted` exigem consulta válida confirmando
inexistência. **Ausência nunca é prova.** `missing_since` para o inconclusivo.

**Pendência técnica registrada:** pedido `8787852565` (R$ 20,00, MAG) permanece
`missing_since` — a confirmação foi interrompida por bloqueio de API. Não bloqueia a
Agenda: não está entre os vivos.

---

## 10. Estado final

- ✅ Código implantado nas 5 empresas (via base compartilhada)
- ✅ Datas preenchidas: MAG 39/39 · EXPERT 12/12 · UNI 11/11
- ✅ Persistência comprovada por 2 execuções reais do cron
- ✅ Nenhuma NF ou financeiro escrito em toda a fase
- ✅ **As 3 flags permanecem desligadas**

**Próxima fase:** 3 — fundação (tabelas de acompanhamento, política 1/3/7/14, estado
de frescor). Sem interface, flags desligadas.
