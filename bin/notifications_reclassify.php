<?php

declare(strict_types=1);

/**
 * Reclassifica o HISTÓRICO de notificações a partir do biomie_webhook_inbox.
 *
 * As notificações antigas foram gravadas antes do OmieEventClassifier e são
 * todas iguais ("Evento Omie recebido — Domínio: commercial"). Este script as
 * substitui pelas versões ricas ("Novo pedido #40 · R$ 1.199,00", clicável),
 * reconstruídas a partir do payload cru que já está no inbox.
 *
 * Uso:
 *   php bin/notifications_reclassify.php --dry-run           (só mostra o plano)
 *   php bin/notifications_reclassify.php --apply             (executa)
 *   php bin/notifications_reclassify.php --apply --days=30   (janela)
 *   php bin/notifications_reclassify.php --apply --unread-hours=24
 *
 * Idempotente: apaga as notificações de source=omie_webhook da janela e
 * recria — rodar duas vezes dá o mesmo resultado.
 *
 * Histórico entra como LIDO (não faz sentido inundar o sininho com 300 eventos
 * de dias atrás). Só o que for mais novo que --unread-hours fica não-lido.
 */

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Notifications\OmieEventClassifier;

putenv('BIOMIE_ALLOW_DEFAULT_TENANT=1');
require_once dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$opts = getopt('', ['apply', 'dry-run', 'days::', 'unread-hours::']);
$apply       = isset($opts['apply']);
$days        = isset($opts['days']) ? max(1, (int) $opts['days']) : 0; // 0 = tudo
$unreadHours = isset($opts['unread-hours']) ? max(0, (int) $opts['unread-hours']) : 24;

$config = new Config();
$pdo = Database::connect($config, Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db');
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$windowSql = $days > 0 ? "WHERE received_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)" : '';

// ── 1. Lê o inbox em ordem cronológica ───────────────────────────────────────
$events = $pdo->query(
    "SELECT company_id, topic_name, payload_json, received_at
       FROM biomie_webhook_inbox
       {$windowSql}
      ORDER BY received_at ASC, id ASC"
)->fetchAll();

echo 'Eventos no inbox: ' . count($events) . PHP_EOL;
if ($events === []) {
    echo "Nada a fazer.\n";
    exit(0);
}

// ── 2. Classifica aplicando o MESMO dedupe do runtime ────────────────────────
// (chave + empresa dentro da janela do próprio evento)
$lastSeen = [];   // "company|key" => timestamp da última emissão
$planned  = [];
$skipped  = 0;

foreach ($events as $event) {
    $topic = trim((string) ($event['topic_name'] ?? ''));
    $payload = json_decode((string) ($event['payload_json'] ?? '{}'), true);
    if (!is_array($payload)) {
        $payload = [];
    }

    $notice = OmieEventClassifier::classify($topic, $payload);

    $companyId = $event['company_id'] !== null ? (int) $event['company_id'] : 0;
    $ts = strtotime((string) $event['received_at']) ?: time();
    $dedupeId = $companyId . '|' . $notice['dedupe_key'];
    $window = (int) $notice['dedupe_window_seconds'];

    if ($window > 0 && isset($lastSeen[$dedupeId]) && ($ts - $lastSeen[$dedupeId]) < $window) {
        $skipped++;
        continue;
    }
    $lastSeen[$dedupeId] = $ts;

    $planned[] = [
        'company_id' => $companyId > 0 ? $companyId : null,
        'category'   => $notice['category'],
        'severity'   => $notice['severity'],
        'priority'   => $notice['priority'],
        'icon'       => $notice['icon'],
        'title'      => mb_substr($notice['title'], 0, 200),
        'body'       => $notice['body'] !== '' ? $notice['body'] : null,
        'link'       => $notice['link'],
        'dedupe_key' => mb_substr($notice['dedupe_key'], 0, 191),
        'created_at' => (string) $event['received_at'],
        'ts'         => $ts,
    ];
}

// ── 3. Resumo do plano ───────────────────────────────────────────────────────
$byPriority = ['high' => 0, 'normal' => 0, 'low' => 0];
foreach ($planned as $p) {
    $byPriority[$p['priority']] = ($byPriority[$p['priority']] ?? 0) + 1;
}
$unreadCut = time() - ($unreadHours * 3600);
$willBeUnread = count(array_filter($planned, static fn (array $p): bool => $p['ts'] >= $unreadCut));

$toDelete = (int) $pdo->query(
    "SELECT COUNT(*) FROM biomie_notifications WHERE source = 'omie_webhook'"
)->fetchColumn();

echo str_repeat('─', 60) . PHP_EOL;
echo "Deduplicados (descartados): {$skipped}\n";
echo 'Notificações a criar: ' . count($planned) . PHP_EOL;
echo "  alta prioridade (pedido/cliente/NF): {$byPriority['high']}\n";
echo "  normal: {$byPriority['normal']}\n";
echo "  baixa (ruído agrupado): {$byPriority['low']}\n";
echo "Notificações antigas a substituir: {$toDelete}\n";
echo "Ficarão NÃO-LIDAS (últimas {$unreadHours}h): {$willBeUnread}\n";
echo str_repeat('─', 60) . PHP_EOL;

if (!$apply) {
    echo "DRY-RUN — nada foi alterado. Use --apply para executar.\n";
    exit(0);
}

// ── 4. Aplica ────────────────────────────────────────────────────────────────
$pdo->beginTransaction();
try {
    // Remove as antigas (as leituras somem junto — as genéricas não têm valor).
    $ids = $pdo->query("SELECT id FROM biomie_notifications WHERE source = 'omie_webhook'")
        ->fetchAll(PDO::FETCH_COLUMN);
    if ($ids !== []) {
        $in = implode(',', array_map('intval', $ids));
        $pdo->exec("DELETE FROM biomie_notification_reads WHERE notification_id IN ({$in})");
        $pdo->exec("DELETE FROM biomie_notifications WHERE id IN ({$in})");
    }

    $insert = $pdo->prepare(
        'INSERT INTO biomie_notifications
            (company_id, category, severity, priority, icon, title, body, link, source, dedupe_key, created_at)
         VALUES
            (:company_id, :category, :severity, :priority, :icon, :title, :body, :link, :source, :dedupe_key, :created_at)'
    );

    $newIds = [];
    foreach ($planned as $p) {
        $insert->execute([
            ':company_id' => $p['company_id'],
            ':category'   => $p['category'],
            ':severity'   => $p['severity'],
            ':priority'   => $p['priority'],
            ':icon'       => $p['icon'],
            ':title'      => $p['title'],
            ':body'       => $p['body'],
            ':link'       => $p['link'],
            ':source'     => 'omie_webhook',
            ':dedupe_key' => $p['dedupe_key'],
            ':created_at' => $p['created_at'],
        ]);
        if ($p['ts'] < $unreadCut) {
            $newIds[] = (int) $pdo->lastInsertId(); // histórico: já entra lido
        }
    }

    // Marca o histórico como lido para TODOS os usuários — senão o sininho
    // abriria com centenas de "não lidas" de dias atrás. Um único INSERT ... SELECT
    // (CROSS JOIN) em vez de uma query por notificação × usuário.
    $marked = 0;
    if ($newIds !== []) {
        $in = implode(',', array_map('intval', $newIds));
        $marked = (int) $pdo->exec(
            "INSERT IGNORE INTO biomie_notification_reads (notification_id, user_id, read_at)
             SELECT n.id, u.id, NOW()
               FROM biomie_notifications n
         CROSS JOIN biomie_users u
              WHERE n.id IN ({$in})"
        );
    }

    $pdo->commit();

    echo 'APLICADO: ' . count($planned) . ' notificações criadas, ' . count($ids) . ' antigas removidas.' . PHP_EOL;
    echo "Marcações de leitura (histórico): {$marked}\n";
    echo 'Não-lidas restantes: ' . ($willBeUnread) . PHP_EOL;
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'FALHOU: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
