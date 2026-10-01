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

    $nomeBarbeiro = trim((string)($_SESSION['usuario_nome'] ?? ''));
    $instanciaNome = EvolutionInstanceService::gerarNomeInstanciaPadrao($nomeBarbeiro, $codigoBarbeiro);
    $instanciaRotulo = EvolutionInstanceService::gerarRotuloInstancia($nomeBarbeiro);

    $evolution = new EvolutionInstanceService();

    $resultadoCriacao = $evolution->criarInstancia($instanciaNome);
    if (!$resultadoCriacao['success']) {
        $statusErro = (int)($resultadoCriacao['status'] ?? 400);
        http_response_code($statusErro >= 400 ? $statusErro : 400);

        $mensagemErro = trim((string)($resultadoCriacao['body']['message'] ?? ''));
        if ($mensagemErro === '') {
            $mensagemErro = 'Nao foi possivel criar a instancia na Evolution API.';
        } else {
            $mensagemErro = 'Erro ao criar instancia na Evolution API: ' . $mensagemErro;
        }

        error_log('Falha ao criar instancia Evolution API | usuario=' . $codigoBarbeiro . ' | instancia=' . $instanciaNome . ' | status=' . $statusErro . ' | detalhe=' . json_encode($resultadoCriacao['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        throw new Exception($mensagemErro);
    }

    $resultadoStatus = $evolution->consultarStatus($instanciaNome);
    $resultadoQr = $evolution->buscarQrcode($instanciaNome);

    $conectada = !empty($resultadoStatus['connected']);
    $estado = $resultadoStatus['state'] ?? ($conectada ? 'open' : 'connecting');

    $stmt = $conexao->prepare(
        "UPDATE usuarios
         SET whatsapp_instancia_nome = :instancia,
             whatsapp_instancia_status = :status,
             whatsapp_instancia_conectada = :conectada,
             whatsapp_instancia_atualizada_em = NOW()
         WHERE codigo = :codigo"
    );

    $stmt->bindValue(':instancia', $instanciaNome, PDO::PARAM_STR);
    $stmt->bindValue(':status', $estado ?: 'connecting', PDO::PARAM_STR);
    $stmt->bindValue(':conectada', $conectada, PDO::PARAM_BOOL);
    $stmt->bindValue(':codigo', $codigoBarbeiro, PDO::PARAM_INT);
    $stmt->execute();

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Instancia criada com sucesso.',
        'instancia_nome' => $instanciaNome,
        'instancia_rotulo' => $instanciaRotulo,
        'conectada' => $conectada,
        'status' => $estado,
        'qrcode' => $resultadoQr['qrcode'] ?? null
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(http_response_code() >= 400 ? http_response_code() : 400);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
