<?php
/**
 * API de Cancelamento de Agendamento por Cliente
 * Sistema: MustaBarber
 * Data: 20/12/2025
 * Atualizado: 09/08/2026
 * 
 * Permite que o cliente cancele seu pr&#65533;prio agendamento.
 * Ao cancelar, o agendamento &#65533; removido da agenda do cliente,
 * o hor&#65533;rio &#65533; reaberto para novos agendamentos e os
 * hor&#65533;rios do dia s&#65533;o recalculados.
 */

header('Content-Type: application/json; charset=utf-8');

require_once '../conexao.php';
require_once '../whatsapp/agendamento_messages.php';
require_once '../horarios/recalcular_horarios_dinamicos.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'M&#65533;todo n&#65533;o permitido. Use POST.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $codigo = isset($_POST['codigo']) ? intval($_POST['codigo']) : 0;
    
    if (empty($codigo)) {
        throw new Exception('C&#65533;digo do agendamento &#65533; obrigat&#65533;rio.');
    }
    
    // Buscar o agendamento
    $sqlBuscar = "SELECT 
                    a.codigo,
                    a.data_agendamento,
                    a.horario,
                    a.codigo_status,
                    a.valor,
                    a.codigo_servico,
                    a.cliente_nome,
                    a.cliente_telefone,
                    a.codigo_barbeiro,
                    u.nome as barbeiro_nome,
                    u.telefone as barbeiro_telefone,
                    s.nome as servico_nome,
                    st.nome as status_nome
                FROM agendamentos a
                INNER JOIN usuarios u ON a.codigo_barbeiro = u.codigo
                LEFT JOIN servicos s ON a.codigo_servico = s.codigo
                INNER JOIN status_agendamento st ON a.codigo_status = st.codigo
                WHERE a.codigo = :codigo";
    
    $stmtBuscar = $conn->prepare($sqlBuscar);
    $stmtBuscar->execute([':codigo' => $codigo]);
    $agendamento = $stmtBuscar->fetch(PDO::FETCH_ASSOC);
    
    if (!$agendamento) {
        throw new Exception('Agendamento n&#65533;o encontrado.');
    }
    
    // Verificar se j&#65533; est&#65533; cancelado
    if ($agendamento['codigo_status'] == 4) {
        throw new Exception('Este agendamento j&#65533; est&#65533; cancelado.');
    }
    
    // Verificar se a data j&#65533; passou
    $dataHora = new DateTime($agendamento['data_agendamento'] . ' ' . $agendamento['horario']);
    $agora = new DateTime();
    
    if ($dataHora < $agora) {
        throw new Exception('N&#65533;o &#65533; poss&#65533;vel cancelar agendamentos que j&#65533; passaram.');
    }
    
    // Verificar antecedencia minima de 3 horas para cancelamento
    $horasRestantes = ($dataHora->getTimestamp() - $agora->getTimestamp()) / 3600;
    
    if ($horasRestantes < 3) {
        throw new Exception('N&#65533;o &#65533; poss&#65533;vel cancelar com menos de 3 horas de antecedencia. Entre em contato diretamente com o estabelecimento.');
    }
    
    // Inicia transa&#65533;&#65533;o
    $conn->beginTransaction();
    
    // 1. Desbloquear hor&#65533;rio (se estava bloqueado)
    $sqlDesbloquear = "DELETE FROM horarios_bloqueados 
                       WHERE codigo_barbeiro = :codigo_barbeiro 
                       AND data_bloqueio = :data_bloqueio 
                       AND horario = :horario 
                       AND codigo_tipo_bloqueio = 1";
    
    $stmtDesbloquear = $conn->prepare($sqlDesbloquear);
    $stmtDesbloquear->execute([
        ':codigo_barbeiro' => $agendamento['codigo_barbeiro'],
        ':data_bloqueio' => $agendamento['data_agendamento'],
        ':horario' => $agendamento['horario']
    ]);
    
    // 2. Remover o agendamento da agenda do cliente
    $sqlDeletar = "DELETE FROM agendamentos WHERE codigo = :codigo";
    $stmtDeletar = $conn->prepare($sqlDeletar);
    $stmtDeletar->execute([':codigo' => $codigo]);
    
    // 3. Buscar configura&#65533;&#65533;o do dia para recalcular hor&#65533;rios
    $diaSemana = date('w', strtotime($agendamento['data_agendamento'])); // 0=domingo, 6=s&#65533;bado
    
    $sqlConfig = "SELECT horario_inicio, horario_fim, intervalo_minutos 
                  FROM horarios_configuracao 
                  WHERE codigo_barbeiro = :codigo_barbeiro 
                  AND dia_semana = :dia_semana 
                  AND ativo = true";
    
    $stmtConfig = $conn->prepare($sqlConfig);
    $stmtConfig->execute([
        ':codigo_barbeiro' => $agendamento['codigo_barbeiro'],
        ':dia_semana' => $diaSemana
    ]);
    $config = $stmtConfig->fetch(PDO::FETCH_ASSOC);
    
    // 4. Recalcular hor&#65533;rios din&#65533;micos se houver configura&#65533;&#65533;o
    $horariosRecalculados = false;
    if ($config) {
        try {
            recalcularHorariosDinamicos(
                $conn, 
                $agendamento['codigo_barbeiro'], 
                $agendamento['data_agendamento'], 
                $config
            );
            $horariosRecalculados = true;
        } catch (Exception $eRecalculo) {
            error_log("Erro ao recalcular hor&#65533;rios: " . $eRecalculo->getMessage());
            // N&#65533;o interrompe o fluxo
        }
    }
    
    // Confirmar transa&#65533;&#65533;o
    $conn->commit();
    
    // Preparar dados para notifica��o ao barbeiro
    $dadosAgendamento = [
        'cliente_nome' => $agendamento['cliente_nome'],
        'cliente_telefone' => $agendamento['cliente_telefone'],
        'data_agendamento' => $agendamento['data_agendamento'],
        'horario' => $agendamento['horario'],
        'valor' => $agendamento['valor']
    ];
    
    // Tentar enviar notifica��o ao barbeiro (n�o bloqueia se falhar)
    $whatsappEnviado = false;
    try {
        $resultadoWhatsApp = notificarBarbeiroCancelamentoCliente(
            $agendamento['barbeiro_telefone'],
            $dadosAgendamento,
            $agendamento['servico_nome'],
            $agendamento['barbeiro_nome'],
            $agendamento['codigo_barbeiro']
        );
        $whatsappEnviado = $resultadoWhatsApp['success'] ?? false;
    } catch (Exception $eWhatsApp) {
        // Log do erro mas n�o interrompe o fluxo
        error_log("Erro ao enviar WhatsApp: " . $eWhatsApp->getMessage());
    }
    
    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Agendamento cancelado com sucesso.',
        'horario_desbloqueado' => true,
        'horarios_recalculados' => $horariosRecalculados,
        'whatsapp_enviado' => $whatsappEnviado
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // Em caso de erro, desfaz a transa&#65533;&#65533;o
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    
    echo json_encode([
        'sucesso' => false,
        'mensagem' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
