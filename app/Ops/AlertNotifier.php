<?php

declare(strict_types=1);

namespace BIomie\Ops;

use BIomie\Config;
use BIomie\Logger;
use BIomie\Support\CaBundleResolver;

/**
 * Canal de alerta operacional para o sentinela de sync.
 *
 * Suporta (todos opcionais, configuraveis via env):
 *   - Telegram        (ops.alert_telegram_bot_token + ops.alert_telegram_chat_id)
 *   - Webhook generico (ops.alert_webhook_url) — POST JSON (Slack/Discord/n8n/Zapier)
 *
 * Sempre grava no log e devolve o resultado por canal. Se nenhum canal estiver
 * configurado, o alerta ainda fica registrado no log e no status file — nada
 * quebra por falta de configuracao.
 */
final class AlertNotifier
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param list<string> $lines
     * @return array<string,mixed>
     */
    public function send(string $severity, string $title, array $lines): array
    {
        $severity = strtolower(trim($severity)) ?: 'info';
        $emoji = match ($severity) {
            'critical' => '🔴',
            'warning'  => '🟠',
            'ok', 'recovered' => '✅',
            default    => 'ℹ️',
        };
        $body = trim($title . "\n" . implode("\n", array_map(
            static fn (string $l): string => '• ' . $l,
            $lines,
        )));
        $text = $emoji . ' [BIomie ' . strtoupper($severity) . '] ' . $body;

        $this->logger->warning('OpsSentinel alerta.', [
            'severity' => $severity,
            'title' => $title,
            'lines' => $lines,
        ]);

        $results = [];
        $telegram = $this->sendTelegram($text);
        if ($telegram !== null) {
            $results['telegram'] = $telegram;
        }
        $webhook = $this->sendWebhook($severity, $title, $lines, $text);
        if ($webhook !== null) {
            $results['webhook'] = $webhook;
        }

        return [
            'severity' => $severity,
            'channels' => $results,
            'delivered' => $results !== [] && !in_array(false, array_column($results, 'ok'), true),
        ];
    }

    /** @return array{ok:bool,status:int}|null null = canal nao configurado */
    private function sendTelegram(string $text): ?array
    {
        $token = trim((string) $this->config->get('ops.alert_telegram_bot_token', ''));
        $chatId = trim((string) $this->config->get('ops.alert_telegram_chat_id', ''));
        if ($token === '' || $chatId === '') {
            return null;
        }

        return $this->post(
            'https://api.telegram.org/bot' . $token . '/sendMessage',
            ['chat_id' => $chatId, 'text' => $text, 'disable_web_page_preview' => true],
        );
    }

    /**
     * @param list<string> $lines
     * @return array{ok:bool,status:int}|null
     */
    private function sendWebhook(string $severity, string $title, array $lines, string $text): ?array
    {
        $url = trim((string) $this->config->get('ops.alert_webhook_url', ''));
        if ($url === '') {
            return null;
        }

        // Payload generico compativel com Slack/Discord ("text") e consumidores custom.
        return $this->post($url, [
            'text' => $text,
            'content' => $text,
            'severity' => $severity,
            'title' => $title,
            'lines' => $lines,
            'source' => 'biomie-ops-sentinel',
            'sent_at' => date(DATE_ATOM),
        ]);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{ok:bool,status:int}
     */
    private function post(string $url, array $payload): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'status' => 0];
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json !== false ? $json : '{}',
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $ca = CaBundleResolver::resolve();
        if (is_string($ca) && is_file($ca)) {
            curl_setopt($ch, CURLOPT_CAINFO, $ca);
        }

        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0) {
            $this->logger->warning('OpsSentinel: falha ao enviar alerta.', ['url_host' => parse_url($url, PHP_URL_HOST), 'curl_errno' => $errno]);
        }

        return ['ok' => $errno === 0 && $status >= 200 && $status < 300, 'status' => $status];
    }
}
