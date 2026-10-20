CREATE TABLE IF NOT EXISTS licenses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  plan_name VARCHAR(100) NOT NULL DEFAULT 'Trace Mensal',
  billing_period ENUM('MENSAL') NOT NULL DEFAULT 'MENSAL',
  status ENUM('ATIVA', 'INADIMPLENTE', 'BLOQUEADA', 'CANCELADA') NOT NULL DEFAULT 'ATIVA',
  due_at DATE NOT NULL,
  grace_until DATE NULL,
  blocked_at DATETIME NULL,
  blocked_reason VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_license_company FOREIGN KEY (company_id) REFERENCES companies (id),
  INDEX idx_license_company_status (company_id, status, due_at),
  INDEX idx_license_due_at (due_at)
);

INSERT INTO licenses (company_id, due_at)
SELECT c.id, DATE_ADD(CURRENT_DATE, INTERVAL 30 DAY)
FROM companies c
LEFT JOIN licenses l ON l.company_id = c.id
WHERE l.id IS NULL;
