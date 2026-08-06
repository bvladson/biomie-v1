from __future__ import annotations

import unittest
import json
from pathlib import Path

from biomie_intel.target_ladder import evaluate, recommend


class TestTargetLadder(unittest.TestCase):
    def test_contrato_compartilhado_com_php(self):
        path = Path(__file__).resolve().parents[3] / "config" / "target-ladder-contract.json"
        contract = json.loads(path.read_text(encoding="utf-8"))
        for case in contract["cases"]:
            with self.subTest(case=case["name"]):
                result = evaluate(case["levels"], case["realized"])
                for field, expected in case.items():
                    if field not in {"name", "levels", "realized"}:
                        self.assertEqual(result[field], expected)

    def test_promove_para_segunda_meta_sem_apagar_primeira(self):
        result = evaluate([40000, 60000, 80000], 47000)
        self.assertEqual(result["achieved_level"], 1)
        self.assertEqual(result["active_level"], 2)
        self.assertEqual(result["active_target"], 60000)
        self.assertEqual(result["active_gap"], 13000)
        self.assertEqual(result["step_progress_pct"], 35)

    def test_nao_inventa_nivel_depois_do_maximo(self):
        result = evaluate([40000, 60000, 80000], 86000)
        self.assertTrue(result["all_levels_achieved"])
        self.assertIsNone(result["active_target"])
        self.assertEqual(result["over_maximum_amount"], 6000)

    def test_valores_repetidos_e_fora_de_ordem_sao_normalizados(self):
        result = evaluate([80000, 40000, 60000, 60000], 41000)
        self.assertEqual(result["levels"], [40000.0, 60000.0, 80000.0])
        self.assertEqual(result["active_level"], 2)

    def test_recomenda_ritmo_para_o_degrau_ativo(self):
        advice = recommend([40000, 80000, 130000], 50000, 76000, 10, 0.20)
        self.assertEqual(advice["required_daily"], 3000)
        self.assertEqual(advice["projected_level"], 1)
        self.assertIsNone(advice["recommended_next_target"])
        self.assertEqual(advice["confidence"], "alta")

    def test_superacao_gera_proposta_sem_inventar_meta_oficial(self):
        advice = recommend([40000, 80000, 130000, 200000], 220000, 230000, 8, 0.30)
        self.assertEqual(advice["projected_level"], 4)
        self.assertEqual(advice["recommended_next_target"], 250000)
        self.assertEqual(advice["confidence"], "media")


if __name__ == "__main__":
    unittest.main()
