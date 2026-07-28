# Webhook Omie - BIomie

O endpoint `api/omie_webhook.php` aceita apenas `POST`.

## Token

Em producao, `OMIE_WEBHOOK_TOKEN` e obrigatorio. Token vazio em producao retorna erro de configuracao e o webhook nao e aceito.

Headers aceitos:

```text
X-Webhook-Token: TOKEN_FORTE
X-Omie-Webhook-Token: TOKEN_FORTE
```

Fallback por query string existe apenas para compatibilidade:

```text
https://dominio/biomie/api/omie_webhook.php?token=TOKEN_FORTE
```

Ao usar query string, nao publique a URL em docs publicas, prints ou logs. O BIomie mascara o parametro `token` nos metadados internos do webhook.

## Ambiente local

Webhook sem token so pode passar em local/dev com bypass explicito:

```text
APP_ENV=local
OMIE_WEBHOOK_TOKEN=
BIOMIE_ALLOW_INSECURE_WEBHOOK_LOCAL=1
```

Com bypass desligado, token vazio retorna `403`.

## Validacao rapida

```bash
curl -i -X POST \
  -H "Content-Type: application/json" \
  -H "X-Webhook-Token: TOKEN_TESTE" \
  -d '{"evento":"teste","domain":"commercial"}' \
  http://127.0.0.1:8080/api/omie_webhook.php
```

Resultado esperado:

- `401`: token ausente ou invalido.
- `403`: token vazio em local/dev sem bypass explicito.
- `500`: token vazio em producao, ou falha controlada de banco/pipeline depois da autenticacao.
- `202`: webhook autenticado, persistido e com processamento enfileirado.

O endpoint nao deve sincronizar Omie dentro do request. O caminho esperado e receber, gravar inbox, criar job `webhook.inbox.process` na fila `high` e responder rapido para nao bloquear a fila FIFO do Omie.
