ALTER TABLE instalacoes_industriais
  ADD COLUMN access_blocked_at DATETIME(3) NULL AFTER sync_token_hash,
  ADD COLUMN archived_at DATETIME(3) NULL AFTER access_blocked_at,
  ADD KEY idx_instalacao_industrial_empresa_arquivo (company_id, archived_at, id);
