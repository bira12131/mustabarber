<?php
/**
 * Solicitar Upgrade de Plano � gera fatura no Asaas
 * Sistema: MustaBarber
 * Data: 29/03/2026
 *
 * Fluxo:
 *   1. Valida o upgrade solicitado.
 *   2. Calcula o valor a cobrar (regra dos 3 dias).
 *   3. Cria / localiza cliente no Asaas pelo CPF.
 *   4. Gera cobran�a no Asaas com o valor calculado.
 *   5. Salva "pending_upgrade:<plano>" nas observacoes da assinatura.
 *   6. Retorna invoice_url para o front.
 *
 * O plano SO � alterado quando o webhook confirmar o pagamento.
 *
 * Recebe via POST JSON: { "novo_plano": "premium" }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$logFile = __DIR__ . '/debug_cobranca.log';

require_once '../conexao.php';
require_once '../usuarios/sessao.php';

define('ASAAS_KEY_UP', getenv('ASAAS_API_KEY') ?: '');
define('ASAAS_URL_UP', 'https://api.asaas.com/v3');

function asaasUp(string $endpoint, string $method = 'GET', ?array $data = null): array {
    global $logFile;
    $ch = curl_init(ASAAS_URL_UP . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'access_token: ' . ASAAS_KEY_UP,
            'User-Agent: MustaBarber/1.0',
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE);
            if ($jsonData === false) {
                // For�ar UTF-8 em todos os valores string e tentar novamente
                array_walk_recursive($data, function(&$v) {
                    if (is_string($v)) $v = mb_convert_encoding($v, 'UTF-8', 'UTF-8');
                });
                $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE);
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        }
    }
    $resposta  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    file_put_contents($logFile,
        date('Y-m-d H:i:s') . " [upgrade] {$method} {$endpoint} HTTP={$httpCode} err={$curlError}\n" .
        "  body: " . substr($resposta, 0, 800) . "\n",
        FILE_APPEND
    );
    return [
        'httpCode'  => $httpCode,
        'curlError' => $curlError,
        'dados'     => json_decode($resposta, true),
    ];
}

$planoConfig = [
    'starter'         => ['nome' => 'Solo B\u00e1sico',    'valor' => 52.90],
    'solo-pro'        => ['nome' => 'Solo Pro',           'valor' => 64.90],
    'equipe'          => ['nome' => 'Equipe',             'valor' => 94.90],
    'estabelecimento' => ['nome' => 'Estabelecimento',    'valor' => 136.90],
    'master'          => ['nome' => 'Master',             'valor' => 199.90],
];

$planoParaPerfil = [
    'starter'         => 2,
    'solo-pro'        => 3,
    'equipe'          => 4,
    'estabelecimento' => 6,
    'master'          => 7,
];

$perfilParaPlano = [
    2 => 'starter',
    3 => 'solo-pro',
    4 => 'equipe',
    6 => 'estabelecimento',
    7 => 'master',
];

try {
    if (!estaLogado()) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Usu�rio n�o autenticado'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $usuario       = obterUsuarioLogado();
    $codigoUsuario = (int) $usuario['codigo'];

    $dados     = json_decode(file_get_contents('php://input'), true);
    $novoPlano = strtolower(trim($dados['novo_plano'] ?? ''));

    if (!isset($planoConfig[$novoPlano])) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Plano inv�lido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Buscar dados do usu�rio (nome, CPF, email, telefone, plano atual)
    $stmtUser = $conexao->prepare(
        "SELECT perfil, nome, email, cpf, telefone
         FROM usuarios WHERE codigo = :codigo LIMIT 1"
    );
    $stmtUser->bindParam(':codigo', $codigoUsuario, PDO::PARAM_INT);
    $stmtUser->execute();
    $dadosUser = $stmtUser->fetch(PDO::FETCH_ASSOC);

    if (!$dadosUser) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Usu�rio n�o encontrado.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $planoAtual = $perfilParaPlano[(int)($dadosUser['perfil'] ?? 2)] ?? 'starter';
    $valorAtual = $planoConfig[$planoAtual]['valor'] ?? 52.90;
    $novoValor  = $planoConfig[$novoPlano]['valor'];

    if ($planoAtual === $novoPlano) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Voc� j� est� neste plano.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Buscar assinatura mais recente
    $stmtAssin = $conexao->prepare(
        "SELECT codigo, data_pagamento, status_pagamento, data_vencimento, observacoes
         FROM assinaturas
         WHERE codigo_barbeiro = :codigo
         ORDER BY codigo DESC LIMIT 1"
    );
    $stmtAssin->bindParam(':codigo', $codigoUsuario, PDO::PARAM_INT);
    $stmtAssin->execute();
    $assinatura = $stmtAssin->fetch(PDO::FETCH_ASSOC);

    if (!$assinatura) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Nenhuma assinatura encontrada.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Downgrade � sem cobran�a, aplica imediatamente
    if ($novoValor < $valorAtual) {
        $novoPerfil = $planoParaPerfil[$novoPlano] ?? 2;
        $stmtDg = $conexao->prepare('UPDATE usuarios SET plano_trial = :plano, perfil = :perfil WHERE codigo = :codigo');
        $stmtDg->bindParam(':plano',  $novoPlano);
        $stmtDg->bindParam(':perfil', $novoPerfil, PDO::PARAM_INT);
        $stmtDg->bindParam(':codigo', $codigoUsuario, PDO::PARAM_INT);
        $stmtDg->execute();

        $stmtDgVal = $conexao->prepare('UPDATE assinaturas SET valor_mensalidade = :valor WHERE codigo = :cod');
        $stmtDgVal->bindParam(':valor', $novoValor);
        $stmtDgVal->bindParam(':cod',   $assinatura['codigo'], PDO::PARAM_INT);
        $stmtDgVal->execute();

        file_put_contents($logFile, date('Y-m-d H:i:s') . " [downgrade] barbeiro {$codigoUsuario} -> {$novoPlano}\n", FILE_APPEND);
        echo json_encode([
            'sucesso'    => true,
            'downgrade'  => true,
            'novo_plano' => $novoPlano,
            'nome_plano' => $planoConfig[$novoPlano]['nome'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Regra dos 3 dias
    $dentroDe3Dias = false;
    $valorCobrar   = $novoValor;

    if ($assinatura['data_pagamento'] && (int) $assinatura['status_pagamento'] === 2) {
        $dtPag = new DateTime($assinatura['data_pagamento']);
        $hoje  = new DateTime('today');
        if ($dtPag > $hoje) $dtPag = clone $hoje;
        $diasDesde = (int) $hoje->diff($dtPag)->days;
        if ($diasDesde <= 3) {
            $dentroDe3Dias = true;
            $valorCobrar   = round($novoValor - $valorAtual, 2);
        }
    }

    // Dados para Asaas
    $cpf      = preg_replace('/\D/', '', $dadosUser['cpf'] ?? '');
    $nome     = trim($dadosUser['nome'] ?? '');
    $email    = trim($dadosUser['email'] ?? '');
    $telefone = preg_replace('/\D/', '', $dadosUser['telefone'] ?? '');

    if (!$nome) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Nome do usu�rio n�o encontrado.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!$cpf || strlen($cpf) < 11) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'CPF inv�lido ou n�o cadastrado. Atualize seu perfil.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!function_exists('curl_init')) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'cURL n�o habilitado no servidor.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Buscar ou criar cliente Asaas
    $customerId = null;
    $busca = asaasUp('/customers?cpfCnpj=' . urlencode($cpf) . '&deleted=false');
    if ($busca['httpCode'] === 200 && !empty($busca['dados']['data'])) {
        foreach ($busca['dados']['data'] as $cust) {
            if (!empty($cust['id']) && empty($cust['deleted'])) {
                $customerId = trim($cust['id']);
                break;
            }
        }
    }
    file_put_contents($logFile, date('Y-m-d H:i:s') . " [upgrade] customerId={$customerId}\n", FILE_APPEND);
    if (!$customerId) {
        $dadosCliente = ['name' => $nome, 'cpfCnpj' => $cpf, 'externalReference' => (string) $codigoUsuario];
        if ($email)    $dadosCliente['email']       = $email;
        if ($telefone) $dadosCliente['mobilePhone'] = $telefone;
        $criar = asaasUp('/customers', 'POST', $dadosCliente);
        if (empty($criar['dados']['id'])) {
            $erroMsg = $criar['dados']['errors'][0]['description'] ?? ($criar['dados']['message'] ?? 'Erro ao criar cliente no Asaas');
            echo json_encode(['sucesso' => false, 'mensagem' => $erroMsg], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $customerId = $criar['dados']['id'];
    }

    // Definir data de vencimento (hoje + 1 se j� vencida)
    $dataVenc = $assinatura['data_vencimento'] ?? date('Y-m-d');
    if (strtotime($dataVenc) < strtotime('today')) {
        $dataVenc = date('Y-m-d', strtotime('+1 day'));
    }

    $nomePlano = $planoConfig[$novoPlano]['nome'];
    $descricao = 'Upgrade para ' . $nomePlano . ' - MustaBarber';
    if ($dentroDe3Dias) {
        $descricao .= ' (taxa diferenca: R$ ' . number_format($valorCobrar, 2, ',', '.') . ')';
    }

    $payloadCob = [
        'customer'          => $customerId,
        'billingType'       => 'UNDEFINED',
        'value'             => (float) $valorCobrar,
        'dueDate'           => $dataVenc,
        'description'       => $descricao,
        'externalReference' => (string) $codigoUsuario,
    ];

    file_put_contents($logFile,
        date('Y-m-d H:i:s') . " [upgrade] payload_pagamento=" . json_encode($payloadCob) . "\n",
        FILE_APPEND
    );

    $criarCob = asaasUp('/payments', 'POST', $payloadCob);

    if (!in_array($criarCob['httpCode'], [200, 201]) || empty($criarCob['dados']['id'])) {
        $erroMsg = $criarCob['dados']['errors'][0]['description']
            ?? ($criarCob['dados']['message'] ?? 'Erro ao gerar cobran�a no Asaas');
        echo json_encode(['sucesso' => false, 'mensagem' => $erroMsg], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $paymentId  = $criarCob['dados']['id'];
    $invoiceUrl = $criarCob['dados']['invoiceUrl'] ?? '';

    // Salvar pending_upgrade nas observacoes (N�O altera o plano ainda)
    $novasObs = 'asaas_payment_id:' . $paymentId
        . '|asaas_customer_id:' . $customerId
        . '|pending_upgrade:' . $novoPlano
        . '|valor_upgrade:' . $valorCobrar;

    $stmtObs = $conexao->prepare(
        "UPDATE assinaturas SET observacoes = :obs WHERE codigo = :cod_assin"
    );
    $stmtObs->bindParam(':obs',       $novasObs);
    $stmtObs->bindParam(':cod_assin', $assinatura['codigo'], PDO::PARAM_INT);
    $stmtObs->execute();

    echo json_encode([
        'sucesso'          => true,
        'invoice_url'      => $invoiceUrl,
        'payment_id'       => $paymentId,
        'dentro_de_3_dias' => $dentroDe3Dias,
        'valor_cobrar'     => $valorCobrar,
        'novo_plano'       => $novoPlano,
        'nome_plano'       => $nomePlano,
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro no banco de dados: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['sucesso' => false, 'mensagem' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
