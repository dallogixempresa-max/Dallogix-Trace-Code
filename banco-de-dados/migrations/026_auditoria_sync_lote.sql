-- Fecha a correlação entre auditoria e outbox sem tentar adivinhar vínculos
-- históricos que não possuíam um identificador comum.

ALTER TABLE logs_auditoria
  ADD COLUMN event_uuid CHAR(36) NULL AFTER id,
  ADD COLUMN delivered_at DATETIME(3) NULL AFTER created_at,
  ADD KEY idx_audit_company_event (company_id, event_uuid),
  ADD KEY idx_audit_delivered_created (delivered_at, created_at);
