-- Fase 4 - Administracao BIomie MAG com RBAC, logs e migrations.
-- Expansiva e idempotente: nao remove nem renomeia colunas existentes.

ALTER TABLE biomie_users
  ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role;

ALTER TABLE biomie_users
  ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL AFTER is_active;

ALTER TABLE biomie_users
  ADD COLUMN IF NOT EXISTS password_changed_at DATETIME NULL AFTER last_login_at;

ALTER TABLE biomie_users
  ADD COLUMN IF NOT EXISTS updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

ALTER TABLE biomie_users
  ADD INDEX IF NOT EXISTS idx_biomie_users_active (is_active);

CREATE TABLE IF NOT EXISTS biomie_roles (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  role_key VARCHAR(40) NOT NULL,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 100,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_biomie_roles_key (role_key),
  KEY idx_biomie_roles_active (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS biomie_permissions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  permission_key VARCHAR(80) NOT NULL,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  group_key VARCHAR(40) NOT NULL DEFAULT 'sistema',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_biomie_permissions_key (permission_key),
  KEY idx_biomie_permissions_group (group_key, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS biomie_role_permissions (
  role_id INT UNSIGNED NOT NULL,
  permission_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (role_id, permission_id),
  KEY idx_biomie_role_permissions_permission (permission_id),
  CONSTRAINT fk_biomie_role_permissions_role
    FOREIGN KEY (role_id) REFERENCES biomie_roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_biomie_role_permissions_permission
    FOREIGN KEY (permission_id) REFERENCES biomie_permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS biomie_user_roles (
  user_id INT UNSIGNED NOT NULL,
  role_id INT UNSIGNED NOT NULL,
  assigned_by INT UNSIGNED DEFAULT NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, role_id),
  KEY idx_biomie_user_roles_role (role_id),
  KEY idx_biomie_user_roles_assigned_by (assigned_by),
  CONSTRAINT fk_biomie_user_roles_user
    FOREIGN KEY (user_id) REFERENCES biomie_users(id) ON DELETE CASCADE,
  CONSTRAINT fk_biomie_user_roles_role
    FOREIGN KEY (role_id) REFERENCES biomie_roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_biomie_user_roles_assigned_by
    FOREIGN KEY (assigned_by) REFERENCES biomie_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS biomie_admin_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_user_id INT UNSIGNED DEFAULT NULL,
  target_user_id INT UNSIGNED DEFAULT NULL,
  event_type VARCHAR(60) NOT NULL,
  route VARCHAR(255) DEFAULT NULL,
  ip_address VARCHAR(45) DEFAULT NULL,
  user_agent VARCHAR(255) DEFAULT NULL,
  details_json LONGTEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_biomie_admin_logs_actor (actor_user_id, created_at),
  KEY idx_biomie_admin_logs_target (target_user_id, created_at),
  KEY idx_biomie_admin_logs_event (event_type, created_at),
  CONSTRAINT fk_biomie_admin_logs_actor
    FOREIGN KEY (actor_user_id) REFERENCES biomie_users(id) ON DELETE SET NULL,
  CONSTRAINT fk_biomie_admin_logs_target
    FOREIGN KEY (target_user_id) REFERENCES biomie_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO biomie_roles (role_key, name, description, is_system, is_active, sort_order)
VALUES
  ('admin', 'Administrador', 'Acesso total a administracao, dados, sincronizacao e migrations.', 1, 1, 10),
  ('manager', 'Gestor', 'Gestao operacional com leitura ampla e logs administrativos.', 1, 1, 20),
  ('analyst', 'Analista', 'Analise comercial, geografica, financeira e fiscal.', 1, 1, 30),
  ('viewer', 'Visualizador', 'Leitura basica do dashboard e geografia.', 1, 1, 40)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  description = VALUES(description),
  is_system = VALUES(is_system),
  is_active = VALUES(is_active),
  sort_order = VALUES(sort_order);

INSERT INTO biomie_permissions (permission_key, name, description, group_key, is_active)
VALUES
  ('dashboard.view', 'Ver dashboard', 'Acessar a visao comercial principal.', 'comercial', 1),
  ('geo.view', 'Ver geografia', 'Acessar analises geograficas.', 'comercial', 1),
  ('geo.materialize', 'Materializar geografia', 'Executar materializacoes geograficas.', 'operacao', 1),
  ('finance.view', 'Ver financeiro', 'Acessar recebiveis e previsao financeira.', 'operacao', 1),
  ('nf.view', 'Ver notas fiscais', 'Acessar notas fiscais e mix faturado.', 'operacao', 1),
  ('omie.sync', 'Sincronizar Omie', 'Executar sincronizacoes com Omie.', 'operacao', 1),
  ('admin.users', 'Administrar usuarios', 'Criar, editar, ativar e redefinir usuarios.', 'sistema', 1),
  ('admin.migrations', 'Executar migrations', 'Listar e executar migrations do sistema.', 'sistema', 1),
  ('admin.logs', 'Ver logs administrativos', 'Consultar trilhas administrativas e diagnosticos.', 'sistema', 1)
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
  'dashboard.view',
  'geo.view',
  'geo.materialize',
  'finance.view',
  'nf.view',
  'admin.logs'
)
WHERE r.role_key = 'manager';

INSERT IGNORE INTO biomie_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM biomie_roles r
JOIN biomie_permissions p ON p.permission_key IN (
  'dashboard.view',
  'geo.view',
  'finance.view',
  'nf.view'
)
WHERE r.role_key = 'analyst';

INSERT IGNORE INTO biomie_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM biomie_roles r
JOIN biomie_permissions p ON p.permission_key IN (
  'dashboard.view',
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
