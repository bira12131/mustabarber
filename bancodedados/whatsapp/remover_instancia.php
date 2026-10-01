<?php
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../conexao.php';
require_once '../planos/plano_manager.php';
require_once 'evolution_instance_service.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Metodo nao permitido');
    }

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
        "SELECT whatsapp_instancia_nome
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

    if (!empty($instanciaNome)) {
        $evolution = new EvolutionInstanceService();
        $evolution->excluirInstancia($instanciaNome);
    }

    $stmtUp = $conexao->prepare(
        "UPDATE usuarios
         SET whatsapp_instancia_nome = NULL,
             whatsapp_instancia_status = 'fallback',
             whatsapp_instancia_conectada = FALSE,
             whatsapp_instancia_atualizada_em = NOW()
         WHERE codigo = :codigo"
    );
    $stmtUp->execute([':codigo' => $codigoBarbeiro]);

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Instancia removida. O sistema voltou a usar o numero padrao do MustaBarber.'
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(http_response_code() >= 400 ? http_response_code() : 400);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
