<?php

declare(strict_types=1);

namespace BIomie\Support;

use PDO;
use RuntimeException;

/**
 * BARREIRA ESTRUTURAL — impede ESCRITA acidental no banco de PRODUÇÃO.
 *
 * ─── POR QUE EXISTE ─────────────────────────────────────────────────────────
 * Nesta instalação o ambiente LOCAL conecta direto ao banco de PRODUÇÃO
 * (`u106688109_Comercial` em br-asc-web1659.main-hosting.eu). Em 2026-07-23 uma
 * migration rodou "local" e, por isso, foi aplicada em produção. Não houve dano
 * (era aditiva), mas o CAMINHO é perigoso: no MariaDB, DDL (CREATE/ALTER/DROP)
 * faz COMMIT IMPLÍCITO — uma migration acidental NÃO é desfeita por rollback de
 * transação. A defesa tem de ser INDEPENDENTE do cuidado de cada operação: um
 * portão que recusa escrita em produção ANTES de qualquer SQL, a menos que a
 * intenção seja EXPLÍCITA.
 *
 * ─── ESPELHA O TestDatabaseGuard, DO OUTRO LADO ─────────────────────────────
 *  · TestDatabaseGuard: suíte mutável só toca banco cujo nome termina em _test.
 *  · ProductionDatabaseGuard: escrita estrutural só toca produção com AUTORIZAÇÃO
 *    explícita. Juntos cobrem os dois erros simétricos (teste→prod, local→prod).
 *
 * ─── AUTORIZAÇÃO EXPLÍCITA (uma das duas) ───────────────────────────────────
 *  1. flag `--allow-production` na linha de comando (intenção deliberada), OU
 *  2. env `BIOMIE_ALLOW_PRODUCTION=1` (definida SÓ no servidor de produção).
 * Sem nenhuma das duas, escrever em produção é BLOQUEADO. O padrão falha para o
 * lado seguro: a máquina local, que não passa flag nem tem a env, não escreve.
 *
 * LEITURA continua livre — diagnósticos locais contra produção são permitidos
 * (idealmente com credencial somente-leitura). O portão trava só a ESCRITA.
 */
final class ProductionDatabaseGuard
{
    /** Bancos reconhecidos como PRODUÇÃO. */
    private const PRODUCTION_DATABASES = ['u106688109_Comercial'];

    /**
     * Garante que uma ESCRITA estrutural no banco corrente está autorizada.
     * Lança quando o alvo é produção e a intenção não foi declarada.
     *
     * Chame ANTES de aplicar migration, DDL manual ou mutação em massa.
     */
    public static function assertWriteAllowed(PDO $pdo, string $operation = 'escrita estrutural'): void
    {
        if (!self::isProduction($pdo)) {
            return; // não é produção — libere
        }

        if (self::isExplicitlyAllowed()) {
            return; // produção + intenção declarada — libere
        }

        $database = self::currentDatabase($pdo);
        throw new RuntimeException(
            'PRODUÇÃO BLOQUEADA: "' . $operation . '" contra o banco "' . $database . '" recusada. '
            . 'Nenhum SQL foi executado. Esta máquina não declarou intenção de escrever em produção. '
            . 'Se for um deploy DELIBERADO, rode pelo caminho oficial com --allow-production '
            . '(ou com BIOMIE_ALLOW_PRODUCTION=1 no ambiente do servidor). '
            . 'Para diagnóstico local, use SOMENTE leitura.'
        );
    }

    /** O banco corrente é de produção? */
    public static function isProduction(PDO $pdo): bool
    {
        return self::isProductionDatabase(self::currentDatabase($pdo));
    }

    /** Um nome de banco é de produção? Testável sem conexão. */
    public static function isProductionDatabase(string $database): bool
    {
        return in_array($database, self::PRODUCTION_DATABASES, true);
    }

    /**
     * A intenção de escrever em produção foi declarada?
     *
     * Público para os scripts de deploy conferirem/exibirem o estado.
     */
    public static function isExplicitlyAllowed(): bool
    {
        if (getenv('BIOMIE_ALLOW_PRODUCTION') === '1') {
            return true;
        }

        $argv = $_SERVER['argv'] ?? [];

        return is_array($argv) && in_array('--allow-production', $argv, true);
    }

    /** Como assertWriteAllowed, mas retorna bool — para testar o próprio guard. */
    public static function isWriteAllowed(PDO $pdo): bool
    {
        return !self::isProduction($pdo) || self::isExplicitlyAllowed();
    }

    private static function currentDatabase(PDO $pdo): string
    {
        return (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    }
}
