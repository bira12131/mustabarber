<?php
/**
 * Verificar Indicação
 * Sistema: MustaBarber
 * Data: 26/03/2026
 * 
 * Verifica se um e-mail ou telefone informado pertence a um barbeiro
 * já cadastrado no sistema para validar a indicação.
 * Retorna apenas o primeiro nome para confirmação visual, sem expor dados sensíveis.
 */

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Método não permitido. Use GET.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_once '../conexao.php';

    $contato = trim($_GET['contato'] ?? '');

    if (empty($contato)) {
        echo json_encode([
            'sucesso' => false,
            'encontrado' => false,
            'mensagem' => 'Informe um e-mail ou telefone.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Remove formatação do telefone para comparação
    $contatoSemFormatacao = preg_replace('/[^0-9]/', '', $contato);

    // Busca pelo e-mail ou pelo telefone (com ou sem formatação)
    $sql = "SELECT codigo, nome
            FROM usuarios
            WHERE status = 1
              AND (
                  LOWER(email) = LOWER(:contato_email)
                  OR REGEXP_REPLACE(telefone, '[^0-9]', '', 'g') = :contato_tel
              )
            LIMIT 1";

    $stmt = $conexao->prepare($sql);
    $stmt->bindParam(':contato_email', $contato, PDO::PARAM_STR);
    $stmt->bindParam(':contato_tel', $contatoSemFormatacao, PDO::PARAM_STR);
    $stmt->execute();

    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($usuario) {
        // Retorna apenas o primeiro nome para não expor dados completos
        $partesNome = explode(' ', trim($usuario['nome']));
        $primeiroNome = $partesNome[0];

        echo json_encode([
            'sucesso' => true,
            'encontrado' => true,
            'primeiro_nome' => $primeiroNome,
            'codigo_indicador' => (int) $usuario['codigo']
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'sucesso' => true,
            'encontrado' => false,
            'mensagem' => 'Barbeiro não encontrado com este e-mail ou telefone.'
        ], JSON_UNESCAPED_UNICODE);
    }

} catch (PDOException $e) {
    echo json_encode([
        'sucesso' => false,
        'encontrado' => false,
        'mensagem' => 'Erro ao verificar indicação. Tente novamente.'
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([
        'sucesso' => false,
        'encontrado' => false,
        'mensagem' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
