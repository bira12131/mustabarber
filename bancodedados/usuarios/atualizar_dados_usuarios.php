<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
error_reporting(0);

require_once 'sessao.php';
require_once '../conexao.php';

try {
    if (!estaLogado()) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Usu&aacute;rio n&atilde;o autenticado'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['sucesso' => false, 'mensagem' => 'M&eacute;todo n&atilde;o permitido'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Dados inv&aacute;lidos'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $usuario = obterUsuarioLogado();
    $codigoUsuario = intval($usuario['codigo']);

    $nome     = trim($input['nome'] ?? '');
    $telefone = trim($input['telefone'] ?? '');
    $email    = trim($input['email'] ?? '');
    $enderecoCep = preg_replace('/\D/', '', (string)($input['endereco_cep'] ?? ''));
    $enderecoLogradouro = trim((string)($input['endereco_logradouro'] ?? ''));
    $enderecoNumero = trim((string)($input['endereco_numero'] ?? ''));
    $enderecoComplemento = trim((string)($input['endereco_complemento'] ?? ''));
    $enderecoBairro = trim((string)($input['endereco_bairro'] ?? ''));
    $enderecoCidade = trim((string)($input['endereco_cidade'] ?? ''));
    $enderecoEstado = strtoupper(trim((string)($input['endereco_estado'] ?? '')));

    if (empty($nome) || empty($email) || empty($telefone)) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Nome, e-mail e telefone s&atilde;o obrigat&oacute;rios'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Validar formato de e-mail
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'E-mail inv&aacute;lido'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($enderecoCep !== '' && strlen($enderecoCep) !== 8) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'CEP inv&aacute;lido. Informe 8 d&iacute;gitos.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($enderecoEstado !== '' && !preg_match('/^[A-Z]{2}$/', $enderecoEstado)) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'UF inv&aacute;lida. Use 2 letras, ex: SP.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $camposEndereco = [
        $enderecoCep,
        $enderecoLogradouro,
        $enderecoNumero,
        $enderecoBairro,
        $enderecoCidade,
        $enderecoEstado
    ];
    $totalPreenchidoEndereco = 0;
    foreach ($camposEndereco as $campo) {
        if ($campo !== '') {
            $totalPreenchidoEndereco++;
        }
    }

    if ($totalPreenchidoEndereco > 0 && $totalPreenchidoEndereco < count($camposEndereco)) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Para dados fiscais, preencha o endere&ccedil;o completo: CEP, logradouro, n&uacute;mero, bairro, cidade e UF.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Verificar se o e-mail j&aacute; pertence a outro usu&aacute;rio
    $stmtCheck = $conexao->prepare(
        "SELECT codigo FROM usuarios WHERE email = :email AND codigo != :codigo LIMIT 1"
    );
    $stmtCheck->bindParam(':email', $email);
    $stmtCheck->bindParam(':codigo', $codigoUsuario, PDO::PARAM_INT);
    $stmtCheck->execute();
    if ($stmtCheck->fetch()) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Este e-mail j&aacute; est&aacute; sendo utilizado por outro usu&aacute;rio'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $conexao->prepare(
        "UPDATE usuarios
            SET nome = :nome,
                email = :email,
                telefone = :telefone,
                endereco_cep = :endereco_cep,
                endereco_logradouro = :endereco_logradouro,
                endereco_numero = :endereco_numero,
                endereco_complemento = :endereco_complemento,
                endereco_bairro = :endereco_bairro,
                endereco_cidade = :endereco_cidade,
                endereco_estado = :endereco_estado
          WHERE codigo = :codigo"
    );
    $stmt->bindParam(':nome', $nome);
    $stmt->bindParam(':email', $email);
    $stmt->bindParam(':telefone', $telefone);
    $cepFormatado = $enderecoCep !== '' ? substr($enderecoCep, 0, 5) . '-' . substr($enderecoCep, 5, 3) : null;
    $stmt->bindValue(':endereco_cep', $cepFormatado, $cepFormatado === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':endereco_logradouro', $enderecoLogradouro !== '' ? $enderecoLogradouro : null, $enderecoLogradouro !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':endereco_numero', $enderecoNumero !== '' ? $enderecoNumero : null, $enderecoNumero !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':endereco_complemento', $enderecoComplemento !== '' ? $enderecoComplemento : null, $enderecoComplemento !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':endereco_bairro', $enderecoBairro !== '' ? $enderecoBairro : null, $enderecoBairro !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':endereco_cidade', $enderecoCidade !== '' ? $enderecoCidade : null, $enderecoCidade !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':endereco_estado', $enderecoEstado !== '' ? $enderecoEstado : null, $enderecoEstado !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindParam(':codigo', $codigoUsuario, PDO::PARAM_INT);
    $stmt->execute();

    // Atualizar sess&atilde;o com novos dados
    $_SESSION['usuario_nome']  = $nome;
    $_SESSION['usuario_email'] = $email;

    echo json_encode(['sucesso' => true, 'mensagem' => 'Dados atualizados com sucesso!'], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao atualizar dados: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
