"""O backtest precisa ser mais confiável que o score que ele julga.

Um backtest que vaza futuro produz número bonito e falso — foi exatamente o que
aconteceu neste projeto quando o viés de sobrevivência inflou um AUC para 0,94.
Aqui o risco é o mesmo: se o score da competência C enxergar as vendas de C+1,
ele "acerta" tudo e o veredito vira propaganda.
"""

from __future__ import annotations

import unittest
from datetime import date

from biomie_intel import geo_backtest as gb


def _linhas(serie: dict[str, dict[str, float]]) -> list[dict]:
    """serie = {competencia: {cidade: receita}} — uma linha por par."""
    out = []
    for comp, cidades in serie.items():
        for cidade, receita in cidades.items():
            out.append({"competencia": comp, "cidade_norm": cidade, "bairro_norm": None,
                        "regiao_nome": None, "revenue": receita})
    return out


def _serie_crescente(n_territorios: int, meses: list[str], base: float = 10_000.0) -> dict:
    serie = {}
    for i, comp in enumerate(meses):
        serie[comp] = {f"cidade{j}": base * (1 + 0.05 * i) + j * 1_000 for j in range(n_territorios)}
    return serie


class TestNaoOlhaOFuturo(unittest.TestCase):
    MESES = ["2026-01", "2026-02", "2026-03", "2026-04", "2026-05"]

    def test_o_score_da_referencia_ignora_o_que_veio_depois(self) -> None:
        """Trocar o FUTURO não pode mexer no score calculado na referência."""
        serie = _serie_crescente(12, self.MESES)
        rows_normal = _linhas(serie)

        explosivo = {**serie}
        explosivo["2026-05"] = {k: v * 50 for k, v in serie["2026-05"].items()}
        rows_explosivo = _linhas(explosivo)

        base = gb._avaliar_janela(rows_normal, 1, "2026-04", 1, 5)
        alterado = gb._avaliar_janela(rows_explosivo, 1, "2026-04", 1, 5)

        self.assertTrue(base, "esperava ao menos uma camada avaliada")
        scores_base = [a["score"] for a in base[0].detalhe["topo"]]
        scores_alterado = [a["score"] for a in alterado[0].detalhe["topo"]]
        self.assertEqual(
            scores_base, scores_alterado,
            "o score da referência mudou quando só o futuro mudou — há vazamento",
        )

    def test_mas_o_observado_MUDA_quando_o_futuro_muda(self) -> None:
        """O contraponto: se nada mudasse, o teste acima seria vazio."""
        serie = _serie_crescente(12, self.MESES)
        explosivo = {**serie, "2026-05": {k: v * 50 for k, v in serie["2026-05"].items()}}

        base = gb._avaliar_janela(_linhas(serie), 1, "2026-04", 1, 5)[0]
        alterado = gb._avaliar_janela(_linhas(explosivo), 1, "2026-04", 1, 5)[0]

        self.assertNotEqual(
            base.detalhe["crescimento_topo"], alterado.detalhe["crescimento_topo"],
            "o observado não reagiu ao futuro — o backtest não está medindo nada",
        )


class TestLiftContraTaxaBase(unittest.TestCase):
    MESES = ["2026-01", "2026-02", "2026-03", "2026-04", "2026-05"]

    def test_precisao_base_e_sempre_registrada(self) -> None:
        """Sem a taxa da população, "acertei 70%" não significa nada."""
        resultados = gb._avaliar_janela(_linhas(_serie_crescente(15, self.MESES)), 1, "2026-04", 1, 5)
        self.assertTrue(resultados)
        for r in resultados:
            self.assertIsNotNone(r.precisao_base)
            self.assertIsNotNone(r.precisao_top_n)

    def test_todos_crescendo_igual_nao_gera_lift(self) -> None:
        """Se a maré sobe para todos, o score não ordenou nada."""
        serie = {}
        for i, comp in enumerate(self.MESES):
            serie[comp] = {f"cidade{j}": 10_000.0 * (1 + 0.10 * i) for j in range(15)}
        resultados = gb._avaliar_janela(_linhas(serie), 1, "2026-04", 1, 5)
        self.assertTrue(resultados)
        for r in resultados:
            if r.lift_top_n is not None:
                self.assertAlmostEqual(r.lift_top_n, 1.0, delta=0.05)


class TestVereditoNaoInventa(unittest.TestCase):
    def test_poucas_janelas_e_amostra_insuficiente(self) -> None:
        veredito, motivo = gb._consolidar([])
        self.assertEqual(veredito, "amostra_insuficiente")
        self.assertIn("minimo", motivo)

    def test_uma_janela_boa_nao_declara_que_ordena(self) -> None:
        """Uma coincidência não é prova — o veredito olha o conjunto."""
        uma = gb.BacktestResult(
            company_id=1, layer_id="ceara_municipios", competencia_referencia="2026-04",
            horizonte_meses=1, territorios_avaliados=20, top_n=5, lift_top_n=9.9,
            precisao_top_n=1.0, precisao_base=0.5, spearman=0.9, mape=0.1,
            cobertura_faixa_pct=80.0, veredito="", motivo="",
        )
        veredito, _ = gb._consolidar([uma])
        self.assertEqual(veredito, "amostra_insuficiente")

    def test_lift_medio_baixo_e_nao_ordena(self) -> None:
        janelas = [
            gb.BacktestResult(
                company_id=1, layer_id="x", competencia_referencia=f"2026-0{i}",
                horizonte_meses=1, territorios_avaliados=20, top_n=5, lift_top_n=1.01,
                precisao_top_n=0.5, precisao_base=0.5, spearman=0.0, mape=0.4,
                cobertura_faixa_pct=50.0, veredito="", motivo="",
            ) for i in range(1, 5)
        ]
        veredito, motivo = gb._consolidar(janelas)
        self.assertEqual(veredito, "nao_ordena")
        self.assertIn("lift mediano", motivo)

    def test_lift_consistente_e_alto_declara_que_ordena(self) -> None:
        janelas = [
            gb.BacktestResult(
                company_id=1, layer_id="x", competencia_referencia=f"2026-0{i}",
                horizonte_meses=1, territorios_avaliados=20, top_n=5, lift_top_n=1.6,
                precisao_top_n=0.8, precisao_base=0.5, spearman=0.5, mape=0.2,
                cobertura_faixa_pct=70.0, veredito="", motivo="",
            ) for i in range(1, 5)
        ]
        veredito, _ = gb._consolidar(janelas)
        self.assertEqual(veredito, "ordena")

    def test_o_limite_de_lift_tem_folga_sobre_o_acaso(self) -> None:
        """1.0 é empate com o acaso; declarar vitória ali seria ruído."""
        self.assertGreater(gb.LIFT_MINIMO, 1.0)


class TestCamadaSemTicketNaoDerrubaOJob(unittest.TestCase):
    """Regressão de um crash achado ao escrever este backtest.

    `geo_intelligence` fazia `median([x for x in tickets[layer] if x > 0])` com
    o guard testando a lista CRUA. Numa camada onde todo território tem ticket
    zero — nenhum cliente identificado no mês — a lista filtrada fica vazia e
    `statistics.median` levanta `StatisticsError`, derrubando o job inteiro
    daquela empresa. Sem cliente não há ticket comparável, e isso é um dado
    legítimo, não uma exceção.
    """

    def test_todos_os_tickets_zerados_nao_levanta_excecao(self) -> None:
        from biomie_intel.geo_intelligence import compute_from_rows

        rows = [
            {"competencia": c, "cidade_norm": f"cidade{j}", "bairro_norm": None,
             "regiao_nome": None, "revenue": 5_000.0, "invoices": 1, "customers": 0}
            for c in ("2026-01", "2026-02", "2026-03", "2026-04")
            for j in range(10)
        ]
        scores = compute_from_rows(rows, 1, date(2026, 4, 30))

        self.assertTrue(scores, "esperava scores mesmo sem ticket comparável")
        self.assertTrue(all(s.average_ticket == 0.0 for s in scores))


class TestReferenciaPrecisaTerFuturo(unittest.TestCase):
    def test_competencia_sem_mes_seguinte_nao_vira_referencia(self) -> None:
        """Avaliar o mês corrente contra um mês que não terminou inventa queda."""
        meses = ["2026-01", "2026-02", "2026-03", "2026-04"]
        serie = _serie_crescente(12, meses)
        comps = sorted(serie)
        referencias = [c for c in comps if gb._proxima_competencia(c, 1) in comps]
        self.assertNotIn("2026-04", referencias)
        self.assertEqual(referencias, ["2026-01", "2026-02", "2026-03"])


if __name__ == "__main__":
    unittest.main()
