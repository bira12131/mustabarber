<?php
/**
 * Obter Barbearia do Usuário Logado
 * Sistema: MustaBarber
 * Data: 11/03/2026
 * Retorna a barbearia pertencente ao usuário logado, se existir.
 */

ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

require_once '../usuarios/sessao.php';

try {
    require_once '../conexao.php';
    if (!estaLogado()) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Usuário não autenticado', 'barbearia' => null]);
        exit;
    }

    $usuarioLogado = obterUsuarioLogado();
    $codigoUsuario = $usuarioLogado['codigo'];

    $sql = "SELECT codigo, nome_barbearia, codigo_usuario, data_cadastro
            FROM barbearia
            WHERE codigo_usuario = :codigo_usuario
            LIMIT 1";

    $stmt = $conexao->prepare($sql);
    $stmt->bindParam(':codigo_usuario', $codigoUsuario, PDO::PARAM_INT);
    $stmt->execute();

    $barbearia = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($barbearia) {
        $barbearia['codigo'] = intval($barbearia['codigo']);
        $barbearia['codigo_usuario'] = intval($barbearia['codigo_usuario']);

        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Barbearia encontrada.',
            'barbearia' => $barbearia
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Nenhuma barbearia cadastrada.',
            'barbearia' => null
        ], JSON_UNESCAPED_UNICODE);
    }

} catch (PDOException $e) {
    error_log('[obter_barbearia] PDOException: ' . $e->getMessage());
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro no banco de dados.', 'barbearia' => null], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['sucesso' => false, 'mensagem' => $e->getMessage(), 'barbearia' => null], JSON_UNESCAPED_UNICODE);
}
?>
