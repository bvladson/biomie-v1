# Artefatos Legados

Este diretório concentra material que foi removido do runtime oficial do BIomie, mas ainda vale preservar por histórico e auditoria.

## Conteúdo

- `root-html/`: cópias antigas das telas que antes ficavam na raiz do projeto.
- `patches/`: diffs históricos exportados durante etapas anteriores de evolução.
- `PATCH_NOTES_COMPETENCIA_COMERCIAL.md`: nota histórica da entrega da competência comercial.

## Regra

Nada daqui entra no pacote oficial de deploy.
O runtime oficial usa:

- controladores `.php` na raiz
- templates em `views/`
- APIs em `api/`
- jobs em `cron/`
