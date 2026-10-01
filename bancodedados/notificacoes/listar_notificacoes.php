<?php
/**
 * Listar Notificações (Convites Pendentes)
 * Sistema: MustaBarber
 * Retorna os convites pendentes recebidos pelo usuário logado.
 */
ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

try {
    require_once '../conexao.php';
    require_once '../usuarios/sessao.php';

    if (!estaLogado()) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Não autenticado', 'notificacoes' => [], 'total' => 0]);
        exit;
    }

    $usuarioLogado = obterUsuarioLogado();
    $codigoUsuario = (int)$usuarioLogado['codigo'];

    $stmt = $conexao->prepare(
        "SELECT c.codigo,
                c.codigo_barbearia,
                TO_CHAR(c.data_convite, 'DD/MM/YYYY HH24:MI') AS data_formatada,
                b.nome_barbearia,
                u.nome AS nome_dono,
                u.foto_caminho AS foto_dono
         FROM convites c
         INNER JOIN barbearia b ON b.codigo = c.codigo_barbearia
         INNER JOIN usuarios u  ON u.codigo = c.codigo_usuario_dono
         WHERE c.codigo_usuario_convidado = :cod
           AND c.status = 'pendente'
         ORDER BY c.data_convite DESC"
    );
    $stmt->bindParam(':cod', $codigoUsuario, PDO::PARAM_INT);
    $stmt->execute();
    $notificacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Verificar se o usuário está em alguma barbearia COMO FUNCIONÁRIO
    // (exclui o caso em que o próprio usuário é o dono da barbearia)
    $stmtBarb = $conexao->prepare(
        "SELECT u.codigo_barbearia, b.nome_barbearia AS minha_barbearia
         FROM usuarios u
         LEFT JOIN barbearia b ON b.codigo = u.codigo_barbearia
         WHERE u.codigo = :cod
           AND u.codigo_barbearia IS NOT NULL
           AND NOT EXISTS (
               SELECT 1 FROM barbearia WHERE codigo = u.codigo_barbearia AND codigo_usuario = :cod2
           )"
    );
    $stmtBarb->bindParam(':cod',  $codigoUsuario, PDO::PARAM_INT);
    $stmtBarb->bindParam(':cod2', $codigoUsuario, PDO::PARAM_INT);
    $stmtBarb->execute();
    $barbRow       = $stmtBarb->fetch(PDO::FETCH_ASSOC);
    $emBarbearia   = ($barbRow !== false);
    $nomeBarbearia = $barbRow['minha_barbearia'] ?? null;

    // Notificações do sistema (boas-vindas, cobranças, avisos)
    // Isolado em try-catch próprio: se a tabela ainda não existir no banco,
    // os convites continuam carregando normalmente.
    $notifSistema = [];
    try {
        $stmtSistema = $conexao->prepare(
            "SELECT codigo, tipo, titulo, mensagem, lida,
                    TO_CHAR(data_cadastro, 'DD/MM/YYYY HH24:MI') AS data_formatada
             FROM notificacoes_sistema
             WHERE codigo_usuario = :cod
             ORDER BY data_cadastro DESC
             LIMIT 30"
        );
        $stmtSistema->bindParam(':cod', $codigoUsuario, PDO::PARAM_INT);
        $stmtSistema->execute();
        $notifSistema = $stmtSistema->fetchAll(PDO::FETCH_ASSOC);
        // Converter lida para boolean JS-friendly
        foreach ($notifSistema as &$ns) {
            $ns['lida'] = ($ns['lida'] === 't' || $ns['lida'] === true || $ns['lida'] === '1' || $ns['lida'] === 1);
        }
        unset($ns);
    } catch (Exception $eSistema) {
        // Tabela ainda não existe ou outro erro pontual — ignora silenciosamente
        $notifSistema = [];
    }

    echo json_encode([
        'sucesso'             => true,
        'total'               => count($notificacoes),
        'notificacoes'        => $notificacoes,
        'notif_sistema'       => $notifSistema,
        'em_barbearia'        => $emBarbearia,
        'minha_barbearia'     => $nomeBarbearia
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        'sucesso'      => false,
        'mensagem'     => $e->getMessage(),
        'notificacoes' => [],
        'total'        => 0
    ], JSON_UNESCAPED_UNICODE);
}
?>
