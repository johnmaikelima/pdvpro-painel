-- ============================================
--   PDV Pro - Painel Admin - Schema MySQL
-- ============================================

-- Tabela de admins do painel
CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    senha VARCHAR(255) NOT NULL,
    ativo TINYINT(1) DEFAULT 1,
    ultimo_acesso DATETIME NULL,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Planos disponiveis
CREATE TABLE IF NOT EXISTS planos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(50) NOT NULL,
    slug VARCHAR(30) NOT NULL UNIQUE,
    tipo_produto ENUM('desktop', 'saas') NOT NULL DEFAULT 'desktop',
    periodo ENUM('mensal', 'trimestral', 'anual') NOT NULL DEFAULT 'mensal',
    preco DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    limite_nfce INT NOT NULL DEFAULT 0 COMMENT '0 = ilimitado',
    limite_terminais INT NOT NULL DEFAULT 1,
    recursos TEXT NULL COMMENT 'JSON com recursos do plano',
    ativo TINYINT(1) DEFAULT 1,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Clientes (empresas que usam o PDV)
CREATE TABLE IF NOT EXISTS clientes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    razao_social VARCHAR(200) NOT NULL,
    nome_fantasia VARCHAR(200) NULL,
    cnpj VARCHAR(18) NULL UNIQUE,
    cpf VARCHAR(14) NULL,
    email VARCHAR(150) NULL,
    telefone VARCHAR(20) NULL,
    whatsapp VARCHAR(20) NULL,
    contato_nome VARCHAR(100) NULL,
    cidade VARCHAR(100) NULL,
    uf CHAR(2) NULL,
    observacoes TEXT NULL,
    plano_id INT NULL,
    status ENUM('ativo', 'inativo', 'inadimplente', 'trial') DEFAULT 'trial',
    api_token VARCHAR(64) NULL UNIQUE COMMENT 'Token para API do desktop',
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (plano_id) REFERENCES planos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Licencas geradas
CREATE TABLE IF NOT EXISTS licencas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    chave VARCHAR(19) NOT NULL UNIQUE COMMENT 'XXXX-XXXX-XXXX-XXXX',
    cliente_id INT NULL,
    plano_id INT NULL,
    tipo ENUM('mensal', 'trimestral', 'anual') NOT NULL DEFAULT 'mensal',
    status ENUM('disponivel', 'ativa', 'expirada', 'revogada', 'bloqueada') DEFAULT 'disponivel',
    hardware_id VARCHAR(64) NULL COMMENT 'Hash da maquina do cliente',
    data_ativacao DATETIME NULL,
    data_vencimento DATETIME NULL,
    ultimo_check DATETIME NULL COMMENT 'Ultima verificacao via API',
    nfce_emitidas_mes INT DEFAULT 0,
    nfce_mes_referencia VARCHAR(7) NULL COMMENT 'YYYY-MM',
    ip_ativacao VARCHAR(45) NULL,
    observacoes TEXT NULL,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE SET NULL,
    FOREIGN KEY (plano_id) REFERENCES planos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Log de atividades da API
CREATE TABLE IF NOT EXISTS api_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    licenca_id INT NULL,
    cliente_id INT NULL,
    acao VARCHAR(50) NOT NULL COMMENT 'validar, ativar, reportar_nfce, checar_atualizacao',
    ip VARCHAR(45) NULL,
    request_data TEXT NULL COMMENT 'JSON do request',
    response_data TEXT NULL COMMENT 'JSON do response',
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (licenca_id) REFERENCES licencas(id) ON DELETE SET NULL,
    FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE SET NULL,
    INDEX idx_acao (acao),
    INDEX idx_criado (criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Historico de pagamentos
CREATE TABLE IF NOT EXISTS pagamentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    licenca_id INT NULL,
    plano_id INT NULL,
    valor DECIMAL(10,2) NOT NULL,
    forma ENUM('pix', 'boleto', 'cartao', 'manual') DEFAULT 'manual',
    status ENUM('pendente', 'pago', 'cancelado') DEFAULT 'pendente',
    referencia VARCHAR(100) NULL COMMENT 'ID do gateway, numero do boleto, etc',
    mes_referencia VARCHAR(7) NULL COMMENT 'YYYY-MM',
    data_pagamento DATETIME NULL,
    observacoes TEXT NULL,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
    FOREIGN KEY (licenca_id) REFERENCES licencas(id) ON DELETE SET NULL,
    FOREIGN KEY (plano_id) REFERENCES planos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Controle de migrations
CREATE TABLE IF NOT EXISTS migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    arquivo VARCHAR(255) NOT NULL,
    executado_em DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
