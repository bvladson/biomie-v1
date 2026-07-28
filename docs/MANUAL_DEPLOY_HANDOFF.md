# Entrega para deploy manual

O deploy será executado pelo responsável do ambiente. Nenhum upload remoto foi
feito durante esta implementação.

## Antes de enviar

1. rotacionar ou confirmar a credencial SSH compartilhada;
2. gerar backup do banco e validar o arquivo;
3. salvar cópia dos arquivos que serão substituídos;
4. conferir `docs/generated/manual-deploy-manifest.json`;
5. não enviar `config/env.php`, logs, caches ou `storage/tmp`.

## Ordem

1. enviar código e ferramentas;
2. conferir SHA-256 remoto;
3. não aplicar as seis migrations neste deploy;
4. executar lint, PHPStan core e teste de entrada;
5. executar guard `--read-only`;
6. validar login, contexto de empresa, Agenda e healthz público;
7. observar logs sem expor payloads ou credenciais.

## Rollback

Restaurar os arquivos salvos antes do upload. Como nenhuma migration faz parte
deste pacote, o rollback é somente de arquivos.

