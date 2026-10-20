ALTER TABLE eventos_sensor
  ADD COLUMN device_detected_at DATETIME(3) NULL AFTER detected_at;
