ALTER TABLE pagamentos ADD COLUMN asaas_id VARCHAR(100) NULL AFTER referencia;
ALTER TABLE pagamentos ADD COLUMN asaas_url VARCHAR(500) NULL AFTER asaas_id;
ALTER TABLE pagamentos ADD INDEX idx_asaas_id (asaas_id);

ALTER TABLE clientes ADD COLUMN asaas_customer_id VARCHAR(100) NULL AFTER api_token;
