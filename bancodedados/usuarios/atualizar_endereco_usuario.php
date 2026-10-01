<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
error_reporting(0);

require_once 'sessao.php';
require_once '../conexao.php';

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

    if ($codigoUsuario <= 0) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Sessao invalida. Faca login novamente.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $enderecoCep = preg_replace('/\D/', '', (string)($input['endereco_cep'] ?? ''));
    $enderecoLogradouro = trim((string)($input['endereco_logradouro'] ?? ''));
    $enderecoNumero = trim((string)($input['endereco_numero'] ?? ''));
    $enderecoComplemento = trim((string)($input['endereco_complemento'] ?? ''));
    $enderecoBairro = trim((string)($input['endereco_bairro'] ?? ''));
    $enderecoCidade = trim((string)($input['endereco_cidade'] ?? ''));
    $enderecoEstado = strtoupper(trim((string)($input['endereco_estado'] ?? '')));

    if (strlen($enderecoCep) !== 8) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'CEP invalido. Informe 8 digitos.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($enderecoLogradouro === '' || $enderecoNumero === '' || $enderecoBairro === '' || $enderecoCidade === '' || $enderecoEstado === '') {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Para dados fiscais, preencha o endereco completo: CEP, logradouro, numero, bairro, cidade e UF.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!preg_match('/^[A-Z]{2}$/', $enderecoEstado)) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'UF invalida. Use 2 letras, ex: SP.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $cepFormatado = substr($enderecoCep, 0, 5) . '-' . substr($enderecoCep, 5, 3);

    $stmt = $conexao->prepare(
        "UPDATE usuarios
            SET endereco_cep = :endereco_cep,
                endereco_logradouro = :endereco_logradouro,
                endereco_numero = :endereco_numero,
                endereco_complemento = :endereco_complemento,
                endereco_bairro = :endereco_bairro,
                endereco_cidade = :endereco_cidade,
                endereco_estado = :endereco_estado
          WHERE codigo = :codigo"
    );

    $stmt->bindValue(':endereco_cep', $cepFormatado, PDO::PARAM_STR);
    $stmt->bindValue(':endereco_logradouro', $enderecoLogradouro, PDO::PARAM_STR);
    $stmt->bindValue(':endereco_numero', $enderecoNumero, PDO::PARAM_STR);
    $stmt->bindValue(':endereco_complemento', $enderecoComplemento !== '' ? $enderecoComplemento : null, $enderecoComplemento !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':endereco_bairro', $enderecoBairro, PDO::PARAM_STR);
    $stmt->bindValue(':endereco_cidade', $enderecoCidade, PDO::PARAM_STR);
    $stmt->bindValue(':endereco_estado', $enderecoEstado, PDO::PARAM_STR);
    $stmt->bindValue(':codigo', $codigoUsuario, PDO::PARAM_INT);

    $executou = $stmt->execute();
    if (!$executou) {
        $erro = $stmt->errorInfo();
        throw new RuntimeException('Falha ao executar atualizacao de endereco: ' . ($erro[2] ?? 'erro desconhecido'));
    }

    if ($stmt->rowCount() === 0) {
        $stmtExiste = $conexao->prepare('SELECT 1 FROM usuarios WHERE codigo = :codigo LIMIT 1');
        $stmtExiste->bindValue(':codigo', $codigoUsuario, PDO::PARAM_INT);
        $stmtExiste->execute();
        $usuarioExiste = (bool)$stmtExiste->fetchColumn();

        if (!$usuarioExiste) {
            echo json_encode([
                'sucesso' => false,
                'mensagem' => 'Usuario nao encontrado para atualizar endereco.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Endereco ja estava atualizado.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['sucesso' => true, 'mensagem' => 'Endereco atualizado com sucesso!'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao atualizar endereco: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
