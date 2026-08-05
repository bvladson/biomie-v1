<?php

declare(strict_types=1);

/**
 * Publica no sininho as leituras críticas calculadas pelo serviço Python.
 *
 * Roda logo depois de `biomie_intel insights` (ver
 * cron/hostinger/intelligence_insights.sh). É etapa DERIVADA: se falhar, as
 * leituras já foram gravadas e a home já está correta — por isso o job que
 * chama este script NÃO deve ser marcado como falho por causa daqui. Mesmo
 * princípio do commit f00e1c1 (a faxina que falha não derruba a missão).
 *
 * ─── POR QUE ELE CONTA "deduplicada" SEPARADO DE "falhou" ───────────────────
 * `NotificationService::push()` devolve `null` em três situações diferentes:
 * título vazio, deduplicação e **exceção engolida** (o catch da própria
 * service). Contar só "publicadas" tornaria uma tabela quebrada
 * indistinguível de um dia sem novidade: o job reportaria sucesso para sempre.
 * Aqui, todo `null` é conferido contra a janela de deduplicação; o que não for
 * dedup é falha, entra no relatório e muda o código de saída.
 *
 *   php bin/publish_intelligence_notifications.php [--dry-run] [--limit=50]
 *
 * Saída: JSON. Código 0 quando nada falhou, 1 quando alguma publicação falhou.
 */

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\CronLock;
use BIomie\Database;
use BIomie\Logger;
use BIomie\Notifications\NotificationService;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

/** Janela de deduplicação. O cron roda de 6 em 6h — ver a nota abaixo. */
const DEDUPE_WINDOW_SECONDS = 8 * 3600;
const EXPIRES_SECONDS = 24 * 3600;

$argv = $_SERVER['argv'] ?? [];
$dryRun = in_array('--dry-run', $argv, true);
$limit = 50;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', (string) $arg, $m) === 1) {
        $limit = max(1, min((int) $m[1], 500));
    }
}

$config = new Config();
$logger = new Logger(dirname(__DIR__) . '/storage/logs/biomie.log');
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = Database::connect($config, $connection);

// Lock de banco: impede que uma execução manual publique junto com o cron e
// crie notificação duplicada pela janela de dedup (SELECT-então-INSERT).
$lock = $dryRun ? null : CronLock::acquire($pdo, 'publish_intelligence_notifications', $logger);
if (!$dryRun && $lock === null) {
    echo json_encode([
        'ok' => true,
        'skipped' => 'lock_em_uso',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(0);
}

try {
    $notifications = new NotificationService($pdo);

    // Publicador global e deliberado: percorre TODAS as empresas, por isso não
    // passa por TenantContext. O isolamento vem do company_id gravado em cada
    // notificação — quem lê é filtrado por `listForUser()`.
    $stmt = $pdo->prepare(<<<'SQL'
        SELECT id, company_id, kind, severity, title, body, link_url, computed_at
          FROM intelligence_insights
         WHERE is_current = 1
           AND valid_until > UTC_TIMESTAMP()
           AND severity IN ('atencao', 'critico')
           AND (kind LIKE 'anomalia\_%' OR kind LIKE 'forecast\_%')
         ORDER BY FIELD(severity, 'critico', 'atencao'), company_id, kind
         LIMIT :limite
    SQL);
    $stmt->bindValue(':limite', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $published = 0;
    $deduplicated = 0;
    $failures = [];

    foreach ($rows as $row) {
        $companyId = (int) $row['company_id'];
        $severity = $row['severity'] === 'critico' ? 'critical' : 'warning';

        // A severidade entra na chave DE PROPÓSITO: sem ela, uma anomalia que
        // piora de 'atencao' para 'critico' dentro da janela de 8h ficaria
        // silenciada justamente quando passou a importar. O custo é oscilação
        // perto do limiar (risco ~0,45 / ~0,70) gerar dois avisos; silenciar
        // um agravamento é o erro pior dos dois.
        $dedupeKey = sprintf('intel:%d:%s:%s', $companyId, (string) $row['kind'], $severity);

        if ($dryRun) {
            ++$published;
            continue;
        }

        $id = $notifications->push([
            'company_id' => $companyId,
            'category' => 'business',
            'severity' => $severity,
            'priority' => 'high',
            'icon' => str_starts_with((string) $row['kind'], 'forecast_') ? 'monitoring' : 'warning',
            'title' => (string) $row['title'],
            'body' => (string) $row['body'],
            'link' => $row['link_url'] ?: 'index.php',
            'source' => 'python_intelligence',
            'dedupe_key' => $dedupeKey,
            'dedupe_window_seconds' => DEDUPE_WINDOW_SECONDS,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + EXPIRES_SECONDS),
        ]);

        if ($id !== null) {
            ++$published;
            continue;
        }

        // null ambíguo: só é dedup se existir mesmo uma notificação na janela.
        if (jaNotificadoNaJanela($pdo, $dedupeKey, $companyId, DEDUPE_WINDOW_SECONDS)) {
            ++$deduplicated;
            continue;
        }

        $failures[] = [
            'insight_id' => (int) $row['id'],
            'company_id' => $companyId,
            'kind' => (string) $row['kind'],
        ];
    }

    if ($failures !== []) {
        $logger->error('Publicação de notificações da inteligência falhou em parte.', [
            'candidatos' => count($rows),
            'publicadas' => $published,
            'falhas' => count($failures),
            'detalhe' => array_slice($failures, 0, 10),
        ]);
    }

    echo json_encode([
        'ok' => $failures === [],
        'dry_run' => $dryRun,
        'candidatos' => count($rows),
        'publicadas' => $published,
        'deduplicadas' => $deduplicated,
        'falhas' => count($failures),
        'detalhe_falhas' => array_slice($failures, 0, 10),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;

    exit($failures === [] ? 0 : 1);
} finally {
    $lock?->release();
}

/**
 * Espelha `NotificationService::recentlyPushed()`, que é privada. Se as duas
 * divergirem, uma falha vira "deduplicada" e some do relatório.
 */
function jaNotificadoNaJanela(PDO $pdo, string $dedupeKey, int $companyId, int $windowSeconds): bool
{
    $companyCond = $companyId > 0 ? 'company_id = :cid' : 'company_id IS NULL';
    $stmt = $pdo->prepare(
        "SELECT 1 FROM biomie_notifications
          WHERE dedupe_key = :k AND {$companyCond}
            AND created_at >= (NOW() - INTERVAL {$windowSeconds} SECOND)
          LIMIT 1"
    );
    $params = [':k' => substr($dedupeKey, 0, 191)];
    if ($companyId > 0) {
        $params[':cid'] = $companyId;
    }
    $stmt->execute($params);

    return $stmt->fetchColumn() !== false;
}
