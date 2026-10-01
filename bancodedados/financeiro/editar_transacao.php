<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, PUT');
header('Access-Control-Allow-Headers: Content-Type');

include_once '../conexao.php';
require_once '../usuarios/sessao.php';
require_once '../planos/plano_manager.php';

try {
    // Verificar autenticação
    if (!estaLogado()) {
        http_response_code(401);
        echo json_encode(['sucesso' => false, 'mensagem' => 'Usuário não autenticado']);
        exit;
    }

    // Verificar acesso por plano (financeiro exige Solo Pro ou superior)
    $usuarioLogado = obterUsuarioLogado();
    exigirPlanoOuBloquear($conexao, (int)$usuarioLogado['codigo'], (int)$usuarioLogado['perfil'], 'financeiro');

    // Receber dados da requisição
    $dados = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($dados['codigo']) || !isset($dados['descricao']) || !isset($dados['tipo_transacao'])) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Dados incompletos. É necessário informar código, descrição e tipo'
        ]);
        exit;
    }
    
    $codigo = $dados['codigo'];
    $descricao = $dados['descricao'];
    $tipo_transacao = $dados['tipo_transacao'];
    $categoria_codigo = isset($dados['categoria_codigo']) && $dados['categoria_codigo'] !== '' ? $dados['categoria_codigo'] : null;
    $valor = isset($dados['valor']) && $dados['valor'] > 0 ? floatval($dados['valor']) : null;
    
    // Validar tipo de transação (1 = Receita, 2 = Despesa)
    if (!in_array($tipo_transacao, [1, 2])) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Tipo de transação inválido'
        ]);
        exit;
    }
    
    // Construir SET dinâmico
    $setCampos = 'descricao = :descricao, tipo_transacao = :tipo_transacao';
    if ($categoria_codigo !== null) {
        $setCampos .= ', categoria = :categoria';
    }
    // Se categoria não foi enviada, mantém o valor atual no banco
    if ($valor !== null) {
        $setCampos .= ', valor = :valor';
    }

    $sql = "UPDATE transacoes SET $setCampos WHERE codigo = :codigo";

    $stmt = $conexao->prepare($sql);
    $stmt->bindParam(':descricao', $descricao, PDO::PARAM_STR);
    $stmt->bindParam(':tipo_transacao', $tipo_transacao, PDO::PARAM_INT);
    if ($categoria_codigo !== null) {
        $stmt->bindParam(':categoria', $categoria_codigo, PDO::PARAM_INT);
    }
    if ($valor !== null) {
        $stmt->bindParam(':valor', $valor, PDO::PARAM_STR);
    }
    $stmt->bindParam(':codigo', $codigo, PDO::PARAM_INT);
    
    if ($stmt->execute()) {
        if ($stmt->rowCount() > 0) {
            echo json_encode([
                'sucesso' => true,
                'mensagem' => 'Transação atualizada com sucesso'
            ]);
        } else {
            echo json_encode([
                'sucesso' => true,
                'mensagem' => 'Nenhuma alteração foi necessária'
            ]);
        }
    } else {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Erro ao atualizar transação'
        ]);
    }
    
} catch (PDOException $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao atualizar: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro: ' . $e->getMessage()
    ]);
}
?>
