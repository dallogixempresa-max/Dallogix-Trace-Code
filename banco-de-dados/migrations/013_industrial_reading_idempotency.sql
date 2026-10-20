-- Cada acionamento do sensor só pode gerar uma leitura operacional.
-- Valores NULL continuam permitidos para importações históricas sem sensor.
ALTER TABLE leituras
ADD UNIQUE KEY uq_reading_sensor_event (sensor_event_id);

-- Evita que comandos abandonados pelo gateway bloqueiem a operação para sempre.
ALTER TABLE plc_command_requests
ADD COLUMN expires_at DATETIME(3) NULL AFTER claimed_at,
ADD KEY idx_plc_command_expiration (status, expires_at);
