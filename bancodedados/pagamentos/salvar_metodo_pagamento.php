<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
error_reporting(0);

require_once '../usuarios/sessao.php';
require_once '../conexao.php';

define('ASAAS_API_URL', 'https://api.asaas.com/v3');
define('ASAAS_API_KEY', getenv('ASAAS_API_KEY') ?: '');

function normalizarSomenteDigitos(string $valor): string {
    return preg_replace('/\D/', '', $valor);
}

function validarLuhn(string $numero): bool {
    $soma = 0;
    $dobrar = false;
    for ($i = strlen($numero) - 1; $i >= 0; $i--) {
        $digito = (int)$numero[$i];
        if ($dobrar) {
            $digito *= 2;
            if ($digito > 9) {
                $digito -= 9;
            }
        }
        $soma += $digito;
        $dobrar = !$dobrar;
    }
    return $soma > 0 && ($soma % 10) === 0;
}

function identificarBandeira(string $numero): string {
    if (preg_match('/^4/', $numero)) return 'VISA';
    if (preg_match('/^(5[1-5]|2[2-7])/', $numero)) return 'MASTERCARD';
    if (preg_match('/^3[47]/', $numero)) return 'AMEX';
    if (preg_match('/^(5067|4576|4011)/', $numero)) return 'ELO';
    if (preg_match('/^(636368|438935|504175|451416|636297)/', $numero)) return 'ELO';
    if (preg_match('/^(6011|65)/', $numero)) return 'DISCOVER';
    if (preg_match('/^3(0[0-5]|[68])/', $numero)) return 'DINERS';
    if (preg_match('/^(35)/', $numero)) return 'JCB';
    return 'OUTRA';
}

function validarExpiracao(string $mes, string $ano): bool {
    if (!preg_match('/^\d{2}$/', $mes) || !preg_match('/^\d{2,4}$/', $ano)) {
        return false;
    }

    $mesInt = (int)$mes;
    $anoInt = (int)$ano;
    if ($mesInt < 1 || $mesInt > 12) {
        return false;
    }
    if ($anoInt < 100) {
        $anoInt += 2000;
    }

    $agoraAno = (int)date('Y');
    $agoraMes = (int)date('m');
    if ($anoInt < $agoraAno) return false;
    if ($anoInt === $agoraAno && $mesInt < $agoraMes) return false;
    if ($anoInt > $agoraAno + 20) return false;

    return true;
}

function registrarAuditoria(PDO $conexao, int $codigoUsuario, string $acao, bool $sucesso, array $detalhes = []): void {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);
        $json = json_encode($detalhes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $stmt = $conexao->prepare(
            "INSERT INTO auditoria_pagamento_cartao (codigo_usuario, acao, sucesso, ip, user_agent, detalhes)
             VALUES (:codigo_usuario, :acao, :sucesso, :ip, :user_agent, :detalhes::jsonb)"
        );
        $stmt->bindValue(':codigo_usuario', $codigoUsuario, PDO::PARAM_INT);
        $stmt->bindValue(':acao', $acao, PDO::PARAM_STR);
        $stmt->bindValue(':sucesso', $sucesso, PDO::PARAM_BOOL);
        $stmt->bindValue(':ip', $ip, $ip ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':user_agent', $ua !== '' ? $ua : null, $ua !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':detalhes', $json !== false ? $json : null, $json !== false ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->execute();
    } catch (Throwable $e) {
        // Auditoria nao pode quebrar o fluxo principal.
    }
}

function limitarTentativas(string $chave): bool {
    $janelaSegundos = 15 * 60;
    $maxTentativas = 5;
    $agora = time();

    if (!isset($_SESSION[$chave]) || !is_array($_SESSION[$chave])) {
        $_SESSION[$chave] = [];
    }

    $_SESSION[$chave] = array_values(array_filter($_SESSION[$chave], function ($ts) use ($agora, $janelaSegundos) {
        return is_int($ts) && ($agora - $ts) <= $janelaSegundos;
    }));

    if (count($_SESSION[$chave]) >= $maxTentativas) {
        return false;
    }

    $_SESSION[$chave][] = $agora;
    return true;
}

function tokenizarCartaoNoAsaas(array $payload): array {
    if (!function_exists('curl_init')) {
        return ['sucesso' => false, 'mensagem' => 'cURL indisponivel', 'dados' => null];
    }

    $ch = curl_init(ASAAS_API_URL . '/creditCard/tokenizeCreditCard');
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'accept: application/json',
            'access_token: ' . ASAAS_API_KEY,
            'User-Agent: MustaBarber/1.0'
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $resp = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erro = curl_error($ch);
    curl_close($ch);

    $body = json_decode((string)$resp, true);

    if ($erro) {
        return ['sucesso' => false, 'mensagem' => 'Falha de conexao com gateway de pagamento', 'dados' => ['erro' => $erro, 'status' => $status]];
    }
    if ($status < 200 || $status >= 300 || !is_array($body)) {
        $msg = 'Nao foi possivel validar o cartao.';
        if (is_array($body) && !empty($body['errors'][0]['description'])) {
            $msg = (string)$body['errors'][0]['description'];
        } elseif (is_array($body) && !empty($body['message'])) {
            $msg = (string)$body['message'];
        }
        return ['sucesso' => false, 'mensagem' => $msg, 'dados' => $body];
    }

    $token = trim((string)($body['creditCardToken'] ?? $body['token'] ?? $body['id'] ?? ''));
    if ($token === '') {
        return ['sucesso' => false, 'mensagem' => 'Token de cartao nao retornado pelo gateway.', 'dados' => $body];
    }

    return ['sucesso' => true, 'mensagem' => 'ok', 'dados' => $body, 'token' => $token];
}

try {
    if (!estaLogado()) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Usuario nao autenticado'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Metodo nao permitido'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Dados invalidos'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $usuario = obterUsuarioLogado();
    $codigoUsuario = (int)$usuario['codigo'];

    $stmtPerfil = $conexao->prepare('SELECT perfil FROM usuarios WHERE codigo = :codigo LIMIT 1');
    $stmtPerfil->bindValue(':codigo', $codigoUsuario, PDO::PARAM_INT);
    $stmtPerfil->execute();
    $rowPerfil = $stmtPerfil->fetch(PDO::FETCH_ASSOC);
    if ((int)($rowPerfil['perfil'] ?? 0) !== 1) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Apenas administradores podem salvar metodo de pagamento.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!limitarTentativas('limite_salvar_cartao_' . $codigoUsuario)) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Muitas tentativas em pouco tempo. Aguarde alguns minutos e tente novamente.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $ultimoEnvio = (int)($_SESSION['ultimo_envio_cartao_' . $codigoUsuario] ?? 0);
    if ($ultimoEnvio > 0 && (time() - $ultimoEnvio) < 3) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Aguarde alguns segundos antes de tentar novamente.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $_SESSION['ultimo_envio_cartao_' . $codigoUsuario] = time();

    $numeroCartao = normalizarSomenteDigitos((string)($input['cartao_numero'] ?? ''));
    $mesValidade = normalizarSomenteDigitos((string)($input['cartao_mes_validade'] ?? ''));
    $anoValidade = normalizarSomenteDigitos((string)($input['cartao_ano_validade'] ?? ''));
    $cvv = normalizarSomenteDigitos((string)($input['cartao_cvv'] ?? ''));
    $titular = trim((string)($input['cartao_titular'] ?? ''));
    $consentimento = (bool)($input['aceite_cobranca_automatica'] ?? false);

    if (!$consentimento) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'E necessario aceitar o termo para salvar cartao e ativar cobranca automatica.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($numeroCartao === '' || strlen($numeroCartao) < 13 || strlen($numeroCartao) > 19) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Numero do cartao invalido'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!validarLuhn($numeroCartao)) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Numero do cartao nao passou na validacao de seguranca'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!validarExpiracao(str_pad($mesValidade, 2, '0', STR_PAD_LEFT), $anoValidade)) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Validade do cartao invalida ou expirada'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!preg_match('/^\d{3,4}$/', $cvv)) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'CVV invalido'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($titular === '' || mb_strlen($titular) > 150) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Nome do titular invalido'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmtUser = $conexao->prepare(
        "SELECT nome, email, cpf, telefone, endereco_cep, endereco_numero
         FROM usuarios
         WHERE codigo = :codigo
         LIMIT 1"
    );
    $stmtUser->bindValue(':codigo', $codigoUsuario, PDO::PARAM_INT);
    $stmtUser->execute();
    $dadosUsuario = $stmtUser->fetch(PDO::FETCH_ASSOC);

    if (!$dadosUsuario) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Usuario nao encontrado'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $cpf = normalizarSomenteDigitos((string)($dadosUsuario['cpf'] ?? ''));
    $cep = normalizarSomenteDigitos((string)($dadosUsuario['endereco_cep'] ?? ''));
    $numeroEndereco = trim((string)($dadosUsuario['endereco_numero'] ?? ''));

    if ($cpf === '' || strlen($cpf) < 11) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'CPF invalido para tokenizacao do cartao'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($cep === '' || strlen($cep) !== 8 || $numeroEndereco === '') {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Endereco incompleto. Preencha CEP e numero antes de salvar o cartao.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $bandeira = identificarBandeira($numeroCartao);
    $final = substr($numeroCartao, -4);
    $mesPad = str_pad($mesValidade, 2, '0', STR_PAD_LEFT);
    $anoPad = strlen($anoValidade) === 2 ? ('20' . $anoValidade) : $anoValidade;

    $payloadTokenizacao = [
        'creditCard' => [
            'holderName' => $titular,
            'number' => $numeroCartao,
            'expiryMonth' => $mesPad,
            'expiryYear' => $anoPad,
            'ccv' => $cvv,
        ],
        'creditCardHolderInfo' => [
            'name' => (string)($dadosUsuario['nome'] ?? $titular),
            'email' => (string)($dadosUsuario['email'] ?? ''),
            'cpfCnpj' => $cpf,
            'postalCode' => $cep,
            'addressNumber' => $numeroEndereco,
            'phone' => normalizarSomenteDigitos((string)($dadosUsuario['telefone'] ?? '')),
            'mobilePhone' => normalizarSomenteDigitos((string)($dadosUsuario['telefone'] ?? '')),
        ]
    ];

    $resultadoToken = tokenizarCartaoNoAsaas($payloadTokenizacao);
    if (!$resultadoToken['sucesso']) {
        registrarAuditoria($conexao, $codigoUsuario, 'tokenizacao_cartao', false, [
            'motivo' => $resultadoToken['mensagem'],
            'bandeira' => $bandeira,
            'final' => $final
        ]);
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Nao foi possivel validar seu cartao: ' . $resultadoToken['mensagem']
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $token = (string)$resultadoToken['token'];

    $stmt = $conexao->prepare(
        "UPDATE usuarios
            SET asaas_cartao_token = :token,
                cartao_bandeira = :bandeira,
                cartao_final = :final,
                cartao_titular = :titular,
                cartao_token_atualizado_em = NOW(),
                pagamento_auto_aceite_em = NOW(),
                pagamento_auto_aceite_ip = :aceite_ip
          WHERE codigo = :codigo"
    );
    $stmt->bindValue(':token', $token, PDO::PARAM_STR);
    $stmt->bindValue(':bandeira', $bandeira !== '' ? $bandeira : null, $bandeira !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':final', $final, PDO::PARAM_STR);
    $stmt->bindValue(':titular', $titular !== '' ? $titular : null, $titular !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':aceite_ip', ($_SERVER['REMOTE_ADDR'] ?? null), isset($_SERVER['REMOTE_ADDR']) ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':codigo', $codigoUsuario, PDO::PARAM_INT);
    $stmt->execute();

    registrarAuditoria($conexao, $codigoUsuario, 'salvar_cartao_tokenizado', true, [
        'bandeira' => $bandeira,
        'final' => $final
    ]);

    $numeroCartao = '';
    $cvv = '';

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Cartao salvo com seguranca',
        'cartao_bandeira' => $bandeira,
        'cartao_final' => $final
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao salvar metodo de pagamento: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
