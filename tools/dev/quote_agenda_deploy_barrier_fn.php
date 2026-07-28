<?php

declare(strict_types=1);

/**
 * AGENDA — barreira de rollback do deploy (decisão pura, sem I/O).
 *
 * Vive em arquivo próprio para que o TESTE exercite exatamente a função que o
 * deploy executa. O caminho de falha é o que um deploy bem sucedido nunca
 * percorre; sem isolá-lo, um engano só apareceria no pior momento possível.
 *
 * Incluído por tools/dev/quote_agenda_deploy_f5_f7.php e por
 * tests/run_deploy_rollback_barrier.php.
 */

/** Migration da Agenda a aplicar (Fase 7). */
const AGENDA_MIGRATION = '20260718120000';

/**
 * Classifica o que aconteceu com o schema depois do migrate-one.
 *
 * Reverter DEMAIS é tão perigoso quanto reverter de menos: devolver o CLI
 * anterior a um banco cujo schema já mudou deixa o servidor executando código
 * que desconhece as colunas existentes.
 *
 *  PRE_DDL            schema comprovadamente intacto → reverter tudo
 *  DDL_DONE           migration completa e exclusiva → stage1 PERMANECE
 *  RECOVERY_REQUIRED  qualquer estado intermediário  → não tocar em nada, parar
 *
 * @param bool         $migrateOk        migrate-one terminou com exit 0
 * @param int          $colunasSnapshot  quantas das 3 colunas existem (0..3)
 * @param bool         $versaoRegistrada versão consta como applied
 * @param list<string> $novasVersoes     versões que surgiram como applied
 * @param list<string> $alheiasAplicadas migrations de OUTRO fluxo aplicadas
 */
function agendaBarreiraDecidir(
    bool $migrateOk,
    int $colunasSnapshot,
    bool $versaoRegistrada,
    array $novasVersoes,
    array $alheiasAplicadas
): string {
    $completo = $migrateOk
        && $colunasSnapshot === 3
        && $versaoRegistrada
        && $novasVersoes === [AGENDA_MIGRATION]
        && $alheiasAplicadas === [];
    if ($completo) {
        return 'DDL_DONE';
    }

    // Só é seguro reverter tudo se NADA no schema mudou.
    if ($colunasSnapshot === 0 && !$versaoRegistrada && $novasVersoes === [] && $alheiasAplicadas === []) {
        return 'PRE_DDL';
    }

    // Meio-termo: colunas parciais, versão sem colunas, colunas sem versão, ou
    // migration alheia arrastada. Adivinhar aqui pode destruir trabalho de
    // outro fluxo — parar e chamar gente é a única resposta correta.
    return 'RECOVERY_REQUIRED';
}
