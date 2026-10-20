-- Ações configuráveis por Dala. A execução física continua exclusiva do gateway/CLP.
CREATE TABLE IF NOT EXISTS acoes_dala (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  equipment_id BIGINT UNSIGNED NOT NULL,
  comando VARCHAR(48) NOT NULL,
  rotulo VARCHAR(80) NOT NULL,
  cor ENUM('VERDE', 'VERMELHO', 'CINZA', 'AMBAR', 'AZUL') NOT NULL DEFAULT 'CINZA',
  visivel TINYINT(1) NOT NULL DEFAULT 1,
  modo ENUM('INCREMENTAL', 'DECREMENTAL', 'DIRETO') NOT NULL DEFAULT 'DIRETO',
  ordem INT UNSIGNED NOT NULL DEFAULT 1,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_acao_dala_comando (equipment_id, comando),
  KEY idx_acao_dala_ordem (equipment_id, ordem),
  CONSTRAINT fk_acao_dala_empresa FOREIGN KEY (company_id) REFERENCES empresas (id),
  CONSTRAINT fk_acao_dala_equipamento FOREIGN KEY (equipment_id) REFERENCES equipamentos (id)
);

-- A fila pode transportar as intenções configuradas; o gateway ainda valida o CLP.
ALTER TABLE solicitacoes_comandos_clp MODIFY COLUMN command VARCHAR(48) NOT NULL;

CREATE TABLE IF NOT EXISTS gatilhos_dala (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  equipment_id BIGINT UNSIGNED NOT NULL,
  evento VARCHAR(80) NOT NULL,
  acao_id BIGINT UNSIGNED NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_gatilho_dala_evento (equipment_id, evento),
  CONSTRAINT fk_gatilho_dala_empresa FOREIGN KEY (company_id) REFERENCES empresas (id),
  CONSTRAINT fk_gatilho_dala_equipamento FOREIGN KEY (equipment_id) REFERENCES equipamentos (id),
  CONSTRAINT fk_gatilho_dala_acao FOREIGN KEY (acao_id) REFERENCES acoes_dala (id) ON DELETE SET NULL
);

-- Falhas inesperadas, separadas da auditoria operacional e sem dados de senha/sessão.
CREATE TABLE IF NOT EXISTS logs_erros (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NULL,
  origem VARCHAR(120) NOT NULL,
  mensagem VARCHAR(1000) NOT NULL,
  contexto JSON NULL,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_log_erro_empresa_data (company_id, criado_em),
  CONSTRAINT fk_log_erro_empresa FOREIGN KEY (company_id) REFERENCES empresas (id) ON DELETE SET NULL,
  CONSTRAINT fk_log_erro_usuario FOREIGN KEY (user_id) REFERENCES usuarios (id) ON DELETE SET NULL
);
