<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

/**
 * NF nao autorizada nao conseguia ser gravada — mesma divergencia do `phase`.
 *
 * ─── ACHADO POR FERRAMENTA, NAO POR ACASO ───────────────────────────────────
 * `tools/dev/enum_domain_drift.php` — escrita para nao repetir o caso `phase` —
 * comparou os ENUM declarados no codigo com os do banco vivo e apontou duas
 * colunas na primeira execucao:
 *
 *   biomie_notas_fiscais_canonicas.status_fiscal   codigo grava 'nao_autorizada'
 *   mart_fiscal_items.status_fiscal                banco so tem ('autorizada','cancelada')
 *
 * `CanonicalSchemaManager` declara
 *     status_fiscal ENUM('autorizada','cancelada','nao_autorizada')
 *                   NOT NULL DEFAULT 'nao_autorizada'
 * e `CanonicalWriteRepository` usa exatamente 'nao_autorizada' como padrao
 * quando a Omie nao informa o status. Como as tabelas ja existiam, o
 * `CREATE TABLE IF NOT EXISTS` nunca as alterou.
 *
 * ─── O QUE ISSO CUSTAVA ─────────────────────────────────────────────────────
 * Toda NF sem status explicito derrubava a gravacao inteira com
 * SQLSTATE[01000] 1265 sob STRICT_TRANS_TABLES — e, antes do STRICT, entrava
 * como string vazia. Sobraram 2 linhas com o sentinela.
 *
 * Pior que o erro: `CanonicalCoverageRepository` CONSULTA
 * `status_fiscal = 'nao_autorizada'` para montar o relatorio de cobertura
 * fiscal. Como nenhuma linha jamais pode ter esse valor, a metrica sempre
 * devolveu zero — um indicador que parecia saudavel por nao conseguir existir.
 *
 * ─── ORDEM: ALTER PRIMEIRO, DEPOIS O REPARO ─────────────────────────────────
 * Ao contrario da migration do `phase`, aqui o valor correto das linhas com
 * sentinela SO EXISTE depois de ampliar o dominio — nao da para repara-las
 * antes. Que o ALTER sobrevive a presenca do '' nao foi suposto: foi
 * reproduzido numa tabela descartavel na propria VPS, com o mesmo sql_mode,
 * antes de escrever esta migration. O '' atravessa o ALTER intacto e o UPDATE
 * seguinte o converte.
 *
 * ─── POR QUE 'nao_autorizada' E O REPARO CORRETO ────────────────────────────
 * Nao e chute. Apenas valor FORA do dominio e truncado; 'autorizada' e
 * 'cancelada' teriam sido gravados normalmente. Como 'nao_autorizada' e o unico
 * valor fora do dominio que o codigo produz, ele e necessariamente o que foi
 * truncado. As duas linhas ainda corroboram: tem chave_nfe e nao tem
 * data_cancelamento.
 */
return new class extends AbstractMigration {

    private const DOMINIO = "'autorizada','cancelada','nao_autorizada'";

    /** @var list<string> */
    private const TABELAS = ['biomie_notas_fiscais_canonicas', 'mart_fiscal_items'];

    public function getVersion(): string
    {
        return '20260805130000';
    }

    public function getDescription(): string
    {
        return "Alinha status_fiscal ao dominio do codigo ('nao_autorizada') na NF canonica e no mart fiscal.";
    }

    public function up(PDO $pdo): void
    {
        foreach (self::TABELAS as $tabela) {
            if (!$this->tableExists($pdo, $tabela)) {
                continue;
            }

            $pdo->exec(sprintf(
                "ALTER TABLE %s MODIFY COLUMN status_fiscal ENUM(%s) NOT NULL DEFAULT 'nao_autorizada'",
                $tabela,
                self::DOMINIO
            ));

            // So agora 'nao_autorizada' e um valor aceitavel.
            $pdo->exec(sprintf(
                "UPDATE %s SET status_fiscal = 'nao_autorizada' WHERE status_fiscal = ''",
                $tabela
            ));
        }
    }

    public function down(PDO $pdo): void
    {
        // Estreitar de volta exigiria decidir o que fazer com as NFs que
        // legitimamente ficarem em 'nao_autorizada' — e reintroduziria a falha
        // de gravacao que esta migration existe para acabar.
        foreach (self::TABELAS as $tabela) {
            if (!$this->tableExists($pdo, $tabela)) {
                continue;
            }
            $pdo->exec(sprintf(
                "ALTER TABLE %s MODIFY COLUMN status_fiscal ENUM(%s) NOT NULL DEFAULT 'nao_autorizada'",
                $tabela,
                self::DOMINIO
            ));
        }
    }

    private function tableExists(PDO $pdo, string $tabela): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = :t'
        );
        $stmt->execute(['t' => $tabela]);

        return (int) $stmt->fetchColumn() > 0;
    }
};
