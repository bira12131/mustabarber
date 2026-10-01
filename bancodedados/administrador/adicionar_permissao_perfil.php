<?php
/**
 * Adicionar Permissão ao Perfil
 * Sistema: MustaBarber - Painel Administrativo
 * Data: 29/01/2026
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

    if (!$dados || !isset($dados['codigo_permissao']) || !isset($dados['codigo_perfil'])) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Dados inválidos'
        ]);
        exit;
    }

    $codigoPermissao = $dados['codigo_permissao'];
    $codigoPerfil = $dados['codigo_perfil'];

    // Atualizar o perfil da permissão
    $sql = "UPDATE permissao 
            SET codigo_perfil = :codigo_perfil 
            WHERE codigo = :codigo_permissao";

    $stmt = $conexao->prepare($sql);
    $stmt->bindParam(':codigo_perfil', $codigoPerfil, PDO::PARAM_INT);
    $stmt->bindParam(':codigo_permissao', $codigoPermissao, PDO::PARAM_INT);
    $stmt->execute();

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Permissão adicionada ao perfil com sucesso'
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
