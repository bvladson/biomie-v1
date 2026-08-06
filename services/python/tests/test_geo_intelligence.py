import unittest
from datetime import date

from biomie_intel.geo_intelligence import compute_from_rows, normalize_key


class TestGeoIntelligence(unittest.TestCase):
    def test_normaliza_chave_sem_perder_identidade(self):
        self.assertEqual("aldeota", normalize_key(" Aldeóta "))

    def test_score_tem_previsao_e_portao_de_confianca(self):
        rows = []
        for month, revenue in zip(("2026-03", "2026-04", "2026-05", "2026-06", "2026-07", "2026-08"),
                                  (10000, 11000, 12000, 13000, 14000, 7500)):
            rows.append({"competencia": month, "cidade_norm": "fortaleza", "bairro_norm": "aldeota",
                         "revenue": revenue, "customers": 10, "invoices": 12})
        result = compute_from_rows(rows, 7, date(2026, 8, 15))
        bairro = next(row for row in result if row.layer_id == "fortaleza_bairros")
        self.assertIsNotNone(bairro.forecast_revenue)
        self.assertLessEqual(bairro.forecast_low, bairro.forecast_revenue)
        self.assertGreaterEqual(bairro.forecast_high, bairro.forecast_revenue)
        self.assertIn(bairro.confidence, {"alta", "media", "baixa"})

    def test_serie_curta_fica_neutra(self):
        result = compute_from_rows([{"competencia": "2026-08", "cidade_norm": "fortaleza",
                                     "bairro_norm": "centro", "revenue": 1000,
                                     "customers": 2, "invoices": 2}], 1, date(2026, 8, 5))
        self.assertTrue(all(row.confidence == "baixa" for row in result))
        self.assertTrue(all("insuficiente" in row.main_reason.lower() for row in result))
