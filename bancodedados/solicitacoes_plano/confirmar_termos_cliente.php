<?php
/**
 * Cliente confirma ou recusa os termos modificados pelo barbeiro
 * Sistema: MustaBarber
 * Endpoint público — identificação por telefone do cliente
 * POST JSON: { codigo, cliente_telefone, acao: 'aceitar'|'recusar' }
 *
 * - 'aceitar': cria os agendamentos, marca assinante, status = 'aceito'
 * - 'recusar': status = 'cancelado_cliente'
 */

ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

require_once '../conexao.php';
require_once 'helper_criar_agendamentos.php';
require_once '../whatsapp/agendamento_messages.php';

function garantirFuncaoTimestampCompativel(PDO $conexao): void
{
    // Torna a funcao de trigger compativel com tabelas que usam
    // 'atualizado_em' OU 'data_atualizacao'.
    $sql = <<<'SQL'
CREATE OR REPLACE FUNCTION fn_atualiza_timestamp()
RETURNS TRIGGER AS $$
DECLARE
    payload jsonb;
BEGIN
    payload := to_jsonb(NEW);

    IF payload ? 'atualizado_em' THEN
        NEW := jsonb_populate_record(NEW, jsonb_set(payload, '{atualizado_em}', to_jsonb(CURRENT_TIMESTAMP), true));
    ELSIF payload ? 'data_atualizacao' THEN
        NEW := jsonb_populate_record(NEW, jsonb_set(payload, '{data_atualizacao}', to_jsonb(CURRENT_TIMESTAMP), true));
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL;

    try {
        $conexao->exec($sql);
    } catch (Throwable $e) {
        // Se o usuario do banco nao puder alterar funcao, nao bloqueia o fluxo.
        error_log('[confirmar_termos_cliente] Falha ao ajustar fn_atualiza_timestamp: ' . $e->getMessage());
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Método não permitido'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Dados inválidos'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $codigo   = isset($input['codigo'])           ? intval($input['codigo'])         : 0;
    $telefone = isset($input['cliente_telefone']) ? trim($input['cliente_telefone']) : '';
    $acao     = isset($input['acao'])             ? strtolower(trim($input['acao'])) : '';

    if ($codigo <= 0 || empty($telefone)) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Dados insuficientes'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!in_array($acao, ['aceitar', 'recusar'])) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Ação inválida'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    garantirFuncaoTimestampCompativel($conexao);

    // Buscar solicitação + dados do barbeiro
        $stmtSol = $conexao->prepare(
        "SELECT spc.*, u.nome AS nome_barbeiro
         FROM solicitacoes_plano_cliente spc
         INNER JOIN usuarios u ON spc.codigo_barbeiro = u.codigo
                 WHERE spc.codigo = :cod
                     AND REGEXP_REPLACE(spc.cliente_telefone, '[^0-9]', '', 'g') = REGEXP_REPLACE(:tel, '[^0-9]', '', 'g')
         LIMIT 1"
    );
    $stmtSol->execute([':cod' => $codigo, ':tel' => $telefone]);
    $solicitacao = $stmtSol->fetch(PDO::FETCH_ASSOC);

    if (!$solicitacao) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Solicitação não encontrada'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($solicitacao['status'] !== 'aguardando_confirmacao_cliente') {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Esta solicitação não está aguardando confirmação'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // URL base para links
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
    $appRoot    = ($pos = strpos($scriptPath, '/bancodedados/')) !== false
                    ? substr($scriptPath, 0, $pos)
                    : '';
    $baseUrl  = $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $appRoot;

    if ($acao === 'recusar') {
        $stmt = $conexao->prepare(
            "UPDATE solicitacoes_plano_cliente
             SET status = 'cancelado_cliente',
                 cliente_confirmou = FALSE,
                 data_confirmacao_cliente = NOW()
             WHERE codigo = :cod"
        );
        $stmt->execute([':cod' => $codigo]);
        echo json_encode(['sucesso' => true, 'mensagem' => 'Proposta recusada. A solicitação foi cancelada.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- Aceitar: criar agendamentos ----
    $conexao->beginTransaction();

    $stmt = $conexao->prepare(
        "UPDATE solicitacoes_plano_cliente
         SET status = 'aceito',
             cliente_confirmou = TRUE,
             data_confirmacao_cliente = NOW()
         WHERE codigo = :cod"
    );
    $stmt->execute([':cod' => $codigo]);

    // Decodificar servicos se necessário
    if (!is_array($solicitacao['servicos'])) {
        $solicitacao['servicos'] = json_decode($solicitacao['servicos'], true) ?? [];
    }

    $nomeBarbeiro = $solicitacao['nome_barbeiro'] ?? 'Barbeiro';
    $resultado    = criarAgendamentosParaPlano($conexao, $solicitacao, $nomeBarbeiro, $baseUrl);

    // Persistir grupo de agendamentos
    $stmtGrupo = $conexao->prepare(
        "UPDATE solicitacoes_plano_cliente SET grupo_agendamentos = :grupo WHERE codigo = :cod"
    );
    $stmtGrupo->execute([':grupo' => $resultado['grupo'], ':cod' => $codigo]);

    $conexao->commit();

    $n = $resultado['agendamentos'];
    echo json_encode([
        'sucesso'              => true,
        'mensagem'             => "Termos aceitos! {$n} agendamento(s) criado(s) com sucesso.",
        'agendamentos_criados' => $n,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    if (isset($conexao) && $conexao->inTransaction()) {
        $conexao->rollBack();
    }
    error_log('[confirmar_termos_cliente] ' . $e->getMessage());
    $mensagem = trim((string)$e->getMessage());
    if ($mensagem === '') {
        $mensagem = 'Erro interno ao confirmar termos';
    }
    echo json_encode(['sucesso' => false, 'mensagem' => $mensagem], JSON_UNESCAPED_UNICODE);
}
?>
