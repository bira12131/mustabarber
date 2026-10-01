<?php
/**
 * API para Bloquear/Desbloquear Cliente
 * Sistema: MustaBarber
 * Data: 05/05/2026
 *
 * Permite que o barbeiro bloqueie ou desbloqueie um cliente de realizar agendamentos.
 */

header('Content-Type: application/json; charset=utf-8');

require_once '../conexao.php';
require_once '../usuarios/sessao.php';

if (!estaLogado()) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Usuário não autenticado.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Método não permitido. Use POST.']);
    exit;
}

try {
    $usuarioLogado = obterUsuarioLogado();
    $codigoUsuario = $usuarioLogado['codigo'];

    $codCliente    = isset($_POST['cod_cliente']) ? intval($_POST['cod_cliente']) : 0;
    $motivoBloqueio = trim($_POST['motivo'] ?? '');

    if (!$codCliente) {
        throw new Exception('Cliente inválido.');
    }

    // Verificar se o cliente pertence ao barbeiro logado
    $stmt = $conn->prepare(
        "SELECT codigo, bloqueado FROM clientes WHERE codigo = :codigo AND cod_barbeiro = :cod_barbeiro"
    );
    $stmt->execute([':codigo' => $codCliente, ':cod_barbeiro' => $codigoUsuario]);
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cliente) {
        throw new Exception('Cliente não encontrado.');
    }

    // Alternar estado de bloqueio
    $bloqueadoAtual = ($cliente['bloqueado'] === true || $cliente['bloqueado'] === 't' || $cliente['bloqueado'] == 1);
    $novoBloqueio   = !$bloqueadoAtual;

    $stmtUpdate = $conn->prepare(
        "UPDATE clientes SET bloqueado = :bloqueado, motivo_bloqueio = :motivo WHERE codigo = :codigo AND cod_barbeiro = :cod_barbeiro"
    );
    $stmtUpdate->execute([
        ':bloqueado'     => $novoBloqueio ? 'true' : 'false',
        ':motivo'        => $novoBloqueio ? ($motivoBloqueio ?: null) : null,
        ':codigo'        => $codCliente,
        ':cod_barbeiro'  => $codigoUsuario,
    ]);

    echo json_encode([
        'sucesso'   => true,
        'bloqueado' => $novoBloqueio,
        'mensagem'  => $novoBloqueio
            ? 'Cliente bloqueado. Ele não poderá realizar novos agendamentos.'
            : 'Cliente desbloqueado. Agendamentos liberados.',
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(['sucesso' => false, 'mensagem' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
