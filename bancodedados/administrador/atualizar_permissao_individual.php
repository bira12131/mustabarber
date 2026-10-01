<?php
/**
 * Atualizar Permissão Individual de Usuário
 * Sistema: MustaBarber - Painel Administrativo
 * Data: 29/01/2026
 * 
 * Atualiza uma permissão específica do usuário em tempo real
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

require_once '../conexao.php';
require_once '../usuarios/sessao.php';

try {
    // Verificar se usuário está logado
    if (!estaLogado()) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Usuário não autenticado'
        ]);
        exit;
    }

    // Verificar se é administrador
    $usuarioLogado = obterUsuarioLogado();
    if ($usuarioLogado['perfil'] != 1) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Acesso negado. Apenas administradores podem acessar esta funcionalidade.'
        ]);
        exit;
    }

    // Obter dados da requisição
    $dados = json_decode(file_get_contents('php://input'), true);

    if (!$dados || !isset($dados['codigo_usuario']) || !isset($dados['codigo_permissao'])) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Dados inválidos'
        ]);
        exit;
    }

    $codigoUsuario = $dados['codigo_usuario'];
    $codigoPermissao = $dados['codigo_permissao'];
    $ativo = isset($dados['ativo']) && $dados['ativo'] ? 1 : 0;

    // Verificar se a tabela de permissões personalizadas por usuário existe
    // Se não existir, criar
    $sqlCheckTable = "SELECT EXISTS (
        SELECT FROM information_schema.tables 
        WHERE table_name = 'usuario_permissao'
    )";
    
    $stmtCheck = $conexao->query($sqlCheckTable);
    $tableExists = $stmtCheck->fetchColumn();

    if (!$tableExists) {
        // Criar tabela de permissões personalizadas por usuário
        $sqlCreate = "CREATE TABLE usuario_permissao (
            codigo SERIAL PRIMARY KEY,
            codigo_usuario INT NOT NULL,
            codigo_permissao INT NOT NULL,
            ativo INT NOT NULL DEFAULT 1,
            data_atualizacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_usuario_permissao_usuario 
                FOREIGN KEY (codigo_usuario) 
                REFERENCES usuarios(codigo) 
                ON DELETE CASCADE,
            CONSTRAINT fk_usuario_permissao_permissao 
                FOREIGN KEY (codigo_permissao) 
                REFERENCES permissao(codigo) 
                ON DELETE CASCADE,
            CONSTRAINT uk_usuario_permissao 
                UNIQUE (codigo_usuario, codigo_permissao)
        )";
        $conexao->exec($sqlCreate);

        // Criar índices
        $conexao->exec("CREATE INDEX idx_usuario_permissao_usuario ON usuario_permissao(codigo_usuario)");
        $conexao->exec("CREATE INDEX idx_usuario_permissao_permissao ON usuario_permissao(codigo_permissao)");
    }

    // Verificar se já existe registro para este usuário e permissão
    $sqlCheck = "SELECT codigo FROM usuario_permissao 
                 WHERE codigo_usuario = :codigo_usuario 
                 AND codigo_permissao = :codigo_permissao";
    
    $stmt = $conexao->prepare($sqlCheck);
    $stmt->bindParam(':codigo_usuario', $codigoUsuario, PDO::PARAM_INT);
    $stmt->bindParam(':codigo_permissao', $codigoPermissao, PDO::PARAM_INT);
    $stmt->execute();
    
    $existe = $stmt->fetch();

    if ($existe) {
        // Atualizar registro existente
        $sqlUpdate = "UPDATE usuario_permissao 
                     SET ativo = :ativo,
                         data_atualizacao = CURRENT_TIMESTAMP
                     WHERE codigo_usuario = :codigo_usuario 
                     AND codigo_permissao = :codigo_permissao";
        
        $stmtUpdate = $conexao->prepare($sqlUpdate);
        $stmtUpdate->bindParam(':ativo', $ativo, PDO::PARAM_INT);
        $stmtUpdate->bindParam(':codigo_usuario', $codigoUsuario, PDO::PARAM_INT);
        $stmtUpdate->bindParam(':codigo_permissao', $codigoPermissao, PDO::PARAM_INT);
        $stmtUpdate->execute();
    } else {
        // Inserir novo registro
        $sqlInsert = "INSERT INTO usuario_permissao 
                     (codigo_usuario, codigo_permissao, ativo) 
                     VALUES (:codigo_usuario, :codigo_permissao, :ativo)";
        
        $stmtInsert = $conexao->prepare($sqlInsert);
        $stmtInsert->bindParam(':codigo_usuario', $codigoUsuario, PDO::PARAM_INT);
        $stmtInsert->bindParam(':codigo_permissao', $codigoPermissao, PDO::PARAM_INT);
        $stmtInsert->bindParam(':ativo', $ativo, PDO::PARAM_INT);
        $stmtInsert->execute();
    }

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Permissão atualizada com sucesso',
        'ativo' => $ativo
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro no banco de dados: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
