-- Estrutura da tabela de Orçamento
CREATE TABLE IF NOT EXISTS glpi_portal_orcamento (
    id INT AUTO_INCREMENT PRIMARY KEY,
    categoria VARCHAR(50) NOT NULL,
    descricao VARCHAR(255) NOT NULL,
    mes_ano VARCHAR(7) NOT NULL, -- YYYY-MM

    qty_prevista INT DEFAULT 1,
    unit_previsto DECIMAL(15,2) DEFAULT 0.00,
    total_prevista DECIMAL(15,2) GENERATED ALWAYS AS (qty_prevista * unit_previsto) STORED,

    qty_realizada INT DEFAULT 0,
    unit_realizado DECIMAL(15,2) DEFAULT 0.00,
    total_realizado DECIMAL(15,2) GENERATED ALWAYS AS (qty_realizada * unit_realizado) STORED,

    observacao TEXT,
    concluido TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
