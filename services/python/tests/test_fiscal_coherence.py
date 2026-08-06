from __future__ import annotations

import unittest
from datetime import datetime, timezone

from biomie_intel import fiscal_coherence
from fakes import FakeConnection


class TestFiscalCoherence(unittest.TestCase):
    def test_cobertura_baixa_e_critica_e_nao_passa_de_cem(self):
        conn = FakeConnection({
            "FROM biomie_notas_fiscais_canonicas": [{"competencia": "2026-07", "invoices": 100, "revenue": 1000}],
            "COUNT(DISTINCT nota_key) invoices, COUNT(*) items": [{"competencia": "2026-07", "invoices": 70, "items": 90, "revenue": 700,
              "built_at": datetime.now(timezone.utc).replace(tzinfo=None)}],
        })
        rows, anomalies = fiscal_coherence.compute(conn, 1)
        self.assertEqual(rows[0]["coverage_pct"], 70)
        self.assertEqual(rows[0]["status"], "critical")
        self.assertEqual(rows[0]["missing_revenue"], 300)
        self.assertEqual(anomalies, [])

    def test_anomalia_divergente_e_apenas_evidencia(self):
        conn = FakeConnection({
            "HAVING COUNT(*)>=2": [{"competencia":"2026-07", "ncm":"9403", "affected_rows":4,
              "min_rate":7, "max_rate":18, "affected_value":500}],
        })
        _, anomalies = fiscal_coherence.compute(conn, 1)
        self.assertEqual(anomalies[0]["type"], "ncm_icms_divergent")
        self.assertIn("revise", anomalies[0]["detail"].lower())

    def test_mesmas_notas_mas_valor_divergente_nao_e_ok(self):
        conn = FakeConnection({
            "FROM biomie_notas_fiscais_canonicas": [{"competencia":"2026-02", "invoices":10, "revenue":1000}],
            "COUNT(DISTINCT nota_key) invoices, COUNT(*) items": [{"competencia":"2026-02", "invoices":10,
              "items":20, "revenue":800, "built_at":datetime.now(timezone.utc).replace(tzinfo=None)}],
        })
        rows, _ = fiscal_coherence.compute(conn, 1)
        self.assertEqual(rows[0]["coverage_pct"], 100)
        self.assertEqual(rows[0]["status"], "warning")
        self.assertIn("valor_mart_diverge_da_fonte", rows[0]["reasons"])


if __name__ == "__main__":
    unittest.main()
