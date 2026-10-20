-- Serializa o limite de ingestão por usuário sem depender de COUNT(*) sobre
-- logs_erros, que permitia exceder a cota em requisições concorrentes.
CREATE TABLE IF NOT EXISTS limites_logs_frontend (
  limiter_key CHAR(64) NOT NULL PRIMARY KEY,
  window_key BIGINT UNSIGNED NOT NULL,
  request_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_seen_at DATETIME(3) NOT NULL,
  KEY idx_frontend_log_limit_last_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
