<?php
/**
 * API de Exclus�o de Agendamento por Cliente
 * Sistema: MustaBarber
 * Data: 29/01/2026
 * 
 * Permite que o cliente delete seu pr�prio agendamento
 * Apenas agendamentos com data igual ou superior � data atual podem ser deletados
 * Ao deletar, desbloqueia o hor�rio e recalcula hor�rios din�micos
 */

// Desabilitar exibi��o de erros no output
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json; charset=utf-8');

require_once '../conexao.php';
require_once '../whatsapp/agendamento_messages.php';
require_once '../horarios/recalcular_horarios_dinamicos.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'M�todo n�o permitido. Use POST.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $codigo = isset($_POST['codigo']) ? intval($_POST['codigo']) : 0;
    
    if (empty($codigo)) {
        throw new Exception('C�digo do agendamento � obrigat�rio.');
    }
    
    // Buscar o agendamento com todas as informa��es necess�rias
    $sqlBuscar = "SELECT 
                    a.codigo,
                    a.data_agendamento,
                    a.horario,
                    a.codigo_status,
                    a.codigo_servico,
                    a.valor,
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
        throw new Exception('Agendamento n�o encontrado.');
    }
    
    // Verificar se a data do agendamento � igual ou superior � data atual
    $dataAgendamento = new DateTime($agendamento['data_agendamento']);
    $hoje = new DateTime();
    $hoje->setTime(0, 0, 0); // Zerar horas para comparar apenas a data
    
    if ($dataAgendamento < $hoje) {
        throw new Exception('N�o � poss�vel deletar agendamentos de datas anteriores � hoje.');
    }
    
    // Verificar antecedencia minima de 3 horas para exclusao de agendamentos futuros
    $dataHoraAgendamento = new DateTime($agendamento['data_agendamento'] . ' ' . $agendamento['horario']);
    $agora = new DateTime();
    
    if ($dataHoraAgendamento > $agora) {
        $horasRestantes = ($dataHoraAgendamento->getTimestamp() - $agora->getTimestamp()) / 3600;
        
        if ($horasRestantes < 3) {
            throw new Exception('N�o � poss�vel excluir com menos de 3 horas de antecedencia. Entre em contato diretamente com o estabelecimento.');
        }
    }
    
    // Iniciar transa��o
    $conn->beginTransaction();
    
    // 1. Desbloquear hor�rio (se estava bloqueado)
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
    
    // 2. Deletar o agendamento
    $sqlDeletar = "DELETE FROM agendamentos WHERE codigo = :codigo";
    $stmtDeletar = $conn->prepare($sqlDeletar);
    $stmtDeletar->execute([':codigo' => $codigo]);
    
    // 3. Buscar configura��o do dia para recalcular hor�rios
    $diaSemana = date('w', strtotime($agendamento['data_agendamento'])); // 0=domingo, 6=s�bado
    
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
    
    // 4. Recalcular hor�rios din�micos se houver configura��o
    $horariosRecalculados = false;
    if ($config) {
        try {
            $resultadoRecalculo = recalcularHorariosDinamicos(
                $conn, 
                $agendamento['codigo_barbeiro'], 
                $agendamento['data_agendamento'], 
                $config
            );
            $horariosRecalculados = true;
        } catch (Exception $eRecalculo) {
            error_log("Erro ao recalcular hor�rios: " . $eRecalculo->getMessage());
            // N�o interrompe o fluxo
        }
    }
    
    // Confirmar transa��o
    $conn->commit();
    
    // 5. Preparar dados para notifica��o ao barbeiro
    $dadosAgendamento = [
        'cliente_nome' => $agendamento['cliente_nome'],
        'cliente_telefone' => $agendamento['cliente_telefone'],
        'data_agendamento' => $agendamento['data_agendamento'],
        'horario' => $agendamento['horario'],
        'valor' => $agendamento['valor']
    ];
    
    // 6. Tentar enviar notifica��o ao barbeiro (n�o bloqueia se falhar)
    $whatsappEnviado = false;
    $whatsappErro = null;
    try {
        // Log dos dados antes de enviar
        error_log("=== Deletar Agendamento - WhatsApp ===");
        error_log("Telefone Barbeiro: " . ($agendamento['barbeiro_telefone'] ?? 'NULL'));
        error_log("Nome Barbeiro: " . ($agendamento['barbeiro_nome'] ?? 'NULL'));
        error_log("Nome Servi�o: " . ($agendamento['servico_nome'] ?? 'NULL'));
        error_log("Cliente: " . $dadosAgendamento['cliente_nome']);
        
        if (empty($agendamento['barbeiro_telefone'])) {
            throw new Exception('Telefone do barbeiro n�o encontrado');
        }
        
        $resultadoWhatsApp = notificarBarbeiroCancelamentoCliente(
            $agendamento['barbeiro_telefone'],
            $dadosAgendamento,
            $agendamento['servico_nome'],
            $agendamento['barbeiro_nome'],
            $agendamento['codigo_barbeiro']
        );
        
        error_log("Resultado WhatsApp: " . json_encode($resultadoWhatsApp));
        $whatsappEnviado = $resultadoWhatsApp['success'] ?? false;
    } catch (Exception $eWhatsApp) {
        // Log do erro mas n�o interrompe o fluxo
        $whatsappErro = $eWhatsApp->getMessage();
        error_log("Erro ao enviar WhatsApp: " . $whatsappErro);
    }
    
    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Agendamento deletado com sucesso.',
        'horario_desbloqueado' => true,
        'horarios_recalculados' => $horariosRecalculados,
        'whatsapp_enviado' => $whatsappEnviado,
        'whatsapp_erro' => $whatsappErro,
        'debug' => [
            'telefone_barbeiro' => $agendamento['barbeiro_telefone'],
            'nome_barbeiro' => $agendamento['barbeiro_nome'],
            'servico' => $agendamento['servico_nome']
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // Em caso de erro, desfaz a transa��o
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    
    echo json_encode([
        'sucesso' => false,
        'mensagem' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
