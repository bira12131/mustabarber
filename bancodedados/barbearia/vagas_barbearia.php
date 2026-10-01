<?php
/**
 * Vagas Disponíveis na Barbearia
 * Sistema: MustaBarber
 * Retorna total de vagas do plano, vagas ocupadas e convites pendentes.
 */
ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

try {
    require_once '../conexao.php';
    require_once '../usuarios/sessao.php';
    require_once '../planos/plano_manager.php';

    if (!estaLogado()) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Não autenticado']);
        exit;
    }

    $usuarioLogado = obterUsuarioLogado();
    $codigoUsuario = (int)$usuarioLogado['codigo'];
    $perfilUsuario = (int)$usuarioLogado['perfil'];

    $stmtBarb = $conexao->prepare(
        "SELECT codigo FROM barbearia WHERE codigo_usuario = :cod LIMIT 1"
    );
    $stmtBarb->bindParam(':cod', $codigoUsuario, PDO::PARAM_INT);
    $stmtBarb->execute();
    $barbearia = $stmtBarb->fetch(PDO::FETCH_ASSOC);

    if (!$barbearia) {
        echo json_encode([
            'sucesso'           => false,
            'mensagem'          => 'Barbearia não encontrada',
            'vagas_total'       => 0,
            'ocupadas'          => 0,
            'vagas_disponiveis' => 0,
            'convites_pendentes'=> []
        ]);
        exit;
    }

    $codigoBarbearia = (int)$barbearia['codigo'];
    $maxBarbeiros    = getMaxBarbeirosPorPlano($perfilUsuario);

    // Funcionários ativos na barbearia
    $stmtCount = $conexao->prepare(
        "SELECT COUNT(*) FROM usuarios WHERE codigo_barbearia = :cod"
    );
    $stmtCount->bindParam(':cod', $codigoBarbearia, PDO::PARAM_INT);
    $stmtCount->execute();
    $ocupadas = (int)$stmtCount->fetchColumn();

    // Convites pendentes enviados por esta barbearia
    $stmtConv = $conexao->prepare(
        "SELECT c.codigo, u.nome, u.email, u.foto_caminho,
                TO_CHAR(c.data_convite, 'DD/MM/YYYY HH24:MI') AS data_formatada
         FROM convites c
         INNER JOIN usuarios u ON u.codigo = c.codigo_usuario_convidado
         WHERE c.codigo_barbearia = :barb AND c.status = 'pendente'
         ORDER BY c.data_convite DESC"
    );
    $stmtConv->bindParam(':barb', $codigoBarbearia, PDO::PARAM_INT);
    $stmtConv->execute();
    $convitesPendentes = $stmtConv->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'sucesso'            => true,
        'vagas_total'        => $maxBarbeiros,
        'ocupadas'           => $ocupadas,
        'vagas_disponiveis'  => max(0, $maxBarbeiros - $ocupadas),
        'convites_pendentes' => $convitesPendentes
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        'sucesso'            => false,
        'mensagem'           => $e->getMessage(),
        'vagas_total'        => 0,
        'ocupadas'           => 0,
        'vagas_disponiveis'  => 0,
        'convites_pendentes' => []
    ], JSON_UNESCAPED_UNICODE);
}
?>
