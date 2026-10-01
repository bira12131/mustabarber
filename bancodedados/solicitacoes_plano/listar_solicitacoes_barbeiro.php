<?php
/**
 * Listar solicitações de plano recebidas pelo barbeiro logado
 * Sistema: MustaBarber
 * Requer login do barbeiro
 * GET ?status=pendente|aceito|recusado|todos
 */

ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

require_once '../usuarios/sessao.php';
require_once '../conexao.php';
require_once '../planos/plano_manager.php';

try {
    if (!estaLogado()) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Usuário não autenticado'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $usuario = obterUsuarioLogado();
    $codigoBarbeiro = intval($usuario['codigo']);
    $perfilUsuario  = intval($usuario['perfil'] ?? 0);

    exigirPlanoOuBloquear($conexao, $codigoBarbeiro, $perfilUsuario, 'solicitacoes_plano');

    $statusFiltro = isset($_GET['status']) ? trim($_GET['status']) : 'todos';
    $statusValidos = ['pendente', 'aceito', 'recusado', 'cancelado_cliente', 'aguardando_confirmacao_cliente', 'todos'];
    if (!in_array($statusFiltro, $statusValidos)) {
        $statusFiltro = 'todos';
    }

    // "pendente" no filtro inclui também os que aguardam confirmação do cliente
    if ($statusFiltro === 'pendente') {
        $whereStatus = "AND status IN ('pendente', 'aguardando_confirmacao_cliente')";
    } elseif ($statusFiltro !== 'todos') {
        $whereStatus = "AND status = :status";
    } else {
        $whereStatus = '';
    };

    $sql = "SELECT codigo, cliente_nome, cliente_telefone, servicos,
                   dia_semana, horario_preferido, dia_semana_2, horario_preferido_2,
                   periodicidade, quantidade_sessoes,
                   valor_total_original, observacoes_cliente,
                   data_ajustada, horario_ajustado,
                   tipo_desconto, valor_desconto, valor_final,
                   observacoes_barbeiro, status,
                   data_resposta,
                   TO_CHAR(data_criacao, 'DD/MM/YYYY HH24:MI') AS criado_em
            FROM solicitacoes_plano_cliente
            WHERE codigo_barbeiro = :barbeiro
              $whereStatus
            ORDER BY
              CASE status WHEN 'pendente' THEN 0 ELSE 1 END,
              data_criacao DESC
            LIMIT 200";

    $stmt = $conexao->prepare($sql);
    $stmt->bindParam(':barbeiro', $codigoBarbeiro, PDO::PARAM_INT);
    if ($statusFiltro !== 'todos' && $statusFiltro !== 'pendente') {
        $stmt->bindParam(':status', $statusFiltro);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Decodificar JSON de serviços
    foreach ($rows as &$r) {
        $r['servicos']           = json_decode($r['servicos'], true) ?? [];
        $r['valor_total_original'] = floatval($r['valor_total_original']);
        $r['valor_desconto']       = $r['valor_desconto'] !== null ? floatval($r['valor_desconto']) : null;
        $r['valor_final']          = $r['valor_final']    !== null ? floatval($r['valor_final'])    : null;
    }
    unset($r);

    echo json_encode([
        'sucesso'       => true,
        'solicitacoes'  => $rows,
        'total'         => count($rows)
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('[listar_solicitacoes_barbeiro] ' . $e->getMessage());
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro interno'], JSON_UNESCAPED_UNICODE);
}
?>
