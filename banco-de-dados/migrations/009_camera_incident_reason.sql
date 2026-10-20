ALTER TABLE camera_capture_requests
ADD COLUMN reason VARCHAR(80) NOT NULL DEFAULT 'SEM_LEITURA' AFTER equipment_id;
