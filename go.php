<?php
ini_set('display_errors', 0);
error_reporting(0);

function base64urlDecode(string $data): string
{
    $resto = strlen($data) % 4;
    if ($resto > 0) {
        $data .= str_repeat('=', 4 - $resto);
    }
    $decoded = base64_decode(strtr($data, '-_', '+/'), true);
    return $decoded === false ? '' : $decoded;
}

function base62Decode(string $texto): int
{
    $alfabeto = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $map = array_flip(str_split($alfabeto));

    $texto = trim($texto);
    if ($texto === '') {
        return -1;
    }

    $valor = 0;
    $chars = str_split($texto);
    foreach ($chars as $ch) {
        if (!isset($map[$ch])) {
            return -1;
        }
        $valor = ($valor * 62) + $map[$ch];
    }

    return $valor;
}

function base62Encode(int $num): string
{
    $alfabeto = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    if ($num <= 0) {
        return '0';
    }

    $out = '';
    while ($num > 0) {
        $resto = $num % 62;
        $out = $alfabeto[$resto] . $out;
        $num = intdiv($num, 62);
    }

    return $out;
}

function obterSegredoLinks(): string
{
    $candidatos = [
        getenv('MB_LINK_SECRET') ?: '',
        getenv('APP_KEY') ?: '',
        $_ENV['MB_LINK_SECRET'] ?? '',
        $_SERVER['MB_LINK_SECRET'] ?? ''
    ];

    foreach ($candidatos as $valor) {
        $valor = trim((string)$valor);
        if ($valor !== '') {
            return $valor;
        }
    }

    return hash('sha256', 'musta-links-default-v1');
}

function validarCodigoCurto(string $codigo): ?array
{
    $codigo = trim($codigo);
    if (!preg_match('/^[a-zA-Z0-9]{4,20}$/', $codigo)) {
        return null;
    }

    $tipo = substr($codigo, 0, 1);
    $checkRecebido = substr($codigo, -2);
    $miolo = substr($codigo, 0, -2);
    $idEncoded = substr($miolo, 1);

    if (!in_array($tipo, ['a', 'p'], true) || $idEncoded === '') {
        return null;
    }

    $segredo = obterSegredoLinks();
    $crc = (int)sprintf('%u', crc32($miolo . '|' . $segredo));
    $checkEsperado = base62Encode($crc % (62 * 62));
    $checkEsperado = str_pad($checkEsperado, 2, '0', STR_PAD_LEFT);
    $checkEsperado = substr($checkEsperado, -2);

    if (!hash_equals($checkEsperado, $checkRecebido)) {
        return null;
    }

    $obfuscado = base62Decode($idEncoded);
    if ($obfuscado < 0) {
        return null;
    }

    $chave = (int)sprintf('%u', crc32('uid|' . $segredo));
    $usuario = (($obfuscado ^ $chave) & 0x7fffffff);

    if ($usuario <= 0) {
        return null;
    }

    return [
        't' => $tipo,
        'u' => $usuario
    ];
}

function validarTokenLink(string $token): ?array
{
    $partes = explode('.', $token, 2);
    if (count($partes) !== 2) {
        return null;
    }

    [$payloadEncoded, $assinaturaEncoded] = $partes;
    if ($payloadEncoded === '' || $assinaturaEncoded === '') {
        return null;
    }

    $assinaturaCalculada = hash_hmac('sha256', $payloadEncoded, obterSegredoLinks(), true);
    $assinaturaRecebida = base64urlDecode($assinaturaEncoded);

    if ($assinaturaRecebida === '' || !hash_equals($assinaturaCalculada, $assinaturaRecebida)) {
        return null;
    }

    $payloadJson = base64urlDecode($payloadEncoded);
    if ($payloadJson === '') {
        return null;
    }

    $payload = json_decode($payloadJson, true);
    if (!is_array($payload)) {
        return null;
    }

    $tipo = isset($payload['t']) ? (string)$payload['t'] : '';
    $usuario = isset($payload['u']) ? (int)$payload['u'] : 0;
    $expira = isset($payload['e']) ? (int)$payload['e'] : 0;

    if ($tipo === '' || $usuario <= 0 || $expira <= 0 || time() > $expira) {
        return null;
    }

    return [
        't' => $tipo,
        'u' => $usuario
    ];
}

function respostaErro(string $mensagem): void
{
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo $mensagem;
    exit;
}

$t = isset($_GET['t']) ? trim((string)$_GET['t']) : '';
$u = isset($_GET['u']) ? (int)$_GET['u'] : 0;
$s = isset($_GET['s']) ? trim((string)$_GET['s']) : '';
$k = isset($_GET['k']) ? trim((string)$_GET['k']) : '';
$c = isset($_GET['c']) ? trim((string)$_GET['c']) : '';

if ($c !== '') {
    $dadosCodigo = validarCodigoCurto($c);
    if ($dadosCodigo === null) {
        respostaErro('Link encurtado invalido.');
    }

    $t = $dadosCodigo['t'];
    $u = $dadosCodigo['u'];
}

if ($k !== '') {
    $dadosToken = validarTokenLink($k);
    if ($dadosToken === null) {
        respostaErro('Link encurtado invalido ou expirado.');
    }

    $t = $dadosToken['t'];
    $u = $dadosToken['u'];
}

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';

$basePath = '';
$dir = rtrim(str_replace('\\', '/', dirname($scriptPath)), '/');
if ($dir !== '' && $dir !== '.') {
    $basePath = $dir;
}

$baseUrl = $protocol . '://' . $host . $basePath;

switch ($t) {
    case 'a':
        if ($u <= 0) {
            respostaErro('Link invalido para agendamento.');
        }
        $destino = $baseUrl . '/paginas/agendamentoclientes/agendar.html?barbeiro=' . $u;
        break;

    case 'p':
        if ($u <= 0) {
            respostaErro('Link invalido para solicitacao de plano.');
        }
        $destino = $baseUrl . '/paginas/agendamentoclientes/solicitar-plano.html?barbeiro=' . $u;
        break;

    case 'r':
        if ($s === '' || !preg_match('/^[a-z0-9]{6,32}$/', $s)) {
            respostaErro('Link de indicacao invalido.');
        }
        $destino = $baseUrl . '/paginas/usuarios/cadastro-barbeiro.html?ref=' . rawurlencode($s);
        break;

    default:
        respostaErro('Tipo de link nao suportado.');
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Location: ' . $destino, true, 302);
exit;
