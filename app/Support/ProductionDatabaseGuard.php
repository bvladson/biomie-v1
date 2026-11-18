<?php

declare(strict_types=1);

namespace BIomie\Support;

use PDO;
use RuntimeException;

/**
 * BARREIRA ESTRUTURAL — impede ESCRITA acidental no banco de PRODUÇÃO.
 *
 * ─── POR QUE EXISTE ─────────────────────────────────────────────────────────
 * Produção é o MariaDB da VPS (`biomie` em srv-vps); a máquina local o lê
 * por túnel SSH com usuário somente-leitura. Antes da migração, o LOCAL
 * conectava direto ao banco de produção de então
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
    public const TARGET_PRODUCTION = 'production';
    public const TARGET_LEGACY = 'legacy';
    public const TARGET_OTHER = 'other';

    /**
     * PRODUÇÃO é reconhecida pelo par servidor + banco: o nome "biomie"
     * sozinho é genérico demais — uma cópia local ou de staging pode tê-lo.
     *
     * Até 21/09/2026 esta lista tinha só `u106688109_Comercial`: depois da
     * migração para a VPS o guard protegia o banco ANTIGO e deixava a produção
     * real (`biomie` em srv-vps) sem barreira nenhuma contra migration.
     */
    private const PRODUCTION_TARGETS = [
        ['server' => 'srv-vps', 'database' => 'biomie'],
    ];

    /**
     * Legado congelado da Hostinger: sem app nem cron desde 21/09/2026, guardado
     * como evidência. Escrita só com BIOMIE_ALLOW_LEGACY_WRITE=1 — a flag de
     * produção NÃO serve aqui, para um deploy de produção nunca alcançá-lo.
     */
    private const LEGACY_DATABASES = ['u106688109_Comercial'];

    /**
     * Garante que uma ESCRITA estrutural no banco corrente está autorizada.
     * Lança quando o alvo é produção e a intenção não foi declarada.
     *
     * Chame ANTES de aplicar migration, DDL manual ou mutação em massa.
     */
    public static function assertWriteAllowed(PDO $pdo, string $operation = 'escrita estrutural'): void
    {
        $target = self::currentTarget($pdo);
        if ($target === self::TARGET_OTHER) {
            return; // nem produção nem legado — libere
        }

        $database = self::currentDatabase($pdo);
        if ($target === self::TARGET_LEGACY) {
            if (getenv('BIOMIE_ALLOW_LEGACY_WRITE') === '1') {
                return;
            }
            throw new RuntimeException(
                'LEGADO BLOQUEADO: "' . $operation . '" contra o banco "' . $database . '" recusada. '
                . 'Este é o banco antigo da Hostinger, congelado como evidência desde 21/09/2026. '
                . 'Nenhum SQL foi executado. Produção fica na VPS (biomie em srv-vps).'
            );
        }

        if (self::isExplicitlyAllowed()) {
            return; // produção + intenção declarada — libere
        }

        throw new RuntimeException(
            'PRODUÇÃO BLOQUEADA: "' . $operation . '" contra o banco "' . $database . '" recusada. '
            . 'Nenhum SQL foi executado. Esta máquina não declarou intenção de escrever em produção. '
            . 'Se for um deploy DELIBERADO, rode pelo caminho oficial com --allow-production '
            . '(ou com BIOMIE_ALLOW_PRODUCTION=1 no ambiente do servidor). '
            . 'Para diagnóstico local, use SOMENTE leitura.'
        );
    }

    /** O banco corrente é protegido (produção ou legado congelado)? */
    public static function isProduction(PDO $pdo): bool
    {
        return self::currentTarget($pdo) !== self::TARGET_OTHER;
    }

    /** production | legacy | other, a partir do banco e do servidor conectados. */
    public static function currentTarget(PDO $pdo): string
    {
        $row = $pdo->query('SELECT DATABASE(), @@hostname')->fetch(PDO::FETCH_NUM) ?: ['', ''];

        return self::classify((string) ($row[0] ?? ''), (string) ($row[1] ?? ''));
    }

    /** Classificação testável sem conexão. */
    public static function classify(string $database, string $server = ''): string
    {
        foreach (self::PRODUCTION_TARGETS as $target) {
            if ($database === $target['database'] && strcasecmp($server, $target['server']) === 0) {
                return self::TARGET_PRODUCTION;
            }
        }

        return in_array($database, self::LEGACY_DATABASES, true) ? self::TARGET_LEGACY : self::TARGET_OTHER;
    }

    /**
     * Um nome de banco é protegido? Só reconhece o legado: produção exige
     * também o servidor (ver classify()).
     */
    public static function isProductionDatabase(string $database): bool
    {
        return self::classify($database) !== self::TARGET_OTHER;
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
        return match (self::currentTarget($pdo)) {
            self::TARGET_OTHER => true,
            self::TARGET_LEGACY => getenv('BIOMIE_ALLOW_LEGACY_WRITE') === '1',
            default => self::isExplicitlyAllowed(),
        };
    }

    private static function currentDatabase(PDO $pdo): string
    {
        return (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    }
}
