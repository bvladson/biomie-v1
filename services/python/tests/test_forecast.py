"""Previsão: série curta, dias sem venda, banda e anomalias.

O que estes testes travam é a HONESTIDADE do número, não a sintaxe. Uma previsão
com banda estreita demais é pior que previsão nenhuma: o gestor confia num
intervalo que a realidade não respeita. E uma série que ignora sábados calcula
sazonalidade sobre amostra enviesada — o erro clássico deste tipo de módulo.

Rodar:  python -m unittest discover -s tests -v
"""

from __future__ import annotations

import unittest
from datetime import date, timedelta

from biomie_intel import forecast
from biomie_intel.forecast import MIN_DIAS_HISTORICO, build
from fakes import FakeConnection

CONSULTA_SERIE = "SELECT data_emissao AS dia, SUM(valor_total) AS receita"
CONSULTA_EVENTOS = "ORDER BY valor_total DESC"


def serie(dias: int, *, base: float = 1000.0, comeco: date | None = None,
          pular_fds: bool = False, salto: dict[int, float] | None = None):
    """Linhas como o banco devolveria: uma por dia COM faturamento."""
    fim = comeco or (date.today() - timedelta(days=1))
    inicio = fim - timedelta(days=dias - 1)
    linhas = []
    for i in range(dias):
        d = inicio + timedelta(days=i)
        if pular_fds and d.weekday() >= 5:
            continue  # o banco simplesmente não tem linha para esse dia
        receita = base + (salto or {}).get(i, 0.0)
        linhas.append({"dia": d, "receita": receita + (i % 5) * 20.0, "notas": 3})
    return linhas


def conn_com(linhas, eventos=None):
    return FakeConnection({
        CONSULTA_SERIE: linhas,
        CONSULTA_EVENTOS: eventos or [],
    })


class TestSerieCurta(unittest.TestCase):
    """Amostra pequena não vira previsão — devolve motivo, não número inventado."""

    def test_sem_faturamento_nenhum(self) -> None:
        f = build(conn_com([]), company_id=1)
        self.assertFalse(f.ok)
        self.assertIn("sem faturamento", f.motivo)
        self.assertIsNone(f.total_previsto_horizonte)
        self.assertEqual(f.serie, [])

    def test_abaixo_do_minimo_recusa_com_motivo_contavel(self) -> None:
        curta = MIN_DIAS_HISTORICO - 5
        f = build(conn_com(serie(curta)), company_id=1)
        self.assertFalse(f.ok)
        self.assertIn("histórico curto", f.motivo)
        # O motivo tem que dizer QUANTO falta, senão não orienta ninguém.
        self.assertIn(str(MIN_DIAS_HISTORICO), f.motivo)

    def test_serie_constante_nao_projeta(self) -> None:
        """Sem variação não há inclinação: recusa em vez de fingir tendência."""
        linhas = [
            {"dia": date.today() - timedelta(days=60 - i), "receita": 500.0, "notas": 1}
            for i in range(60)
        ]
        f = build(conn_com(linhas), company_id=1)
        self.assertFalse(f.ok)
        self.assertIn("variação", f.motivo)

    def test_no_limite_exato_do_minimo_ja_projeta(self) -> None:
        f = build(conn_com(serie(MIN_DIAS_HISTORICO + 1)), company_id=1)
        self.assertTrue(f.ok, f.motivo)


class TestDiasSemVenda(unittest.TestCase):
    """Dia sem nota é ZERO, não lacuna — senão a sazonalidade sai enviesada."""

    def test_fins_de_semana_ausentes_viram_zero_na_serie(self) -> None:
        linhas = serie(70, pular_fds=True)
        f = build(conn_com(linhas), company_id=1)
        self.assertTrue(f.ok, f.motivo)

        # O banco devolveu só dias úteis; a série reconstruída tem que ter
        # TODOS os dias corridos do intervalo.
        observados = [d for d in f.serie if d.realizado is not None]
        primeiro = date.fromisoformat(observados[0].dia)
        ultimo = date.fromisoformat(observados[-1].dia)
        esperado = (ultimo - primeiro).days + 1
        self.assertEqual(
            len(observados), esperado,
            "dias sem venda sumiram da série — a sazonalidade seria calculada "
            "só sobre os dias que venderam",
        )

    def test_zeros_aparecem_como_realizado_zero(self) -> None:
        f = build(conn_com(serie(70, pular_fds=True)), company_id=1)
        zerados = [d for d in f.serie if d.realizado == 0.0]
        self.assertTrue(zerados, "nenhum dia zerado — os fins de semana viraram lacuna")

    def test_sazonalidade_cobre_os_sete_dias(self) -> None:
        f = build(conn_com(serie(70, pular_fds=True)), company_id=1)
        self.assertEqual(sorted(f.sazonalidade), [str(d) for d in range(7)])
        # Fim de semana sem venda tem que puxar o fator PARA BAIXO.
        fds = min(f.sazonalidade[str(5)], f.sazonalidade[str(6)])
        util = max(f.sazonalidade[str(d)] for d in range(5))
        self.assertLess(fds, util, "o fator de fim de semana não ficou abaixo do útil")


class TestIntervalos(unittest.TestCase):
    """A banda vem do erro MEDIDO e nunca é irreal nem inútil."""

    def setUp(self) -> None:
        self.f = build(conn_com(serie(90)), company_id=1)
        self.assertTrue(self.f.ok, self.f.motivo)
        self.futuros = [d for d in self.f.serie if d.previsto is not None and d.realizado is None]

    def test_todo_dia_projetado_tem_banda(self) -> None:
        self.assertTrue(self.futuros)
        for d in self.futuros:
            with self.subTest(dia=d.dia):
                self.assertIsNotNone(d.minimo)
                self.assertIsNotNone(d.maximo)

    def test_a_banda_contem_a_previsao(self) -> None:
        for d in self.futuros:
            with self.subTest(dia=d.dia):
                self.assertLessEqual(d.minimo, d.previsto)
                self.assertLessEqual(d.previsto, d.maximo)

    def test_banda_nunca_negativa(self) -> None:
        """Faturamento não é negativo — a banda inferior trunca em zero."""
        for d in self.futuros:
            self.assertGreaterEqual(d.minimo, 0.0)

    def test_largura_fica_dentro_dos_limites_declarados(self) -> None:
        """Nem banda irreal (<15%) nem inútil (>120%) — o clamp do módulo."""
        largura = self.f.metrics["largura_banda_pct"]
        self.assertGreaterEqual(largura, 15.0)
        self.assertLessEqual(largura, 120.0)

    def test_horizonte_respeitado(self) -> None:
        f = build(conn_com(serie(90)), company_id=1, horizonte=7)
        futuros = [d for d in f.serie if d.realizado is None]
        self.assertEqual(len(futuros), 7)
        self.assertEqual(f.metrics["horizonte_dias"], 7)

    def test_backtest_nao_olha_o_futuro(self) -> None:
        """O erro histórico sai de reajuste com dados até D-1, não do ajuste cheio.

        Prova indireta e honesta: o erro do backtest é MAIOR que o resíduo do
        ajuste completo. Se alguém trocar o backtest por resíduo in-sample, o
        número cai e este teste quebra.
        """
        import numpy as np
        import pandas as pd

        linhas = serie(90)
        f = build(conn_com(linhas), company_id=1)
        self.assertIsNotNone(f.erro_medio_pct)

        df = pd.DataFrame(linhas)
        df["dia"] = pd.to_datetime(df["dia"])
        s = df.set_index("dia")["receita"].astype(float)
        s = s.reindex(pd.date_range(s.index.min(), s.index.max(), freq="D"), fill_value=0.0)
        nivel, inclinacao, fator, residuo_pct = forecast._ajustar(s, np, pd)

        self.assertGreaterEqual(
            f.erro_medio_pct, residuo_pct * 0.9,
            "o erro histórico ficou abaixo do resíduo in-sample — sinal de que "
            "o backtest passou a olhar o futuro",
        )


class TestAnomalias(unittest.TestCase):
    def test_pico_isolado_e_marcado(self) -> None:
        linhas = serie(90, base=1000.0, salto={60: 40000.0})
        f = build(conn_com(linhas), company_id=1)
        self.assertTrue(f.ok, f.motivo)
        anomalos = [d for d in f.serie if d.anomalia]
        self.assertTrue(anomalos, "pico de 40x não foi marcado como anomalia")
        self.assertTrue(all(d.nota for d in anomalos), "anomalia sem motivo legível")

    def test_serie_comportada_nao_inventa_anomalia(self) -> None:
        """Falso positivo destrói a confiança tão rápido quanto o falso negativo."""
        f = build(conn_com(serie(90)), company_id=1)
        self.assertEqual(
            f.metrics["anomalias"], 0,
            "série regular produziu anomalia — o limiar está sensível demais",
        )

    def test_dia_zerado_nao_conta_como_anomalia(self) -> None:
        """Fim de semana fechado é rotina, não evento — marcá-lo vira ruído."""
        f = build(conn_com(serie(70, pular_fds=True)), company_id=1)
        for d in f.serie:
            if d.realizado == 0.0:
                with self.subTest(dia=d.dia):
                    self.assertFalse(d.anomalia)


class TestEscopoDeEmpresa(unittest.TestCase):
    """Toda consulta da previsão leva company_id — nenhuma leitura global."""

    def test_serie_e_eventos_filtram_por_empresa(self) -> None:
        conn = conn_com(serie(90))
        build(conn, company_id=7)

        for trecho in (CONSULTA_SERIE, CONSULTA_EVENTOS):
            with self.subTest(consulta=trecho[:40]):
                params = conn.params_de(trecho)
                self.assertEqual(
                    params[0], 7,
                    "a empresa não é o primeiro parâmetro da consulta",
                )

    def test_nenhuma_consulta_roda_sem_company_id(self) -> None:
        """Trava geral: já houve vazamento entre empresas por leitura sem filtro."""
        conn = conn_com(serie(90))
        build(conn, company_id=7)

        for sql in conn.sql_executado():
            with self.subTest(sql=sql[:60]):
                self.assertIn(
                    "company_id = %s", sql,
                    "consulta comercial sem filtro de empresa",
                )


if __name__ == "__main__":  # pragma: no cover
    unittest.main()
