-- Índices para as consultas limitadas do monitoramento e para os últimos eventos
-- por empresa. As colunas de empresa vêm primeiro para manter o isolamento barato.
ALTER TABLE romaneios
  ADD KEY idx_romaneio_company_created (company_id, created_at, status);

ALTER TABLE carregamentos
  ADD KEY idx_loading_company_id (company_id, id);

ALTER TABLE ocorrencias
  ADD KEY idx_occurrence_company_created (company_id, created_at);
