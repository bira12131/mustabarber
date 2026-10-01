<?php
/**
 * Buscar Usu�rios para Adicionar � Barbearia
 * Sistema: MustaBarber
 * Data: 11/03/2026
 * Busca usu�rios pelo nome ou e-mail para que possam ser
 * adicionados como funcion�rios de uma barbearia.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

require_once '../conexao.php';
require_once '../usuarios/sessao.php';

try {
    if (!estaLogado()) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Usu�rio n�o autenticado', 'usuarios' => []]);
        exit;
    }

    $usuarioLogado = obterUsuarioLogado();
    $codigoUsuario = $usuarioLogado['codigo'];

    $termo = isset($_GET['termo']) ? trim($_GET['termo']) : '';

    // Validar: aceitar somente e-mail completo ou telefone completo (10�11 d�gitos)
    $emailCompleto = filter_var($termo, FILTER_VALIDATE_EMAIL) !== false;
    $apenasDigitos = preg_replace('/\D/', '', $termo);
    $telefoneCompleto = strlen($apenasDigitos) >= 10 && strlen($apenasDigitos) <= 11;

    if (!$emailCompleto && !$telefoneCompleto) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Informe o e-mail completo ou o telefone completo (com DDD).', 'usuarios' => []]);
        exit;
    }

    // Buscar barbearia do usu�rio logado para excluir funcion�rios j� vinculados
    $stmtBarb = $conexao->prepare("SELECT codigo FROM barbearia WHERE codigo_usuario = :codigo_usuario LIMIT 1");
    $stmtBarb->bindParam(':codigo_usuario', $codigoUsuario, PDO::PARAM_INT);
    $stmtBarb->execute();
    $barbearia = $stmtBarb->fetch(PDO::FETCH_ASSOC);
    $codigoBarbearia = $barbearia ? intval($barbearia['codigo']) : 0;

    // Para telefone, usar apenas d�gitos para bater com o formato do banco
    $termoBusca = $emailCompleto
        ? '%' . $termo . '%'
        : '%' . $apenasDigitos . '%';

    // Retornar usu�rios ativos que:
    // - n�o sejam o pr�prio dono
    // - n�o estejam vinculados a NENHUMA barbearia (codigo_barbearia NULL)
    // - n�o sejam criadores de nenhuma barbearia (barbearia.codigo_usuario)
    // - n�o sejam administradores (perfil 1) nem clientes (perfil 5)
    // - busca por e-mail ou telefone
    $sql = "SELECT codigo, nome, email, telefone, foto_caminho
            FROM usuarios
            WHERE status = 1
              AND perfil NOT IN (1, 5)
              AND codigo != :codigo_usuario
              AND codigo_barbearia IS NULL
              AND NOT EXISTS (
                SELECT 1 FROM barbearia WHERE codigo_usuario = usuarios.codigo
              )
              AND (
                LOWER(nome)     LIKE LOWER(:termo)
                OR LOWER(email) LIKE LOWER(:termo2)
                OR telefone     LIKE :termo3
              )
            ORDER BY nome ASC
            LIMIT 10";

    $stmt = $conexao->prepare($sql);
    $stmt->bindParam(':codigo_usuario', $codigoUsuario, PDO::PARAM_INT);
    $stmt->bindParam(':termo',  $termoBusca);
    $stmt->bindParam(':termo2', $termoBusca);
    $stmt->bindParam(':termo3', $termoBusca);
    $stmt->execute();

    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($usuarios as &$u) {
        $u['codigo'] = intval($u['codigo']);
    }

    echo json_encode([
        'sucesso' => true,
        'mensagem' => count($usuarios) . ' usu�rio(s) encontrado(s).',
        'usuarios' => $usuarios
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log('[buscar_usuarios_barbearia] PDOException: ' . $e->getMessage());
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro no banco de dados.', 'usuarios' => []], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['sucesso' => false, 'mensagem' => $e->getMessage(), 'usuarios' => []], JSON_UNESCAPED_UNICODE);
}
?>
