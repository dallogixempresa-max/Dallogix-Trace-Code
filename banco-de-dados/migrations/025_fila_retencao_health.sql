-- Isola a fila por empresa e prepara a operação contínua de sincronização.

ALTER TABLE fila_sincronizacao
  ADD COLUMN company_id BIGINT UNSIGNED NULL AFTER id,
  ADD COLUMN processing_started_at DATETIME(3) NULL AFTER status;

-- Filas antigas não guardavam a empresa. Só atribui automaticamente quando o
-- vínculo histórico é inequívoco; os demais registros ficam preservados para
-- inspeção antes de serem removidos da fila ativa.
UPDATE fila_sincronizacao q
JOIN (
  SELECT q2.id, MIN(a.company_id) AS company_id
  FROM fila_sincronizacao q2
  JOIN logs_auditoria a
    ON a.entity_type = q2.aggregate_type
   AND a.entity_id = q2.aggregate_id
  WHERE a.company_id IS NOT NULL
  GROUP BY q2.id
  HAVING COUNT(DISTINCT a.company_id) = 1
) owner ON owner.id = q.id
SET q.company_id = owner.company_id
WHERE q.company_id IS NULL;

CREATE TABLE IF NOT EXISTS fila_sincronizacao_orfas (
  original_id BIGINT UNSIGNED PRIMARY KEY,
  event_uuid CHAR(36) NOT NULL,
  aggregate_type VARCHAR(100) NOT NULL,
  aggregate_id BIGINT UNSIGNED NOT NULL,
  payload JSON NOT NULL,
  status VARCHAR(20) NOT NULL,
  attempts INT UNSIGNED NOT NULL,
  last_error TEXT NULL,
  available_at DATETIME NULL,
  created_at TIMESTAMP NULL,
  archived_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reason VARCHAR(255) NOT NULL
);

INSERT INTO fila_sincronizacao_orfas
  (original_id, event_uuid, aggregate_type, aggregate_id, payload, status,
   attempts, last_error, available_at, created_at, reason)
SELECT q.id, q.event_uuid, q.aggregate_type, q.aggregate_id, q.payload, q.status,
       q.attempts, q.last_error, q.available_at, q.created_at,
       'Não foi possível determinar a empresa com segurança.'
FROM fila_sincronizacao q
WHERE q.company_id IS NULL
  AND NOT EXISTS (
    SELECT 1 FROM fila_sincronizacao_orfas o WHERE o.original_id = q.id
  );

DELETE FROM fila_sincronizacao WHERE company_id IS NULL;

ALTER TABLE fila_sincronizacao
  MODIFY COLUMN company_id BIGINT UNSIGNED NOT NULL,
  ADD KEY idx_sync_company_status_available (company_id, status, available_at),
  ADD KEY idx_sync_processing_started (status, processing_started_at),
  ADD CONSTRAINT fk_sync_company FOREIGN KEY (company_id) REFERENCES empresas (id);

-- Apoia a consulta de saúde e as rotinas de retenção sem alterar o modelo
-- funcional das leituras e dos eventos.
ALTER TABLE logs_erros
  ADD KEY idx_error_created (criado_em);
