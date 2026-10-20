ALTER TABLE fila_sincronizacao
  ADD COLUMN transient_attempts INT UNSIGNED NOT NULL DEFAULT 0 AFTER attempts;
