-- Arquivamento mantém o histórico da empresa sem permitir novos acessos.
-- A exclusão definitiva continua sendo uma ação separada e explícita.
ALTER TABLE empresas
  ADD COLUMN archived_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at,
  ADD KEY idx_empresas_archived_name (archived_at, name);
