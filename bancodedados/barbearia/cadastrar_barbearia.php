<?php
/**
 * Cadastrar Barbearia
 * Sistema: MustaBarber
 * Data: 11/03/2026
 * Cria uma nova barbearia vinculada ao usuário logado.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

require_once '../conexao.php';
require_once '../usuarios/sessao.php';

try {
    if (!estaLogado()) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Usuário não autenticado']);
        exit;
    }

    $usuarioLogado = obterUsuarioLogado();
    $codigoUsuario = $usuarioLogado['codigo'];

    $dados = json_decode(file_get_contents('php://input'), true);
    $nomeBarbearia = isset($dados['nome_barbearia']) ? trim($dados['nome_barbearia']) : '';

    if (empty($nomeBarbearia)) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'O nome da barbearia é obrigatório.']);
        exit;
    }

    if (mb_strlen($nomeBarbearia) > 150) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'O nome da barbearia deve ter no máximo 150 caracteres.']);
        exit;
    }

    // Verificar se o usuário já possui uma barbearia
    $stmtCheck = $conexao->prepare("SELECT codigo FROM barbearia WHERE codigo_usuario = :codigo_usuario LIMIT 1");
    $stmtCheck->bindParam(':codigo_usuario', $codigoUsuario, PDO::PARAM_INT);
    $stmtCheck->execute();

    if ($stmtCheck->rowCount() > 0) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Você já possui uma barbearia cadastrada.']);
        exit;
    }

    // Inserir a nova barbearia
    $sql = "INSERT INTO barbearia (nome_barbearia, codigo_usuario) VALUES (:nome_barbearia, :codigo_usuario) RETURNING codigo";
    $stmt = $conexao->prepare($sql);
    $stmt->bindParam(':nome_barbearia', $nomeBarbearia);
    $stmt->bindParam(':codigo_usuario', $codigoUsuario, PDO::PARAM_INT);
    $stmt->execute();

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $codigoBarbearia = intval($row['codigo']);

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Barbearia cadastrada com sucesso!',
        'barbearia' => [
            'codigo' => $codigoBarbearia,
            'nome_barbearia' => $nomeBarbearia,
            'codigo_usuario' => $codigoUsuario
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log('[cadastrar_barbearia] PDOException: ' . $e->getMessage());
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro no banco de dados.'], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['sucesso' => false, 'mensagem' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
