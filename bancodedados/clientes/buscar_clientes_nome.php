<?php
/**
 * API para Buscar Clientes por Nome
 * Sistema: MustaBarber
 * Data: 20/12/2025
 */

header('Content-Type: application/json; charset=utf-8');

try {
    require_once '../conexao.php';
    
    // Obter termo de busca
    $busca = isset($_GET['termo']) ? trim($_GET['termo']) : '';
    
    // Validar comprimento m�nimo
    if (strlen($busca) < 3) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Digite pelo menos 3 caracteres',
            'clientes' => []
        ]);
        exit;
    }
    
    // Buscar clientes por nome (case-insensitive)
    $sql = "SELECT codigo, nome, telefone, COALESCE(bloqueado, FALSE) AS bloqueado, motivo_bloqueio FROM clientes WHERE LOWER(nome) LIKE LOWER(:busca) ORDER BY nome LIMIT 20";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':busca' => '%' . $busca . '%']);
    
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $clientes = [];
    foreach ($rows as $row) {
        $clientes[] = [
            'codigo'         => $row['codigo'],
            'nome'           => $row['nome'],
            'telefone'       => $row['telefone'],
            'bloqueado'      => ($row['bloqueado'] === true || $row['bloqueado'] === 't' || $row['bloqueado'] == 1),
            'motivo_bloqueio'=> $row['motivo_bloqueio'] ?? null,
        ];
    }
    
    echo json_encode([
        'sucesso' => true,
        'clientes' => $clientes
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao buscar clientes: ' . $e->getMessage(),
        'clientes' => []
    ]);
}
?>
