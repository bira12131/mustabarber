<?php
/**
 * Listar Funcionários da Barbearia
 * Sistema: MustaBarber
 * Data: 11/03/2026
 * Retorna os usuários vinculados à barbearia do usuário logado,
 * com a quantidade de agendamentos de cada um no mês atual.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

require_once '../conexao.php';
require_once '../usuarios/sessao.php';

try {
    if (!estaLogado()) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Usuário não autenticado', 'funcionarios' => []]);
        exit;
    }

    $usuarioLogado = obterUsuarioLogado();
    $codigoUsuario = $usuarioLogado['codigo'];

    // Verificar acesso por plano (funcionários exige plano Equipe ou superior)
    require_once '../planos/plano_manager.php';
    exigirPlanoOuBloquear($conexao, (int)$codigoUsuario, (int)$usuarioLogado['perfil'], 'funcionarios');

    // Buscar barbearia do usuário logado
    $stmtBarb = $conexao->prepare("SELECT codigo FROM barbearia WHERE codigo_usuario = :codigo_usuario LIMIT 1");
    $stmtBarb->bindParam(':codigo_usuario', $codigoUsuario, PDO::PARAM_INT);
    $stmtBarb->execute();
    $barbearia = $stmtBarb->fetch(PDO::FETCH_ASSOC);

    if (!$barbearia) {
        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Nenhuma barbearia encontrada.',
            'funcionarios' => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $codigoBarbearia = intval($barbearia['codigo']);
    $mesAtual = date('Y-m');

    // Listar funcionários com quantidade de agendamentos do mês
    // Apenas usuários que aceitaram um convite desta barbearia (EXISTS evita duplicatas)
    $sql = "SELECT
                u.codigo,
                u.nome,
                u.email,
                u.telefone,
                u.foto_caminho,
                u.status,
                COALESCE(ag.total_agendamentos, 0) AS agendamentos_mes
            FROM usuarios u
            LEFT JOIN (
                SELECT
                    codigo_barbeiro,
                    COUNT(*) AS total_agendamentos
                FROM agendamentos
                WHERE TO_CHAR(data_agendamento, 'YYYY-MM') = :mes_atual
                  AND codigo_status NOT IN (5, 6)
                GROUP BY codigo_barbeiro
            ) ag ON ag.codigo_barbeiro = u.codigo
            WHERE u.codigo_barbearia = :codigo_barbearia
              AND u.status = 1
              AND EXISTS (
                  SELECT 1 FROM convites c
                  WHERE c.codigo_usuario_convidado = u.codigo
                    AND c.codigo_barbearia = :codigo_barbearia2
                    AND c.status = 'aceito'
              )
            ORDER BY u.nome ASC";

    $stmt = $conexao->prepare($sql);
    $stmt->bindParam(':mes_atual',          $mesAtual,        PDO::PARAM_STR);
    $stmt->bindParam(':codigo_barbearia',   $codigoBarbearia, PDO::PARAM_INT);
    $stmt->bindParam(':codigo_barbearia2',  $codigoBarbearia, PDO::PARAM_INT);
    $stmt->execute();

    $funcionarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($funcionarios as &$f) {
        $f['codigo'] = intval($f['codigo']);
        $f['status'] = intval($f['status']);
        $f['agendamentos_mes'] = intval($f['agendamentos_mes']);
    }

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Funcionários carregados com sucesso.',
        'funcionarios' => $funcionarios,
        'total' => count($funcionarios)
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log('[listar_funcionarios] PDOException: ' . $e->getMessage());
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro no banco de dados.', 'funcionarios' => []], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['sucesso' => false, 'mensagem' => $e->getMessage(), 'funcionarios' => []], JSON_UNESCAPED_UNICODE);
}
?>
