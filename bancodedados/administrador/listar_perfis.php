<?php
/**
 * Listar Todos os Perfis
 * Sistema: MustaBarber - Painel Administrativo
 * Data: 29/01/2026
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

require_once '../conexao.php';
require_once '../usuarios/sessao.php';

try {
    // Verificar se usuário está logado
    if (!estaLogado()) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Usuário não autenticado',
            'perfis' => []
        ]);
        exit;
    }

    // Verificar se é administrador
    $usuarioLogado = obterUsuarioLogado();
    if ($usuarioLogado['perfil'] != 1) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Acesso negado. Apenas administradores podem acessar esta funcionalidade.',
            'perfis' => []
        ]);
        exit;
    }

    // Buscar todos os perfis
    $sql = "SELECT 
                codigo,
                nome,
                descricao,
                data_cadastro
            FROM perfil 
            ORDER BY codigo";

    $stmt = $conexao->prepare($sql);
    $stmt->execute();
    
    $perfis = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formatar dados
    foreach ($perfis as &$perfil) {
        $perfil['codigo'] = intval($perfil['codigo']);
    }

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Perfis carregados com sucesso',
        'perfis' => $perfis,
        'total' => count($perfis)
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro no banco de dados: ' . $e->getMessage(),
        'perfis' => []
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => $e->getMessage(),
        'perfis' => []
    ], JSON_UNESCAPED_UNICODE);
}
?>
