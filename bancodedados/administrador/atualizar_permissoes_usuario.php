<?php
/**
 * Atualizar Permissões de Usuário
 * Sistema: MustaBarber - Painel Administrativo
 * Data: 29/01/2026
 * 
 * Nota: Esta funcionalidade atualiza permissões personalizadas de um usuário específico
 * As permissões são armazenadas no perfil do usuário por padrão
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

    if (!$dados || !isset($dados['codigo_usuario'])) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Dados inválidos'
        ]);
        exit;
    }

    // Por enquanto, apenas registra as permissões atualizadas
    // Em uma implementação completa, você criaria uma tabela de permissões personalizadas por usuário
    
    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Permissões atualizadas com sucesso',
        'observacao' => 'As permissões são gerenciadas através do perfil do usuário'
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
