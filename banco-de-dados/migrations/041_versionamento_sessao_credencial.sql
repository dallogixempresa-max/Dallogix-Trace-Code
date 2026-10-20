-- Trocas de senha, perfil ou ativação invalidam imediatamente sessões antigas.
ALTER TABLE usuarios
  ADD COLUMN auth_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER must_change_password;
