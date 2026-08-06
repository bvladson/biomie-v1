<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

/**
 * `missing_revenue` nao conseguia representar metade do problema.
 *
 * ─── O QUE ESTAVA ERRADO ────────────────────────────────────────────────────
 * A auditoria de coerencia gravava `max(0.0, canonical - mart)`. O nome diz
 * "quanto falta", e por construcao o campo so sabe expressar isso — quando o
 * mart ficava MAIOR que a fonte, o valor era zerado.
 *
 * A deteccao ja era bidirecional (`abs()` no limiar de 1%), entao a linha era
 * marcada como `warning` com o motivo `valor_mart_diverge_da_fonte` e o numero
 * ao lado dizendo R$ 0,00. Em quatro dos cinco warnings da MAG_FILIAL a tela
 * afirmava "o valor diverge" e "a diferenca e zero" na mesma linha.
 *
 *   2026-03  warning  divergencia real -8.118,54  gravado 0,00
 *   2026-05  warning  divergencia real -11.631,60 gravado 0,00
 *   2026-06  warning  divergencia real -6.295,45  gravado 0,00
 *   2026-08  warning  divergencia real -2.982,53  gravado 0,00
 *
 * ─── A MUDANCA ──────────────────────────────────────────────────────────────
 * O campo passa a guardar a diferenca COM SINAL:
 *   positivo = falta valor no mart · negativo = o mart tem a mais.
 *
 * O rename e deliberado. Manter o nome `missing_revenue` guardando valor
 * negativo seria uma mentira no schema, e schema que mente e a origem de meia
 * duzia de incidentes ja documentados neste projeto.
 *
 * ─── SOBRE OS DADOS EXISTENTES ──────────────────────────────────────────────
 * Nao ha backfill: os valores atuais continuam validos como delta (todos >= 0),
 * e as linhas onde o sinal foi perdido serao recalculadas na proxima execucao
 * do job, que roda diariamente e rebaixa as anteriores por `is_current`.
 * Reconstruir o sinal de linhas historicas exigiria refazer a conta com o mart
 * da epoca, que nao existe mais.
 */
return new class extends AbstractMigration {

    private const T = 'intelligence_fiscal_coherence';

    public function getVersion(): string
    {
        return '20260806010000';
    }

    public function getDescription(): string
    {
        return 'Coerencia fiscal: missing_revenue vira revenue_delta com sinal (mart maior que a fonte tambem e divergencia).';
    }

    public function up(PDO $pdo): void
    {
        if (!$this->columnExists($pdo, self::T, 'missing_revenue')) {
            return; // ja renomeada
        }
        $pdo->exec(sprintf(
            'ALTER TABLE %s CHANGE COLUMN missing_revenue revenue_delta DECIMAL(15,2) NOT NULL DEFAULT 0
             COMMENT %s',
            self::T,
            $pdo->quote('positivo = falta no mart; negativo = mart tem a mais')
        ));
    }

    public function down(PDO $pdo): void
    {
        if (!$this->columnExists($pdo, self::T, 'revenue_delta')) {
            return;
        }
        // Voltar ao nome antigo NAO restaura a semantica truncada de proposito:
        // os valores negativos existentes ficariam invisiveis de novo.
        $pdo->exec(sprintf(
            'ALTER TABLE %s CHANGE COLUMN revenue_delta missing_revenue DECIMAL(15,2) NOT NULL DEFAULT 0',
            self::T
        ));
    }

    private function columnExists(PDO $pdo, string $tabela, string $coluna): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c'
        );
        $stmt->execute(['t' => $tabela, 'c' => $coluna]);

        return (int) $stmt->fetchColumn() > 0;
    }
};
