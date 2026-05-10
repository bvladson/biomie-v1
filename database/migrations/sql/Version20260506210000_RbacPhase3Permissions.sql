-- Fase 3 - RBAC granular para paginas, APIs, jobs, logs e integracoes.
-- Expansiva e idempotente: nao remove permissoes antigas.

INSERT INTO biomie_permissions (permission_key, name, description, group_key, is_active)
VALUES
  ('sales.view', 'Ver comercial', 'Acessar dashboards comerciais, vendedores, pedidos e produtos.', 'comercial', 1),
  ('sales.sync', 'Sincronizar comercial', 'Executar atualizacao comercial e materializacoes de vendas.', 'comercial', 1),
  ('sales.export', 'Exportar comercial', 'Exportar dados comerciais.', 'comercial', 1),
  ('commercial.view', 'Ver comercial legado', 'Alias legado para acesso comercial.', 'comercial', 1),
  ('dashboard.view', 'Ver dashboard', 'Acessar a visao comercial principal.', 'comercial', 1),

  ('finance.view', 'Ver financeiro', 'Acessar recebiveis e previsao financeira.', 'financeiro', 1),
  ('financial.view', 'Ver financeiro legado', 'Alias legado para acesso financeiro.', 'financeiro', 1),
  ('finance.sync', 'Sincronizar financeiro', 'Executar atualizacao financeira e titulos Omie.', 'financeiro', 1),
  ('finance.export', 'Exportar financeiro', 'Exportar dados financeiros.', 'financeiro', 1),

  ('fiscal.view', 'Ver fiscal', 'Acessar notas, reconciliacao e indicadores fiscais.', 'fiscal', 1),
  ('fiscal.sync', 'Sincronizar fiscal', 'Executar rotinas fiscais e reconciliacoes.', 'fiscal', 1),
  ('fiscal.export', 'Exportar fiscal', 'Exportar dados fiscais.', 'fiscal', 1),
  ('nf.view', 'Ver notas fiscais', 'Acessar notas fiscais e mix faturado.', 'fiscal', 1),
  ('reconciliation.view', 'Ver reconciliacao legado', 'Alias legado para acesso fiscal/reconciliacao.', 'fiscal', 1),

  ('geo.view', 'Ver geografia', 'Acessar analises geograficas.', 'comercial', 1),
  ('geo.materialize', 'Materializar geografia', 'Executar materializacoes geograficas.', 'operacao', 1),

  ('omie.view', 'Ver status Omie', 'Acessar status local da integracao Omie.', 'omie', 1),
  ('omie.sync', 'Sincronizar Omie', 'Executar sincronizacoes com Omie.', 'omie', 1),

  ('jobs.view', 'Ver jobs', 'Acessar status de filas, syncs e rotinas.', 'operacao', 1),
  ('jobs.run', 'Executar jobs', 'Executar materializacoes, filas e rotinas operacionais.', 'operacao', 1),

  ('logs.view', 'Ver logs', 'Consultar logs administrativos e observabilidade.', 'sistema', 1),
  ('admin.view', 'Ver administracao', 'Acessar area administrativa.', 'sistema', 1),
  ('admin.manage', 'Gerenciar administracao', 'Gerenciar usuarios, permissoes e configuracoes criticas.', 'sistema', 1),
  ('settings.view', 'Ver configuracoes', 'Acessar configuracoes do sistema.', 'sistema', 1),
  ('settings.manage', 'Gerenciar configuracoes', 'Alterar configuracoes, politicas e rotinas internas.', 'sistema', 1),

  ('admin.users', 'Administrar usuarios', 'Criar, editar, ativar e redefinir usuarios.', 'sistema', 1),
  ('admin.migrations', 'Executar migrations', 'Listar e executar migrations do sistema.', 'sistema', 1),
  ('admin.logs', 'Ver logs administrativos', 'Consultar trilhas administrativas e diagnosticos.', 'sistema', 1),
  ('admin.console', 'Console administrativo', 'Executar comandos internos no console administrativo auditado.', 'sistema', 1),
  ('users.manage', 'Gerenciar usuarios legado', 'Alias legado para administracao de usuarios.', 'sistema', 1),

  ('documents.full', 'Ver documentos completos', 'Visualizar CPF/CNPJ sem mascaramento.', 'dados', 1),
  ('export', 'Exportar dados legado', 'Permissao legada generica de exportacao.', 'dados', 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  description = VALUES(description),
  group_key = VALUES(group_key),
  is_active = VALUES(is_active);

INSERT IGNORE INTO biomie_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM biomie_roles r
CROSS JOIN biomie_permissions p
WHERE r.role_key = 'admin';

INSERT IGNORE INTO biomie_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM biomie_roles r
JOIN biomie_permissions p ON p.permission_key IN (
  'sales.view', 'sales.sync', 'sales.export',
  'dashboard.view', 'commercial.view',
  'finance.view', 'financial.view', 'finance.export',
  'fiscal.view', 'nf.view', 'reconciliation.view', 'fiscal.export',
  'geo.view', 'geo.materialize',
  'omie.view',
  'jobs.view',
  'logs.view', 'admin.logs',
  'documents.full', 'export'
)
WHERE r.role_key = 'manager';

INSERT IGNORE INTO biomie_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM biomie_roles r
JOIN biomie_permissions p ON p.permission_key IN (
  'sales.view', 'dashboard.view', 'commercial.view',
  'finance.view', 'financial.view',
  'fiscal.view', 'nf.view', 'reconciliation.view',
  'geo.view',
  'omie.view',
  'jobs.view'
)
WHERE r.role_key = 'analyst';

INSERT IGNORE INTO biomie_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM biomie_roles r
JOIN biomie_permissions p ON p.permission_key IN (
  'sales.view', 'dashboard.view', 'commercial.view',
  'geo.view'
)
WHERE r.role_key = 'viewer';

INSERT IGNORE INTO biomie_user_roles (user_id, role_id)
SELECT u.id,
       r.id
FROM biomie_users u
JOIN biomie_roles r ON r.role_key = CASE
  WHEN LOWER(COALESCE(u.role, '')) IN ('admin', 'manager', 'analyst', 'viewer') THEN LOWER(u.role)
  WHEN LOWER(COALESCE(u.role, '')) = 'user' THEN 'analyst'
  ELSE 'viewer'
END
WHERE NOT EXISTS (
  SELECT 1 FROM biomie_user_roles existing WHERE existing.user_id = u.id
);

