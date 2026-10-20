CREATE INDEX idx_loading_equipment_id ON carregamentos (equipment_id, id);
CREATE INDEX idx_reading_loading_result ON leituras (carregamento_id, result);
CREATE INDEX idx_item_romaneio_truck ON romaneio_items (romaneio_id, truck_id);
CREATE INDEX idx_audit_company_entity ON audit_logs (company_id, entity_type, entity_id);
