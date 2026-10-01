<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../conexao.php';
require_once '../usuarios/sessao.php';

// Verifica se é uma requisição POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Método não permitido']);
    exit;
}

try {
    // Verificar se usuário está logado
    if (!estaLogado()) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Usuário não autenticado'
        ]);
        exit;
    }

    // Obter código do usuário logado
    $usuarioLogado = obterUsuarioLogado();
    $codigoUsuario = $usuarioLogado['codigo'];

    $nome = $_POST['nome'] ?? '';
    $telefone = $_POST['telefone'] ?? '';
    
    // Remove caracteres especiais do telefone (parênteses, traços, espaços, etc.)
    $telefone = preg_replace('/[^0-9]/', '', $telefone);
    
    // Validações básicas
    if (empty($nome)) {
        throw new Exception('Nome é obrigatório');
    }
    
    if (empty($telefone)) {
        throw new Exception('Telefone é obrigatório');
    }
    
    // Verifica se já existe cliente com o mesmo telefone para este barbeiro
    $codigo = $_POST['codigo'] ?? 0;
    $stmt = $conn->prepare("SELECT codigo FROM clientes WHERE telefone = :telefone AND codigo != :codigo AND cod_barbeiro = :codigo_usuario");
    $stmt->execute([':telefone' => $telefone, ':codigo' => $codigo, ':codigo_usuario' => $codigoUsuario]);
    
    if ($stmt->rowCount() > 0) {
        throw new Exception('Já existe um cliente cadastrado com este telefone');
    }
    
    // Verifica se &#65533; atualiza&#65533;&#65533;o ou cadastro novo
    if (!empty($_POST['codigo'])) {
        // Atualiza&#65533;&#65533;o
        $codigo = $_POST['codigo'];
        
        $stmt = $conn->prepare("UPDATE clientes SET nome = :nome, telefone = :telefone WHERE codigo = :codigo");
        $stmt->execute([
            ':nome' => $nome,
            ':telefone' => $telefone,
            ':codigo' => $codigo
        ]);
        
        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Cliente atualizado com sucesso!',
            'codigo' => $codigo
        ]);
        
    } else {
        // Novo cadastro
        $stmt = $conn->prepare("INSERT INTO clientes (nome, telefone, cod_barbeiro) VALUES (:nome, :telefone, :cod_barbeiro) RETURNING codigo");
        $stmt->execute([
            ':nome' => $nome,
            ':telefone' => $telefone,
            ':cod_barbeiro' => $codigoUsuario
        ]);
        
        $resultado = $stmt->fetch();
        $codigo_inserido = $resultado['codigo'];
        
        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Cliente cadastrado com sucesso!',
            'codigo' => $codigo_inserido
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => $e->getMessage()
    ]);
}
?>
