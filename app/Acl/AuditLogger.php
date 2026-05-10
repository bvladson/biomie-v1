<?php

declare(strict_types=1);

namespace BIomie\Acl;

use BIomie\Auth;
use PDO;
use Throwable;

/**
 * Registra eventos de acesso a dados sensíveis na trilha de auditoria.
 *
 * Usa a tabela biomie_access_logs (já existente) com o novo campo `module`
 * adicionado na migração Fase 12.
 *
 * Eventos registrados:
 *   data_accessed   — acesso a um endpoint sensível (dossiê, financeiro, etc.)
 *   data_exported   — exportação de dados para CSV/JSON
 *   document_viewed — visualização de CPF/CNPJ sem mascaramento
 *   access_denied   — tentativa de acesso a módulo não autorizado
 *
 * Chamada estática: AuditLogger::log($pdo, 'data_accessed', 'dossier', ['client_id' => 123])
 */
final class AuditLogger
{
    private const TABLE = 'biomie_access_logs';

    /**
     * Registra um evento de acesso a dado.
     *
     * @param array<string,mixed>|null $context  Dados de contexto serializados em JSON
     */
    public static function log(
        PDO $pdo,
        string $event,
        string $module,
        ?array $context = null
    ): void {
        try {
            $user    = Auth::currentUser();
            $userId  = $user !== null ? (int) $user['id'] : null;
            $route   = trim((string) ($_SERVER['REQUEST_URI'] ?? '/'));
            $ip      = trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')) ?: 'unknown';
            $agent   = mb_substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);

            $stmt = $pdo->prepare(
                'INSERT INTO ' . self::TABLE . '
                    (user_id, event_type, module, username_attempted, route, ip_address, user_agent, details_json, created_at)
                 VALUES (:uid, :evt, :mod, :uname, :route, :ip, :agent, :details, NOW())'
            );

            $stmt->execute([
                'uid'     => $userId,
                'evt'     => mb_substr($event, 0, 40),
                'mod'     => mb_substr($module, 0, 40),
                'uname'   => $user !== null ? mb_substr((string) $user['username'], 0, 160) : null,
                'route'   => mb_substr($route, 0, 255),
                'ip'      => mb_substr($ip, 0, 45),
                'agent'   => $agent !== '' ? $agent : null,
                'details' => $context !== null ? json_encode($context, JSON_UNESCAPED_UNICODE) : null,
            ]);
        } catch (Throwable) {
            // Não-fatal: auditoria nunca deve quebrar o fluxo principal
        }
    }

    /**
     * Registra uma exportação de dados para a tabela dedicada biomie_data_export_log.
     *
     * @param array<string,mixed>|null $filters  Filtros aplicados na exportação
     */
    public static function logExport(
        PDO $pdo,
        string $format,
        string $module,
        int $rowCount,
        ?array $filters = null
    ): void {
        try {
            $user = Auth::currentUser();
            if ($user === null) {
                return;
            }

            $stmt = $pdo->prepare(
                'INSERT INTO biomie_data_export_log
                    (user_id, username, format, module, filters_json, row_count, ip_address, user_agent, exported_at)
                 VALUES (:uid, :uname, :fmt, :mod, :filters, :rows, :ip, :agent, NOW())'
            );

            $stmt->execute([
                'uid'     => (int) $user['id'],
                'uname'   => mb_substr((string) $user['username'], 0, 80),
                'fmt'     => mb_substr($format, 0, 20),
                'mod'     => mb_substr($module, 0, 40),
                'filters' => $filters !== null ? json_encode($filters, JSON_UNESCAPED_UNICODE) : null,
                'rows'    => $rowCount,
                'ip'      => mb_substr(trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')), 0, 45),
                'agent'   => mb_substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255),
            ]);

            // Também registra no access_log geral para visibilidade unificada
            self::log($pdo, 'data_exported', $module, [
                'format'    => $format,
                'row_count' => $rowCount,
                'filters'   => $filters,
            ]);
        } catch (Throwable) {}
    }
}
