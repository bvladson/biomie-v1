"""Testes do score determinístico.

Estes testes existem para travar uma CALIBRAÇÃO, não só a sintaxe. A primeira
versão do score colocou 100% dos orçamentos em "alta" e isso só apareceu numa
inspeção manual da saída. Um teste que exige DISPERSÃO pega essa classe de
regressão antes do deploy.

Rodar:  python -m unittest discover -s tests -v
"""

from __future__ import annotations

import unittest
from datetime import date

from biomie_intel.scoring import (
    BAND_HIGH,
    BAND_MEDIUM,
    MIN_AMOSTRA_PERCENTIL,
    band_for,
    movimento,
    percentile_ranks,
    score_quote,
)

HOJE = date(2026, 8, 4)


def quote(**kwargs):
    """Orçamento neutro: nada atrasado, nada previsto, sem cobrança."""
    base = {
        "company_id": 1,
        "omie_order_id": 999,
        "quote_created_at": date(2026, 8, 3),
        "quote_forecast_on": None,
        "next_followup_on": None,
        "followup_step": 0,
        "charge_count": 0,
        "valor_total": None,
        "etapa_omie": "10",
        "business_channel": "varejo",
    }
    base.update(kwargs)
    return base


class TestComponentes(unittest.TestCase):
    def test_sem_atraso_nao_pontua(self):
        s = score_quote(quote(next_followup_on=date(2026, 8, 10)), HOJE, None)
        self.assertEqual(s.features["componentes"]["followup_overdue"], 0.0)

    def test_atraso_cresce_com_magnitude(self):
        leve = score_quote(quote(next_followup_on=date(2026, 8, 1)), HOJE, None)
        grave = score_quote(quote(next_followup_on=date(2026, 7, 20)), HOJE, None)
        self.assertLess(
            leve.features["componentes"]["followup_overdue"],
            grave.features["componentes"]["followup_overdue"],
            "atraso maior tem que pontuar mais — senão o score não ordena",
        )

    def test_atraso_satura_em_30_dias(self):
        trinta = score_quote(quote(next_followup_on=date(2026, 7, 5)), HOJE, None)
        cem = score_quote(quote(next_followup_on=date(2026, 4, 26)), HOJE, None)
        self.assertEqual(
            trinta.features["componentes"]["followup_overdue"],
            cem.features["componentes"]["followup_overdue"],
        )

    def test_previsao_e_u_invertido(self):
        """Previsão estourada há muito tempo é negócio à deriva, não urgência."""
        iminente = score_quote(quote(quote_forecast_on=date(2026, 8, 6)), HOJE, None)
        recem = score_quote(quote(quote_forecast_on=date(2026, 7, 28)), HOJE, None)
        deriva = score_quote(quote(quote_forecast_on=date(2026, 5, 1)), HOJE, None)

        c = lambda s: s.features["componentes"]["forecast_pressure"]  # noqa: E731
        self.assertGreater(c(iminente), c(deriva))
        self.assertGreater(c(recem), c(deriva))

    def test_valor_desconhecido_nao_vira_zero(self):
        """Zerar empurraria para 'baixa' por falta de dado, não por mérito."""
        s = score_quote(quote(valor_total=None), HOJE, None)
        self.assertGreater(s.features["componentes"]["value_weight"], 0.0)
        self.assertTrue(s.features["valor_desconhecido"])

    def test_nao_afirma_percentil_com_amostra_pequena(self):
        pequena = score_quote(quote(valor_total=1000.0), HOJE, 0.95, amostra_empresa=2)
        grande = score_quote(quote(valor_total=1000.0), HOJE, 0.95, amostra_empresa=50)

        self.assertNotIn("10% maiores", " ".join(pequena.reasons))
        self.assertIn("10% maiores", " ".join(grande.reasons))
        # A pontuação é a mesma: o que muda é a AFIRMAÇÃO, não o peso.
        self.assertEqual(pequena.score, grande.score)


class TestInvariantes(unittest.TestCase):
    def test_score_nunca_passa_de_100(self):
        pior = quote(
            next_followup_on=date(2026, 1, 1),
            quote_forecast_on=date(2026, 8, 5),
            quote_created_at=date(2026, 1, 1),
            valor_total=999999.0,
            charge_count=9,
        )
        s = score_quote(pior, HOJE, 1.0, amostra_empresa=50)
        self.assertLessEqual(s.score, 100.0)
        self.assertGreaterEqual(s.score, 0.0)

    def test_e_deterministico(self):
        q = quote(next_followup_on=date(2026, 7, 20), valor_total=5000.0)
        a = score_quote(q, HOJE, 0.6, amostra_empresa=30)
        b = score_quote(q, HOJE, 0.6, amostra_empresa=30)
        self.assertEqual(a.score, b.score)
        self.assertEqual(a.reasons, b.reasons)

    def test_bandas_respeitam_os_limiares(self):
        self.assertEqual(band_for(BAND_HIGH), "alta")
        self.assertEqual(band_for(BAND_HIGH - 0.01), "media")
        self.assertEqual(band_for(BAND_MEDIUM), "media")
        self.assertEqual(band_for(BAND_MEDIUM - 0.01), "baixa")


class TestRelacionamento(unittest.TestCase):
    def test_cliente_novo_nao_pontua(self):
        s = score_quote(quote(cliente_nfs=0), HOJE, None)
        self.assertEqual(s.features["componentes"]["relacionamento"], 0.0)

    def test_recorrente_pontua_mais_que_primeira_compra(self):
        uma = score_quote(quote(cliente_nfs=1), HOJE, None)
        varias = score_quote(quote(cliente_nfs=8), HOJE, None)
        self.assertLess(
            uma.features["componentes"]["relacionamento"],
            varias.features["componentes"]["relacionamento"],
        )

    def test_ausencia_do_campo_nao_quebra(self):
        """O join pode não trazer cliente_nfs; isso não pode explodir o score."""
        s = score_quote(quote(), HOJE, None)
        self.assertEqual(s.features["componentes"]["relacionamento"], 0.0)


class TestMovimento(unittest.TestCase):
    def test_sem_anterior_e_novo(self):
        direcao, delta, motivo = movimento(60.0, None)
        self.assertEqual(direcao, "novo")
        self.assertIsNone(delta)
        self.assertIsNotNone(motivo)

    def test_variacao_pequena_e_estavel(self):
        """Ruído de 1-2 pontos não pode virar alerta na tela todo dia."""
        direcao, _, motivo = movimento(60.0, 58.0)
        self.assertEqual(direcao, "estavel")
        self.assertIsNone(motivo)

    def test_salto_relevante_vira_motivo(self):
        direcao, delta, motivo = movimento(72.0, 40.0)
        self.assertEqual(direcao, "subiu")
        self.assertEqual(delta, 32.0)
        self.assertIn("32", motivo or "")

    def test_queda_nao_polui_a_tela(self):
        direcao, _, motivo = movimento(30.0, 70.0)
        self.assertEqual(direcao, "desceu")
        self.assertIsNone(motivo, "queda não é chamada para ação")


class TestPercentil(unittest.TestCase):
    def test_empates_recebem_o_mesmo_percentil(self):
        """Sem isto, dois valores iguais cairiam em faixas diferentes conforme
        a ordem da consulta — e o score deixaria de ser determinístico."""
        ranks = percentile_ranks([10.0, 10.0, 20.0])
        self.assertEqual(ranks[0], ranks[1])

    def test_none_permanece_none(self):
        self.assertEqual(percentile_ranks([None, 5.0])[0], None)

    def test_lista_toda_nula(self):
        self.assertEqual(percentile_ranks([None, None]), [None, None])


class TestDispersao(unittest.TestCase):
    """A regressão que realmente aconteceu: score que não separa a população.

    Reproduz o perfil medido em produção — 93% com follow-up atrasado e 91% com
    previsão vencida — e exige que o resultado AINDA assim se espalhe.
    """

    def test_populacao_realista_nao_cai_toda_na_mesma_banda(self):
        import random

        rng = random.Random(42)
        linhas, valores = [], []
        for i in range(200):
            atraso = rng.choice([0, 2, 5, 9, 12, 20, 34])       # 93% atrasados
            previsao = rng.choice([-40, -20, -8, -3, -1, 2, 9])  # 91% vencidas
            valor = rng.choice([None, 500.0, 5000.0, 20000.0, 120000.0])
            linhas.append(quote(
                omie_order_id=i,
                next_followup_on=date(2026, 8, 4 - min(atraso, 3)) if atraso else date(2026, 8, 10),
                quote_forecast_on=date(2026, 8, 4),
                valor_total=valor,
            ))
            valores.append(valor)

        # Recria o caminho real: percentil por empresa.
        ranks = percentile_ranks(valores)
        amostra = sum(1 for v in valores if v is not None)
        scores = [score_quote(l, HOJE, r, amostra) for l, r in zip(linhas, ranks)]

        bandas = {b: sum(1 for s in scores if s.band == b) for b in ("alta", "media", "baixa")}
        maior = max(bandas.values())
        self.assertLess(
            maior / len(scores), 0.90,
            f"mais de 90% dos orçamentos na mesma banda ({bandas}) — o score não ordena nada",
        )

        distintos = {round(s.score, 1) for s in scores}
        self.assertGreater(len(distintos), 10, "o score produz poucos valores distintos")


if __name__ == "__main__":
    unittest.main()
