-- Fila local entre a aplicação e o gateway industrial.
-- O servidor nunca escreve diretamente no CLP: somente o gateway autenticado
-- consome esta fila após validar o mapa de I/O e os intertravamentos.
CREATE TABLE IF NOT EXISTS plc_command_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  equipment_id BIGINT UNSIGNED NOT NULL,
  carregamento_id BIGINT UNSIGNED NOT NULL,
  command ENUM('REVERSAO') NOT NULL,
  status ENUM(
    'PENDENTE',
    'PROCESSANDO',
    'APLICADO',
    'REJEITADO',
    'ERRO'
  ) NOT NULL DEFAULT 'PENDENTE',
  requested_by BIGINT UNSIGNED NOT NULL,
  requested_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  claimed_at DATETIME(3) NULL,
  completed_at DATETIME(3) NULL,
  response_message VARCHAR(1000) NULL,
  KEY idx_plc_command_claim (equipment_id, status, requested_at),
  KEY idx_plc_command_loading (carregamento_id, status),
  CONSTRAINT fk_plc_command_company FOREIGN KEY (company_id) REFERENCES companies (id),
  CONSTRAINT fk_plc_command_equipment FOREIGN KEY (equipment_id) REFERENCES equipments (id),
  CONSTRAINT fk_plc_command_loading FOREIGN KEY (carregamento_id) REFERENCES carregamentos (id),
  CONSTRAINT fk_plc_command_user FOREIGN KEY (requested_by) REFERENCES users (id)
);
