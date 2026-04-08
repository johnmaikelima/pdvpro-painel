ALTER TABLE licencas ADD COLUMN asaas_subscription_id VARCHAR(100) NULL AFTER observacoes;
ALTER TABLE licencas ADD COLUMN inadimplente_desde DATE NULL AFTER asaas_subscription_id;
ALTER TABLE licencas ADD INDEX idx_subscription (asaas_subscription_id);

ALTER TABLE pagamentos ADD COLUMN asaas_subscription_id VARCHAR(100) NULL AFTER asaas_url;
ALTER TABLE pagamentos ADD COLUMN tipo_cobranca ENUM('avulso','recorrente') DEFAULT 'avulso' AFTER asaas_subscription_id;

INSERT IGNORE INTO configuracoes (chave, valor) VALUES
('inadimplencia_aviso_dias', '3'),
('inadimplencia_alerta_dias', '7'),
('inadimplencia_bloqueio_dias', '15'),
('inadimplencia_cancelamento_dias', '30');
