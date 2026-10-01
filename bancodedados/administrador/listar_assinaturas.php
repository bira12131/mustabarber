<?php
/**
 * Listar Assinaturas
 * Sistema: MustaBarber - Painel Administrativo
 * Data: 21/12/2025
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

try {
    // Incluir arquivo de conexão
    require_once '../conexao.php';
    require_once '../usuarios/sessao.php';
    // Verificar se usuário está logado
    if (!estaLogado()) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Usuário não autenticado',
            'assinaturas' => []
        ]);
        exit;
    }

    // Verificar se é administrador (perfil 1)
    $usuarioLogado = obterUsuarioLogado();
    if ($usuarioLogado['perfil'] != 1) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Acesso negado. Apenas administradores podem acessar esta funcionalidade.',
            'assinaturas' => []
        ]);
        exit;
    }

    // Buscar assinaturas utilizando a view criada
    $sql = "SELECT * FROM vw_assinaturas_completa ORDER BY data_cadastro DESC";

    $stmt = $conexao->prepare($sql);
    $stmt->execute();
    
    $assinaturas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formatar dados
    foreach ($assinaturas as &$assinatura) {
        $assinatura['codigo'] = intval($assinatura['codigo']);
        $assinatura['codigo_barbeiro'] = intval($assinatura['codigo_barbeiro']);
        $assinatura['codigo_perfil'] = intval($assinatura['codigo_perfil']);
        $assinatura['valor_mensalidade'] = floatval($assinatura['valor_mensalidade']);
        $assinatura['status_pagamento'] = intval($assinatura['status_pagamento']);
        $assinatura['dias_para_vencimento'] = intval($assinatura['dias_para_vencimento']);
        $assinatura['esta_vencido'] = $assinatura['esta_vencido'] === 't' || $assinatura['esta_vencido'] === true;
    }

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Assinaturas carregadas com sucesso',
        'assinaturas' => $assinaturas,
        'total' => count($assinaturas)
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro no banco de dados: ' . $e->getMessage(),
        'assinaturas' => []
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => $e->getMessage(),
        'assinaturas' => []
    ], JSON_UNESCAPED_UNICODE);
}
?>
