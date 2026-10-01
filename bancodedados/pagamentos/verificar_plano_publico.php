<?php
/**
 * Verificar Plano do Barbeiro - Endpoint Público
 * Sistema: MustaBarber
 *
 * Endpoint sem autenticação, usado pela tela pública de agendamento
 * para checar se o barbeiro/barbearia está com o plano em dia.
 *
 * GET: ?cod_barbeiro=X
 * Retorna: { ativo: bool, mensagem: string }
 */

header('Content-Type: application/json; charset=utf-8');

require_once '../conexao.php';

//  Aceita apenas GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ativo' => false, 'mensagem' => 'Método não permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$codBarbeiro = isset($_GET['cod_barbeiro']) ? intval($_GET['cod_barbeiro']) : 0;

if ($codBarbeiro <= 0) {
    echo json_encode(['ativo' => false, 'mensagem' => 'Barbeiro inválido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 1. Verificar se o barbeiro existe e está ativo
    $stmtUser = $conn->prepare(
        "SELECT codigo, nome, status, isento_pagamento, data_cadastro
         FROM usuarios
         WHERE codigo = :codigo
         LIMIT 1"
    );
    $stmtUser->bindParam(':codigo', $codBarbeiro, PDO::PARAM_INT);
    $stmtUser->execute();
    $usuario = $stmtUser->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        echo json_encode(['ativo' => false, 'mensagem' => 'Profissional não encontrado.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ((int)$usuario['status'] !== 1) {
        echo json_encode(['ativo' => false, 'mensagem' => 'Este profissional não está disponível para agendamentos no momento.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2. Verificar isenção de pagamento
    $isento = ($usuario['isento_pagamento'] === true || $usuario['isento_pagamento'] === 't');

    if (!$isento) {
        // Verificar isenção por indicações (5 indicados ativos)
        $stmtInd = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM indicacoes i
             INNER JOIN usuarios u ON u.codigo = i.codigo_indicado
             WHERE i.codigo_indicador = :codigo
               AND i.convertido = TRUE
               AND u.status = 1"
        );
        $stmtInd->bindParam(':codigo', $codBarbeiro, PDO::PARAM_INT);
        $stmtInd->execute();
        $resInd = $stmtInd->fetch(PDO::FETCH_ASSOC);
        if ((int)$resInd['total'] >= 5) {
            $isento = true;
        }
    }

    if ($isento) {
        echo json_encode(['ativo' => true, 'mensagem' => 'ok'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 3. Verificar assinatura
    $stmtAs = $conn->prepare(
        "SELECT status_pagamento, data_vencimento
         FROM assinaturas
         WHERE codigo_barbeiro = :codigo
         ORDER BY codigo DESC
         LIMIT 1"
    );
    $stmtAs->bindParam(':codigo', $codBarbeiro, PDO::PARAM_INT);
    $stmtAs->execute();
    $assinatura = $stmtAs->fetch(PDO::FETCH_ASSOC);

    // Sem registro de assinatura = liberar somente durante os 7 dias de trial
    if (!$assinatura) {
        $dataCadastroStr = $usuario['data_cadastro'] ?? null;
        if (!$dataCadastroStr) {
            echo json_encode([
                'ativo' => false,
                'mensagem' => 'Este profissional está com o plano vencido e não está recebendo agendamentos no momento. Entre em contato diretamente com ele.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $hoje = new DateTime('today');
        $cadastro = new DateTime((string)$dataCadastroStr);
        $cadastro->setTime(0, 0, 0);
        $diasDecorridos = (int)$hoje->diff($cadastro)->days;

        if ($cadastro > $hoje || $diasDecorridos <= 7) {
            echo json_encode(['ativo' => true, 'mensagem' => 'ok'], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode([
                'ativo' => false,
                'mensagem' => 'Este profissional está com o plano vencido e não está recebendo agendamentos no momento. Entre em contato diretamente com ele.'
            ], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    $hoje       = new DateTime('today');
    $vencimento = new DateTime($assinatura['data_vencimento']);

    // Ativo somente se estiver dentro do prazo de vencimento
    if ($vencimento >= $hoje) {
        echo json_encode(['ativo' => true, 'mensagem' => 'ok'], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'ativo'    => false,
            'mensagem' => 'Este profissional está com o plano vencido e não está recebendo agendamentos no momento. Entre em contato diretamente com ele.'
        ], JSON_UNESCAPED_UNICODE);
    }

} catch (Exception $e) {
    error_log('[VerificarPlanoPublico] ' . $e->getMessage());
    // Em caso de erro interno, bloquear por segurança para não liberar plano vencido
    echo json_encode([
        'ativo' => false,
        'mensagem' => 'Não foi possível validar o plano no momento. Tente novamente em instantes.'
    ], JSON_UNESCAPED_UNICODE);
}
?>
