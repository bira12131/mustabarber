<?php
/**
 * API para obter dados do usuario logado
 * Sistema: MustaBarber
 * Data: 20/12/2025
 * Atualizado: 14/03/2026 - Incluir cod_barbearia no retorno
 */

ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');

require_once 'sessao.php';

try {
    require_once '../conexao.php';

    if (!estaLogado()) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Usuario nao autenticado'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $usuario = obterUsuarioLogado();
    $codigoUsuario = intval($usuario['codigo']);

    // Verificar se o usuario e dono de uma barbearia
    $codBarbearia = null;
    try {
        $stmtOwner = $conexao->prepare(
            "SELECT codigo FROM barbearia WHERE codigo_usuario = :codigo LIMIT 1"
        );
        $stmtOwner->bindParam(':codigo', $codigoUsuario, PDO::PARAM_INT);
        $stmtOwner->execute();
        $barbearia = $stmtOwner->fetch(PDO::FETCH_ASSOC);

        if ($barbearia) {
            $codBarbearia = intval($barbearia['codigo']);
        } else {
            // Verificar se e funcionario de uma barbearia (coluna pode nao existir)
            try {
                $stmtFunc = $conexao->prepare(
                    "SELECT codigo_barbearia FROM usuarios WHERE codigo = :codigo AND codigo_barbearia IS NOT NULL LIMIT 1"
                );
                $stmtFunc->bindParam(':codigo', $codigoUsuario, PDO::PARAM_INT);
                $stmtFunc->execute();
                $func = $stmtFunc->fetch(PDO::FETCH_ASSOC);
                if ($func && isset($func['codigo_barbearia'])) {
                    $codBarbearia = intval($func['codigo_barbearia']);
                }
            } catch (Exception $eFunc) {
                // Coluna codigo_barbearia nao existe - ignorar
                $codBarbearia = null;
            }
        }
    } catch (Exception $eBarbearia) {
        // Tabela barbearia nao existe ou outro erro - ignorar
        $codBarbearia = null;
    }

    $usuario['cod_barbearia'] = $codBarbearia;

    echo json_encode([
        'sucesso' => true,
        'usuario' => $usuario
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao obter dados do usuario: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
