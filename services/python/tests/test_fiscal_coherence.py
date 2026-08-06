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
        self.assertEqual(rows[0]["revenue_delta"], 300)
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


class TestDivergenciaTemDirecao(unittest.TestCase):
    """`missing_revenue` só sabia dizer "falta" — metade do problema sumia.

    O campo era `max(0.0, canonical - mart)`. Quando o mart ficava MAIOR que a
    fonte o valor virava 0,00, e como a detecção usa `abs()` no limiar de 1%, a
    linha era marcada `warning` com motivo "valor_mart_diverge_da_fonte" e o
    número ao lado dizendo zero. Aconteceu em quatro dos cinco warnings da
    MAG_FILIAL em produção.
    """

    @staticmethod
    def _conn(canonical: float, mart: float) -> FakeConnection:
        agora = datetime.now(timezone.utc).replace(tzinfo=None)
        return FakeConnection({
            "FROM biomie_notas_fiscais_canonicas": [
                {"competencia": "2026-05", "invoices": 10, "revenue": canonical}],
            "COUNT(DISTINCT nota_key) invoices, COUNT(*) items": [
                {"competencia": "2026-05", "invoices": 10, "items": 20,
                 "revenue": mart, "built_at": agora}],
        })

    def test_mart_maior_que_a_fonte_grava_delta_NEGATIVO(self):
        rows, _ = fiscal_coherence.compute(self._conn(598_679.69, 610_311.29), 1)
        self.assertLess(rows[0]["revenue_delta"], 0,
                        "mart maior que a fonte precisa gravar negativo, não zero")

    def test_warning_nunca_vem_acompanhado_de_diferenca_zero(self):
        """O invariante da tela: se o motivo diz que diverge, o número diz quanto."""
        for canonical, mart in [(598_679.69, 610_311.29), (996_461.62, 955_265.74)]:
            with self.subTest(canonical=canonical, mart=mart):
                rows, _ = fiscal_coherence.compute(self._conn(canonical, mart), 1)
                if "valor_mart_diverge_da_fonte" in rows[0]["reasons"]:
                    self.assertNotEqual(rows[0]["revenue_delta"], 0.0)

    def test_o_campo_antigo_nao_volta(self):
        rows, _ = fiscal_coherence.compute(self._conn(1000.0, 900.0), 1)
        self.assertNotIn("missing_revenue", rows[0])


class TestComposicaoComparavel(unittest.TestCase):
    """O mart precisa ser somado como o documento é somado.

    `valor_total_item` é `valor_produto + valor_ipi`, SEM subtrair desconto —
    conferido em produção: MAG_FILIAL 2026-05 deu 591.229,82 + 19.081,47 =
    610.311,29, exato. Comparar isso com o total da NF criava divergência
    estrutural em seis das oito competências e afogava fevereiro, a única
    anomalia real (R$ 41.195,88).
    """

    def test_a_consulta_do_mart_subtrai_desconto_e_soma_ipi(self):
        import re
        from pathlib import Path
        fonte = Path(fiscal_coherence.__file__).read_text(encoding="utf-8")
        sql = re.search(r"mart = fetch_all\(conn, \"\"\"(.*?)\"\"\"", fonte, re.S)
        self.assertIsNotNone(sql, "não achei a consulta do mart")
        self.assertIn("valor_produto - valor_desconto + valor_ipi", sql.group(1))
        self.assertNotRegex(
            sql.group(1), r"SUM\(valor_total_item\)\s*,?\s*0?\)?\s+revenue\b",
            "voltou a comparar valor_total_item com o total do documento",
        )


class TestProjecaoCobreTodasAsCompetencias(unittest.TestCase):
    """A projeção varria só o mês corrente e anulava o próprio propósito.

    A EXPERT é Simples Nacional, faturou R$ 1,65 mi de janeiro a julho e em
    agosto ainda não tinha emitido nota: a projeção gravava R$ 0 e os R$ 154 mil
    de DAS seguiam invisíveis — apresentados como zero, que é a confusão entre
    ausência e valor nulo que esta base inteira combate.
    """

    def setUp(self):
        from pathlib import Path
        self.script = Path(__file__).resolve().parents[3] / "bin" / "fiscal_persist_projection.php"
        if not self.script.is_file():
            self.skipTest("script de projeção não encontrado")
        self.fonte = self.script.read_text(encoding="utf-8")

    def test_nao_fixa_a_competencia_no_mes_corrente(self):
        import re
        self.assertIsNone(
            re.search(r"\$competencia\s*=\s*date\('Y-m'\)\s*;", self.fonte),
            "voltou a projetar só o mês corrente",
        )

    def test_descobre_as_competencias_pela_fonte_canonica(self):
        self.assertIn("FROM biomie_notas_fiscais_canonicas", self.fonte)

    def test_usa_a_mesma_janela_da_auditoria(self):
        """Períodos diferentes fazem uma das duas mentir por omissão."""
        import re
        from pathlib import Path
        auditoria = Path(fiscal_coherence.__file__).read_text(encoding="utf-8")
        janela_auditoria = re.search(r"INTERVAL (\d+) MONTH", auditoria)
        janela_projecao = re.search(r"JANELA_MESES_PADRAO\s*=\s*(\d+)", self.fonte)
        self.assertIsNotNone(janela_auditoria)
        self.assertIsNotNone(janela_projecao)
        self.assertEqual(janela_auditoria.group(1), janela_projecao.group(1))

    def test_mes_sem_movimento_e_pulado_e_nao_zerado(self):
        """Gravar R$ 0 afirma "não houve imposto"; pular diz "nada a apurar"."""
        self.assertIn("competencias_sem_movimento", self.fonte)


if __name__ == "__main__":
    unittest.main()
