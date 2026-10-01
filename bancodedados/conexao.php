<?php
/**
 * Arquivo de Conexao com Banco de Dados PostgreSQL
 * Sistema: MustaBarber
 * Data: 29/01/2026
 * 
 * Este arquivo utiliza o DatabaseAssistente (Connection Pool Manager)
 * para gerenciar conexões de forma segura e eficiente.
 * 
 * O Assistente garante:
 * - Apenas uma conexão reutilizável (evita sobrecarga)
 * - Reconexão automática em caso de falha
 * - Health checks periódicos
 * - Conexões persistentes
 */

require_once __DIR__ . '/DatabaseAssistente.php';

try {
    // Obter instância única do Assistente
    $assistente = DatabaseAssistente::getInstance();
    
    // Obter conexão gerenciada
    $conexao = $assistente->getConexao();
    
    // Criar alias $conn para compatibilidade com código existente
    $conn = $conexao;

} catch (PDOException $e) {
    // Em caso de erro de conexao, lancar excecao que sera tratada pelo arquivo principal
    error_log("[Conexao] ERRO CRÍTICO: " . $e->getMessage());
    throw new PDOException("Falha na conexao com banco de dados: " . $e->getMessage(), (int)$e->getCode());
}
