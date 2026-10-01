<?php
/**
 * M&#65533;dulo de Notifica&#65533;&#65533;es WhatsApp para Agendamentos
 * Sistema: MustaBarber
 * Data: 20/12/2025
 * 
 * Este arquivo cont&#65533;m fun&#65533;&#65533;es para enviar notifica&#65533;&#65533;es de agendamento
 * via WhatsApp em diferentes situa&#65533;&#65533;es
 */

require_once __DIR__ . '/whatsappservice.php';

/**
 * Enviar mensagem de confirma&#65533;&#65533;o de agendamento
 * 
 * @param array $agendamento - Array com dados do agendamento
 * @param string $nomeServico - Nome do servi&#65533;o agendado
 * @param string $nomeBarbeiro - Nome do barbeiro
 * @param string|null $telefoneBarbeiro - Telefone do barbeiro para contato
 * @return array - Resultado do envio
 */
function enviarConfirmacaoAgendamento($agendamento, $nomeServico, $nomeBarbeiro, $telefoneBarbeiro = null, $codigoBarbeiro = null) {
    try {
        $whatsapp = new WhatsAppService($codigoBarbeiro);
        
        // Formatar data e hora para exibi&#65533;&#65533;o
        $dataAgendamento = new DateTime($agendamento['data_agendamento']);
        $dataFormatada = $dataAgendamento->format('d/m/Y');
        $horaFormatada = substr($agendamento['horario'], 0, 5); // HH:MM
        
        // Montar mensagem
        $mensagem = "\xE2\x9C\x85 *AGENDAMENTO CONFIRMADO*\n\n";
        $mensagem .= "Ola *{$agendamento['cliente_nome']}*!\n\n";
        $mensagem .= "Seu agendamento foi realizado com sucesso! \xF0\x9F\x8E\x89\n\n";
        $mensagem .= "\xF0\x9F\x93\x85 *Data:* {$dataFormatada}\n";
        $mensagem .= "\xF0\x9F\x95\x90 *Horario:* {$horaFormatada}\n";
        $mensagem .= "\xF0\x9F\x92\x88 *Barbeiro:* {$nomeBarbeiro}\n";
        $mensagem .= "\xE2\x9C\x82 *Servico:* {$nomeServico}\n";
        $mensagem .= "\xF0\x9F\x92\xB0 *Valor:* R$ " . number_format($agendamento['valor'], 2, ',', '.') . "\n\n";
        if (!empty($telefoneBarbeiro)) {
            $mensagem .= "\xF0\x9F\x93\xB1 *Contato do barbeiro:* {$telefoneBarbeiro}\n\n";
        }
        $mensagem .= "Chegue alguns minutos antes do horario.\n";
        $mensagem .= "Obrigado! \xF0\x9F\x99\x8F";
        
        // Enviar mensagem
        return $whatsapp->enviarMensagem($agendamento['cliente_telefone'], $mensagem);
        
    } catch (Exception $e) {
        return [
            "success" => false,
            "message" => "Erro ao enviar mensagem de confirmacao: " . $e->getMessage()
        ];
    }
}

/**
 * Enviar mensagem de cancelamento de agendamento
 * 
 * @param array $agendamento - Array com dados do agendamento
 * @param string $nomeServico - Nome do servi&#65533;o
 * @param string $nomeBarbeiro - Nome do barbeiro
 * @return array - Resultado do envio
 */
function enviarCancelamentoAgendamento($agendamento, $nomeServico, $nomeBarbeiro, $codigoBarbeiro = null) {
    try {
        $whatsapp = new WhatsAppService($codigoBarbeiro);
        
        // Formatar data e hora para exibi&#65533;&#65533;o
        $dataAgendamento = new DateTime($agendamento['data_agendamento']);
        $dataFormatada = $dataAgendamento->format('d/m/Y');
        $horaFormatada = substr($agendamento['horario'], 0, 5); // HH:MM
        
        // Montar mensagem
        $mensagem = "\xE2\x9D\x8C *AGENDAMENTO CANCELADO*\n\n";
        $mensagem .= "Ola *{$agendamento['cliente_nome']}*!\n\n";
        $mensagem .= "Seu agendamento foi cancelado. \xE2\x9A\xA0\xEF\xB8\x8F\n\n";
        $mensagem .= "*Informacoes do agendamento cancelado:*\n";
        $mensagem .= "\xF0\x9F\x93\x85 *Data:* {$dataFormatada}\n";
        $mensagem .= "\xF0\x9F\x95\x90 *Horario:* {$horaFormatada}\n";
        $mensagem .= "\xF0\x9F\x92\x88 *Barbeiro:* {$nomeBarbeiro}\n";
        $mensagem .= "\xE2\x9C\x82 *Servico:* {$nomeServico}\n\n";
        $mensagem .= "Se deseja agendar novamente, entre em contato conosco.\n";
        $mensagem .= "Obrigado! \xF0\x9F\x99\x8F";
        
        // Enviar mensagem
        return $whatsapp->enviarMensagem($agendamento['cliente_telefone'], $mensagem);
        
    } catch (Exception $e) {
        return [
            "success" => false,
            "message" => "Erro ao enviar mensagem de cancelamento: " . $e->getMessage()
        ];
    }
}

/**
 * Enviar mensagem de reabertura de agendamento
 * 
 * @param array $agendamento - Array com dados do agendamento
 * @param string $nomeServico - Nome do servi&#65533;o
 * @param string $nomeBarbeiro - Nome do barbeiro
 * @return array - Resultado do envio
 */
function enviarReaberturaAgendamento($agendamento, $nomeServico, $nomeBarbeiro, $codigoBarbeiro = null) {
    try {
        $whatsapp = new WhatsAppService($codigoBarbeiro);
        
        // Formatar data e hora para exibi&#65533;&#65533;o
        $dataAgendamento = new DateTime($agendamento['data_agendamento']);
        $dataFormatada = $dataAgendamento->format('d/m/Y');
        $horaFormatada = substr($agendamento['horario'], 0, 5); // HH:MM
        
        // Montar mensagem
        $mensagem = "\xE2\x9C\x85 *AGENDAMENTO REATIVADO*\n\n";
        $mensagem .= "Ola *{$agendamento['cliente_nome']}*!\n\n";
        $mensagem .= "Seu agendamento foi reativado com sucesso! \xF0\x9F\x8E\x89\n\n";
        $mensagem .= "\xF0\x9F\x93\x85 *Data:* {$dataFormatada}\n";
        $mensagem .= "\xF0\x9F\x95\x90 *Horario:* {$horaFormatada}\n";
        $mensagem .= "\xF0\x9F\x92\x88 *Barbeiro:* {$nomeBarbeiro}\n";
        $mensagem .= "\xE2\x9C\x82 *Servico:* {$nomeServico}\n";
        $mensagem .= "\xF0\x9F\x92\xB0 *Valor:* R$ " . number_format($agendamento['valor'], 2, ',', '.') . "\n\n";
        $mensagem .= "Seu agendamento esta ativo novamente.\n";
        $mensagem .= "Obrigado! \xF0\x9F\x99\x8F";
        
        // Enviar mensagem
        return $whatsapp->enviarMensagem($agendamento['cliente_telefone'], $mensagem);
        
    } catch (Exception $e) {
        return [
            "success" => false,
            "message" => "Erro ao enviar mensagem de reabertura: " . $e->getMessage()
        ];
    }
}

/**
 * Enviar mensagem de finaliza&#65533;&#65533;o de agendamento
 * 
 * @param array $agendamento - Array com dados do agendamento
 * @param string $nomeServico - Nome do servi&#65533;o
 * @param string $nomeBarbeiro - Nome do barbeiro
 * @return array - Resultado do envio
 */
function enviarFinalizacaoAgendamento($agendamento, $nomeServico, $nomeBarbeiro, $codigoBarbeiro = null) {
    try {
        $whatsapp = new WhatsAppService($codigoBarbeiro);
        
        // Formatar data e hora para exibi&#65533;&#65533;o
        $dataAgendamento = new DateTime($agendamento['data_agendamento']);
        $dataFormatada = $dataAgendamento->format('d/m/Y');
        $horaFormatada = substr($agendamento['horario'], 0, 5); // HH:MM
        
        // Montar mensagem de conclus&#65533;o do servi&#65533;o
        $mensagem = "\xE2\x9C\x85 *SERVICO FINALIZADO*\n\n";
        $mensagem .= "Ola *{$agendamento['cliente_nome']}*!\n\n";
        $mensagem .= "Seu atendimento foi concluido com sucesso! \xF0\x9F\x92\x88\n\n";
        $mensagem .= "*Resumo do atendimento:*\n";
        $mensagem .= "\xF0\x9F\x93\x85 *Data:* {$dataFormatada}\n";
        $mensagem .= "\xF0\x9F\x95\x90 *Horario:* {$horaFormatada}\n";
        $mensagem .= "\xF0\x9F\x92\x88 *Barbeiro:* {$nomeBarbeiro}\n";
        $mensagem .= "\xE2\x9C\x82 *Servico:* {$nomeServico}\n";
        $mensagem .= "\xF0\x9F\x92\xB0 *Valor:* R$ " . number_format($agendamento['valor'], 2, ',', '.') . "\n\n";
        $mensagem .= "Obrigado por confiar em nosso trabalho! \xF0\x9F\x98\x8A\n";
        $mensagem .= "Volte sempre! \xF0\x9F\x99\x8F";
        
        // Enviar mensagem
        return $whatsapp->enviarMensagem($agendamento['cliente_telefone'], $mensagem);
        
    } catch (Exception $e) {
        return [
            "success" => false,
            "message" => "Erro ao enviar mensagem de finalizacao: " . $e->getMessage()
        ];
    }
}

/**
 * Enviar mensagem de confirma&#65533;&#65533;o de agendamento para o barbeiro
 * 
 * @param array $agendamento - Array com dados do agendamento
 * @param string $nomeServico - Nome do servi&#65533;o agendado
 * @param string $nomeBarbeiro - Nome do barbeiro
 * @param string $telefoneBarbeiro - Telefone do barbeiro
 * @return array - Resultado do envio
 */
function enviarConfirmacaoAgendamentoBarbeiro($agendamento, $nomeServico, $nomeBarbeiro, $telefoneBarbeiro) {
    try {
        $whatsapp = new WhatsAppService($agendamento['codigo_barbeiro'] ?? null);
        
        // Formatar data e hora para exibi&#65533;&#65533;o
        $dataAgendamento = new DateTime($agendamento['data_agendamento']);
        $dataFormatada = $dataAgendamento->format('d/m/Y');
        $horaFormatada = substr($agendamento['horario'], 0, 5); // HH:MM
        
        // Montar mensagem para o barbeiro
        $mensagem = "\xF0\x9F\x93\x8B *NOVO AGENDAMENTO*\n\n";
        $mensagem .= "Ola *{$nomeBarbeiro}*!\n\n";
        $mensagem .= "Voce tem um novo agendamento! \xF0\x9F\x93\x85\n\n";
        $mensagem .= "\xF0\x9F\x91\xA4 *Cliente:* {$agendamento['cliente_nome']}\n";
        $mensagem .= "\xF0\x9F\x93\x85 *Data:* {$dataFormatada}\n";
        $mensagem .= "\xF0\x9F\x95\x90 *Horario:* {$horaFormatada}\n";
        $mensagem .= "\xE2\x9C\x82 *Servico:* {$nomeServico}\n";
        $mensagem .= "\xF0\x9F\x92\xB0 *Valor:* R$ " . number_format($agendamento['valor'], 2, ',', '.') . "\n";
        
        // Adicionar telefone do cliente se dispon&#65533;vel
        if (!empty($agendamento['cliente_telefone'])) {
            $mensagem .= "\xF0\x9F\x93\x9E *Telefone do cliente:* {$agendamento['cliente_telefone']}\n";
        }
        
        // Adicionar observa&#65533;&#65533;es se existirem
        if (!empty($agendamento['observacoes'])) {
            $mensagem .= "\n\xF0\x9F\x93\x9D *Observacoes:* {$agendamento['observacoes']}\n";
        }
        
        $mensagem .= "\nSe prepare para atender o cliente! \xF0\x9F\x92\x88\xF0\x9F\x91\x8D";
        
        // Enviar mensagem
        return $whatsapp->enviarMensagem($telefoneBarbeiro, $mensagem);
        
    } catch (Exception $e) {
        return [
            "success" => false,
            "message" => "Erro ao enviar mensagem de confirmacao para barbeiro: " . $e->getMessage()
        ];
    }
}

/**
 * Notificar barbeiro sobre cancelamento feito pelo cliente
 * 
 * @param string $telefoneBarbeiro - Telefone do barbeiro
 * @param array $agendamento - Array com dados do agendamento
 * @param string $nomeServico - Nome do servi�o
 * @param string $nomeBarbeiro - Nome do barbeiro
 * @return array - Resultado do envio
 */
function notificarBarbeiroCancelamentoCliente($telefoneBarbeiro, $agendamento, $nomeServico, $nomeBarbeiro, $codigoBarbeiro = null) {
    try {
        // Log de entrada
        error_log("=== notificarBarbeiroCancelamentoCliente INICIADA ===");
        error_log("Telefone: " . $telefoneBarbeiro);
        error_log("Nome Barbeiro: " . $nomeBarbeiro);
        error_log("Servi�o: " . $nomeServico);
        error_log("Agendamento: " . json_encode($agendamento));
        
        $whatsapp = new WhatsAppService($codigoBarbeiro);
        
        // Formatar data e hora para exibi��o
        $dataAgendamento = new DateTime($agendamento['data_agendamento']);
        $dataFormatada = $dataAgendamento->format('d/m/Y');
        $horaFormatada = substr($agendamento['horario'], 0, 5); // HH:MM
        
        // Montar mensagem para o barbeiro
        $mensagem = "\xE2\x9A\xA0\xEF\xB8\x8F *AGENDAMENTO CANCELADO PELO CLIENTE*\n\n";
        $mensagem .= "Ola *{$nomeBarbeiro}*!\n\n";
        $mensagem .= "O cliente cancelou o seguinte agendamento:\n\n";
        $mensagem .= "\xF0\x9F\x91\xA4 *Cliente:* {$agendamento['cliente_nome']}\n";
        $mensagem .= "\xF0\x9F\x93\x9E *Telefone:* {$agendamento['cliente_telefone']}\n";
        $mensagem .= "\xF0\x9F\x93\x85 *Data:* {$dataFormatada}\n";
        $mensagem .= "\xF0\x9F\x95\x90 *Horario:* {$horaFormatada}\n";
        $mensagem .= "\xE2\x9C\x82 *Servico:* {$nomeServico}\n";
        $mensagem .= "\xF0\x9F\x92\xB0 *Valor:* R$ " . number_format($agendamento['valor'], 2, ',', '.') . "\n\n";
        $mensagem .= "O horario agora esta disponivel para outros agendamentos. \xF0\x9F\x93\x85";
        
        error_log("Mensagem montada: " . $mensagem);
        
        // Enviar mensagem
        $resultado = $whatsapp->enviarMensagem($telefoneBarbeiro, $mensagem);
        
        error_log("Resultado envio: " . json_encode($resultado));
        
        return $resultado;
        
    } catch (Exception $e) {
        error_log("EXCE��O em notificarBarbeiroCancelamentoCliente: " . $e->getMessage());
        return [
            "success" => false,
            "message" => "Erro ao notificar barbeiro sobre cancelamento: " . $e->getMessage()
        ];
    }
}

/**
 * Notificar cliente que seu horário foi alterado por conflito com um plano
 *
 * @param string $telefone          - Telefone do cliente afetado
 * @param string $clienteNome       - Nome do cliente afetado
 * @param string $data              - Data do agendamento (YYYY-MM-DD)
 * @param string $horarioAntigo     - Horário original (HH:MM)
 * @param string $horarioNovo       - Novo horário (HH:MM)
 * @param string $nomeServico       - Nome do serviço
 * @param string $nomeBarbeiro      - Nome do barbeiro
 * @param string $linkAgendamentos  - URL da página de agendamentos do cliente
 * @return array
 */
function enviarNotificacaoHorarioAlterado($telefone, $clienteNome, $data, $horarioAntigo, $horarioNovo, $nomeServico, $nomeBarbeiro, $linkAgendamentos, $codigoBarbeiro = null) {
    try {
        $whatsapp = new WhatsAppService($codigoBarbeiro);

        $dataFmt    = (new DateTime($data))->format('d/m/Y');
        $antFmt     = substr($horarioAntigo, 0, 5);
        $novoFmt    = substr($horarioNovo,   0, 5);

        $mensagem  = "\xE2\x9A\xA0\xEF\xB8\x8F *ALTERAÇÃO DE HORÁRIO*\n\n";
        $mensagem .= "Olá *{$clienteNome}*!\n\n";
        $mensagem .= "Seu agendamento do dia *{$dataFmt}* precisou ser ajustado.\n\n";
        $mensagem .= "\xE2\x9C\x82 *Serviço:* {$nomeServico}\n";
        $mensagem .= "\xF0\x9F\x92\x88 *Barbeiro:* {$nomeBarbeiro}\n";
        $mensagem .= "\xF0\x9F\x95\x90 *Horário anterior:* {$antFmt}\n";
        $mensagem .= "\xF0\x9F\x95\x91 *Novo horário:* *{$novoFmt}*\n\n";
        $mensagem .= "Caso queira cancelar, acesse seus agendamentos:\n";
        $mensagem .= $linkAgendamentos . "\n\n";
        $mensagem .= "Qualquer dúvida, entre em contato com *{$nomeBarbeiro}*. \xF0\x9F\x99\x8F";

        return $whatsapp->enviarMensagem($telefone, $mensagem);
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Erro ao enviar notificação de horário alterado: ' . $e->getMessage()];
    }
}

/**
 * Notificar cliente sobre novos termos propostos pelo barbeiro para o plano
 *
 * @param string $telefone       - Telefone do cliente
 * @param string $clienteNome    - Nome do cliente
 * @param string $nomeServicos   - Serviços do plano (texto resumido)
 * @param string $diaHorario     - Dia/horário ajustado (ex: "Sexta às 10:00")
 * @param float  $valorFinal     - Valor final proposto pelo barbeiro
 * @param string $nomeBarbeiro   - Nome do barbeiro
 * @param string $linkConfirmacao - URL para o cliente aceitar/recusar
 * @return array
 */
function enviarNotificacaoTermosPlano($telefone, $clienteNome, $nomeServicos, $diaHorario, $valorFinal, $nomeBarbeiro, $linkConfirmacao, $codigoBarbeiro = null) {
    try {
        $whatsapp = new WhatsAppService($codigoBarbeiro);

        $valorFinalFmt = 'R$ ' . number_format($valorFinal,   2, ',', '.');

        $mensagem  = "\xF0\x9F\x93\x8B *PROPOSTA DE PLANO — CONFIRMAÇÃO NECESSÁRIA*\n\n";
        $mensagem .= "Olá *{$clienteNome}*!\n\n";
        $mensagem .= "O barbeiro *{$nomeBarbeiro}* revisou sua solicitação de plano e apresentou novos termos.\n\n";
        $mensagem .= "\xE2\x9C\x82 *Serviços:* {$nomeServicos}\n";
        $mensagem .= "\xF0\x9F\x93\x85 *Horário:* {$diaHorario}\n";
        $mensagem .= "\xF0\x9F\x92\xB0 *Valor do plano:* *{$valorFinalFmt}*\n\n";
        $mensagem .= "\xF0\x9F\x91\x87 *Acesse o link abaixo para aceitar ou recusar:*\n";
        $mensagem .= $linkConfirmacao . "\n\n";
        $mensagem .= "Esta proposta ficará disponível aguardando sua resposta. \xF0\x9F\x99\x8F";

        return $whatsapp->enviarMensagem($telefone, $mensagem);
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Erro ao enviar notificação de termos: ' . $e->getMessage()];
    }
}

/**
 * Enviar mensagem de alteração de horário do agendamento
 *
 * @param array  $agendamento  - Array com dados do agendamento (cliente_nome, cliente_telefone, data_agendamento, valor)
 * @param string $horarioAntigo - Horário original no formato HH:MM
 * @param string $horarioNovo   - Novo horário no formato HH:MM
 * @param string $nomeServico   - Nome do serviço
 * @param string $nomeBarbeiro  - Nome do barbeiro
 * @return array - Resultado do envio
 */
function enviarAlteracaoHorarioAgendamento($agendamento, $horarioAntigo, $horarioNovo, $nomeServico, $nomeBarbeiro, $codigoBarbeiro = null) {
    try {
        $whatsapp = new WhatsAppService($codigoBarbeiro);

        $dataAgendamento = new DateTime($agendamento['data_agendamento']);
        $dataFormatada   = $dataAgendamento->format('d/m/Y');

        $mensagem  = "\xF0\x9F\x94\x94 *HORÁRIO ALTERADO*\n\n";
        $mensagem .= "Olá *{$agendamento['cliente_nome']}*!\n\n";
        $mensagem .= "O horário do seu agendamento foi alterado. \xF0\x9F\x93\x85\n\n";
        $mensagem .= "\xF0\x9F\x93\x85 *Data:* {$dataFormatada}\n";
        $mensagem .= "\xE2\x8F\xB0 *Horário anterior:* {$horarioAntigo}\n";
        $mensagem .= "\xE2\x9C\x85 *Novo horário:* *{$horarioNovo}*\n";
        $mensagem .= "\xF0\x9F\x92\x88 *Barbeiro:* {$nomeBarbeiro}\n";
        $mensagem .= "\xE2\x9C\x82 *Serviço:* {$nomeServico}\n\n";
        $mensagem .= "Anote o novo horário e chegue alguns minutos antes. \xF0\x9F\x99\x8F";

        return $whatsapp->enviarMensagem($agendamento['cliente_telefone'], $mensagem);
    } catch (Exception $e) {
        return [
            'success'  => false,
            'message'  => 'Erro ao enviar mensagem de alteração de horário: ' . $e->getMessage()
        ];
    }
}
?>
