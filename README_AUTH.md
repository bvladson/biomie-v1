# BIomie - autenticação simples

## O que foi adicionado

- `login.php`: tela de login e setup do primeiro administrador.
- `logout.php`: encerra a sessão.
- `admin.php`: painel para criar, editar, ativar/desativar usuários e redefinir senhas.
- `app/Auth.php`: sessão, login, logout, CSRF, timeout e autorização.
- `app/AuthRepository.php`: criação automática das tabelas `biomie_users` e `biomie_access_logs`.
- `app/PageRenderer.php`: injeta usuário logado, botão de sair e link do admin nas páginas do cockpit.
- Proteção nas APIs existentes via sessão.
- Conversão das telas públicas para wrappers `.php` protegidos.

## Fluxo de implantação

1. Suba os arquivos do projeto atualizados.
2. Acesse `/login.php`.
3. Na primeira execução, crie o primeiro administrador.
4. Entre no sistema e use `/admin.php` para criar os demais usuários.

## Tabelas criadas automaticamente

- `biomie_users`
- `biomie_access_logs`

## Regras atuais

- Perfis: `admin` e `user`
- Timeout por inatividade: 2 horas
- Bloqueio simples após muitas tentativas inválidas recentes
- Logs de: criação do primeiro admin, login válido, login inválido, bloqueio, logout, criação/edição de usuário e reset de senha

## URLs principais

- `/login.php`
- `/logout.php`
- `/admin.php`
- `/index.php`

## Observação

As páginas antigas `.html` foram movidas para `views/` e o acesso principal agora é pelas versões `.php`.
