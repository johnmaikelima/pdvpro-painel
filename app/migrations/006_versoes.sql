CREATE TABLE IF NOT EXISTS versoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    versao VARCHAR(20) NOT NULL COMMENT '1.0.0, 1.1.0, etc',
    tipo_produto ENUM('desktop', 'saas') NOT NULL DEFAULT 'desktop',
    arquivo_nome VARCHAR(255) NULL,
    arquivo_path VARCHAR(500) NULL,
    arquivo_tamanho BIGINT DEFAULT 0,
    changelog TEXT NULL COMMENT 'O que mudou nesta versao',
    obrigatoria TINYINT(1) DEFAULT 0 COMMENT 'Forca atualizacao',
    ativa TINYINT(1) DEFAULT 1,
    downloads INT DEFAULT 0,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
