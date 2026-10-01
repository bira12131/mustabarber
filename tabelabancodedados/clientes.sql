CREATE TABLE IF NOT EXISTS clientes (
    codigo SERIAL PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    telefone VARCHAR(20) NOT NULL,
    cod_barbeiro int not null,
    dtnow TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
