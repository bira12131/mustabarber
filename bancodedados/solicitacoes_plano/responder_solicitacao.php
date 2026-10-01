<?php
/**
 * Responder solicitação de plano (barbeiro aceita ou recusa)
 * Sistema: MustaBarber
 * Requer login do barbeiro
 * POST JSON
 *
 * Lógica de aceite:
 *  - SEM modificações (data/hora/desconto) → cria agendamentos imediatamente + marca assinante
 *  - COM modificações → status = 'aguardando_confirmacao_cliente' + WhatsApp ao cliente
 * Lógica de recusa: status = 'recusado'
 */

ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

require_once '../usuarios/sessao.php';
require_once '../conexao.php';
require_once 'helper_criar_agendamentos.php';
require_once '../whatsapp/agendamento_messages.php';

function garantirFuncaoTimestampCompativel(PDO $conexao): void
{
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
        error_log('[responder_solicitacao] Falha ao ajustar fn_atualiza_timestamp: ' . $e->getMessage());
    }
}

try {
    if (!estaLogado()) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Usuário não autenticado'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Método não permitido'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Dados inválidos'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $usuario        = obterUsuarioLogado();
    $codigoBarbeiro = intval($usuario['codigo']);
    $nomeBarbeiro   = $usuario['nome'] ?? 'Barbeiro';

    $codigoSolicitacao = isset($input['codigo'])               ? intval($input['codigo'])               : 0;
    $acao              = isset($input['acao'])                  ? trim($input['acao'])                    : '';
    $dataAjustada      = isset($input['data_ajustada'])         ? trim($input['data_ajustada'])           : null;
    $horarioAjustado   = isset($input['horario_ajustado'])      ? trim($input['horario_ajustado'])        : null;
    $tipoDesconto      = isset($input['tipo_desconto'])         ? trim($input['tipo_desconto'])           : null;
    $valorDesconto     = isset($input['valor_desconto'])        ? floatval($input['valor_desconto'])      : null;
    $valorFinal        = isset($input['valor_final'])           ? floatval($input['valor_final'])         : null;
    $obsBarbeiro       = isset($input['observacoes_barbeiro'])  ? trim($input['observacoes_barbeiro'])    : null;
    $forcarConfirmacaoCliente = isset($input['forcar_confirmacao_cliente'])
        ? filter_var($input['forcar_confirmacao_cliente'], FILTER_VALIDATE_BOOLEAN)
        : false;

    if ($dataAjustada    === '')   $dataAjustada    = null;
    if ($horarioAjustado === '')   $horarioAjustado = null;
    if ($tipoDesconto    === '')   $tipoDesconto    = null;
    if ($obsBarbeiro     === '')   $obsBarbeiro     = null;

    if ($codigoSolicitacao <= 0) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Solicitação não informada'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!in_array($acao, ['aceitar', 'recusar'])) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Ação inválida'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    garantirFuncaoTimestampCompativel($conexao);

    if ($acao === 'aceitar') {
        if ($tipoDesconto !== null && !in_array($tipoDesconto, ['percentual', 'fixo'])) {
            echo json_encode(['sucesso' => false, 'mensagem' => 'Tipo de desconto inválido'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($tipoDesconto === 'percentual' && ($valorDesconto < 0 || $valorDesconto > 100)) {
            echo json_encode(['sucesso' => false, 'mensagem' => 'Percentual de desconto deve ser entre 0 e 100'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($tipoDesconto === 'fixo' && $valorDesconto < 0) {
            echo json_encode(['sucesso' => false, 'mensagem' => 'Valor de desconto não pode ser negativo'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($dataAjustada !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataAjustada)) {
            echo json_encode(['sucesso' => false, 'mensagem' => 'Formato de data ajustada inválido'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($horarioAjustado !== null && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $horarioAjustado)) {
            echo json_encode(['sucesso' => false, 'mensagem' => 'Formato de horário ajustado inválido'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // Buscar solicitação completa
    $stmtSol = $conexao->prepare(
        "SELECT codigo, status, codigo_barbeiro,
                cliente_nome, cliente_telefone, servicos,
                dia_semana, horario_preferido,
                dia_semana_2, horario_preferido_2,
                periodicidade,
                quantidade_sessoes, valor_total_original,
                data_ajustada, horario_ajustado,
                tipo_desconto, valor_desconto, valor_final,
                observacoes_barbeiro
         FROM solicitacoes_plano_cliente
         WHERE codigo = :cod AND codigo_barbeiro = :barbeiro
         LIMIT 1"
    );
    $stmtSol->bindParam(':cod',      $codigoSolicitacao, PDO::PARAM_INT);
    $stmtSol->bindParam(':barbeiro', $codigoBarbeiro,    PDO::PARAM_INT);
    $stmtSol->execute();
    $solicitacao = $stmtSol->fetch(PDO::FETCH_ASSOC);

    if (!$solicitacao) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Solicitação não encontrada'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($solicitacao['status'] !== 'pendente') {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Esta solicitação já foi respondida'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // URL base para links WhatsApp
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
    $appRoot    = ($pos = strpos($scriptPath, '/bancodedados/')) !== false
                    ? substr($scriptPath, 0, $pos)
                    : '';
    $baseUrl  = $protocol . '://' . $host . $appRoot;

    // ----------------------------------------------------------------
    // RECUSAR
    // ----------------------------------------------------------------
    if ($acao === 'recusar') {
        $stmt = $conexao->prepare(
            "UPDATE solicitacoes_plano_cliente
             SET status = 'recusado', observacoes_barbeiro = :obs, data_resposta = NOW()
             WHERE codigo = :cod AND codigo_barbeiro = :barbeiro"
        );
        $stmt->execute([':obs' => $obsBarbeiro, ':cod' => $codigoSolicitacao, ':barbeiro' => $codigoBarbeiro]);
        echo json_encode(['sucesso' => true, 'mensagem' => 'Solicitação recusada.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ----------------------------------------------------------------
    // ACEITAR — verificar se há modificações nos termos
    // ----------------------------------------------------------------
    $temModificacao = (!empty($dataAjustada))
                   || (!empty($horarioAjustado))
                   || (!empty($tipoDesconto) && $valorDesconto > 0)
                   || ($forcarConfirmacaoCliente === true);

    // Valor final calculado
    $valorOriginal = floatval($solicitacao['valor_total_original']);
    if ($valorFinal === null) {
        if ($tipoDesconto === 'percentual' && $valorDesconto > 0) {
            $valorFinal = $valorOriginal - $valorOriginal * ($valorDesconto / 100);
        } elseif ($tipoDesconto === 'fixo' && $valorDesconto > 0) {
            $valorFinal = max(0, $valorOriginal - $valorDesconto);
        } else {
            $valorFinal = $valorOriginal;
        }
    }

    // Dados consolidados da solicitação com os ajustes do barbeiro
    $solicitacaoAtualizada                         = $solicitacao;
    $solicitacaoAtualizada['data_ajustada']        = $dataAjustada    ?: $solicitacao['data_ajustada'];
    $solicitacaoAtualizada['horario_ajustado']     = $horarioAjustado ?: $solicitacao['horario_ajustado'];
    $solicitacaoAtualizada['tipo_desconto']        = $tipoDesconto    ?: $solicitacao['tipo_desconto'];
    $solicitacaoAtualizada['valor_desconto']       = $valorDesconto   ?? $solicitacao['valor_desconto'];
    $solicitacaoAtualizada['valor_final']          = $valorFinal;
    $solicitacaoAtualizada['observacoes_barbeiro'] = $obsBarbeiro;

    if ($temModificacao) {
        // ---- Aceite COM modificações → aguardar confirmação do cliente ----
        $stmt = $conexao->prepare(
            "UPDATE solicitacoes_plano_cliente
             SET status               = 'aguardando_confirmacao_cliente',
                 data_ajustada        = :data_ajustada,
                 horario_ajustado     = :horario_ajustado,
                 tipo_desconto        = :tipo_desconto,
                 valor_desconto       = :valor_desconto,
                 valor_final          = :valor_final,
                 observacoes_barbeiro = :obs_barbeiro,
                 data_resposta        = NOW()
             WHERE codigo = :cod AND codigo_barbeiro = :barbeiro"
        );
        $stmt->execute([
            ':data_ajustada'    => $solicitacaoAtualizada['data_ajustada'],
            ':horario_ajustado' => $solicitacaoAtualizada['horario_ajustado'],
            ':tipo_desconto'    => $solicitacaoAtualizada['tipo_desconto'],
            ':valor_desconto'   => $solicitacaoAtualizada['valor_desconto'],
            ':valor_final'      => $solicitacaoAtualizada['valor_final'],
            ':obs_barbeiro'     => $solicitacaoAtualizada['observacoes_barbeiro'],
            ':cod'              => $codigoSolicitacao,
            ':barbeiro'         => $codigoBarbeiro,
        ]);

        // Enviar WhatsApp ao cliente com link de confirmação
        // O link inclui o telefone do cliente para pré-preencher e auto-buscar a solicitação
        $telEncoded      = urlencode($solicitacao['cliente_telefone']);
        $linkConfirmacao = $baseUrl
            . '/paginas/agendamentoclientes/solicitar-plano.html?barbeiro='
            . $codigoBarbeiro . '&sol=' . $codigoSolicitacao . '&tel=' . $telEncoded;

        $servicos   = is_array($solicitacao['servicos'])
                       ? $solicitacao['servicos']
                       : (json_decode($solicitacao['servicos'], true) ?? []);
        $nomesServs = implode(', ', array_map(fn($s) => $s['nome'] ?? '', $servicos));

        $diasLabel  = [
            'segunda' => 'Segunda-feira', 'terca' => 'Terça-feira', 'quarta' => 'Quarta-feira',
            'quinta'  => 'Quinta-feira',  'sexta' => 'Sexta-feira', 'sabado' => 'Sábado',
            'domingo' => 'Domingo',
        ];
        $horExib = substr($solicitacaoAtualizada['horario_ajustado'] ?: $solicitacao['horario_preferido'], 0, 5);
        if ($solicitacaoAtualizada['data_ajustada']) {
            $diaHorTexto = (new DateTime($solicitacaoAtualizada['data_ajustada']))->format('d/m/Y') . ' às ' . $horExib;
        } else {
            $diaHorTexto = ($diasLabel[$solicitacao['dia_semana']] ?? $solicitacao['dia_semana']) . ' às ' . $horExib;
        }

        enviarNotificacaoTermosPlano(
            $solicitacao['cliente_telefone'],
            $solicitacao['cliente_nome'],
            $nomesServs,
            $diaHorTexto,
            floatval($solicitacaoAtualizada['valor_final']),
            $nomeBarbeiro,
            $linkConfirmacao,
            $codigoBarbeiro
        );

        echo json_encode([
            'sucesso'  => true,
            'mensagem' => 'Proposta enviada ao cliente! Aguardando confirmação dos novos termos.',
        ], JSON_UNESCAPED_UNICODE);

    } else {
        // ---- Aceite SEM modificações → criar agendamentos imediatamente ----
        $conexao->beginTransaction();

        $stmt = $conexao->prepare(
            "UPDATE solicitacoes_plano_cliente
             SET status               = 'aceito',
                 valor_final          = :valor_final,
                 observacoes_barbeiro = :obs_barbeiro,
                 data_resposta        = NOW(),
                 cliente_confirmou    = TRUE,
                 data_confirmacao_cliente = NOW()
             WHERE codigo = :cod AND codigo_barbeiro = :barbeiro"
        );
        $stmt->execute([
            ':valor_final'  => $valorFinal,
            ':obs_barbeiro' => $obsBarbeiro,
            ':cod'          => $codigoSolicitacao,
            ':barbeiro'     => $codigoBarbeiro,
        ]);

        $resultado = criarAgendamentosParaPlano(
            $conexao,
            $solicitacaoAtualizada,
            $nomeBarbeiro,
            $baseUrl
        );

        // Persistir o grupo de agendamentos
        $stmtGrupo = $conexao->prepare(
            "UPDATE solicitacoes_plano_cliente SET grupo_agendamentos = :grupo WHERE codigo = :cod"
        );
        $stmtGrupo->execute([':grupo' => $resultado['grupo'], ':cod' => $codigoSolicitacao]);

        $conexao->commit();

        $n = $resultado['agendamentos'];
        echo json_encode([
            'sucesso'             => true,
            'mensagem'            => "Solicitação aceita! {$n} agendamento(s) criado(s) com sucesso.",
            'agendamentos_criados' => $n,
            'grupo'               => $resultado['grupo'],
        ], JSON_UNESCAPED_UNICODE);
    }

} catch (Exception $e) {
    if (isset($conexao) && $conexao->inTransaction()) {
        $conexao->rollBack();
    }
    error_log('[responder_solicitacao_plano] ' . $e->getMessage());
    // Mensagens de conflito de horário são erros de negócio — exibir ao barbeiro
    echo json_encode(['sucesso' => false, 'mensagem' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
