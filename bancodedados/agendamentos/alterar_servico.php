<?php
/**
 * API para Alterar Serviço de Agendamento
 * Sistema: MustaBarber
 *
 * Recebe: { codigo: int, codigo_servico: int }
 * Atualiza codigo_servico, duracao_minutos e valor do agendamento.
 */

header('Content-Type: application/json; charset=utf-8');

require_once '../conexao.php';
require_once '../usuarios/sessao.php';

if (!estaLogado()) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Usuário não autenticado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Método não permitido. Use POST.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $usuarioLogado = obterUsuarioLogado();
    $codBarbeiro   = (int) $usuarioLogado['codigo'];

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        throw new Exception('Dados inválidos.');
    }

    $codAgendamento = isset($input['codigo'])         ? (int) $input['codigo']         : 0;
    $codServico     = isset($input['codigo_servico']) ? (int) $input['codigo_servico'] : 0;

    if (!$codAgendamento || !$codServico) {
        throw new Exception('Código do agendamento e do serviço são obrigatórios.');
    }

    // Verifica se o agendamento pertence ao barbeiro e está em status editável
    $stmtAg = $conn->prepare(
        "SELECT codigo, codigo_barbeiro, codigo_status
         FROM agendamentos
         WHERE codigo = :codigo"
    );
    $stmtAg->execute([':codigo' => $codAgendamento]);
    $ag = $stmtAg->fetch(PDO::FETCH_ASSOC);

    if (!$ag) {
        throw new Exception('Agendamento não encontrado.');
    }

    if ((int) $ag['codigo_barbeiro'] !== $codBarbeiro) {
        throw new Exception('Você não tem permissão para editar este agendamento.');
    }

    $statusFechados = [4, 5, 6]; // concluído, cancelado, falta
    if (in_array((int) $ag['codigo_status'], $statusFechados)) {
        throw new Exception('Não é possível alterar o serviço de um agendamento encerrado.');
    }

    // Busca o serviço e valida que pertence ao barbeiro
    $stmtServ = $conn->prepare(
        "SELECT codigo, nome, preco, duracao
         FROM servicos
         WHERE codigo = :codigo
           AND codigo_barbeiro = :barbeiro
           AND status = 1"
    );
    $stmtServ->execute([':codigo' => $codServico, ':barbeiro' => $codBarbeiro]);
    $servico = $stmtServ->fetch(PDO::FETCH_ASSOC);

    if (!$servico) {
        throw new Exception('Serviço não encontrado ou inativo.');
    }

    // Atualiza o agendamento
    $stmtUp = $conn->prepare(
        "UPDATE agendamentos
         SET codigo_servico    = :codigo_servico,
             duracao_minutos   = :duracao,
             valor             = :valor,
             data_atualizacao  = CURRENT_TIMESTAMP
         WHERE codigo = :codigo"
    );
    $stmtUp->execute([
        ':codigo_servico' => $servico['codigo'],
        ':duracao'        => (int) $servico['duracao'],
        ':valor'          => (float) $servico['preco'],
        ':codigo'         => $codAgendamento,
    ]);

    echo json_encode([
        'sucesso'  => true,
        'mensagem' => 'Serviço alterado para "' . $servico['nome'] . '" com sucesso!',
        'servico'  => [
            'codigo'  => (int) $servico['codigo'],
            'nome'    => $servico['nome'],
            'valor'   => (float) $servico['preco'],
            'duracao' => (int) $servico['duracao'],
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        'sucesso'  => false,
        'mensagem' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
?>
