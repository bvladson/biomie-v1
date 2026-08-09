"""Faixa normal por dia da semana — e as duas decisões que a sustentam.

O que estes testes protegem:

1. QUANTIL, NÃO MÉDIA ± DESVIO. Na MAG_FILIAL o desvio é 2,6× a média
   (R$ 78.162 contra R$ 30.519, com máximo de R$ 671.082). "Média ± 1 desvio"
   daria faixa de −R$ 47 mil a R$ 108 mil, que cobre qualquer dia possível.
   Faixa que nunca erra também nunca informa.

2. PORTÃO DE AMOSTRA. p10 e p90 sobre 2 sextas-feiras não são percentis, são os
   dois valores. Três das quatro empresas não têm amostra para isto, e a
   resposta certa é `sem_amostra` — não um número.
"""

from __future__ import annotations

import unittest
from datetime import date

from biomie_intel import daily_baseline as bl

from fakes import FakeConnection

HOJE = date(2026, 8, 8)
SQL = "GROUP BY n.data_competencia, weekday"


def _dias(weekday: int, receitas: list[float], notas: float = 5.0):
    return [{"weekday": weekday, "revenue": r, "invoices": notas} for r in receitas]


class TestQuantil(unittest.TestCase):
    def test_mediana_de_lista_impar(self) -> None:
        self.assertEqual(bl._quantil([1, 2, 3, 4, 5], 0.50), 3.0)

    def test_interpola_entre_posicoes(self) -> None:
        self.assertAlmostEqual(bl._quantil([1, 2, 3, 4, 5], 0.10), 1.4)

    def test_um_elemento_nao_quebra(self) -> None:
        self.assertEqual(bl._quantil([10], 0.90), 10.0)

    def test_lista_vazia_devolve_zero_sem_estourar(self) -> None:
        self.assertEqual(bl._quantil([], 0.50), 0.0)

    def test_um_outlier_gigante_NAO_move_a_mediana(self) -> None:
        """O ponto inteiro de usar quantil: um dia de R$ 671 mil não pode
        redefinir o que é um dia normal."""
        normal = [1000.0] * 20
        com_outlier = sorted(normal + [671000.0])
        self.assertEqual(bl._quantil(sorted(normal), 0.50), 1000.0)
        self.assertEqual(bl._quantil(com_outlier, 0.50), 1000.0)


class TestPortaoDeAmostra(unittest.TestCase):
    def _calcular(self, linhas):
        return bl.calcular(FakeConnection({SQL: linhas}), 1, HOJE)

    def test_amostra_curta_devolve_sem_amostra_e_nao_numero(self) -> None:
        [faixa] = self._calcular(_dias(6, [100.0, 200.0]))
        self.assertEqual(faixa.confianca, "sem_amostra")
        self.assertIsNone(faixa.p10_revenue)
        self.assertIsNone(faixa.mediana_revenue)
        self.assertEqual(faixa.amostra, 2)

    def test_no_limite_da_amostra_a_faixa_sai(self) -> None:
        [faixa] = self._calcular(_dias(3, [float(i) for i in range(bl.MIN_AMOSTRA)]))
        self.assertNotEqual(faixa.confianca, "sem_amostra")
        self.assertIsNotNone(faixa.p10_revenue)

    def test_amostra_grande_vira_confianca_alta(self) -> None:
        [faixa] = self._calcular(_dias(3, [float(i) for i in range(bl.AMOSTRA_CONFIANCA_ALTA)]))
        self.assertEqual(faixa.confianca, "alta")

    def test_dia_em_que_a_empresa_NUNCA_fatura_nao_gera_linha(self) -> None:
        """Domingo na MAG_FILIAL: 0 dias em 161. Publicar "faixa R$ 0–0" faria
        qualquer venda de domingo parecer anomalia gigante."""
        faixas = self._calcular(_dias(2, [100.0] * 15))
        self.assertEqual([f.weekday for f in faixas], [2])

    def test_a_janela_exclui_o_dia_corrente(self) -> None:
        """O dia de hoje ainda está em curso: entraria como dia fraco e puxaria
        a faixa para baixo justamente contra o dia que se quer avaliar."""
        conn = FakeConnection({SQL: []})
        bl.carregar_dias(conn, 1, HOJE)
        sql = conn.sql_executado()[0]
        # O duplo normaliza espaços, então a asserção casa a forma normalizada.
        self.assertIn("data_competencia < %s", sql)
        _, inicio, fim = conn.params_de(SQL)
        self.assertEqual(fim, HOJE)
        self.assertEqual((HOJE - inicio).days, bl.JANELA_DIAS)

    def test_a_janela_e_escopada_por_empresa_e_pelo_universo_comercial(self) -> None:
        conn = FakeConnection({SQL: []})
        bl.carregar_dias(conn, 7, HOJE)
        sql = conn.sql_executado()[0]
        self.assertIn("company_id = %s", sql)
        self.assertIn("operation_scope = 'commercial'", sql)
        self.assertIn("status_fiscal = 'autorizada'", sql)
        self.assertEqual(conn.params_de(SQL)[0], 7)


class TestVeredito(unittest.TestCase):
    def _faixa(self, confianca="alta", p10=100.0, p90=900.0):
        return bl.BaselineDia(company_id=1, weekday=3, amostra=25, janela_dias=180,
                              mediana_revenue=500.0, p10_revenue=p10, p25_revenue=300.0,
                              p75_revenue=700.0, p90_revenue=p90, mediana_invoices=5.0,
                              confianca=confianca)

    def test_acima_do_p90(self) -> None:
        self.assertEqual(bl.classificar_dia(1000.0, self._faixa()), "acima")

    def test_abaixo_do_p10(self) -> None:
        self.assertEqual(bl.classificar_dia(50.0, self._faixa()), "abaixo")

    def test_dentro_da_faixa(self) -> None:
        self.assertEqual(bl.classificar_dia(500.0, self._faixa()), "dentro")

    def test_sem_faixa_NAO_vira_dentro(self) -> None:
        """"Não tenho referência" e "está normal" levam a ações opostas."""
        self.assertEqual(bl.classificar_dia(500.0, None), "sem_referencia")
        self.assertEqual(
            bl.classificar_dia(500.0, self._faixa(confianca="sem_amostra")),
            "sem_referencia",
        )


class TestResumoEGravacao(unittest.TestCase):
    def test_o_resumo_declara_quantos_dias_ficaram_sem_faixa(self) -> None:
        conn = FakeConnection({SQL: _dias(2, [1.0] * 25) + _dias(6, [1.0, 2.0])})
        info = bl.resumo(bl.calcular(conn, 1, HOJE))
        self.assertEqual(info["dias_da_semana"], 2)
        self.assertEqual(info["com_faixa"], 1)
        self.assertEqual(info["por_confianca"], {"alta": 1, "sem_amostra": 1})

    def test_grava_com_upsert_e_nao_versiona(self) -> None:
        """Janela móvel recalculada todo dia não tem competência que faça a
        versão anterior continuar valendo — versionar daria 7 linhas por empresa
        por dia sem ninguém para lê-las."""
        conn = FakeConnection()
        bl.persist(conn, bl.calcular(FakeConnection({SQL: _dias(2, [1.0] * 25)}), 1, HOJE))
        sql = next(q for q in conn.sql_executado() if q.startswith("INSERT"))
        self.assertIn("ON DUPLICATE KEY UPDATE", sql)
        self.assertNotIn("is_current", sql)

    def test_lista_vazia_nao_toca_no_banco(self) -> None:
        conn = FakeConnection()
        self.assertEqual(bl.persist(conn, []), 0)
        self.assertEqual(conn.executed, [])


if __name__ == "__main__":
    unittest.main()
