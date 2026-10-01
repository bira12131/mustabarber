<?php
/**
 * Verificar status do upgrade pendente
 * Retorna se o pending_upgrade ainda existe nas observacoes da assinatura.
 * O front usa polling: se pending_upgrade sumir, o webhook confirmou o pagamento.
 */
header('Content-Type: application/json; charset=utf-8');

require_once '../conexao.php';
require_once '../usuarios/sessao.php';

// Mapeamento perfil → nome do plano (string usado no frontend)
$perfilParaPlano = [
    2 => 'starter',
    3 => 'essencial',
    4 => 'equipe',
    6 => 'estabelecimento',
    7 => 'master',
];

try {
    if (!estaLogado()) {
        echo json_encode(['sucesso' => false, 'pago' => false]);
        exit;
    }

    $usuario       = obterUsuarioLogado();
    $codigoUsuario = (int) $usuario['codigo'];

    $stmt = $conexao->prepare(
        "SELECT u.perfil, a.observacoes, a.status_pagamento
         FROM usuarios u
         LEFT JOIN assinaturas a ON a.codigo_barbeiro = u.codigo
         WHERE u.codigo = :codigo
         ORDER BY a.codigo DESC LIMIT 1"
    );
    $stmt->bindParam(':codigo', $codigoUsuario, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['sucesso' => false, 'pago' => false]);
        exit;
    }

    $obs          = $row['observacoes'] ?? '';
    $pendente     = strpos($obs, 'pending_upgrade:') !== false;
    $planoAtual   = $perfilParaPlano[(int)($row['perfil'] ?? 2)] ?? 'starter';

    // Se nao ha mais pending_upgrade, o webhook ja aplicou o plano
    echo json_encode([
        'sucesso'     => true,
        'pago'        => !$pendente,
        'plano_atual' => $planoAtual,
    ]);

} catch (Exception $e) {
    echo json_encode(['sucesso' => false, 'pago' => false]);
}
?>
