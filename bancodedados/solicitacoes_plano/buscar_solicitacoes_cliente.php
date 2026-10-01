<?php
/**
 * Buscar solicitações de plano por telefone do cliente
 * Sistema: MustaBarber
 * Endpoint público — identificação por telefone
 * GET ?telefone=<tel>&barbeiro=<cod>
 */

ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

require_once '../conexao.php';

try {
    $telefone       = isset($_GET['telefone']) ? trim($_GET['telefone']) : '';
    $codigoBarbeiro = isset($_GET['barbeiro'])  ? intval($_GET['barbeiro']) : 0;

    if (empty($telefone)) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Telefone não informado'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $where = "WHERE cliente_telefone = :telefone";
    $params = [':telefone' => $telefone];

    if ($codigoBarbeiro > 0) {
        $where .= " AND codigo_barbeiro = :barbeiro";
        $params[':barbeiro'] = $codigoBarbeiro;
    }

    $sql = "SELECT spc.codigo, spc.cliente_nome, spc.servicos,
                   spc.dia_semana, spc.horario_preferido, spc.periodicidade,
                   spc.quantidade_sessoes, spc.valor_total_original,
                   spc.observacoes_cliente,
                   spc.data_ajustada, spc.horario_ajustado,
                   spc.tipo_desconto, spc.valor_desconto, spc.valor_final,
                   spc.observacoes_barbeiro, spc.status,
                   spc.grupo_agendamentos, spc.cliente_confirmou,
                   TO_CHAR(spc.data_criacao, 'DD/MM/YYYY HH24:MI') AS criado_em,
                   u.nome AS nome_barbeiro
            FROM solicitacoes_plano_cliente spc
            INNER JOIN usuarios u ON spc.codigo_barbeiro = u.codigo
            $where
            ORDER BY spc.data_criacao DESC
            LIMIT 50";

    $stmt = $conexao->prepare($sql);
    foreach ($params as $k => $v) {
        if ($k === ':barbeiro') {
            $stmt->bindValue($k, $v, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($k, $v);
        }
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Para planos aceitos com grupo, contar agendamentos concluídos
    $stmtConcl = $conexao->prepare(
        "SELECT COUNT(*) FROM agendamentos
          WHERE grupo_recorrente = :grupo AND codigo_status IN (3, 4)"
    );
    foreach ($rows as &$r) {
        $r['servicos']             = json_decode($r['servicos'], true) ?? [];
        $r['valor_total_original'] = floatval($r['valor_total_original']);
        $r['valor_desconto']       = $r['valor_desconto'] !== null ? floatval($r['valor_desconto']) : null;
        $r['valor_final']          = $r['valor_final']    !== null ? floatval($r['valor_final'])    : null;
        // Contar sessões concluídas dentro do grupo do plano
        $r['agendamentos_concluidos'] = 0;
        if (!empty($r['grupo_agendamentos'])) {
            $stmtConcl->execute([':grupo' => $r['grupo_agendamentos']]);
            $r['agendamentos_concluidos'] = intval($stmtConcl->fetchColumn());
        }
    }
    unset($r);

    echo json_encode([
        'sucesso'      => true,
        'solicitacoes' => $rows,
        'total'        => count($rows)
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('[buscar_solicitacoes_cliente] ' . $e->getMessage());
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro interno'], JSON_UNESCAPED_UNICODE);
}
?>
