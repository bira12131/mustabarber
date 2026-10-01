<?php
/**
 * Convidar Barbeiro para a Barbearia
 * Sistema: MustaBarber
 * Envia um convite a um usuário para fazer parte da equipe.
 * Verifica limite de vagas do plano antes de criar o convite.
 */
ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

try {
    require_once '../conexao.php';
    require_once '../usuarios/sessao.php';
    require_once '../planos/plano_manager.php';

    if (!estaLogado()) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Usuário não autenticado']);
        exit;
    }

    $usuarioLogado  = obterUsuarioLogado();
    $codigoUsuario  = (int)$usuarioLogado['codigo'];
    $perfilUsuario  = (int)$usuarioLogado['perfil'];

    // Exige plano Equipe ou superior
    exigirPlanoOuBloquear($conexao, $codigoUsuario, $perfilUsuario, 'funcionarios');

    $dados           = json_decode(file_get_contents('php://input'), true);
    $codigoConvidado = isset($dados['codigo_usuario']) ? (int)$dados['codigo_usuario'] : 0;

    if ($codigoConvidado <= 0 || $codigoConvidado === $codigoUsuario) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Usuário inválido.']);
        exit;
    }

    // Buscar barbearia do dono
    $stmtBarb = $conexao->prepare(
        "SELECT codigo FROM barbearia WHERE codigo_usuario = :cod LIMIT 1"
    );
    $stmtBarb->bindParam(':cod', $codigoUsuario, PDO::PARAM_INT);
    $stmtBarb->execute();
    $barbearia = $stmtBarb->fetch(PDO::FETCH_ASSOC);

    if (!$barbearia) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Você não possui uma barbearia cadastrada.']);
        exit;
    }

    $codigoBarbearia = (int)$barbearia['codigo'];

    // Verificar vagas disponíveis
    $maxBarbeiros = getMaxBarbeirosPorPlano($perfilUsuario);

    $stmtCount = $conexao->prepare(
        "SELECT COUNT(*) FROM usuarios WHERE codigo_barbearia = :cod"
    );
    $stmtCount->bindParam(':cod', $codigoBarbearia, PDO::PARAM_INT);
    $stmtCount->execute();
    $totalAtual = (int)$stmtCount->fetchColumn();

    if ($totalAtual >= $maxBarbeiros) {
        echo json_encode([
            'sucesso'  => false,
            'mensagem' => 'Limite de barbeiros atingido para o seu plano (' . $maxBarbeiros . '). Faça upgrade ou remova um barbeiro.'
        ]);
        exit;
    }

    // Buscar dados do convidado
    $stmtUser = $conexao->prepare(
        "SELECT codigo, nome, perfil, codigo_barbearia
         FROM usuarios WHERE codigo = :cod AND status = 1"
    );
    $stmtUser->bindParam(':cod', $codigoConvidado, PDO::PARAM_INT);
    $stmtUser->execute();
    $convidado = $stmtUser->fetch(PDO::FETCH_ASSOC);

    if (!$convidado) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Usuário não encontrado ou inativo.']);
        exit;
    }

    if ((int)$convidado['perfil'] === 1) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Administradores não podem ser convidados como funcionários.']);
        exit;
    }

    if (!is_null($convidado['codigo_barbearia'])) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Este barbeiro já faz parte de uma barbearia.']);
        exit;
    }

    // Verificar se já existe convite pendente deste dono para este convidado
    $stmtPend = $conexao->prepare(
        "SELECT codigo FROM convites
         WHERE codigo_barbearia = :barb
           AND codigo_usuario_convidado = :conv
           AND status = 'pendente'"
    );
    $stmtPend->bindParam(':barb', $codigoBarbearia, PDO::PARAM_INT);
    $stmtPend->bindParam(':conv', $codigoConvidado, PDO::PARAM_INT);
    $stmtPend->execute();

    if ($stmtPend->fetch()) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Já existe um convite pendente para este barbeiro.']);
        exit;
    }

    // Criar convite
    $stmtIns = $conexao->prepare(
        "INSERT INTO convites (codigo_barbearia, codigo_usuario_dono, codigo_usuario_convidado, status)
         VALUES (:barb, :dono, :conv, 'pendente')"
    );
    $stmtIns->bindParam(':barb', $codigoBarbearia, PDO::PARAM_INT);
    $stmtIns->bindParam(':dono', $codigoUsuario,   PDO::PARAM_INT);
    $stmtIns->bindParam(':conv', $codigoConvidado, PDO::PARAM_INT);
    $stmtIns->execute();

    echo json_encode([
        'sucesso'  => true,
        'mensagem' => 'Convite enviado para ' . $convidado['nome'] . '! Ele receberá uma notificação.'
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log('[convidar_barbeiro] PDOException: ' . $e->getMessage());
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro no banco de dados.'], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['sucesso' => false, 'mensagem' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
