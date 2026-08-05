<?php

declare(strict_types=1);

/**
 * CONTRATO — P2/Etapa 1: o erro de banco chega inteiro ao log.
 *
 * ─── O QUE ISTO IMPEDE DE VOLTAR ────────────────────────────────────────────
 * Em 2026-08-04 o sync falhava em ~60% dos runs e a causa era CEGA. Todo catch
 * externo gravava `SecretMask::sanitizeErrorMessage()`, cuja regex trocava o
 * trecho `SQLSTATE[...]: ...` por seis palavras:
 *
 *   SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded  ─┐
 *   SQLSTATE[HY000] [2002] Connection refused                        ─┤→ "Erro de
 *   SQLSTATE[23000]: ... 1062 Duplicate entry                        ─┤   banco de
 *   SQLSTATE[01000]: Warning: 1265 Data truncated for column         ─┘   dados"
 *
 * Quatro causas com respostas OPOSTAS viravam a mesma frase. Sem SQLSTATE e
 * código do driver não dá para agrupar as falhas — só supor.
 *
 * ─── O QUE ESTE CONTRATO EXIGE ──────────────────────────────────────────────
 * Preservar sem vazar, e sem mudar comportamento nenhum:
 *   1. SQLSTATE preservado           5. company_id e run_id acompanham
 *   2. driver_code registrado        6. erro desconhecido continua visível
 *   3. mensagem sanitizada           7. captura não altera rollback
 *   4. senha/segredo nunca no log    8. nenhuma política nova de retry
 *
 * Os itens 7 e 8 são verificados no CÓDIGO-FONTE: a Etapa 1 é só instrumentação.
 * Classificar e mexer em retry é Etapa 3, e só depois de observar dados reais —
 * retry mal coordenado durante recuperação amplia a falha, porque a Omie limita
 * por IP, por IP+App Key+método e por concorrência.
 *
 *   php tests/run_db_error_envelope_contract.php
 */

require_once __DIR__ . '/TestSupport.php';

use BIomie\Support\DatabaseErrorEnvelope;
use BIomie\Support\SecretMask;

$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$root = dirname(__DIR__);

/** Fabrica uma PDOException como o driver a entrega. */
$pdoError = static function (string $message, ?array $errorInfo = null): PDOException {
    $exception = new PDOException($message);
    if ($errorInfo !== null) {
        $exception->errorInfo = $errorInfo;
    }

    return $exception;
};

// ─────────────────────────────────────────────────────────── 1 e 2. SQLSTATE
// Caso canônico: errorInfo populado (erro de statement).
$lockTimeout = $pdoError(
    'SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction',
    ['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'],
);
$envelope = DatabaseErrorEnvelope::fromThrowable($lockTimeout);

$check('1. PDOException preserva SQLSTATE', ($envelope['sqlstate'] ?? null) === 'HY000', $envelope['sqlstate'] ?? null);
$check('2. driver_code é registrado', ($envelope['driver_code'] ?? null) === 1205, $envelope['driver_code'] ?? null);
$check('categoria é "database"', ($envelope['error_category'] ?? null) === 'database', $envelope['error_category'] ?? null);
$check(
    'a mensagem do driver sobrevive',
    str_contains((string) ($envelope['message_safe'] ?? ''), 'Lock wait timeout exceeded'),
    $envelope['message_safe'] ?? null,
);

// Falha de CONEXÃO não popula errorInfo — só sobra parsear a mensagem.
$connRefused = DatabaseErrorEnvelope::fromThrowable(
    $pdoError('SQLSTATE[HY000] [2002] Connection refused')
);
$check(
    'conexão recusada: SQLSTATE + código sem errorInfo',
    ($connRefused['sqlstate'] ?? null) === 'HY000'
        && ($connRefused['driver_code'] ?? null) === 2002
        && str_contains((string) $connRefused['message_safe'], 'Connection refused'),
    json_encode([$connRefused['sqlstate'] ?? null, $connRefused['driver_code'] ?? null, $connRefused['message_safe'] ?? null]),
);

// Violação de constraint — a resposta certa é OPOSTA à de um deadlock.
$duplicate = DatabaseErrorEnvelope::fromThrowable(
    $pdoError("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'x' for key 'uniq_doc'")
);
$check(
    'constraint: 23000/1062 separado do resto',
    ($duplicate['sqlstate'] ?? null) === '23000' && ($duplicate['driver_code'] ?? null) === 1062,
    json_encode([$duplicate['sqlstate'] ?? null, $duplicate['driver_code'] ?? null]),
);

// O erro real do incidente de 2026-08-04 (materialização do mart).
$truncated = DatabaseErrorEnvelope::fromThrowable(
    $pdoError("SQLSTATE[01000]: Warning: 1265 Data truncated for column 'tipo_pessoa' at row 30")
);
$check(
    'truncamento: 01000/1265 — o erro que ficou 40 runs escondido',
    ($truncated['sqlstate'] ?? null) === '01000'
        && ($truncated['driver_code'] ?? null) === 1265
        && str_contains((string) $truncated['message_safe'], 'tipo_pessoa'),
    json_encode([$truncated['sqlstate'] ?? null, $truncated['driver_code'] ?? null]),
);

// SQLSTATE sem código numérico do driver: não pode inventar um.
$noCode = DatabaseErrorEnvelope::fromThrowable(
    $pdoError('SQLSTATE[HY093]: Invalid parameter number: parameter was not defined')
);
$check(
    'SQLSTATE sem código do driver não inventa código',
    ($noCode['sqlstate'] ?? null) === 'HY093' && ($noCode['driver_code'] ?? null) === null,
    json_encode([$noCode['sqlstate'] ?? null, $noCode['driver_code'] ?? null]),
);

// A exceção lançada quase nunca é a PDOException: services embrulham.
$wrapped = DatabaseErrorEnvelope::fromThrowable(new RuntimeException(
    'Falha ao sincronizar pedidos.',
    0,
    $pdoError('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock', ['40001', 1213, 'Deadlock found when trying to get lock'])
));
$check(
    'cadeia getPrevious(): acha o SQLSTATE embrulhado',
    ($wrapped['sqlstate'] ?? null) === '40001'
        && ($wrapped['driver_code'] ?? null) === 1213
        && ($wrapped['exception_class'] ?? null) === RuntimeException::class
        && ($wrapped['cause_class'] ?? null) === PDOException::class,
    json_encode([$wrapped['sqlstate'] ?? null, $wrapped['exception_class'] ?? null, $wrapped['cause_class'] ?? null]),
);

// ────────────────────────────────────────────────────── 3 e 4. sanitização
$comSegredo = DatabaseErrorEnvelope::fromThrowable($pdoError(
    "SQLSTATE[28000]: Access denied for user 'biomie'@'localhost' (password=hunter2 app_secret=abc123XYZ)"
));
$serializado = (string) json_encode($comSegredo);

$check(
    '3. mensagem é sanitizada (credencial substituída)',
    str_contains($serializado, 'CREDENCIAL REMOVIDA'),
    $comSegredo['message_safe'] ?? null,
);
$check(
    '4. senha e app_secret NÃO aparecem no log',
    !str_contains($serializado, 'hunter2') && !str_contains($serializado, 'abc123XYZ'),
    $serializado,
);
$check(
    'stack trace não vai para o log',
    !str_contains(
        (string) DatabaseErrorEnvelope::fromThrowable(
            new RuntimeException("Quebrou aqui.\nStack trace:\n#0 /app/x.php(10): foo()")
        )['message_safe'],
        'Stack trace'
    ),
);

// ────────────────────────────────────────────────── 5. quem/onde acompanha
$comContexto = DatabaseErrorEnvelope::fromThrowable($lockTimeout, [
    'company_id' => 3,
    'run_id' => 91827,
    'mode' => 'delta',
    'domain' => 'comercial',
]);
$check(
    '5. company_id e run_id acompanham o erro',
    ($comContexto['company_id'] ?? null) === 3 && ($comContexto['run_id'] ?? null) === 91827,
    json_encode([$comContexto['company_id'] ?? null, $comContexto['run_id'] ?? null]),
);
$check(
    'mode e domain acompanham (separa "uma empresa" de "o banco inteiro")',
    ($comContexto['mode'] ?? null) === 'delta' && ($comContexto['domain'] ?? null) === 'comercial',
    json_encode([$comContexto['mode'] ?? null, $comContexto['domain'] ?? null]),
);
$check(
    'release e hostname registrados',
    array_key_exists('release', $comContexto) && array_key_exists('hostname', $comContexto),
    json_encode([$comContexto['release'] ?? null, $comContexto['hostname'] ?? null]),
);
// SHA errado é pior que SHA ausente: manda comparar o incidente com um commit
// que não existe. Só o formato completo do deploy (`<sha>-<data>_<hora>`) vale.
$release = (string) ($comContexto['release'] ?? '');
$sha = $comContexto['release_sha'] ?? null;
$temFormatoDeDeploy = preg_match('/^[0-9a-f]{7,40}-\d{8}_\d{6}$/i', $release) === 1;
$check(
    'release_sha só existe quando o release tem o formato do deploy',
    $temFormatoDeDeploy
        ? (is_string($sha) && str_starts_with($release, $sha))
        : $sha === null,
    json_encode(['release' => $release, 'release_sha' => $sha]),
);

// ───────────────────────────────────────── 6. erro desconhecido continua visível
$desconhecido = DatabaseErrorEnvelope::fromThrowable(
    new LogicException('Perfil de dominio inexistente: xpto')
);
$check(
    '6. erro desconhecido continua visível (não vira "database")',
    ($desconhecido['error_category'] ?? null) === 'application'
        && ($desconhecido['sqlstate'] ?? null) === null
        && str_contains((string) $desconhecido['message_safe'], 'xpto'),
    json_encode($desconhecido['message_safe'] ?? null),
);

// ───────────────────────────────────────────────── resumo de uma linha só
$captura = DatabaseErrorEnvelope::capture($lockTimeout, ['run_id' => 5, 'mode' => 'delta']);
$check(
    'summarize devolve "[SQLSTATE/code] mensagem"',
    str_starts_with($captura['summary'], '[HY000/1205] '),
    $captura['summary'],
);
// Este é o teste que prova que a informação chega na TELA: o resumo passa pelo
// sanitizador padrão (usado ao exibir error_summary) sem ser reapagado.
$check(
    '🔴 o resumo sobrevive ao sanitizador padrão da exibição',
    str_contains(SecretMask::sanitizeErrorMessage($captura['summary'], 300), 'HY000/1205')
        && !str_contains(SecretMask::sanitizeErrorMessage($captura['summary'], 300), 'Erro de banco de dados'),
    SecretMask::sanitizeErrorMessage($captura['summary'], 300),
);

// ─────────────────────────── regressão: as ~60 chamadas HTTP não mudaram
$mensagemCrua = 'SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded';
$check(
    '🔴 SecretMask padrão AINDA colapsa SQLSTATE (superfícies HTTP intactas)',
    SecretMask::sanitizeErrorMessage($mensagemCrua) === 'Erro de banco de dados',
    SecretMask::sanitizeErrorMessage($mensagemCrua),
);
$check(
    'collapse=false só é permitido explicitamente',
    str_contains(SecretMask::sanitizeErrorMessage($mensagemCrua, 500, false), 'SQLSTATE[HY000]'),
    SecretMask::sanitizeErrorMessage($mensagemCrua, 500, false),
);

// ────────────────────────────────────── nunca lança: roda dentro de catch
$naoLanca = true;
try {
    DatabaseErrorEnvelope::capture(new RuntimeException(str_repeat('ã', 5000)));
    DatabaseErrorEnvelope::capture(new RuntimeException(''));
    DatabaseErrorEnvelope::capture(new RuntimeException("\x00\x01binário"));
} catch (Throwable $e) {
    $naoLanca = false;
}
$check('o envelope nunca lança (é chamado de dentro de catch)', $naoLanca);

// ───────────────────── o tratador de falha não pode falhar ao gravar a falha
// `biomie_webhook_inbox.error_summary` e `biomie_jobs.error_summary` são
// VARCHAR(255) e o banco roda com STRICT_TRANS_TABLES (confirmado em produção).
// Estourar o tamanho ou cortar UTF-8 no meio derruba a escrita e apaga o erro
// original — a mesma família do sentinela de ENUM: STRICT recusa, não trunca.
$mensagemLonga = str_repeat('Falha ao sincronizar orçamentos não previstos. ', 20);
$truncada = SecretMask::sanitizeErrorMessage($mensagemLonga, 250);
$check(
    'resumo de webhook cabe em VARCHAR(255)',
    mb_strlen($truncada) <= 255,
    'tamanho=' . mb_strlen($truncada),
);
$check(
    'truncar não gera UTF-8 inválido (json_encode sobreviveria)',
    mb_check_encoding($truncada, 'UTF-8') && json_encode($truncada) !== false,
);

$fonteWebhookState = (string) file_get_contents($root . '/app/SyncPipelineRepository.php');
$check(
    'updateWebhookState não sanitiza para 500 numa coluna de 255',
    !preg_match('/updateWebhookState.{0,600}?sanitizeErrorMessage\(\$message,\s*500\)/s', $fonteWebhookState),
);

foreach (['app/Queue/DatabaseJobQueue.php', 'app/Queue/FileJobQueue.php'] as $arquivoFila) {
    $fonteFila = (string) file_get_contents($root . '/' . $arquivoFila);
    $check(
        sprintf('%s corta error_summary por caractere, não por byte', basename($arquivoFila)),
        !preg_match('/error_summary.{0,40}?\bsubstr\(/s', $fonteFila),
    );
}

// ────────────────────────────── 7. captura não altera rollback (estático)
// Varre só o CÓDIGO: comentário que menciona "commit" não é chamada a commit.
$codigoSemComentarios = static function (string $arquivo): string {
    $tokens = token_get_all((string) file_get_contents($arquivo));
    $saida = '';
    foreach ($tokens as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            $saida .= $token[1];
            continue;
        }
        $saida .= $token;
    }

    return $saida;
};

$codigoEnvelope = $codigoSemComentarios($root . '/app/Support/DatabaseErrorEnvelope.php');
$verbosProibidos = [];
foreach (['beginTransaction', 'commit', 'rollBack', '->exec(', '->query(', '->prepare('] as $verbo) {
    if (stripos($codigoEnvelope, $verbo) !== false) {
        $verbosProibidos[] = $verbo;
    }
}
$check(
    '7. o envelope não toca em transação nem executa SQL',
    $verbosProibidos === [],
    $verbosProibidos === [] ? 'nenhum verbo transacional no código' : implode(' | ', $verbosProibidos),
);

$instrumentados = [
    'app/OmieRealtimeSyncService.php' => 'runDomainWindow',
    'app/Sync/ReplaySyncCoordinator.php' => 'runDomainWindow',
];
foreach ($instrumentados as $arquivo => $metodo) {
    $fonte = (string) file_get_contents($root . '/' . $arquivo);
    $check(
        sprintf('7. %s ainda relança a exceção (fluxo de rollback intacto)', $arquivo),
        substr_count($fonte, 'throw $exception;') >= 1,
        $metodo,
    );
}

// ────────────────────────── 8. nenhuma política nova de retry (estático)
$arquivosComRetry = [
    'app/OmieRealtimeSyncService.php',
    'app/Sync/ReplaySyncCoordinator.php',
    'app/Sync/WebhookInboxProcessor.php',
];
$retryContaminado = [];
foreach ($arquivosComRetry as $arquivo) {
    $fonte = (string) file_get_contents($root . '/' . $arquivo);
    $inicio = strpos($fonte, 'function isRetryable');
    if ($inicio === false) {
        continue;
    }
    $fim = strpos($fonte, "\n    }", $inicio);
    $corpo = substr($fonte, $inicio, ($fim === false ? strlen($fonte) : $fim) - $inicio);
    foreach (['sqlstate', 'driver_code', 'DatabaseErrorEnvelope', 'error_detail'] as $proibido) {
        if (stripos($corpo, $proibido) !== false) {
            $retryContaminado[] = $arquivo . ' → ' . $proibido;
        }
    }
}
$check(
    '8. isRetryable NÃO passou a decidir por SQLSTATE (classificar é Etapa 3)',
    $retryContaminado === [],
    implode(' | ', $retryContaminado),
);

foreach ($arquivosComRetry as $arquivo) {
    $fonte = (string) file_get_contents($root . '/' . $arquivo);
    $check(
        sprintf('8. %s decide retry pela exceção original', basename($arquivo)),
        str_contains($fonte, '$this->isRetryable($exception)'),
    );
}

$passed = count(array_filter($checks, static fn (array $r): bool => $r['ok']));
$result = [
    'ok' => $passed === count($checks),
    'total' => count($checks),
    'passed' => $passed,
    'failed' => count($checks) - $passed,
    'checks' => $checks,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($result['ok'] ? 0 : 1);
