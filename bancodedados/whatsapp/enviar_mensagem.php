<?php
/**
 * Endpoint para envio de mensagens personalizadas via WhatsApp
 * Sistema: MustaBarber
 */

header('Content-Type: application/json; charset=utf-8');

// Iniciar sessão
session_start();

// Incluir a classe de serviço do WhatsApp
require_once 'whatsappservice.php';
require_once '../conexao.php';
require_once '../planos/plano_manager.php';

try {
    // Verificar se é POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método não permitido');
    }

    // Verificar se usuário está logado
    if (!isset($_SESSION['usuario_logado']) || $_SESSION['usuario_logado'] !== true) {
        http_response_code(401);
        throw new Exception('Usuário não autenticado');
    }

    // Verificar acesso por plano (WhatsApp exige Solo Pro ou superior)
    exigirPlanoOuBloquear($conexao, (int)($_SESSION['usuario_codigo'] ?? 0), (int)($_SESSION['usuario_perfil'] ?? 0), 'whatsapp');

    // Receber dados
    $telefone = isset($_POST['telefone']) ? trim($_POST['telefone']) : '';
    $mensagem = isset($_POST['mensagem']) ? trim($_POST['mensagem']) : '';

    // Validar dados
    if (empty($telefone)) {
        throw new Exception('Telefone n�o informado');
    }

    if (empty($mensagem)) {
        throw new Exception('Mensagem n�o pode estar vazia');
    }

    // Instanciar servi�o
    $whatsapp = new WhatsAppService((int)($_SESSION['usuario_codigo'] ?? 0));

    // Obter nome do barbeiro da sessao
    $nomeBarbeiro = $_SESSION['usuario_nome'] ?? 'Barbeiro';

    // Quando usa numero proprio (instancia personalizada conectada),
    // envia a mensagem limpa, sem assinatura no topo.
    // Quando cai no numero padrao do MustaBarber, mantem a assinatura.
    $mensagemCompleta = $mensagem;
    if (!$whatsapp->usaInstanciaPersonalizadaAtiva()) {
        $mensagemCompleta = "_# *" . $nomeBarbeiro . "*_\n\n" . $mensagem;
    }

    // Enviar mensagem
    $resultado = $whatsapp->enviarMensagem($telefone, $mensagemCompleta);

    // Retornar resultado
    echo json_encode([
        'sucesso' => $resultado['success'],
        'mensagem' => $resultado['message'],
        'dados' => $resultado['data'] ?? null
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
