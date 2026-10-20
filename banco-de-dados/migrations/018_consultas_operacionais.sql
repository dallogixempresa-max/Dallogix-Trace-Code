-- Índices para as consultas mais frequentes do dashboard, monitoramento e histórico.
-- A ordem acompanha os predicados de empresa e a ordenação por recência.
CREATE INDEX idx_romaneio_company_date_status
  ON romaneios (company_id, scheduled_date, status);

CREATE INDEX idx_occurrence_company_id
  ON ocorrencias (company_id, id);

CREATE INDEX idx_audit_company_id
  ON audit_logs (company_id, id);

CREATE INDEX idx_loading_company_equipment_id
  ON carregamentos (company_id, equipment_id, id);
