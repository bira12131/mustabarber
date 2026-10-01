<?php
/**
 * Criar Cobran&#65533;a - Asaas
 * Sistema: MustaBarber
 * Gera uma cobran&#65533;a (PIX + Cart&#65533;o + Boleto) via API do Asaas
 */

// Resposta sempre em JSON
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Log para diagn&#65533;stico
$logFile = __DIR__ . '/debug_cobranca.log';

// Shutdown function que SEMPRE retorna JSON, mesmo em erro fatal
register_shutdown_function(function() use ($logFile) {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        $msg = 'Erro fatal: ' . $err['message'] . ' em ' . $err['file'] . ':' . $err['line'];
        file_put_contents($logFile, date('Y-m-d H:i:s') . " - FATAL: $msg\n", FILE_APPEND);
        echo json_encode(['sucesso' => false, 'mensagem' => $msg], JSON_UNESCAPED_UNICODE);
    }
});

ini_set('display_errors', 1);
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

file_put_contents($logFile, date('Y-m-d H:i:s') . " - In&#65533;cio do script\n");

file_put_contents($logFile, date('Y-m-d H:i:s') . " - Carregando conexao.php\n", FILE_APPEND);
require_once '../conexao.php';
file_put_contents($logFile, date('Y-m-d H:i:s') . " - Carregando sessao.php\n", FILE_APPEND);
require_once '../usuarios/sessao.php';
file_put_contents($logFile, date('Y-m-d H:i:s') . " - Requires OK\n", FILE_APPEND);

ob_start();

// ============================================================
// CONFIGURA&#65533;&#65533;O &#65533; Asaas
// ============================================================
define('ASAAS_API_KEY', getenv('ASAAS_API_KEY') ?: '');
define('ASAAS_API_URL', 'https://api.asaas.com/v3');

/**
 * Faz uma requisi&#65533;&#65533;o &#65533; API do Asaas
 */
function asaasRequest(string $endpoint, string $method = 'GET', ?array $data = null): array {
    global $logFile;

    $url = ASAAS_API_URL . $endpoint;
    $ch  = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'access_token: ' . ASAAS_API_KEY,
            'User-Agent: MustaBarber/1.0',
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    }

    $resposta  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    file_put_contents($logFile, date('Y-m-d H:i:s') . " - Asaas {$method} {$endpoint} HTTP={$httpCode} | Erro={$curlError} | Resp=" . substr($resposta ?: '(vazio)', 0, 500) . "\n", FILE_APPEND);

    return [
        'httpCode'  => $httpCode,
        'curlError' => $curlError,
        'resposta'  => $resposta,
        'dados'     => json_decode($resposta, true),
    ];
}

try {
    file_put_contents($logFile, date('Y-m-d H:i:s') . " - estaLogado: " . (estaLogado() ? 'SIM' : 'N&#65533;O') . "\n", FILE_APPEND);

    if (!estaLogado()) {
        ob_end_clean();
        echo json_encode(['sucesso' => false, 'mensagem' => 'Usu&#65533;rio n&#65533;o autenticado']);
        exit;
    }

    $usuario = obterUsuarioLogado();
    $codigoUsuario = (int) $usuario['codigo'];

    // Buscar assinatura vigente do barbeiro
    $stmt = $conexao->prepare(
        "SELECT a.codigo, a.valor_mensalidade, a.data_vencimento, a.status_pagamento,
                u.nome, u.email, u.cpf, u.telefone,
                u.asaas_customer_id,
                u.pagamento_auto_cartao,
                u.asaas_cartao_token,
                u.endereco_cep,
                u.endereco_logradouro,
                u.endereco_numero,
                u.endereco_complemento,
                u.endereco_bairro,
                u.endereco_cidade,
                u.endereco_estado
         FROM assinaturas a
         INNER JOIN usuarios u ON u.codigo = a.codigo_barbeiro
         WHERE a.codigo_barbeiro = :codigo
         ORDER BY a.codigo DESC LIMIT 1"
    );
    $stmt->bindParam(':codigo', $codigoUsuario, PDO::PARAM_INT);
    $stmt->execute();
    $assinatura = $stmt->fetch(PDO::FETCH_ASSOC);

    file_put_contents($logFile, date('Y-m-d H:i:s') . " - Assinatura: " . ($assinatura ? 'ENCONTRADA (cod=' . $assinatura['codigo'] . ', valor=' . $assinatura['valor_mensalidade'] . ')' : 'N�O ENCONTRADA') . "\n", FILE_APPEND);

    $ehPrimeiraCobranca = false;

    if (!$assinatura) {
        // Nenhuma assinatura ainda: criar registro inicial com base no perfil do usu�rio
        $stmtUser = $conexao->prepare(
            "SELECT perfil, nome, email, cpf, telefone, dia_vencimento, data_cadastro,
                    asaas_customer_id, pagamento_auto_cartao, asaas_cartao_token,
                    endereco_cep, endereco_logradouro, endereco_numero,
                    endereco_complemento, endereco_bairro, endereco_cidade, endereco_estado
             FROM usuarios
             WHERE codigo = :cod LIMIT 1"
        );
        $stmtUser->bindParam(':cod', $codigoUsuario, PDO::PARAM_INT);
        $stmtUser->execute();
        $dadosUser = $stmtUser->fetch(PDO::FETCH_ASSOC);

        if (!$dadosUser) {
            ob_end_clean();
            echo json_encode(['sucesso' => false, 'mensagem' => 'Usu�rio n�o encontrado.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Tabela de pre�os por perfil
        $precoPorPerfil = [2 => 52.90, 3 => 64.90, 4 => 94.90, 6 => 136.90, 7 => 199.90];
        $codigoPerfil   = (int) $dadosUser['perfil'];
        $valorMens      = $precoPorPerfil[$codigoPerfil] ?? 52.90;

        // Verificar se h� indica��o de embaixador com desconto pendente
        $embCodigo   = null;
        $embDesconto = 0.0;
        $valorSemDesc = null;
        $stmtEmb = $conexao->prepare(
            "SELECT codigo_embaixador, desconto_aplicado
             FROM indicacoes
             WHERE codigo_indicado = :cod
               AND codigo_embaixador IS NOT NULL
               AND desconto_aplicado > 0
             ORDER BY data_cadastro DESC
             LIMIT 1"
        );
        $stmtEmb->bindParam(':cod', $codigoUsuario, PDO::PARAM_INT);
        $stmtEmb->execute();
        $rowEmb = $stmtEmb->fetch(PDO::FETCH_ASSOC);
        if ($rowEmb) {
            $embCodigo   = (int)$rowEmb['codigo_embaixador'];
            $embDesconto = (float)$rowEmb['desconto_aplicado'];
            if ($embDesconto > 0 && $embDesconto <= 100) {
                $valorSemDesc = $valorMens;
                $valorMens    = round($valorMens * (1 - $embDesconto / 100), 2);
                file_put_contents($logFile, date('Y-m-d H:i:s') . " - Desconto embaixador aplicado: {$embDesconto}% -> R$ {$valorMens}\n", FILE_APPEND);
            }
        }

        $dataInicio  = date('Y-m-d');

        // Primeira cobrança: sempre 7 dias após o cadastro (período de trial)
        $dataCadUser = $dadosUser['data_cadastro'] ?? $dataInicio;
        $primVenc    = new DateTime($dataCadUser);
        $primVenc->modify('+7 days');
        $dataVenc    = $primVenc->format('Y-m-d');
        file_put_contents($logFile, date('Y-m-d H:i:s') . " - Primeira cobrança: vencimento em 7 dias após cadastro -> {$dataVenc}\n", FILE_APPEND);
        $ehPrimeiraCobranca = true;

        $stmtIns = $conexao->prepare(
            "INSERT INTO assinaturas
                (codigo_barbeiro, codigo_perfil, data_inicio, data_vencimento,
                 valor_mensalidade, status_pagamento, codigo_embaixador,
                 desconto_embaixador, valor_sem_desconto)
             VALUES
                (:barbeiro, :perfil, :inicio, :venc, :valor, 1,
                 :cod_emb, :desc_emb, :val_sem_desc)"
        );
        $stmtIns->execute([
            ':barbeiro'     => $codigoUsuario,
            ':perfil'       => $codigoPerfil,
            ':inicio'       => $dataInicio,
            ':venc'         => $dataVenc,
            ':valor'        => $valorMens,
            ':cod_emb'      => $embCodigo,
            ':desc_emb'     => $embDesconto,
            ':val_sem_desc' => $valorSemDesc,
        ]);
        $novaAssinaturaId = (int) $conexao->lastInsertId();

        file_put_contents($logFile, date('Y-m-d H:i:s') . " - Assinatura inicial criada: id={$novaAssinaturaId}, valor={$valorMens}\n", FILE_APPEND);

        // Recarregar assinatura rec�m-criada
        $stmt2 = $conexao->prepare(
            "SELECT a.codigo, a.valor_mensalidade, a.data_vencimento, a.status_pagamento,
                    u.nome, u.email, u.cpf, u.telefone,
                    u.asaas_customer_id,
                    u.pagamento_auto_cartao,
                    u.asaas_cartao_token,
                    u.endereco_cep,
                    u.endereco_logradouro,
                    u.endereco_numero,
                    u.endereco_complemento,
                    u.endereco_bairro,
                    u.endereco_cidade,
                    u.endereco_estado
             FROM assinaturas a
             INNER JOIN usuarios u ON u.codigo = a.codigo_barbeiro
             WHERE a.codigo = :id LIMIT 1"
        );
        $stmt2->bindParam(':id', $novaAssinaturaId, PDO::PARAM_INT);
        $stmt2->execute();
        $assinatura = $stmt2->fetch(PDO::FETCH_ASSOC);

        if (!$assinatura) {
            ob_end_clean();
            echo json_encode(['sucesso' => false, 'mensagem' => 'Erro ao criar assinatura inicial.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    if (!function_exists('curl_init')) {
        ob_end_clean();
        echo json_encode(['sucesso' => false, 'mensagem' => 'Extens&#65533;o cURL n&#65533;o est&#65533; habilitada no servidor.']);
        exit;
    }

    // Para assinatura existente: verificar se há valor proporcional de transição armazenado
    $valorOverride = null;
    if (!$ehPrimeiraCobranca) {
        $obsExistente = $assinatura['observacoes'] ?? '';
        if (preg_match('/valor_prox_cobranca:([\d.]+)/', $obsExistente, $mMatch)) {
            $valorOverride = (float) $mMatch[1];
            file_put_contents($logFile, date('Y-m-d H:i:s') . " - Usando valor proporcional de transição: R$ {$valorOverride}\n", FILE_APPEND);
        }
    }

    $valor      = $valorOverride ?? (float) $assinatura['valor_mensalidade'];
    $nome       = trim($assinatura['nome']);
    $email      = $assinatura['email'];
    $cpf        = preg_replace('/\D/', '', $assinatura['cpf'] ?? '');
    $telefone   = preg_replace('/\D/', '', $assinatura['telefone'] ?? '');
    $customerIdSalvo = trim((string)($assinatura['asaas_customer_id'] ?? ''));
    $pagamentoAutoCartao = filter_var($assinatura['pagamento_auto_cartao'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $cartaoToken = trim((string)($assinatura['asaas_cartao_token'] ?? ''));
    $cep = preg_replace('/\D/', '', (string)($assinatura['endereco_cep'] ?? ''));
    $logradouro = trim((string)($assinatura['endereco_logradouro'] ?? ''));
    $numeroEndereco = trim((string)($assinatura['endereco_numero'] ?? ''));
    $complemento = trim((string)($assinatura['endereco_complemento'] ?? ''));
    $bairro = trim((string)($assinatura['endereco_bairro'] ?? ''));
    $cidade = trim((string)($assinatura['endereco_cidade'] ?? ''));
    $estado = strtoupper(trim((string)($assinatura['endereco_estado'] ?? '')));
    $codAssin   = (int) $assinatura['codigo'];
    $vencimento = $assinatura['data_vencimento'];

    // Valida&#65533;&#65533;es b&#65533;sicas
    if (!$nome) {
        ob_end_clean();
        echo json_encode(['sucesso' => false, 'mensagem' => 'Nome do usu&#65533;rio n&#65533;o encontrado no cadastro.']);
        exit;
    }
    if (!$cpf || strlen($cpf) < 11) {
        ob_end_clean();
        echo json_encode(['sucesso' => false, 'mensagem' => 'CPF inv&#65533;lido ou n&#65533;o cadastrado. Atualize seu perfil.']);
        exit;
    }

    if ($cep === '' || $logradouro === '' || $numeroEndereco === '' || $bairro === '' || $cidade === '' || $estado === '') {
        ob_end_clean();
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Para gerar nota fiscal e cobran&ccedil;a segura, complete seu endere&ccedil;o em Configura&ccedil;&otilde;es.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (strlen($cep) !== 8 || !preg_match('/^[A-Z]{2}$/', $estado)) {
        ob_end_clean();
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Endere&ccedil;o inv&aacute;lido. Verifique CEP e UF em Configura&ccedil;&otilde;es.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ============================================================
    // PASSO 1 &#65533; Buscar ou criar cliente no Asaas
    // ============================================================
    $customerId = $customerIdSalvo !== '' ? $customerIdSalvo : null;

    // Tentar encontrar cliente pelo CPF
    if (!$customerId) {
        $busca = asaasRequest('/customers?cpfCnpj=' . urlencode($cpf));
        if ($busca['httpCode'] === 200 && !empty($busca['dados']['data'][0]['id'])) {
            $customerId = $busca['dados']['data'][0]['id'];
            file_put_contents($logFile, date('Y-m-d H:i:s') . " - Cliente Asaas encontrado: {$customerId}\n", FILE_APPEND);
        }
    }

    // Se n&#65533;o encontrou, criar novo cliente
    if (!$customerId) {
        $dadosCliente = [
            'name'                 => $nome,
            'cpfCnpj'             => $cpf,
            'externalReference'   => (string) $codigoUsuario,
            'notificationDisabled' => false,
            'postalCode'          => $cep,
            'address'             => $logradouro,
            'addressNumber'       => $numeroEndereco,
            'province'            => $bairro,
        ];
        if ($complemento !== '') $dadosCliente['complement'] = $complemento;
        // S&#65533; enviar email e telefone se preenchidos
        if ($email) $dadosCliente['email'] = $email;
        if ($telefone) $dadosCliente['mobilePhone'] = $telefone;

        file_put_contents($logFile, date('Y-m-d H:i:s') . " - Criando cliente: " . json_encode($dadosCliente) . "\n", FILE_APPEND);

        $criarCliente = asaasRequest('/customers', 'POST', $dadosCliente);

        if ($criarCliente['curlError']) {
            ob_end_clean();
            echo json_encode(['sucesso' => false, 'mensagem' => 'Erro de conex&#65533;o com Asaas: ' . $criarCliente['curlError']]);
            exit;
        }

        if (empty($criarCliente['dados']['id'])) {
            $erro = '';
            if (!empty($criarCliente['dados']['errors'])) {
                $mensagens = [];
                foreach ($criarCliente['dados']['errors'] as $e) {
                    $mensagens[] = $e['description'] ?? $e['code'] ?? 'Erro';
                }
                $erro = implode('; ', $mensagens);
            } else {
                $erro = $criarCliente['dados']['message'] ?? 'Resposta HTTP ' . $criarCliente['httpCode'];
            }
            file_put_contents($logFile, date('Y-m-d H:i:s') . " - Erro criar cliente: HTTP={$criarCliente['httpCode']} Resp={$criarCliente['resposta']}\n", FILE_APPEND);
            ob_end_clean();
            echo json_encode(['sucesso' => false, 'mensagem' => 'Erro ao criar cliente no Asaas: ' . $erro]);
            exit;
        }

        $customerId = $criarCliente['dados']['id'];
        file_put_contents($logFile, date('Y-m-d H:i:s') . " - Cliente Asaas criado: {$customerId}\n", FILE_APPEND);

        $stmtSalvarCustomer = $conexao->prepare(
            "UPDATE usuarios SET asaas_customer_id = :customer WHERE codigo = :codigo"
        );
        $stmtSalvarCustomer->bindValue(':customer', $customerId);
        $stmtSalvarCustomer->bindValue(':codigo', $codigoUsuario, PDO::PARAM_INT);
        $stmtSalvarCustomer->execute();
    } else {
        $payloadAtualizacao = [
            'name' => $nome,
            'email' => $email ?: null,
            'mobilePhone' => $telefone ?: null,
            'postalCode' => $cep,
            'address' => $logradouro,
            'addressNumber' => $numeroEndereco,
            'province' => $bairro,
            'externalReference' => (string)$codigoUsuario,
        ];
        if ($complemento !== '') {
            $payloadAtualizacao['complement'] = $complemento;
        }
        asaasRequest('/customers/' . rawurlencode($customerId), 'POST', $payloadAtualizacao);

        $stmtSalvarCustomer = $conexao->prepare(
            "UPDATE usuarios SET asaas_customer_id = :customer WHERE codigo = :codigo"
        );
        $stmtSalvarCustomer->bindValue(':customer', $customerId);
        $stmtSalvarCustomer->bindValue(':codigo', $codigoUsuario, PDO::PARAM_INT);
        $stmtSalvarCustomer->execute();
    }

    // ============================================================
    // PASSO 2 &#65533; Criar cobran&#65533;a no Asaas
    // ============================================================
    $dataVencimento = date('Y-m-d', strtotime($vencimento));
    // Se o vencimento j&#65533; passou, usar amanh&#65533;
    if (strtotime($dataVencimento) < strtotime('today')) {
        $dataVencimento = date('Y-m-d', strtotime('+1 day'));
    }

    $payloadCobranca = [
        'customer'          => $customerId,
        'billingType'       => 'UNDEFINED', // permite PIX, Cart&#65533;o e Boleto
        'value'             => $valor,
        'dueDate'           => $dataVencimento,
        'description'       => 'Mensalidade MustaBarber - vencimento ' . date('d/m/Y', strtotime($vencimento)),
        'externalReference' => (string) $codigoUsuario,
    ];

    if ($pagamentoAutoCartao && $cartaoToken !== '') {
        $payloadCobranca['billingType'] = 'CREDIT_CARD';
        $payloadCobranca['creditCardToken'] = $cartaoToken;
        $payloadCobranca['authorizedOnly'] = false;
    }

    $criarCobranca = asaasRequest('/payments', 'POST', $payloadCobranca);

    if (!in_array($criarCobranca['httpCode'], [200, 201]) || empty($criarCobranca['dados']['id'])) {
        $erro = '';
        if (!empty($criarCobranca['dados']['errors'])) {
            $erro = $criarCobranca['dados']['errors'][0]['description'] ?? 'Erro desconhecido';
        } else {
            $erro = $criarCobranca['dados']['message'] ?? 'Erro ao criar cobran&#65533;a';
        }
        ob_end_clean();
        echo json_encode(['sucesso' => false, 'mensagem' => 'Asaas retornou erro: ' . $erro, 'resposta_asaas' => $criarCobranca['resposta']]);
        exit;
    }

    $cobranca = $criarCobranca['dados'];

    // Salvar ID da cobran&#65533;a Asaas na tabela para rastreamento
    $stmtUpdate = $conexao->prepare(
        "UPDATE assinaturas SET observacoes = :obs WHERE codigo_barbeiro = :cod AND codigo = :codAssin"
    );
    $marcadorPrimeira = $ehPrimeiraCobranca ? '|primeira_cobranca:true' : '';
    $obs = 'asaas_payment_id:' . $cobranca['id'] . '|asaas_customer_id:' . $customerId . $marcadorPrimeira;
    $stmtUpdate->bindParam(':obs',      $obs);
    $stmtUpdate->bindParam(':cod',      $codigoUsuario, PDO::PARAM_INT);
    $stmtUpdate->bindParam(':codAssin', $codAssin,      PDO::PARAM_INT);
    $stmtUpdate->execute();

    ob_end_clean();
    echo json_encode([
        'sucesso'    => true,
        'payment_id' => $cobranca['id'],
        'invoiceUrl' => $cobranca['invoiceUrl'],
        'valor'      => number_format($valor, 2, ',', '.'),
        'vencimento' => date('d/m/Y', strtotime($vencimento)),
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    $lixo = ob_get_clean();
    error_log('[Asaas] Erro DB: ' . $e->getMessage() . ' | Output capturado: ' . $lixo);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro de banco de dados: ' . $e->getMessage()]);
} catch (Exception $e) {
    $lixo = ob_get_clean();
    error_log('[Asaas] Erro: ' . $e->getMessage() . ' | Output capturado: ' . $lixo);
    echo json_encode(['sucesso' => false, 'mensagem' => $e->getMessage()]);
}
?>
