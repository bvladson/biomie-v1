"""Schema MariaDB DESCARTÁVEL para os testes que precisam de banco de verdade.

Permissão, transação, rollback e isolamento entre empresas não podem ser
provados com um duplo: o comportamento que interessa É o do servidor. Estes
testes criam um schema próprio, aplicam o DDL mínimo, rodam e derrubam tudo.

CONFIGURAÇÃO (opcional — sem ela os testes PULAM, não falham):

    INTEL_TEST_DB_HOST=127.0.0.1
    INTEL_TEST_DB_PORT=3306
    INTEL_TEST_DB_USER=root
    INTEL_TEST_DB_PASSWORD=...

Pular em vez de falhar é deliberado: o gate de deploy roda `unittest discover`
na VPS e não pode quebrar por causa de um banco de teste ausente. Quando as
credenciais existem, os testes rodam de verdade — e é assim que devem rodar no
pipeline. Um teste que "passa" sem exercitar nada seria pior que não tê-lo, por
isso `skipTest` diz ALTO o que deixou de ser verificado.

SEGURANÇA: o nome do schema é sempre `biomie_intel_test_<aleatório>` e o fixture
recusa qualquer nome que não comece por esse prefixo — nenhum DROP pode
alcançar um banco real por engano.
"""

from __future__ import annotations

import os
import secrets
import unittest

PREFIXO = "biomie_intel_test_"

DDL = (
    """CREATE TABLE biomie_companies (
           id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
           slug VARCHAR(40) NOT NULL,
           nome_fantasia VARCHAR(120) NULL,
           razao_social VARCHAR(120) NULL,
           ativo TINYINT(1) NOT NULL DEFAULT 1,
           omie_app_key VARCHAR(80) NULL,
           omie_app_secret VARCHAR(80) NULL
       ) ENGINE=InnoDB""",

    """CREATE TABLE intelligence_job_runs (
           id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
           job_name VARCHAR(40) NOT NULL,
           company_id TINYINT UNSIGNED NULL,
           run_mode VARCHAR(16) NOT NULL DEFAULT 'baseline',
           status VARCHAR(16) NOT NULL DEFAULT 'running',
           model_version VARCHAR(40) NULL,
           started_at DATETIME NULL,
           finished_at DATETIME NULL,
           duration_ms INT NULL,
           rows_in INT NOT NULL DEFAULT 0,
           rows_out INT NOT NULL DEFAULT 0,
           metrics_json TEXT NULL,
           error_message TEXT NULL,
           hostname VARCHAR(64) NULL,
           correlation_id VARCHAR(64) NULL
       ) ENGINE=InnoDB""",

    """CREATE TABLE intelligence_quote_scores (
           id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
           company_id TINYINT UNSIGNED NOT NULL,
           omie_order_id BIGINT NOT NULL,
           score DECIMAL(6,4) NOT NULL DEFAULT 0,
           band VARCHAR(16) NOT NULL DEFAULT 'baixa',
           is_current TINYINT(1) NOT NULL DEFAULT 1,
           scored_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
       ) ENGINE=InnoDB""",

    """CREATE TABLE intelligence_quote_feedback (
           id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
           company_id TINYINT UNSIGNED NOT NULL,
           omie_order_id BIGINT NOT NULL,
           resolution VARCHAR(32) NULL
       ) ENGINE=InnoDB""",

    """CREATE TABLE intelligence_insights (
           id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
           company_id TINYINT UNSIGNED NOT NULL,
           job_run_id BIGINT UNSIGNED NULL,
           kind VARCHAR(48) NOT NULL,
           severity ENUM('positivo','neutro','atencao','critico') NOT NULL DEFAULT 'neutro',
           rank_weight SMALLINT NOT NULL DEFAULT 0,
           title VARCHAR(120) NOT NULL,
           body VARCHAR(400) NOT NULL,
           metric_json TEXT NULL,
           link_url VARCHAR(200) NULL,
           is_current TINYINT(1) NOT NULL DEFAULT 1,
           computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
           valid_until DATETIME NOT NULL,
           KEY idx_ii_current (company_id, is_current, valid_until)
       ) ENGINE=InnoDB""",

    """CREATE TABLE biomie_notas_fiscais_canonicas (
           id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
           company_id TINYINT UNSIGNED NOT NULL,
           data_emissao DATE NULL,
           valor_total DECIMAL(15,2) NOT NULL DEFAULT 0,
           nome_cliente VARCHAR(200) NULL,
           nome_vendedor VARCHAR(120) NULL,
           status_fiscal VARCHAR(24) NOT NULL DEFAULT 'autorizada',
           operation_scope VARCHAR(24) NOT NULL DEFAULT 'commercial',
           business_channel VARCHAR(24) NULL,
           is_biomie_commercial_visible TINYINT(1) NOT NULL DEFAULT 1
       ) ENGINE=InnoDB""",

    """CREATE TABLE biomie_pedidos_canonicos (
           id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
           company_id TINYINT UNSIGNED NOT NULL,
           pedido_id_omie BIGINT NULL
       ) ENGINE=InnoDB""",

    """CREATE TABLE biomie_sales_quote_followup_schedule (
           id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
           company_id TINYINT UNSIGNED NOT NULL,
           omie_order_id BIGINT NULL
       ) ENGINE=InnoDB""",
)


def admin_params() -> dict[str, object] | None:
    """Credenciais do usuário que cria/derruba o schema, ou None.

    `INTEL_TEST_DB_SOCKET` permite rodar pelo socket unix — é assim que estes
    testes rodam no servidor sem precisar criar um usuário TCP com CREATE USER e
    GRANT OPTION globais só para o teste. Um "usuário de teste" com poder de
    superusuário seria um preço alto demais por uma verificação.
    """
    user = os.environ.get("INTEL_TEST_DB_USER")
    if not user:
        return None

    socket = os.environ.get("INTEL_TEST_DB_SOCKET")
    if socket:
        return {"unix_socket": socket, "user": user,
                "password": os.environ.get("INTEL_TEST_DB_PASSWORD", "")}

    return {
        "host": os.environ.get("INTEL_TEST_DB_HOST", "127.0.0.1"),
        "port": int(os.environ.get("INTEL_TEST_DB_PORT", "3306")),
        "user": user,
        "password": os.environ.get("INTEL_TEST_DB_PASSWORD", ""),
    }


def require_db() -> dict[str, object]:
    params = admin_params()
    if params is None:
        raise unittest.SkipTest(
            "NAO VERIFICADO: sem INTEL_TEST_DB_USER, os testes de banco "
            "(permissao, transacao, isolamento) nao rodaram. "
            "Configure INTEL_TEST_DB_* para exercita-los."
        )
    try:
        import pymysql  # noqa: F401
    except ImportError as exc:  # pragma: no cover
        raise unittest.SkipTest(f"NAO VERIFICADO: pymysql ausente ({exc})") from exc
    return params


class DisposableSchema:
    """Cria um schema, aplica o DDL e derruba tudo ao sair."""

    def __init__(self) -> None:
        self.params = require_db()
        self.name = PREFIXO + secrets.token_hex(6)
        if not self.name.startswith(PREFIXO):  # pragma: no cover — trava paranoica
            raise RuntimeError("nome de schema fora do prefixo de teste")
        self._admin = None

    def __enter__(self) -> "DisposableSchema":
        import pymysql

        self._admin = pymysql.connect(autocommit=True, **self.params)
        with self._admin.cursor() as cur:
            cur.execute(f"CREATE DATABASE `{self.name}` CHARACTER SET utf8mb4")
            cur.execute(f"USE `{self.name}`")
            for ddl in DDL:
                cur.execute(ddl)
        return self

    def __exit__(self, *exc: object) -> None:
        if self._admin is None:
            return
        try:
            with self._admin.cursor() as cur:
                # Segunda trava: o DROP só roda com o prefixo confirmado aqui.
                if self.name.startswith(PREFIXO):
                    cur.execute(f"DROP DATABASE IF EXISTS `{self.name}`")
        finally:
            self._admin.close()

    def connect(self, user: str | None = None, password: str | None = None):
        """Conexão ao schema descartável (por padrão, como o admin)."""
        import pymysql

        params = dict(self.params)
        if user is not None:
            params["user"] = user
            params["password"] = password or ""
        return pymysql.connect(
            database=self.name,
            charset="utf8mb4",
            cursorclass=pymysql.cursors.DictCursor,
            autocommit=False,
            init_command="SET time_zone = '+00:00'",
            **params,
        )

    def exec_admin(self, sql: str) -> None:
        with self._admin.cursor() as cur:  # type: ignore[union-attr]
            cur.execute(sql)

    def user_host(self) -> str:
        """Parte do host ao criar usuários de teste.

        Conexão por socket unix é casada pelo MariaDB como 'localhost', não '%'.
        Criar o usuário com o host errado faz o GRANT existir e a conexão ser
        recusada — o teste falharia por motivo enganoso.
        """
        return "localhost" if "unix_socket" in self.params else "%"
