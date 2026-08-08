"""Risco de atraso em recebíveis — e a separação de universo que o torna válido.

O QUE ESTES TESTES PROTEGEM: a descoberta que viabilizou o módulo é que
`data_vencimento` significa coisas DIFERENTES conforme a forma de pagamento. Em
cartão é a data em que a adquirente repassa; em boleto é a data em que o cliente
deve. Misturar os dois produz "1,9% de inadimplência" — a média entre uma taxa
real e uma taxa que não existe.

Se alguém remover a separação, nada explode: o score continua saindo, a tela
continua enchendo, e os números viram ficção. Por isso a regra é testada de
frente, e não como efeito colateral.
"""

from __future__ import annotations

import unittest
from datetime import date

from biomie_intel import receivables as r
from biomie_intel.receivables import HistoricoPagador, classificar_universo

from fakes import FakeConnection

HOJE = date(2026, 8, 8)
SQL_ABERTOS = "status_titulo = 'aberto'"
SQL_HISTORICO = "status_titulo = 'liquidado'"


def _aberto(titulo="T1", forma="Boleto", valor=1000.0, venc=date(2026, 8, 20), cliente=10):
    return {
        "titulo_key": titulo, "codigo_cliente_omie": cliente, "nome_cliente": "Cliente X",
        "forma_pagamento": forma, "valor_aberto": valor, "valor_liquido": valor,
        "data_vencimento": venc, "data_emissao": date(2026, 7, 1),
    }


def _liquidado(forma="Boleto", dias=0, cliente=10):
    return {"codigo_cliente_omie": cliente, "forma_pagamento": forma, "dias": dias}


class TestClassificacaoDeUniverso(unittest.TestCase):
    """A regra que torna o rótulo interpretável."""

    def test_cartao_e_adquirente_sao_liquidacao_imediata(self) -> None:
        for forma in ("Mercado Pago", "Cartao Master", "Cartao Visa",
                      "Cartao Elo", "Cartao Amex", "Maquininha Stone"):
            with self.subTest(forma=forma):
                self.assertEqual(classificar_universo(forma), "imediato")

    def test_boleto_pix_e_transferencia_sao_credito_real(self) -> None:
        for forma in ("Boleto", "Boleto/TED", "PIX", "Dinheiro", "Transferencia"):
            with self.subTest(forma=forma):
                self.assertEqual(classificar_universo(forma), "credito_real")

    def test_forma_vazia_e_INDEFINIDA_e_nao_um_chute(self) -> None:
        """São 1.700 títulos com forma vazia, de perfil idêntico ao do cartão.

        Chutar "crédito real" encheria a fila de cobrança de gente que já pagou;
        chutar "imediato" esconderia devedor. Indefinido é a resposta honesta.
        """
        for vazio in ("", "   ", None):
            with self.subTest(valor=vazio):
                self.assertEqual(classificar_universo(vazio), "indefinido")

    def test_forma_desconhecida_nao_vira_credito_real_por_omissao(self) -> None:
        self.assertEqual(classificar_universo("Convenio Novo XPTO"), "indefinido")

    def test_a_classificacao_ignora_caixa_e_espaco(self) -> None:
        self.assertEqual(classificar_universo("  BOLETO/TED  "), "credito_real")
        self.assertEqual(classificar_universo("mercado pago"), "imediato")

    def test_as_duas_listas_nao_se_sobrepoem(self) -> None:
        """Sobreposição faria a ordem do `if` decidir o significado do dado."""
        self.assertEqual(r.FORMAS_IMEDIATAS & r.FORMAS_CREDITO_REAL, frozenset())

    def test_as_listas_nao_esvaziaram(self) -> None:
        """Sem isto, tudo viraria `indefinido` e nenhum outro teste acusaria."""
        self.assertIn("mercado pago", r.FORMAS_IMEDIATAS)
        self.assertIn("boleto", r.FORMAS_CREDITO_REAL)


class TestHistoricoSoContaCreditoReal(unittest.TestCase):
    """Cartão no histórico envenenaria a taxa de atraso do cliente."""

    def _conn(self, linhas):
        return FakeConnection({SQL_HISTORICO: linhas})

    def test_titulo_de_cartao_nao_entra_no_historico(self) -> None:
        conn = self._conn([_liquidado("Cartao Visa", dias=-140)] * 20)
        self.assertEqual(r.carregar_historico(conn, 1, HOJE), {})

    def test_boleto_entra_e_conta_atraso(self) -> None:
        conn = self._conn([
            _liquidado("Boleto", dias=10), _liquidado("Boleto", dias=0),
            _liquidado("Boleto", dias=-2), _liquidado("Boleto", dias=25),
        ])
        h = r.carregar_historico(conn, 1, HOJE)[10]
        self.assertEqual(h.titulos, 4)
        self.assertEqual(h.atrasados, 2)
        self.assertEqual(h.pior_atraso, 25)
        self.assertAlmostEqual(h.taxa_atraso, 0.5)

    def test_a_tolerancia_absorve_compensacao_bancaria(self) -> None:
        """Boleto pago na segunda seguinte não é inadimplência."""
        conn = self._conn([_liquidado("Boleto", dias=d) for d in (1, 2, 3, 4)])
        h = r.carregar_historico(conn, 1, HOJE)[10]
        self.assertEqual(h.atrasados, 1, "a folga de 3 dias deixou de valer")

    def test_antecipacao_implausivel_conta_como_pago_mas_nao_move_a_media(self) -> None:
        """Sobraram 88 casos de −60 a −131 dias mesmo fora do cartão."""
        conn = self._conn([_liquidado("Boleto", dias=-131)] * 3 + [_liquidado("Boleto", dias=10)])
        h = r.carregar_historico(conn, 1, HOJE)[10]
        self.assertEqual(h.titulos, 4)
        self.assertEqual(h.atrasados, 1)
        self.assertEqual(h.atraso_medio, 10.0, "dias negativos contaminaram a média")

    def test_historico_e_escopado_por_empresa_e_por_data(self) -> None:
        conn = self._conn([])
        r.carregar_historico(conn, 7, HOJE)
        sql = conn.sql_executado()[0]
        self.assertIn("company_id = %s", sql)
        self.assertIn("data_liquidacao <= %s", sql)
        self.assertEqual(conn.params_de("status_titulo = 'liquidado'"), (7, HOJE))


class TestAmostraPequenaNaoAfirma(unittest.TestCase):
    def test_menos_de_tres_titulos_nao_gera_taxa(self) -> None:
        h = HistoricoPagador(titulos=2, atrasados=2)
        self.assertIsNone(h.taxa_atraso, "2 títulos viraram '100% de inadimplência'")

    def test_com_amostra_a_taxa_aparece(self) -> None:
        h = HistoricoPagador(titulos=4, atrasados=2)
        self.assertAlmostEqual(h.taxa_atraso, 0.5)


class TestScoreDosAbertos(unittest.TestCase):
    def _avaliar(self, abertos, historico=None):
        conn = FakeConnection({SQL_ABERTOS: abertos, SQL_HISTORICO: historico or []})
        return r.avaliar(conn, 1, HOJE)

    def test_cartao_recebe_score_NULO_e_nao_baixo(self) -> None:
        """Pontuar cartão como baixo risco é tecnicamente verdade e
        praticamente mentira: ele não está na fila porque não há o que cobrar."""
        [s] = self._avaliar([_aberto(forma="Cartao Visa")])
        self.assertIsNone(s.score)
        self.assertIsNone(s.band)
        self.assertEqual(s.universo, "imediato")
        self.assertTrue(any("repasse" in m for m in s.reasons))

    def test_forma_indefinida_tambem_fica_sem_score(self) -> None:
        [s] = self._avaliar([_aberto(forma="")])
        self.assertIsNone(s.score)
        self.assertEqual(s.universo, "indefinido")

    def test_titulo_vencido_pontua_mesmo_sem_historico(self) -> None:
        """Vencido é FATO, não previsão — não depende de conhecer o cliente."""
        [s] = self._avaliar([_aberto(venc=date(2026, 7, 1))])
        self.assertIsNotNone(s.score)
        self.assertGreater(s.score, 0)
        self.assertTrue(any("Vencido há 38 dias" in m for m in s.reasons))

    def test_mau_pagador_pontua_mais_que_bom_pagador(self) -> None:
        mau = self._avaliar(
            [_aberto(cliente=10)],
            [_liquidado("Boleto", dias=40, cliente=10)] * 3 + [_liquidado("Boleto", dias=0, cliente=10)],
        )[0]
        bom = self._avaliar(
            [_aberto(cliente=10)],
            [_liquidado("Boleto", dias=0, cliente=10)] * 4,
        )[0]
        self.assertGreater(mau.score, bom.score)

    def test_cliente_sem_historico_e_dito_explicitamente(self) -> None:
        [s] = self._avaliar([_aberto()])
        self.assertTrue(
            any("sem histórico" in m for m in s.reasons),
            f"a lacuna não foi declarada: {s.reasons}",
        )

    def test_exposicao_maior_sobe_na_fila(self) -> None:
        """Fila ordenada só por probabilidade manda cobrar R$ 80 e esquecer R$ 80 mil."""
        scores = self._avaliar([
            _aberto("PEQUENO", valor=100.0), _aberto("MEDIO", valor=1_000.0),
            _aberto("GRANDE", valor=100_000.0),
        ])
        por_titulo = {s.titulo_key: s.score for s in scores}
        self.assertGreater(por_titulo["GRANDE"], por_titulo["PEQUENO"])

    def test_sem_titulo_aberto_devolve_lista_vazia(self) -> None:
        self.assertEqual(self._avaliar([]), [])

    def test_a_mediana_de_referencia_ignora_cartao(self) -> None:
        """Cartão tem ticket médio de R$ 522 contra R$ 2.121 do crédito real.

        Se ele entrasse na mediana, ela despencaria e quase todo boleto
        pareceria "exposição alta" — a comparação tem que ser contra o que
        disputa a fila de cobrança, não contra o que nem entra nela.
        """
        muitos_cartoes = [_aberto(f"C{i}", forma="Cartao Visa", valor=50.0) for i in range(20)]
        boleto = _aberto("B", forma="Boleto", valor=1_000.0)

        sozinho = self._avaliar([boleto])[0]
        acompanhado = next(s for s in self._avaliar(muitos_cartoes + [boleto])
                           if s.titulo_key == "B")
        self.assertEqual(
            acompanhado.features["mediana_empresa"], sozinho.features["mediana_empresa"],
            "os títulos de cartão entraram na mediana e distorceram a exposição",
        )
        self.assertEqual(acompanhado.score, sozinho.score)

    def test_a_leitura_de_abertos_e_escopada_por_empresa(self) -> None:
        conn = FakeConnection({SQL_ABERTOS: []})
        r.carregar_abertos(conn, 4)
        self.assertIn("company_id = %s", conn.sql_executado()[0])
        self.assertEqual(conn.params_de(SQL_ABERTOS), (4,))


class TestResumoMostraOBuraco(unittest.TestCase):
    def test_o_resumo_declara_quantos_ficaram_SEM_score(self) -> None:
        """"12 de alto risco" parece cobertura total se ninguém disser de quantos."""
        conn = FakeConnection({SQL_ABERTOS: [
            _aberto("A", forma="Boleto"), _aberto("B", forma="Cartao Visa"),
            _aberto("C", forma=""),
        ], SQL_HISTORICO: []})
        info = r.resumo(r.avaliar(conn, 1, HOJE))
        self.assertEqual(info["titulos"], 3)
        self.assertEqual(info["pontuados"], 1)
        self.assertEqual(info["por_universo"],
                         {"credito_real": 1, "imediato": 1, "indefinido": 1})

    def test_o_resumo_separa_exposicao_total_de_pontuada(self) -> None:
        conn = FakeConnection({SQL_ABERTOS: [
            _aberto("A", forma="Boleto", valor=1000.0),
            _aberto("B", forma="Cartao Visa", valor=9000.0),
        ], SQL_HISTORICO: []})
        info = r.resumo(r.avaliar(conn, 1, HOJE))
        self.assertEqual(info["exposicao_total"], 10000.0)
        self.assertEqual(info["exposicao_pontuada"], 1000.0)

    def test_o_resumo_reporta_dispersao(self) -> None:
        """Score que não separa é indistinguível de não ter score."""
        conn = FakeConnection({SQL_ABERTOS: [
            _aberto("A", venc=date(2026, 6, 1)), _aberto("B", venc=date(2026, 12, 1)),
        ], SQL_HISTORICO: []})
        info = r.resumo(r.avaliar(conn, 1, HOJE))
        self.assertIn("mediana", info["dispersao"])
        self.assertGreater(info["dispersao"]["max"], info["dispersao"]["min"])


class TestGravacao(unittest.TestCase):
    def test_rebaixa_o_vigente_antes_de_inserir(self) -> None:
        conn = FakeConnection()
        s = r.RiscoTitulo(company_id=1, titulo_key="T", codigo_cliente_omie=1,
                          nome_cliente="X", forma_pagamento="Boleto", universo="credito_real",
                          valor_aberto=10.0, data_vencimento=HOJE, dias_para_vencer=0,
                          score=50.0, band="media")
        r.persist(conn, 1, [s])
        sqls = conn.sql_executado()
        rebaixa = next(i for i, q in enumerate(sqls) if "SET is_current = 0" in q)
        insere = next(i for i, q in enumerate(sqls) if "INSERT INTO intelligence_receivable_risk" in q)
        self.assertLess(rebaixa, insere)

    def test_lista_vazia_AINDA_rebaixa(self) -> None:
        """Empresa que quitou tudo não pode ficar com a fila de ontem na tela."""
        conn = FakeConnection()
        self.assertEqual(r.persist(conn, 1, []), 0)
        self.assertTrue(any("SET is_current = 0" in q for q in conn.sql_executado()))
        self.assertEqual(conn.commits, 1)

    def test_a_gravacao_e_escopada_por_empresa(self) -> None:
        conn = FakeConnection()
        r.persist(conn, 9, [])
        self.assertEqual(conn.params_de("SET is_current = 0"), (9,))


if __name__ == "__main__":
    unittest.main()
