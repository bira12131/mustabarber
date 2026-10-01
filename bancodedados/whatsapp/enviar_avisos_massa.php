<?php
/**
 * Endpoint para envio de avisos em massa para clientes
 * Sistema: MustaBarber
 */

// Limpar qualquer output buffer anterior
if (ob_get_level()) {
    ob_end_clean();
}
ob_start();

// Log de erros mas n�o exibir no output
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Iniciar sessão
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Incluir arquivos necessários
require_once '../conexao.php';
require_once 'whatsappservice.php';
require_once '../planos/plano_manager.php';

// Limpar buffer e definir header
ob_clean();
header('Content-Type: application/json; charset=utf-8');

try {
    // Verificar se é POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método não permitido');
    }

    // Verificar se usuário está logado
    if (!isset($_SESSION['usuario_logado']) || $_SESSION['usuario_logado'] !== true) {
        http_response_code(401);
        throw new Exception('Usuário não autenticado');
    }

    // Verificar acesso por plano (WhatsApp exige Solo Pro ou superior)
    exigirPlanoOuBloquear($conexao, (int)($_SESSION['usuario_codigo'] ?? 0), (int)($_SESSION['usuario_perfil'] ?? 0), 'whatsapp');

    // Verificar conexão com banco de dados (PDO)
    if (!isset($conn) || !($conn instanceof PDO)) {
        throw new Exception('Erro na conexão com banco de dados');
    }

    // Receber dados
    $enviarHoje   = isset($_POST['enviar_hoje'])    && $_POST['enviar_hoje']    == '1';
    $enviarAmanha = isset($_POST['enviar_amanha'])  && $_POST['enviar_amanha']  == '1';
    $apenasContar = isset($_POST['apenas_contar'])  && $_POST['apenas_contar'] == '1';

    // Validar dados
    if (!$enviarHoje && !$enviarAmanha) {
        throw new Exception('Selecione pelo menos um per�odo');
    }

    // Obter informa��es do barbeiro logado
    $codigoBarbeiro = $_SESSION['usuario_codigo'];
    $nomeBarbeiro = $_SESSION['usuario_nome'] ?? 'Barbeiro';

    // Preparar datas
    $hoje = date('Y-m-d');
    $amanha = date('Y-m-d', strtotime('+1 day'));
    
    $datas = [];
    if ($enviarHoje) {
        $datas[] = $hoje;
    }
    if ($enviarAmanha) {
        $datas[] = $amanha;
    }

    // Montar query para buscar agendamentos
    $placeholders = implode(',', array_fill(0, count($datas), '?'));
    
    $sql = "
        SELECT 
            a.codigo,
            a.cliente_nome,
            a.cliente_telefone,
            a.data_agendamento,
            a.horario,
            s.nome as nome_servico,
            s.duracao,
            u.nome as barbeiro_nome,
            a.valor
        FROM agendamentos a
        INNER JOIN servicos s ON a.codigo_servico = s.codigo
        INNER JOIN usuarios u ON a.codigo_barbeiro = u.codigo
        WHERE a.codigo_barbeiro = ?
        AND a.data_agendamento IN ($placeholders)
        AND a.codigo_status IN (1, 2)
        ORDER BY a.data_agendamento, a.horario
    ";

    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        throw new Exception('Erro ao preparar consulta');
    }
    
    // Preparar par�metros para PDO
    $params = [$codigoBarbeiro];
    foreach ($datas as $data) {
        $params[] = $data;
    }
    
    // Executar com PDO
    if (!$stmt->execute($params)) {
        throw new Exception('Erro ao executar consulta');
    }
    
    $agendamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Contar por per�odo
    $countHoje = 0;
    $countAmanha = 0;
    
    foreach ($agendamentos as $row) {
        if ($row['data_agendamento'] == $hoje) {
            $countHoje++;
        } else {
            $countAmanha++;
        }
    }

    // Se � apenas para contar, retornar contagem
    if ($apenasContar) {
        $response = json_encode([
            'sucesso' => true,
            'total' => count($agendamentos),
            'hoje' => $countHoje,
            'amanha' => $countAmanha
        ], JSON_UNESCAPED_UNICODE);
        
        echo $response;
        ob_end_flush();
        exit;
    }

    // Se n�o h� agendamentos, retornar
    if (count($agendamentos) == 0) {
        $response = json_encode([
            'sucesso' => true,
            'mensagem' => 'Nenhum agendamento encontrado para o periodo selecionado',
            'enviados' => 0,
            'erros' => 0
        ], JSON_UNESCAPED_UNICODE);
        
        echo $response;
        ob_end_flush();
        exit;
    }

    // Instanciar servi�o de WhatsApp
    $whatsapp = new WhatsAppService((int)$codigoBarbeiro);

    // Enviar mensagens
    $enviados = 0;
    $erros = 0;

    foreach ($agendamentos as $agendamento) {
        try {
            // Formatar data
            $dataAgendamento = DateTime::createFromFormat('Y-m-d', $agendamento['data_agendamento']);
            $dataFormatada = $dataAgendamento->format('d/m/Y');
            
            // Obter dia da semana em portugu�s
            $diasSemana = [
                'Sunday' => 'Domingo',
                'Monday' => 'Segunda-feira',
                'Tuesday' => 'Ter�a-feira',
                'Wednesday' => 'Quarta-feira',
                'Thursday' => 'Quinta-feira',
                'Friday' => 'Sexta-feira',
                'Saturday' => 'S�bado'
            ];
            $diaSemanaEn = $dataAgendamento->format('l');
            $diaSemana = $diasSemana[$diaSemanaEn] ?? $diaSemanaEn;
            
            // Formatar hor�rio
            $horario = substr($agendamento['horario'], 0, 5);
            
            // Determinar se � hoje ou amanh�
            $quando = ($agendamento['data_agendamento'] == $hoje) ? 'hoje' : 'amanh�';
            
            // Montar mensagem personalizada no padr�o do sistema
            if ($quando == 'hoje') {
                $mensagem = "&#128276; *LEMBRETE DE AGENDAMENTO*\n\n";
                $mensagem .= "Ola *{$agendamento['cliente_nome']}*!\n\n";
                $mensagem .= "Voce tem um horario agendado *HOJE*! &#128197;\n\n";
            } else {
                $mensagem = "&#128276; *LEMBRETE DE AGENDAMENTO*\n\n";
                $mensagem .= "Ola *{$agendamento['cliente_nome']}*!\n\n";
                $mensagem .= "Voce tem um horario agendado para *AMANHA*! &#128197;\n\n";
            }
            
            $mensagem .= "*Detalhes do agendamento:*\n";
            $mensagem .= "&#128197; *Data:* {$diaSemana}, {$dataFormatada}\n";
            $mensagem .= "&#128336; *Horario:* {$horario}\n";
            $mensagem .= "&#128136; *Barbeiro:* {$agendamento['barbeiro_nome']}\n";
            $mensagem .= "&#9986; *Servico:* {$agendamento['nome_servico']}\n";
            
            if ($agendamento['duracao']) {
                $mensagem .= "&#9201; *Duracao:* {$agendamento['duracao']} minutos\n";
            }
            
            $mensagem .= "\nChegue alguns minutos antes do horario.\n";
            $mensagem .= "&#9888;&#65039; Se precisar cancelar, avise com antecedencia.\n\n";
            $mensagem .= "Obrigado! &#128591;";

            // Enviar mensagem
            $resultadoEnvio = $whatsapp->enviarMensagem($agendamento['cliente_telefone'], $mensagem);
            
            if ($resultadoEnvio['success']) {
                $enviados++;
            } else {
                $erros++;
            }
            
            // Aguardar um pouco entre envios para n�o sobrecarregar
            usleep(500000); // 0.5 segundo
            
        } catch (Exception $e) {
            $erros++;
            error_log("Erro ao enviar mensagem para {$agendamento['cliente_nome']}: " . $e->getMessage());
        }
    }

    // Retornar resultado
    $response = json_encode([
        'sucesso' => true,
        'mensagem' => 'Processo de envio concluido',
        'total' => count($agendamentos),
        'enviados' => $enviados,
        'erros' => $erros
    ], JSON_UNESCAPED_UNICODE);
    
    echo $response;
    ob_end_flush();
    exit;

} catch (Exception $e) {
    error_log("Erro em enviar_avisos_massa.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    http_response_code(400);
    $response = json_encode([
        'sucesso' => false,
        'mensagem' => $e->getMessage(),
        'erro_detalhes' => $e->getFile() . ':' . $e->getLine()
    ], JSON_UNESCAPED_UNICODE);
    
    echo $response;
    ob_end_flush();
    exit;
} catch (Throwable $e) {
    error_log("Erro fatal em enviar_avisos_massa.php: " . $e->getMessage());
    
    http_response_code(500);
    $response = json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro interno do servidor',
        'erro' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    
    echo $response;
    ob_end_flush();
    exit;
}
