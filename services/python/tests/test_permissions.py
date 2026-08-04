"""Permissões do usuário restrito de inteligência.

O BUG QUE ISTO IMPEDE DE VOLTAR (2026-08-04): `intelligence_insights` era
gravada pelo cron mas NÃO aparecia nem em `WRITABLE_TABLES` nem nos GRANTs de
`provision_intelligence_db_user.sh`. Funcionava só porque alguém concedeu a
permissão à mão no banco. Como o script começa com `REVOKE ALL PRIVILEGES`, a
próxima execução dele teria removido a concessão e derrubado o job de insights —
sem ninguém tocar em código e sem alerta nenhum.

São dois níveis, de propósito:

  · ESTÁTICO — as duas declarações da mesma verdade (a tupla em config.py e os
    GRANTs no script) têm que concordar. Roda sempre, em qualquer máquina.
  · INTEGRAÇÃO — cria o usuário restrito de verdade num schema descartável e
    prova, contra o servidor, que ele escreve nas três tabelas e é RECUSADO
    fora delas. Pula com aviso alto quando não há MariaDB configurado.

O nível estático sozinho não bastaria: ele prova que as listas concordam, não
que o GRANT resultante funciona. O de integração sozinho também não: ele não
roda em toda máquina. Juntos, cobrem os dois modos de falha.

Rodar:  python -m unittest discover -s tests -v
"""

from __future__ import annotations

import re
import unittest
from pathlib import Path

from biomie_intel.config import WRITABLE_TABLES

SCRIPT = (
    Path(__file__).resolve().parents[3]
    / "tools" / "deploy" / "vps" / "provision_intelligence_db_user.sh"
)

#: tabelas que o serviço só pode LER — escrever nelas é bug
SOMENTE_LEITURA = (
    "biomie_notas_fiscais_canonicas",
    "biomie_pedidos_canonicos",
    "biomie_sales_quote_followup_schedule",
    "intelligence_quote_feedback",
)


def _grants() -> dict[str, set[str]]:
    """Privilégios por tabela, lidos do script de provisionamento."""
    texto = SCRIPT.read_text(encoding="utf-8")
    achados: dict[str, set[str]] = {}
    padrao = re.compile(
        r"^\s*GRANT\s+(?P<privs>[A-Z, ]+?)(?:\s*\([^)]*\))?\s+ON\s+\$\{DB\}\.(?P<tabela>\w+)",
        re.MULTILINE,
    )
    for m in padrao.finditer(texto):
        privs = {p.strip() for p in m.group("privs").split(",") if p.strip()}
        achados.setdefault(m.group("tabela"), set()).update(privs)
    return achados


class TestDeclaracoesConcordam(unittest.TestCase):
    """O script de provisionamento e config.py descrevem a mesma coisa."""

    def setUp(self) -> None:
        if not SCRIPT.is_file():  # pragma: no cover
            self.skipTest(f"NAO VERIFICADO: script ausente em {SCRIPT}")
        self.grants = _grants()

    def test_script_foi_lido(self) -> None:
        # Se o parser deixar de casar (o script mudou de forma), todos os outros
        # testes desta classe passariam vazios. Esta é a trava contra isso.
        self.assertGreaterEqual(
            len(self.grants), 6,
            f"o parser de GRANT casou pouca coisa ({sorted(self.grants)}) — "
            f"o formato do script mudou e este teste ficaria cego",
        )

    def test_toda_tabela_gravavel_tem_grant_de_escrita(self) -> None:
        for tabela in WRITABLE_TABLES:
            with self.subTest(tabela=tabela):
                privs = self.grants.get(tabela, set())
                self.assertTrue(
                    privs, f"{tabela} está em WRITABLE_TABLES mas NÃO tem GRANT no script — "
                           f"o próximo REVOKE ALL derruba o job que escreve nela"
                )
                for exigido in ("INSERT", "UPDATE"):
                    self.assertIn(
                        exigido, privs,
                        f"{tabela}: falta {exigido} no script (tem {sorted(privs)})",
                    )

    def test_nenhuma_tabela_ganha_escrita_sem_estar_declarada(self) -> None:
        """O inverso: GRANT de escrita em tabela fora de WRITABLE_TABLES."""
        for tabela, privs in self.grants.items():
            if privs & {"INSERT", "UPDATE", "DELETE"}:
                with self.subTest(tabela=tabela):
                    self.assertIn(
                        tabela, WRITABLE_TABLES,
                        f"{tabela} recebe escrita no script mas não está em "
                        f"WRITABLE_TABLES — privilégio sem dono declarado",
                    )

    def test_tabelas_de_leitura_nao_recebem_escrita(self) -> None:
        for tabela in SOMENTE_LEITURA:
            with self.subTest(tabela=tabela):
                privs = self.grants.get(tabela, set())
                self.assertFalse(
                    privs & {"INSERT", "UPDATE", "DELETE"},
                    f"{tabela} deveria ser somente leitura, mas tem {sorted(privs)}",
                )

    def test_delete_e_excecao_e_so_para_insights(self) -> None:
        """DELETE existe só onde há expurgo por validade — não é permissão ampla."""
        com_delete = {t for t, p in self.grants.items() if "DELETE" in p}
        self.assertEqual(
            com_delete, {"intelligence_insights"},
            f"DELETE deveria existir só em intelligence_insights (expurgo por "
            f"validade), mas está em {sorted(com_delete)}",
        )

    def test_credenciais_da_empresa_nao_sao_legiveis(self) -> None:
        """biomie_companies é concedida por COLUNA — nunca a tabela inteira."""
        texto = SCRIPT.read_text(encoding="utf-8")
        m = re.search(r"GRANT SELECT \(([^)]*)\)\s*ON \$\{DB\}\.biomie_companies", texto)
        self.assertIsNotNone(m, "biomie_companies deve ter GRANT por coluna")
        colunas = {c.strip() for c in m.group(1).split(",")}  # type: ignore[union-attr]
        for segredo in ("omie_app_key", "omie_app_secret"):
            self.assertNotIn(
                segredo, colunas,
                f"{segredo} não pode ser legível por um job de análise",
            )


class TestPermissoesReais(unittest.TestCase):
    """Contra o servidor: o usuário restrito escreve onde deve e é barrado fora."""

    def setUp(self) -> None:
        from dbfixture import DisposableSchema

        self.schema = DisposableSchema()
        self.schema.__enter__()
        self.addCleanup(self.schema.__exit__, None, None, None)

        self.host = self.schema.user_host()
        self.user = "intel_test_" + self.schema.name[-6:]
        self.password = "s3nha-de-teste-" + self.schema.name[-4:]
        db = self.schema.name

        self.schema.exec_admin(
            f"CREATE USER '{self.user}'@'{self.host}' IDENTIFIED BY '{self.password}'"
        )
        self.addCleanup(self._drop_user)

        # Espelha EXATAMENTE a intenção do script de provisionamento.
        for tabela in WRITABLE_TABLES:
            extra = ", DELETE" if tabela == "intelligence_insights" else ""
            self.schema.exec_admin(
                f"GRANT SELECT, INSERT, UPDATE{extra} ON `{db}`.`{tabela}` TO '{self.user}'@'{self.host}'"
            )
        for tabela in SOMENTE_LEITURA:
            self.schema.exec_admin(
                f"GRANT SELECT ON `{db}`.`{tabela}` TO '{self.user}'@'{self.host}'"
            )
        self.schema.exec_admin(
            f"GRANT SELECT (id, slug, nome_fantasia, razao_social, ativo) "
            f"ON `{db}`.`biomie_companies` TO '{self.user}'@'{self.host}'"
        )
        self.schema.exec_admin("FLUSH PRIVILEGES")

    def _drop_user(self) -> None:
        try:
            self.schema.exec_admin(f"DROP USER IF EXISTS '{self.user}'@'{self.host}'")
        except Exception:  # pragma: no cover — o schema já foi derrubado
            pass

    def _conn(self):
        return self.schema.connect(self.user, self.password)

    def test_escreve_em_intelligence_insights(self) -> None:
        """O caso que estava quebrado: INSERT + UPDATE + DELETE na tabela de insights."""
        import pymysql

        conn = self._conn()
        self.addCleanup(conn.close)
        with conn.cursor() as cur:
            try:
                cur.execute(
                    """INSERT INTO intelligence_insights
                           (company_id, kind, severity, rank_weight, title, body, valid_until)
                       VALUES (1,'tendencia_receita','atencao',80,'t','b',
                               UTC_TIMESTAMP() + INTERVAL 8 HOUR)"""
                )
                cur.execute(
                    "UPDATE intelligence_insights SET is_current = 0 WHERE company_id = 1"
                )
                cur.execute("DELETE FROM intelligence_insights WHERE company_id = 1")
            except pymysql.err.OperationalError as exc:  # pragma: no cover
                self.fail(f"usuário restrito não consegue gravar insights: {exc}")
        conn.commit()

    def test_escreve_nas_demais_tabelas_declaradas(self) -> None:
        conn = self._conn()
        self.addCleanup(conn.close)
        with conn.cursor() as cur:
            cur.execute(
                "INSERT INTO intelligence_job_runs (job_name, run_mode, status) "
                "VALUES ('insights','baseline','running')"
            )
            cur.execute(
                "INSERT INTO intelligence_quote_scores (company_id, omie_order_id, score) "
                "VALUES (1, 42, 0.5)"
            )
        conn.commit()

    def test_e_recusado_em_tabela_de_leitura(self) -> None:
        """A contenção é real: LER pode, ESCREVER não."""
        import pymysql

        conn = self._conn()
        self.addCleanup(conn.close)
        with conn.cursor() as cur:
            cur.execute("SELECT COUNT(*) AS n FROM biomie_notas_fiscais_canonicas")
            with self.assertRaises(pymysql.err.OperationalError):
                cur.execute(
                    "INSERT INTO biomie_notas_fiscais_canonicas (company_id, valor_total) "
                    "VALUES (1, 10)"
                )

    def test_nao_enxerga_credenciais_da_empresa(self) -> None:
        import pymysql

        conn = self._conn()
        self.addCleanup(conn.close)
        with conn.cursor() as cur:
            cur.execute("SELECT id, slug FROM biomie_companies")
            with self.assertRaises(pymysql.err.OperationalError):
                cur.execute("SELECT omie_app_secret FROM biomie_companies")

    def test_nao_tem_ddl(self) -> None:
        """Sem DROP/ALTER: um bug no job não pode destruir estrutura."""
        import pymysql

        conn = self._conn()
        self.addCleanup(conn.close)
        with conn.cursor() as cur:
            with self.assertRaises(pymysql.err.OperationalError):
                cur.execute("DROP TABLE intelligence_quote_scores")


if __name__ == "__main__":  # pragma: no cover
    unittest.main()
