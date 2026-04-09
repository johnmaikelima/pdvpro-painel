-- Migration 007: Precos por periodo nos planos
ALTER TABLE planos ADD COLUMN preco_mensal DECIMAL(10,2) DEFAULT NULL AFTER preco;
ALTER TABLE planos ADD COLUMN preco_trimestral DECIMAL(10,2) DEFAULT NULL AFTER preco_mensal;
ALTER TABLE planos ADD COLUMN preco_semestral DECIMAL(10,2) DEFAULT NULL AFTER preco_trimestral;
ALTER TABLE planos ADD COLUMN preco_anual DECIMAL(10,2) DEFAULT NULL AFTER preco_semestral;
ALTER TABLE planos MODIFY slug VARCHAR(50) NOT NULL;
