<?php
/**
 * Listar Permissões por Perfil
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
            'permissoes' => []
        ]);
        exit;
    }

    // Verificar se é administrador
    $usuarioLogado = obterUsuarioLogado();
    if ($usuarioLogado['perfil'] != 1) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Acesso negado. Apenas administradores podem acessar esta funcionalidade.',
            'permissoes' => []
        ]);
        exit;
    }

    // Obter perfil da query string
    $perfilId = $_GET['perfil'] ?? null;

    if (!$perfilId) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Perfil não informado',
            'permissoes' => []
        ]);
        exit;
    }

    // Buscar permissões do perfil
    $sql = "SELECT 
                codigo,
                codigo_perfil,
                nome,
                modulo,
                acao,
                descricao,
                data_cadastro
            FROM permissao 
            WHERE codigo_perfil = :perfil
            ORDER BY modulo, nome";

    $stmt = $conexao->prepare($sql);
    $stmt->bindParam(':perfil', $perfilId, PDO::PARAM_INT);
    $stmt->execute();
    
    $permissoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formatar dados
    foreach ($permissoes as &$permissao) {
        $permissao['codigo'] = intval($permissao['codigo']);
        $permissao['codigo_perfil'] = intval($permissao['codigo_perfil']);
    }

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Permissões carregadas com sucesso',
        'permissoes' => $permissoes,
        'total' => count($permissoes)
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro no banco de dados: ' . $e->getMessage(),
        'permissoes' => []
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => $e->getMessage(),
        'permissoes' => []
    ], JSON_UNESCAPED_UNICODE);
}
?>
