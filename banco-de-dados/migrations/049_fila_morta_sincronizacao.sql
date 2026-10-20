-- Separa eventos que excederam as tentativas normais da fila operacional.
-- O payload é preservado para reprocessamento ou investigação posterior.
CREATE TABLE IF NOT EXISTS sync_dead_letter_queue (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  original_event_id BIGINT UNSIGNED NOT NULL,
  company_id BIGINT UNSIGNED NULL,
  event_uuid CHAR(36) NOT NULL,
  event_type VARCHAR(100) NOT NULL,
  aggregate_id BIGINT UNSIGNED NOT NULL,
  payload JSON NOT NULL,
  last_error TEXT NOT NULL,
  failed_attempts INT UNSIGNED NOT NULL,
  moved_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at TIMESTAMP NULL,
  resolved_by BIGINT UNSIGNED NULL,
  resolution_note VARCHAR(1000) NULL,
  UNIQUE KEY uq_sync_dead_letter_event (original_event_id),
  KEY idx_sync_dead_letter_company_status (company_id, resolved_at, moved_at),
  CONSTRAINT fk_sync_dead_letter_company FOREIGN KEY (company_id)
    REFERENCES empresas (id) ON DELETE SET NULL,
  CONSTRAINT fk_sync_dead_letter_user FOREIGN KEY (resolved_by)
    REFERENCES usuarios (id) ON DELETE SET NULL
);
