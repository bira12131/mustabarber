<?php
/**
 * Obter Resumo da Barbearia
 * Sistema: MustaBarber
 * Data: 11/03/2026
 * Retorna os cards de resumo: total de barbeiros, total de agendamentos
 * do mês e receita mensal (agendamentos concluídos) da barbearia do usuário logado.
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
        echo json_encode(['sucesso' => false, 'mensagem' => 'Usuário não autenticado']);
        exit;
    }

    $usuarioLogado = obterUsuarioLogado();
    $codigoUsuario = $usuarioLogado['codigo'];

    // Buscar a barbearia do usuário logado
    $stmtBarb = $conexao->prepare("SELECT codigo FROM barbearia WHERE codigo_usuario = :codigo_usuario LIMIT 1");
    $stmtBarb->bindParam(':codigo_usuario', $codigoUsuario, PDO::PARAM_INT);
    $stmtBarb->execute();
    $barbearia = $stmtBarb->fetch(PDO::FETCH_ASSOC);

    if (!$barbearia) {
        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Nenhuma barbearia encontrada.',
            'total_barbeiros' => 0,
            'total_agendamentos_mes' => 0,
            'receita_mensal' => 0
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $codigoBarbearia = intval($barbearia['codigo']);
    $mesAtual = date('Y-m');

    // Total de funcionários da barbearia (coluna codigo_barbearia pode não existir)
    $totalBarbeiros = 0;
    $totalAgendamentos = 0;
    $receitaMensal = 0.0;
    try {
        $stmtTotal = $conexao->prepare(
            "SELECT COUNT(*) as total
             FROM usuarios u
             WHERE u.codigo_barbearia = :codigo_barbearia
               AND u.status = 1
               AND EXISTS (
                   SELECT 1 FROM convites c
                   WHERE c.codigo_usuario_convidado = u.codigo
                     AND c.codigo_barbearia = :codigo_barbearia2
                     AND c.status = 'aceito'
               )"
        );
        $stmtTotal->bindParam(':codigo_barbearia',  $codigoBarbearia, PDO::PARAM_INT);
        $stmtTotal->bindParam(':codigo_barbearia2', $codigoBarbearia, PDO::PARAM_INT);
        $stmtTotal->execute();
        $totalBarbeiros = intval($stmtTotal->fetch(PDO::FETCH_ASSOC)['total']);

        // Total de agendamentos do mês — apenas de membros com convite aceito
        $stmtAgend = $conexao->prepare(
            "SELECT COUNT(*) as total
             FROM agendamentos a
             INNER JOIN usuarios u ON a.codigo_barbeiro = u.codigo
             WHERE u.codigo_barbearia = :codigo_barbearia
               AND TO_CHAR(a.data_agendamento, 'YYYY-MM') = :mes_atual
               AND a.codigo_status NOT IN (5, 6)
               AND EXISTS (
                   SELECT 1 FROM convites c
                   WHERE c.codigo_usuario_convidado = u.codigo
                     AND c.codigo_barbearia = :codigo_barbearia2
                     AND c.status = 'aceito'
               )"
        );
        $stmtAgend->bindParam(':codigo_barbearia',  $codigoBarbearia, PDO::PARAM_INT);
        $stmtAgend->bindParam(':codigo_barbearia2', $codigoBarbearia, PDO::PARAM_INT);
        $stmtAgend->bindParam(':mes_atual', $mesAtual);
        $stmtAgend->execute();
        $totalAgendamentos = intval($stmtAgend->fetch(PDO::FETCH_ASSOC)['total']);

        // Receita mensal — apenas de membros com convite aceito
        $stmtReceita = $conexao->prepare(
            "SELECT COALESCE(SUM(a.valor), 0) as total
             FROM agendamentos a
             INNER JOIN usuarios u ON a.codigo_barbeiro = u.codigo
             WHERE u.codigo_barbearia = :codigo_barbearia
               AND TO_CHAR(a.data_agendamento, 'YYYY-MM') = :mes_atual
               AND a.codigo_status = 4
               AND EXISTS (
                   SELECT 1 FROM convites c
                   WHERE c.codigo_usuario_convidado = u.codigo
                     AND c.codigo_barbearia = :codigo_barbearia2
                     AND c.status = 'aceito'
               )"
        );
        $stmtReceita->bindParam(':codigo_barbearia',  $codigoBarbearia, PDO::PARAM_INT);
        $stmtReceita->bindParam(':codigo_barbearia2', $codigoBarbearia, PDO::PARAM_INT);
        $stmtReceita->bindParam(':mes_atual', $mesAtual);
        $stmtReceita->execute();
        $receitaMensal = floatval($stmtReceita->fetch(PDO::FETCH_ASSOC)['total']);
    } catch (Exception $eFunc) {
        // Coluna codigo_barbearia não existe ainda - retornar zeros
    }

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Resumo carregado com sucesso.',
        'total_barbeiros' => $totalBarbeiros,
        'total_agendamentos_mes' => $totalAgendamentos,
        'receita_mensal' => $receitaMensal
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log('[obter_resumo_barbearia] PDOException: ' . $e->getMessage());
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro no banco de dados.'], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['sucesso' => false, 'mensagem' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
