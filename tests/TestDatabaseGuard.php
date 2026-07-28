<?php

declare(strict_types=1);

namespace BIomie\Tests;

use PDO;
use RuntimeException;

/**
 * BARREIRA ESTRUTURAL — impede suítes MUTÁVEIS de tocarem o banco de produção.
 *
 * ─── POR QUE EXISTE ─────────────────────────────────────────────────────────
 * Em 2026-07-22 um teste, por um erro (DROP de uma TEMPORARY que sombreava a
 * tabela real), gravou em produção. Corrigir aquele DROP não basta: outro erro
 * alcançaria outra tabela. A defesa tem de ser INDEPENDENTE do acerto de cada
 * suíte — um portão que recusa produção ANTES de qualquer SQL.
 *
 * ─── DUAS CONDIÇÕES, AMBAS OBRIGATÓRIAS ─────────────────────────────────────
 *  1. APP_ENV = 'test'  — a intenção precisa ser explícita.
 *  2. O nome do banco termina em _test / _testing / _ci — o alvo precisa ser,
 *     de fato, um banco de testes.
 * O padrão falha para o lado seguro: sem as duas, bloqueia.
 *
 * Chame ANTES de qualquer DDL, fixture ou transação nas suítes que escrevem.
 */
final class TestDatabaseGuard
{
    /** Sufixos aceitos de banco de teste. */
    private const TEST_DB_SUFFIX = '/(?:_test|_testing|_ci)$/i';

    /**
     * Garante que $pdo aponta para um banco de TESTE. Lança se for produção.
     */
    public static function assertSafe(PDO $pdo): void
    {
        $environment = (string) getenv('APP_ENV');
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

        if ($environment !== 'test') {
            throw new RuntimeException(
                'Suíte mutável BLOQUEADA: APP_ENV="' . $environment . '" (esperado "test"). '
                . 'Nenhum SQL foi executado. Defina APP_ENV=test e aponte para um banco de testes.'
            );
        }

        if (preg_match(self::TEST_DB_SUFFIX, $database) !== 1) {
            throw new RuntimeException(
                'Suíte mutável BLOQUEADA: banco "' . $database . '" não é de testes '
                . '(precisa terminar em _test, _testing ou _ci). Nenhum SQL foi executado.'
            );
        }
    }

    /**
     * Como assertSafe, mas retorna bool em vez de lançar — para os TESTES que
     * exercitam o próprio guard.
     */
    public static function isSafe(PDO $pdo): bool
    {
        try {
            self::assertSafe($pdo);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }
}
