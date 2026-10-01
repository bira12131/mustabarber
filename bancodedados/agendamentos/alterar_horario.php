<?php
/**
 * API para Alterar Horário de Agendamento
 * Sistema: MustaBarber
 *
 * Fluxo:
 *  1. Valida e busca o agendamento.
 *  2. Calcula se a duração do serviço ultrapassa o próximo agendamento do dia.
 *  3. Se houver conflito e confirmar_conflito=false  → retorna conflito para o frontend confirmar.
 *  4. Se confirmar_conflito=true  → altera os dois agendamentos e dispara WhatsApp para ambos.
 */

header('Content-Type: application/json; charset=utf-8');

require_once '../conexao.php';
require_once '../usuarios/sessao.php';
require_once '../whatsapp/agendamento_messages.php';
require_once '../horarios/recalcular_horarios_dinamicos.php';

if (!estaLogado()) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Usuário não autenticado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Método não permitido. Use POST.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $usuarioLogado    = obterUsuarioLogado();
    $codBarbeiro      = $usuarioLogado['codigo'];

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    $codAgendamento   = isset($input['codigo'])              ? intval($input['codigo'])          : 0;
    $novaData         = isset($input['nova_data'])           ? trim($input['nova_data'])          : '';
    $novoHorario      = isset($input['novo_horario'])        ? trim($input['novo_horario'])       : '';
    $confirmarConflito = !empty($input['confirmar_conflito']);
    $forcarHorarioOcupado = !empty($input['forcar_horario_ocupado']);

    if (!$codAgendamento || !$novaData || !$novoHorario) {
        throw new Exception('Código do agendamento, nova data e novo horário são obrigatórios.');
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $novaData)) {
        throw new Exception('Formato de data inválido. Use YYYY-MM-DD.');
    }

    if (!preg_match('/^\d{2}:\d{2}$/', $novoHorario)) {
        throw new Exception('Formato de horário inválido. Use HH:MM.');
    }

    // ── Buscar agendamento principal ─────────────────────────────────────────
    $stmtAg = $conn->prepare(
        "SELECT a.codigo, a.codigo_barbeiro, a.cliente_nome, a.cliente_telefone,
                a.data_agendamento, a.horario, a.valor, a.codigo_status,
                COALESCE(NULLIF(a.duracao_minutos, 0), s.duracao, 30) AS duracao_minutos,
                s.nome AS servico_nome,
                u.nome AS barbeiro_nome
         FROM agendamentos a
         INNER JOIN usuarios u ON u.codigo = a.codigo_barbeiro
         LEFT  JOIN servicos s ON s.codigo = a.codigo_servico
         WHERE a.codigo = :codigo AND a.codigo_barbeiro = :barbeiro"
    );
    $stmtAg->execute([':codigo' => $codAgendamento, ':barbeiro' => $codBarbeiro]);
    $agendamento = $stmtAg->fetch(PDO::FETCH_ASSOC);

    if (!$agendamento) {
        throw new Exception('Agendamento não encontrado ou sem permissão.');
    }

    if (in_array((int)$agendamento['codigo_status'], [4, 5])) {
        throw new Exception('Não é possível alterar o horário de um agendamento finalizado ou cancelado.');
    }

    $horarioAntigoFormatado = substr($agendamento['horario'], 0, 5);
    $dataAntiga             = $agendamento['data_agendamento'];

    if ($novaData === $dataAntiga && $novoHorario === $horarioAntigoFormatado) {
        throw new Exception('O novo horário é igual ao horário atual do agendamento.');
    }

    // ── Verificar ocupação exata no novo horário ──────────────────────────────
    $stmtOcupado = $conn->prepare(
        "SELECT codigo FROM agendamentos
         WHERE codigo_barbeiro = :barbeiro
           AND data_agendamento = :data
           AND CAST(horario AS VARCHAR) LIKE :horario
           AND codigo_status NOT IN (4, 5)
           AND codigo != :codigo"
    );
    $stmtOcupado->execute([
        ':barbeiro' => $codBarbeiro,
        ':data'     => $novaData,
        ':horario'  => $novoHorario . '%',
        ':codigo'   => $codAgendamento,
    ]);
    if ($stmtOcupado->rowCount() > 0 && !$forcarHorarioOcupado) {
        throw new Exception('Já existe um agendamento neste horário. Escolha outro horário disponível.');
    }

    // ── Verificar bloqueio ────────────────────────────────────────────────────
    $stmtBloq = $conn->prepare(
        "SELECT codigo FROM horarios_bloqueados
         WHERE codigo_barbeiro = :barbeiro
           AND data_bloqueio = :data
           AND CAST(horario AS VARCHAR) LIKE :horario"
    );
    $stmtBloq->execute([
        ':barbeiro' => $codBarbeiro,
        ':data'     => $novaData,
        ':horario'  => $novoHorario . '%',
    ]);
    if ($stmtBloq->rowCount() > 0) {
        throw new Exception('Este horário está bloqueado e não pode receber agendamentos.');
    }

    // ── Verificar conflito com horário de almoço ──────────────────────────────
    if (verificarConflitoAlmoco($conn, $codBarbeiro, $novaData, $novoHorario, (int)$agendamento['duracao_minutos'])) {
        throw new Exception('Este horário conflita com o horário de almoço configurado. Escolha outro horário.');
    }

    // ── Detectar conflito de duração com próximo agendamento ─────────────────
    //    Calcula horário de término: novoHorario + duração do serviço
    $duracao      = (int)$agendamento['duracao_minutos'];
    $inicioTs     = strtotime($novaData . ' ' . $novoHorario . ':00');
    $fimTs        = $inicioTs + ($duracao * 60);
    $fimFormatado = date('H:i', $fimTs); // ex: "18:55"

    // Buscar próximo agendamento ativo do mesmo barbeiro naquele dia APÓS o novo horário
    $stmtProximo = $conn->prepare(
        "SELECT a.codigo, a.cliente_nome, a.cliente_telefone,
                a.horario, a.valor, a.codigo_status,
                COALESCE(s.nome, 'Serviço') AS servico_nome
         FROM agendamentos a
         LEFT JOIN servicos s ON s.codigo = a.codigo_servico
         WHERE a.codigo_barbeiro = :barbeiro
           AND a.data_agendamento = :data
           AND CAST(a.horario AS VARCHAR) > :horario
           AND a.codigo_status NOT IN (4, 5)
           AND a.codigo != :codigo
         ORDER BY a.horario ASC
         LIMIT 1"
    );
    $stmtProximo->execute([
        ':barbeiro' => $codBarbeiro,
        ':data'     => $novaData,
        ':horario'  => $novoHorario,
        ':codigo'   => $codAgendamento,
    ]);
    $proximoAg = $stmtProximo->fetch(PDO::FETCH_ASSOC);

    $conflitoDuracao = false;
    $proximoHorarioAtual = null;

    if ($proximoAg) {
        $proximoHorarioAtual = substr($proximoAg['horario'], 0, 5); // ex: "18:50"
        $proximoTs           = strtotime($novaData . ' ' . $proximoHorarioAtual . ':00');

        // Conflito: próximo começa ANTES de este terminar
        if ($proximoTs < $fimTs) {
            $conflitoDuracao = true;
        }
    }

    // ── Se há conflito de duração e ainda não foi confirmado → retornar para frontend ──
    if ($conflitoDuracao && !$confirmarConflito) {
        echo json_encode([
            'sucesso'              => false,
            'conflito'             => true,
            'mensagem'             => 'O serviço ultrapassa o próximo agendamento.',
            'fim_servico'          => $fimFormatado,
            'proximo_agendamento'  => [
                'codigo'          => (int)$proximoAg['codigo'],
                'cliente_nome'    => $proximoAg['cliente_nome'],
                'cliente_telefone'=> $proximoAg['cliente_telefone'],
                'horario_atual'   => $proximoHorarioAtual,
                'horario_novo'    => $fimFormatado,
                'servico_nome'    => $proximoAg['servico_nome'],
                'valor'           => $proximoAg['valor'],
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── Executar alterações ───────────────────────────────────────────────────
    $conn->beginTransaction();

    // 1. Alterar agendamento principal
    $stmtUp = $conn->prepare(
        "UPDATE agendamentos
         SET data_agendamento = :nova_data,
             horario = :novo_horario
         WHERE codigo = :codigo AND codigo_barbeiro = :barbeiro"
    );
    $stmtUp->execute([
        ':nova_data'    => $novaData,
        ':novo_horario' => $novoHorario,
        ':codigo'       => $codAgendamento,
        ':barbeiro'     => $codBarbeiro,
    ]);

    // 2. Se havia conflito confirmado, alterar o próximo agendamento também
    if ($conflitoDuracao && $confirmarConflito && $proximoAg) {
        $stmtUpProximo = $conn->prepare(
            "UPDATE agendamentos
             SET horario = :novo_horario
             WHERE codigo = :codigo AND codigo_barbeiro = :barbeiro"
        );
        $stmtUpProximo->execute([
            ':novo_horario' => $fimFormatado,
            ':codigo'       => $proximoAg['codigo'],
            ':barbeiro'     => $codBarbeiro,
        ]);
    }

    $conn->commit();

    // ── Buscar nome do serviço para a notificação ─────────────────────────────
    $nomeServico = $agendamento['servico_nome'] ?: 'Serviço';

    // ── Enviar WhatsApp para o cliente do agendamento principal ───────────────
    enviarAlteracaoHorarioAgendamento(
        [
            'codigo_barbeiro' => $codBarbeiro,
            'cliente_nome'     => $agendamento['cliente_nome'],
            'cliente_telefone' => $agendamento['cliente_telefone'],
            'data_agendamento' => $novaData,
            'valor'            => $agendamento['valor'],
        ],
        $horarioAntigoFormatado,
        $novoHorario,
        $nomeServico,
        $agendamento['barbeiro_nome'],
        $codBarbeiro
    );

    // ── Enviar WhatsApp para o cliente do próximo agendamento (se alterado) ───
    if ($conflitoDuracao && $confirmarConflito && $proximoAg && $proximoAg['cliente_telefone']) {
        enviarAlteracaoHorarioAgendamento(
            [
                'codigo_barbeiro' => $codBarbeiro,
                'cliente_nome'     => $proximoAg['cliente_nome'],
                'cliente_telefone' => $proximoAg['cliente_telefone'],
                'data_agendamento' => $novaData,
                'valor'            => $proximoAg['valor'],
            ],
            $proximoHorarioAtual,
            $fimFormatado,
            $proximoAg['servico_nome'],
            $agendamento['barbeiro_nome'],
            $codBarbeiro
        );
    }

    $mensagemRetorno = 'Horário alterado com sucesso!';
    if ($conflitoDuracao && $confirmarConflito) {
        $mensagemRetorno = "Horário alterado! O agendamento de {$proximoAg['cliente_nome']} também foi ajustado para {$fimFormatado}.";
    }

    echo json_encode([
        'sucesso'              => true,
        'mensagem'             => $mensagemRetorno,
        'nova_data'            => $novaData,
        'novo_horario'         => $novoHorario,
        'proximo_alterado'     => ($conflitoDuracao && $confirmarConflito),
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(['sucesso' => false, 'mensagem' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
