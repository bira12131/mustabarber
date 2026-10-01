<?php
/**
 * API para atualizar preferências do usuário logado
 * Sistema: MustaBarber
 */
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
error_reporting(0);

require_once 'sessao.php';
require_once '../conexao.php';

try {
    if (!estaLogado()) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Usuário não autenticado'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Método não permitido'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if ($input === null) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Dados inválidos'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $usuario = obterUsuarioLogado();
    $codigoUsuario = intval($usuario['codigo']);

    // Apenas campos de preferência permitidos
    $setClauses = [];
    $params = [':codigo' => $codigoUsuario];

    if (isset($input['finalizacao_automatica'])) {
        $setClauses[] = 'finalizacao_automatica = :finalizacao_automatica';
        $params[':finalizacao_automatica'] = (bool) $input['finalizacao_automatica'];
    }

    if (isset($input['permite_solicitacao_plano'])) {
        $setClauses[] = 'permite_solicitacao_plano = :permite_solicitacao_plano';
        $params[':permite_solicitacao_plano'] = (bool) $input['permite_solicitacao_plano'];
    }

    if (isset($input['pagamento_auto_cartao'])) {
        $ativarAuto = (bool) $input['pagamento_auto_cartao'];

        $stmtPerfil = $conexao->prepare(
            'SELECT perfil, asaas_cartao_token FROM usuarios WHERE codigo = :codigo LIMIT 1'
        );
        $stmtPerfil->bindValue(':codigo', $codigoUsuario, PDO::PARAM_INT);
        $stmtPerfil->execute();
        $rowPerfil = $stmtPerfil->fetch(PDO::FETCH_ASSOC);
        $perfilUsuario = (int)($rowPerfil['perfil'] ?? 0);

        if ($perfilUsuario !== 1) {
            echo json_encode([
                'sucesso' => false,
                'mensagem' => 'Apenas administradores podem alterar esta preferencia.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($ativarAuto) {
            $stmtCartao = $conexao->prepare(
                'SELECT asaas_cartao_token FROM usuarios WHERE codigo = :codigo LIMIT 1'
            );
            $stmtCartao->bindValue(':codigo', $codigoUsuario, PDO::PARAM_INT);
            $stmtCartao->execute();
            $rowCartao = $stmtCartao->fetch(PDO::FETCH_ASSOC);
            $token = trim((string)($rowCartao['asaas_cartao_token'] ?? ''));

            if ($token === '') {
                echo json_encode([
                    'sucesso' => false,
                    'mensagem' => 'Cadastre um cart&atilde;o tokenizado antes de ativar o pagamento autom&aacute;tico.'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        $setClauses[] = 'pagamento_auto_cartao = :pagamento_auto_cartao';
        $params[':pagamento_auto_cartao'] = $ativarAuto;
    }

    if (empty($setClauses)) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Nenhuma preferência informada'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $sql = 'UPDATE usuarios SET ' . implode(', ', $setClauses) . ' WHERE codigo = :codigo';
    $stmt = $conexao->prepare($sql);
    $stmt->bindValue(':codigo', $codigoUsuario, PDO::PARAM_INT);
    if (isset($input['finalizacao_automatica'])) {
        $stmt->bindValue(':finalizacao_automatica', (bool) $input['finalizacao_automatica'], PDO::PARAM_BOOL);
    }
    if (isset($input['permite_solicitacao_plano'])) {
        $stmt->bindValue(':permite_solicitacao_plano', (bool) $input['permite_solicitacao_plano'], PDO::PARAM_BOOL);
    }
    if (isset($input['pagamento_auto_cartao'])) {
        $stmt->bindValue(':pagamento_auto_cartao', (bool) $input['pagamento_auto_cartao'], PDO::PARAM_BOOL);
    }
    $stmt->execute();

    echo json_encode(['sucesso' => true, 'mensagem' => 'Preferências salvas com sucesso!'], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao salvar preferências: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
