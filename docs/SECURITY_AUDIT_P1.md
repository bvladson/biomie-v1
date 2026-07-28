# Auditoria de segurança P1

## Fronteiras comprovadas

- `api/bootstrap.php` exige login, inicializa o contexto tenant, aplica CSRF
  globalmente a métodos mutáveis e bloqueia escrita no contexto consolidado.
- Endpoints de escrita reforçam capability e, em muitos casos, CSRF localmente.
- `api/omie_webhook.php` é uma integração externa sem cookie de sessão; usa token
  dedicado, comparação constante e falha fechada em produção.
- `healthz.php` entrega publicamente somente `ok` e timestamp. Diagnóstico
  detalhado exige token derivado ou loopback.

## Evidências geradas

- `docs/generated/endpoint-security-matrix.json`
- `docs/generated/frontend-security-inventory.json`
- `config/security-html-allowlist.json`

A matriz usa análise estática conservadora. “Review required” significa que a
proteção não foi comprovada pelo scanner, não que exista vulnerabilidade.

## Ratchet de HTML e SQL

O inventário registra usos dinâmicos de `innerHTML`/`insertAdjacentHTML` e
`SELECT *` nas APIs. A allowlist representa dívida existente, não aprovação de
segurança. Uma ocorrência nova ou alterada falha:

```bash
php tools/dev/frontend_security_inventory.php --enforce
```

Remover uma ocorrência é sempre permitido. Atualizar a allowlist exige revisão
manual da origem do conteúdo, escaping e necessidade de HTML rico.

