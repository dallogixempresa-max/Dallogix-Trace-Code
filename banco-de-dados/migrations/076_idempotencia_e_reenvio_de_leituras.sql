SET NAMES utf8mb4;

DELIMITER //
CREATE PROCEDURE trace_leituras_evento_sync()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'leituras'
      AND column_name = 'sync_event_uuid'
  ) THEN
    ALTER TABLE leituras
      ADD COLUMN sync_event_uuid VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL
      AFTER sensor_event_id;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'leituras'
      AND index_name = 'uq_leitura_sync_evento'
  ) THEN
    ALTER TABLE leituras
      ADD UNIQUE KEY uq_leitura_sync_evento (company_id, sync_event_uuid);
  END IF;
END//
DELIMITER ;

CALL trace_leituras_evento_sync();
DROP PROCEDURE trace_leituras_evento_sync;

CREATE TABLE IF NOT EXISTS sync_backfill_runs (
  backfill_key VARCHAR(80) PRIMARY KEY,
  affected_events INT UNSIGNED NOT NULL DEFAULT 0,
  completed_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
