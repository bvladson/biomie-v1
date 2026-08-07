"""Leitura e gravação dos scores — o módulo que toca o banco de verdade.

Por que este arquivo existe: `scoring.py` (a matemática pura) tinha 221 linhas
de teste e `quotes.py` não tinha NENHUMA, apesar de ser quem lê os orçamentos
abertos e quem faz o `UPDATE is_current = 0` antes de inserir. O bug caro aqui
não é aritmético — é o rebaixamento escapar do escopo `(empresa, scorer, modo)`
e apagar a Agenda de quem não tinha nada a ver com a rodada.

O que se afirma aqui é sobre a CONSULTA, não só sobre o retorno: o duplo de
conexão registra todo SQL executado, então dá para provar que o filtro existe.
"""

from __future__ import annotations

import json
import unittest
from datetime import date

from biomie_intel import quotes
from biomie_intel.scoring import QuoteScore

from fakes import FakeConnection

HOJE = date(2026, 8, 7)


def _linha(order_id: int, **extra):
    base = {
        "company_id": 1,
        "omie_order_id": order_id,
        "quote_created_at": date(2026, 7, 1),
        "quote_forecast_on": date(2026, 8, 10),
        "next_followup_on": date(2026, 8, 1),
        "followup_step": 1,
        "charge_count": 0,
        "valor_total": 10_000.0,
        "etapa_omie": "10",
        "business_channel": "varejo",
        "cliente_nfs": 0,
    }
    base.update(extra)
    return base


class TestLeituraDosOrcamentosAbertos(unittest.TestCase):
    def test_le_apenas_de_uma_empresa(self) -> None:
        """Não existe consulta comercial sem empresa nesta camada."""
        conn = FakeConnection()
        quotes.load_open_quotes(conn, 7)
        self.assertIn("s.company_id = %s", conn.sql_executado()[0])
        self.assertEqual(conn.params_de("FROM biomie_sales_quote_followup_schedule"), (7,))

    def test_o_join_com_pedidos_tambem_e_escopado(self) -> None:
        """Casar pedido só por id vazaria valor de OUTRA empresa para o score.

        Os ids da Omie se repetem entre as cinco empresas — o JOIN precisa da
        empresa nos dois lados, senão o percentil de valor é calculado sobre
        dinheiro que não é daquela empresa.
        """
        sql = " ".join(quotes.OPEN_QUOTES_SQL.split())
        self.assertIn("p.pedido_id_omie = s.omie_order_id AND p.company_id = s.company_id", sql)

    def test_so_traz_orcamento_sem_desfecho(self) -> None:
        self.assertIn("s.resolved_at IS NULL", " ".join(quotes.OPEN_QUOTES_SQL.split()))

    def test_a_contagem_de_nfs_do_cliente_e_da_mesma_empresa(self) -> None:
        sql = " ".join(quotes.OPEN_QUOTES_SQL.split())
        self.assertIn("n.company_id = s.company_id", sql)
        self.assertIn("n.status_fiscal = 'autorizada'", sql)


class TestPontuacaoDoLote(unittest.TestCase):
    def test_o_percentil_e_calculado_dentro_da_empresa(self) -> None:
        """R$ 50 mil é negócio grande numa empresa e rotina noutra."""
        linhas = [_linha(1, valor_total=1_000.0), _linha(2, valor_total=500_000.0)]
        scores = quotes.score_company(linhas, HOJE)
        self.assertEqual(len(scores), 2)
        self.assertLess(
            scores[0].features["valor_percentil"], scores[1].features["valor_percentil"]
        )

    def test_orcamento_sem_valor_nao_e_tratado_como_zero(self) -> None:
        """Zerar quem não casou com pedido seria viés silencioso, não mérito.

        A amostra é uma distribuição de verdade (10 valores) e não duas linhas:
        com dois orçamentos, o mais barato já é o percentil 50 e a comparação
        não diz nada. Era esse o defeito da primeira versão deste teste.

        O que se afirma é o que a calibração promete: quem não casou com pedido
        fica ACIMA do quartil de baixo, e não junto dele. O peso concedido é
        `W_VALUE * 0.35` — entre a faixa de p25 (0.22) e a de p50 (0.50).
        """
        valores = [100.0, 200.0, 400.0, 800.0, 1_600.0,
                   3_200.0, 6_400.0, 12_800.0, 25_600.0, 51_200.0]
        linhas = [_linha(i + 2, valor_total=v) for i, v in enumerate(valores)]
        linhas.insert(0, _linha(1, valor_total=None))

        scores = quotes.score_company(linhas, HOJE)
        sem_valor = scores[0]
        peso = lambda s: s.features["componentes"]["value_weight"]

        self.assertIsNone(sem_valor.features["valor_percentil"])
        self.assertTrue(sem_valor.features["valor_desconhecido"])
        self.assertGreater(
            peso(sem_valor), peso(scores[1]),
            "orçamento sem valor caiu junto com o mais barato — voltou a ser punido por falta de dado",
        )
        self.assertLess(
            peso(sem_valor), peso(scores[-1]),
            "orçamento sem valor empatou com o maior da empresa — passou a ser premiado pela lacuna",
        )

    def test_a_lacuna_de_valor_nao_inventa_frase_de_tamanho(self) -> None:
        """Sem valor não pode virar "entre os 10% maiores" — não há como saber."""
        linhas = [_linha(i, valor_total=None) for i in range(1, 4)]
        for s in quotes.score_company(linhas, HOJE):
            self.assertFalse(
                [m for m in s.reasons if "maiores" in m or "R$" in m],
                f"motivo afirma tamanho sem ter valor: {s.reasons}",
            )

    def test_amostra_pequena_nao_afirma_estar_entre_os_10_por_cento(self) -> None:
        """Com 2 orçamentos, um deles é sempre o topo — a frase induziria a erro."""
        linhas = [_linha(1, valor_total=1_000.0), _linha(2, valor_total=90_000.0)]
        maior = quotes.score_company(linhas, HOJE)[1]
        self.assertFalse(
            [m for m in maior.reasons if "10% maiores" in m],
            f"afirmou percentil com amostra de 2: {maior.reasons}",
        )

    def test_lote_vazio_nao_explode(self) -> None:
        self.assertEqual(quotes.score_company([], HOJE), [])


class TestMovimentoEntreRodadas(unittest.TestCase):
    def _score(self, valor: float, band: str = "alta") -> QuoteScore:
        return QuoteScore(company_id=1, omie_order_id=42, score=valor, band=band,
                          features={}, reasons=[])

    def test_score_novo_entra_como_novo(self) -> None:
        s = self._score(80.0)
        quotes.apply_movement([s], {})
        self.assertEqual(s.features["movimento"], "novo")
        self.assertIsNone(s.features["score_anterior"])
        self.assertIn("Entrou na fila de prioridade", s.reasons)

    def test_novo_e_baixo_nao_polui_a_tela(self) -> None:
        """"Novo na fila" só interessa se o orçamento já merece atenção."""
        s = self._score(12.0, band="baixa")
        quotes.apply_movement([s], {})
        self.assertEqual(s.features["movimento"], "novo")
        self.assertEqual(s.reasons, [])

    def test_variacao_pequena_nao_vira_alerta(self) -> None:
        s = self._score(72.0)
        quotes.apply_movement([s], {42: 70.0})
        self.assertEqual(s.features["movimento"], "estavel")
        self.assertEqual(s.reasons, [])

    def test_subida_relevante_vira_motivo_no_topo(self) -> None:
        s = self._score(80.0)
        quotes.apply_movement([s], {42: 50.0})
        self.assertEqual(s.features["movimento"], "subiu")
        self.assertEqual(s.features["delta"], 30.0)
        self.assertTrue(s.reasons[0].startswith("Subiu 30 pontos"))

    def test_queda_nao_gera_motivo(self) -> None:
        """Cair não é chamada para ação — é ruído na lista do vendedor."""
        s = self._score(40.0)
        quotes.apply_movement([s], {42: 80.0})
        self.assertEqual(s.features["movimento"], "desceu")
        self.assertEqual(s.reasons, [])

    def test_a_leitura_anterior_e_escopada(self) -> None:
        conn = FakeConnection()
        quotes.load_previous_scores(conn, 3, "deterministic", "baseline")
        sql = conn.sql_executado()[0]
        for filtro in ("company_id = %s", "scorer = %s", "run_mode = %s", "is_current = 1"):
            self.assertIn(filtro, sql)
        self.assertEqual(conn.params_de("FROM intelligence_quote_scores"),
                         (3, "deterministic", "baseline"))


class TestGravacaoDosScores(unittest.TestCase):
    def _scores(self, n: int = 2) -> list[QuoteScore]:
        return [
            QuoteScore(company_id=1, omie_order_id=100 + i, score=50.0 + i, band="media",
                       features={"componentes": {}}, reasons=["motivo"])
            for i in range(n)
        ]

    def test_rebaixa_o_vigente_antes_de_inserir(self) -> None:
        conn = FakeConnection()
        quotes.persist_scores(conn, self._scores(), company_id=1, job_run_id=9,
                              scorer="deterministic", run_mode="baseline")
        sqls = conn.sql_executado()
        rebaixa = next(i for i, s in enumerate(sqls) if "SET is_current = 0" in s)
        insere = next(i for i, s in enumerate(sqls) if "INSERT INTO intelligence_quote_scores" in s)
        self.assertLess(rebaixa, insere, "inseriu antes de rebaixar — dois vigentes ao mesmo tempo")

    def test_o_rebaixamento_NAO_alcanca_outro_scorer_ou_modo(self) -> None:
        """Uma rodada do shadow não pode deixar a tela sem score do baseline.

        Este é o estrago caro deste módulo: um UPDATE frouxo aqui apaga a
        prioridade da Agenda de uma empresa inteira sem erro nenhum.
        """
        conn = FakeConnection()
        quotes.persist_scores(conn, self._scores(), company_id=4, job_run_id=9,
                              scorer="model", run_mode="shadow")
        sql = next(s for s in conn.sql_executado() if "SET is_current = 0" in s)
        for filtro in ("company_id = %s", "scorer = %s", "run_mode = %s"):
            self.assertIn(filtro, sql)
        self.assertEqual(conn.params_de("SET is_current = 0"), (4, "model", "shadow"))

    def test_lote_vazio_nao_rebaixa_nada(self) -> None:
        """Sem score novo, rebaixar deixaria a Agenda sem prioridade nenhuma."""
        conn = FakeConnection()
        self.assertEqual(
            quotes.persist_scores(conn, [], company_id=1, job_run_id=9,
                                  scorer="deterministic", run_mode="baseline"),
            0,
        )
        self.assertEqual(conn.executed, [])

    def test_motivos_e_features_vao_como_json_legivel(self) -> None:
        conn = FakeConnection()
        quotes.persist_scores(conn, self._scores(1), company_id=1, job_run_id=9,
                              scorer="deterministic", run_mode="baseline")
        linhas = conn.params_de("INSERT INTO intelligence_quote_scores")
        self.assertEqual(json.loads(linhas[0][-1]), ["motivo"])
        self.assertIn("componentes", json.loads(linhas[0][-2]))


class TestExpurgoDeScores(unittest.TestCase):
    """A tabela cresce 3.426 linhas/dia; sem expurgo é 1,1 GB/ano numa VPS."""

    def _conn(self, apagadas: int) -> FakeConnection:
        return FakeConnection(rowcounts={"DELETE FROM intelligence_quote_scores": apagadas})

    def test_nunca_apaga_o_que_esta_na_tela(self) -> None:
        conn = self._conn(0)
        quotes.purge_superseded(conn, 1)
        sql = next(s for s in conn.sql_executado() if s.startswith("DELETE"))
        self.assertIn("is_current = 0", sql)

    def test_respeita_a_janela_de_retencao(self) -> None:
        conn = self._conn(0)
        quotes.purge_superseded(conn, 1, retencao_dias=45)
        self.assertIn("scored_at <", next(s for s in conn.sql_executado() if s.startswith("DELETE")))
        self.assertEqual(conn.params_de("DELETE FROM intelligence_quote_scores")[1], 45)

    def test_e_escopado_a_uma_empresa(self) -> None:
        conn = self._conn(0)
        quotes.purge_superseded(conn, 7)
        self.assertEqual(conn.params_de("DELETE FROM intelligence_quote_scores")[0], 7)

    def test_NAO_apaga_score_amarrado_a_feedback(self) -> None:
        """`fk_iqf_score` é ON DELETE SET NULL — o banco não recusa, ele anula.

        O `score_id` do feedback existe para saber qual número estava na tela
        quando o vendedor respondeu. Perder o elo não daria erro nenhum, só
        tornaria o rótulo inútil no dia em que houver volume para treinar — que
        é a única coisa capaz de destravar o modelo supervisionado.
        """
        conn = self._conn(0)
        quotes.purge_superseded(conn, 1)
        sql = next(s for s in conn.sql_executado() if s.startswith("DELETE"))
        self.assertIn("NOT EXISTS", sql)
        self.assertIn("intelligence_quote_feedback", sql)
        self.assertIn("f.score_id = intelligence_quote_scores.id", sql)

    def test_apaga_em_lotes_ate_esvaziar(self) -> None:
        """Um passivo grande não pode sair numa transação só."""
        conn = self._conn(quotes.LOTE_EXPURGO)
        chamadas = {"n": 0}
        original = conn.rowcount_for

        def parar_no_terceiro(sql, rows):
            if sql.strip().upper().startswith("DELETE"):
                chamadas["n"] += 1
                return quotes.LOTE_EXPURGO if chamadas["n"] < 3 else 12
            return original(sql, rows)

        conn.rowcount_for = parar_no_terceiro  # type: ignore[method-assign]
        total = quotes.purge_superseded(conn, 1)
        self.assertEqual(chamadas["n"], 3)
        self.assertEqual(total, quotes.LOTE_EXPURGO * 2 + 12)

    def test_commita_entre_lotes_para_soltar_o_lock(self) -> None:
        """A Agenda lê esta tabela; a faxina não pode prendê-la."""
        conn = self._conn(3)
        quotes.purge_superseded(conn, 1)
        self.assertGreaterEqual(conn.commits, 1)

    def test_tem_teto_por_rodada(self) -> None:
        """Sem teto, um passivo enorme faria o job estourar o tempo e virar failed."""
        conn = self._conn(quotes.LOTE_EXPURGO)
        total = quotes.purge_superseded(conn, 1)
        self.assertLessEqual(total, quotes.MAX_EXPURGO_POR_RODADA)


if __name__ == "__main__":
    unittest.main()
