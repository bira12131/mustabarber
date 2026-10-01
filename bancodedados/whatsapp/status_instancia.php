<?php
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../conexao.php';
require_once '../planos/plano_manager.php';
require_once 'evolution_instance_service.php';

try {
    if (!isset($_SESSION['usuario_logado']) || $_SESSION['usuario_logado'] !== true) {
        http_response_code(401);
        throw new Exception('Usuario nao autenticado');
    }

    $codigoBarbeiro = (int)($_SESSION['usuario_codigo'] ?? 0);
    if ($codigoBarbeiro <= 0) {
        throw new Exception('Barbeiro nao identificado');
    }

    exigirPlanoOuBloquear(
        $conexao,
        (int)($_SESSION['usuario_codigo'] ?? 0),
        (int)($_SESSION['usuario_perfil'] ?? 0),
        'whatsapp'
    );

    $stmt = $conexao->prepare(
        "SELECT nome, whatsapp_instancia_nome, whatsapp_instancia_status, whatsapp_instancia_conectada
         FROM usuarios
         WHERE codigo = :codigo
         LIMIT 1"
    );
    $stmt->execute([':codigo' => $codigoBarbeiro]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        throw new Exception('Usuario nao encontrado');
    }

    $instanciaNome = $usuario['whatsapp_instancia_nome'] ?? null;
    $instanciaRotulo = EvolutionInstanceService::gerarRotuloInstancia($usuario['nome'] ?? 'Barbeiro');

    if (empty($instanciaNome)) {
        echo json_encode([
            'sucesso' => true,
            'possui_instancia' => false,
            'instancia_nome' => null,
            'instancia_rotulo' => $instanciaRotulo,
            'status' => 'fallback',
            'conectada' => false,
            'qrcode' => null
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $evolution = new EvolutionInstanceService();
    $resultadoStatus = $evolution->consultarStatus($instanciaNome);

    $status = $resultadoStatus['state'] ?? ($usuario['whatsapp_instancia_status'] ?? 'disconnected');
    $conectada = !empty($resultadoStatus['connected']);

    $qrcode = null;
    if (!$conectada) {
        $resultadoQr = $evolution->buscarQrcode($instanciaNome);
        $qrcode = $resultadoQr['qrcode'] ?? null;
    }

    $stmtUp = $conexao->prepare(
        "UPDATE usuarios
         SET whatsapp_instancia_status = :status,
             whatsapp_instancia_conectada = :conectada,
             whatsapp_instancia_atualizada_em = NOW()
         WHERE codigo = :codigo"
    );
    $stmtUp->bindValue(':status', $status ?: 'disconnected', PDO::PARAM_STR);
    $stmtUp->bindValue(':conectada', $conectada, PDO::PARAM_BOOL);
    $stmtUp->bindValue(':codigo', $codigoBarbeiro, PDO::PARAM_INT);
    $stmtUp->execute();

    echo json_encode([
        'sucesso' => true,
        'possui_instancia' => true,
        'instancia_nome' => $instanciaNome,
        'instancia_rotulo' => $instanciaRotulo,
        'status' => $status,
        'conectada' => $conectada,
        'qrcode' => $qrcode
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(http_response_code() >= 400 ? http_response_code() : 400);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
