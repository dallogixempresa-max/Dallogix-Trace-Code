-- Padronização da estrutura física do banco com os nomes usados no Trace.
-- Cada renomeação é condicional: instalações antigas podem já não ter machines/esteiras.
DELIMITER //
CREATE PROCEDURE renomear_tabela_trace(IN nome_atual VARCHAR(64), IN nome_novo VARCHAR(64))
BEGIN
  IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = nome_atual)
     AND NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = nome_novo) THEN
    SET @sql_renomear_trace = CONCAT('RENAME TABLE `', nome_atual, '` TO `', nome_novo, '`');
    PREPARE comando_renomear_trace FROM @sql_renomear_trace;
    EXECUTE comando_renomear_trace;
    DEALLOCATE PREPARE comando_renomear_trace;
  END IF;
END //
DELIMITER ;

CALL renomear_tabela_trace('companies', 'empresas');
CALL renomear_tabela_trace('users', 'usuarios');
CALL renomear_tabela_trace('products', 'produtos');
CALL renomear_tabela_trace('product_codes', 'codigos_produtos');
CALL renomear_tabela_trace('equipments', 'equipamentos');
CALL renomear_tabela_trace('romaneio_trucks', 'romaneio_caminhoes');
CALL renomear_tabela_trace('romaneio_items', 'romaneio_itens');
CALL renomear_tabela_trace('sensor_events', 'eventos_sensor');
CALL renomear_tabela_trace('device_status', 'status_dispositivos');
CALL renomear_tabela_trace('audit_logs', 'logs_auditoria');
CALL renomear_tabela_trace('sync_queue', 'fila_sincronizacao');
CALL renomear_tabela_trace('plc_command_requests', 'solicitacoes_comandos_clp');
CALL renomear_tabela_trace('camera_capture_requests', 'solicitacoes_captura_camera');
CALL renomear_tabela_trace('company_settings', 'configuracoes_empresa');
CALL renomear_tabela_trace('licenses', 'licencas');
DROP PROCEDURE renomear_tabela_trace;
