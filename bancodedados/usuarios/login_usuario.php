<?php
/**
 * API de Login de Usuarios
 * Sistema: MustaBarber
 * Data: 28/11/2025
 * 
 * Este arquivo processa o login de usuarios no sistema
 * Permite login com email ou telefone
 */

header('Content-Type: application/json; charset=utf-8');

// Verifica se a requisicao e do tipo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Metodo nao permitido. Use POST.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    //  Inclui o arquivo de conexao
    require_once '../conexao.php';
    
    // Inclui o sistema de sessao
    require_once 'sessao.php';
    
    // Recebe os dados do formulario
    $login = trim($_POST['login'] ?? '');
    $senha = trim($_POST['senha'] ?? '');
    
    // Validacoes basicas
    if (empty($login) || empty($senha)) {
        throw new Exception('Login e senha sao obrigatorios.');
    }
    
    // Remove formatacao se for telefone (apenas numeros)
    $loginLimpo = preg_replace('/[^0-9]/', '', $login);
    
    // Verifica se o login e um telefone (somente numeros) ou email
    $isTelefone = !empty($loginLimpo) && strlen($loginLimpo) >= 10 && strlen($loginLimpo) <= 11;
    
    // Prepara a query baseada no tipo de login
    if ($isTelefone) {
        // Busca por telefone
        $sql = "SELECT codigo, nome, email, telefone, senha, perfil, status, foto_caminho 
                FROM usuarios 
                WHERE telefone = :login";
    } else {
        // Busca por email
        $sql = "SELECT codigo, nome, email, telefone, senha, perfil, status, foto_caminho 
                FROM usuarios 
                WHERE email = :login";
    }
    
    $stmt = $conexao->prepare($sql);
    
    // Define o valor do login (telefone limpo ou email)
    $valorLogin = $isTelefone ? $loginLimpo : $login;
    $stmt->bindParam(':login', $valorLogin, PDO::PARAM_STR);
    $stmt->execute();
    
    // Verifica se o usuario existe
    if ($stmt->rowCount() === 0) {
        throw new Exception('Usuario nao encontrado.');
    }
    
    // Obtem os dados do usuario
    $usuario = $stmt->fetch();
    
    // Verifica se o usuario esta ativo
    if ($usuario['status'] != 1) {
        throw new Exception('Usuario inativo. Entre em contato com o administrador.');
    }
    
    // Verifica a senha
    if (!password_verify($senha, $usuario['senha'])) {
        throw new Exception('Senha incorreta.');
    }

    // Registrar último acesso
    try {
        $stmtAcesso = $conexao->prepare(
            "UPDATE usuarios SET ultimo_acesso = CURRENT_TIMESTAMP WHERE codigo = :cod"
        );
        $stmtAcesso->bindParam(':cod', $usuario['codigo'], PDO::PARAM_INT);
        $stmtAcesso->execute();
    } catch (Exception $eAcesso) {
        // Silencioso — coluna pode não existir ainda (migration pendente)
    }

    // Criar sess�o do usu�rio
    criarSessao($usuario);
    
    // Login bem-sucedido - retorna dados do usuario (sem a senha)
    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Login realizado com sucesso!',
        'dados' => [
            'codigo' => $usuario['codigo'],
            'nome' => $usuario['nome'],
            'email' => $usuario['email'],
            'telefone' => $usuario['telefone'],
            'perfil' => $usuario['perfil'],
            'foto_caminho' => $usuario['foto_caminho']
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // Em caso de erro, retorna mensagem
    echo json_encode([
        'sucesso' => false,
        'mensagem' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    // Erro de banco de dados
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao processar login. Tente novamente.'
    ], JSON_UNESCAPED_UNICODE);
}
?>
