<?php
/**
 * Criar solicitação de plano pelo cliente
 * Sistema: MustaBarber
 * Endpoint público — não requer login de barbeiro
 * POST JSON
 */

ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

require_once '../conexao.php';
require_once '../whatsapp/whatsappservice.php';

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

    // Campos obrigatórios
    $codigoBarbeiro    = isset($input['codigo_barbeiro'])    ? intval($input['codigo_barbeiro'])    : 0;
    $clienteNome       = isset($input['cliente_nome'])       ? trim($input['cliente_nome'])         : '';
    $clienteTelefone   = isset($input['cliente_telefone'])   ? trim($input['cliente_telefone'])     : '';
    $servicos          = isset($input['servicos'])           ? $input['servicos']                   : [];
    $diaSemana         = isset($input['dia_semana'])          ? trim($input['dia_semana'])           : '';
    $horarioPreferido  = isset($input['horario_preferido'])  ? trim($input['horario_preferido'])    : '';
    $diaSemana2        = isset($input['dia_semana_2'])        ? trim($input['dia_semana_2'])         : null;
    $horarioPref2      = isset($input['horario_preferido_2'])? trim($input['horario_preferido_2'])   : null;
    $periodicidade     = isset($input['periodicidade'])      ? trim($input['periodicidade'])        : 'mensal';
    $quantSessoes      = isset($input['quantidade_sessoes']) ? intval($input['quantidade_sessoes']) : 1;
    $valorTotal        = isset($input['valor_total_original'])? floatval($input['valor_total_original']): 0;
    $observacoes       = isset($input['observacoes_cliente'])? trim($input['observacoes_cliente'])  : null;
    $tipoSolicitacao   = isset($input['tipo_solicitacao'])   ? trim((string)$input['tipo_solicitacao']) : 'nova';
    $codigoOrigemRenov = isset($input['codigo_solicitacao_origem']) ? intval($input['codigo_solicitacao_origem']) : 0;
    $valorPlanoAtual   = isset($input['valor_plano_atual']) ? floatval($input['valor_plano_atual']) : 0;

    // Validações básicas
    if ($codigoBarbeiro <= 0) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Barbeiro não informado'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (empty($clienteNome) || mb_strlen($clienteNome) > 150) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Nome do cliente inválido'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (empty($clienteTelefone)) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Telefone obrigatório'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (empty($servicos) || !is_array($servicos)) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Selecione ao menos um serviço'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (empty($diaSemana) || empty($horarioPreferido)) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Dia da semana e horário são obrigatórios'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // Validar dia da semana
    $diasValidos = ['segunda', 'terca', 'quarta', 'quinta', 'sexta', 'sabado', 'domingo'];
    if (!in_array($diaSemana, $diasValidos)) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Dia da semana inválido'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!preg_match('/^\d{2}:\d{2}$/', $horarioPreferido)) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Formato de horário inválido'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // Validar dia_semana_2 se fornecido
    if (!empty($diaSemana2) && !in_array($diaSemana2, $diasValidos)) {
        $diaSemana2 = null; $horarioPref2 = null;
    }
    if (!empty($horarioPref2) && !preg_match('/^\d{2}:\d{2}$/', $horarioPref2)) {
        $horarioPref2 = null;
    }
    // Segundo dia só é válido se ambos (dia2 e horário2) foram informados
    if (empty($diaSemana2) || empty($horarioPref2)) {
        $diaSemana2 = null; $horarioPref2 = null;
    }
    // Segundo dia não pode ser igual ao primeiro
    if ($diaSemana2 === $diaSemana) {
        $diaSemana2 = null; $horarioPref2 = null;
    }

    if ($quantSessoes < 1 || $quantSessoes > 52) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Quantidade de sessões inválida (1-52)'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Verificar se o barbeiro existe e aceita solicitações
    $stmtCheck = $conexao->prepare(
        "SELECT COALESCE(permite_solicitacao_plano, FALSE) AS aceita,
                nome,
                telefone
         FROM usuarios WHERE codigo = :cod AND status = 1 LIMIT 1"
    );
    $stmtCheck->bindParam(':cod', $codigoBarbeiro, PDO::PARAM_INT);
    $stmtCheck->execute();
    $barb = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if (!$barb) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Barbeiro não encontrado'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $aceita = ($barb['aceita'] === true || $barb['aceita'] === 't' || $barb['aceita'] == 1);
    if (!$aceita) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Este barbeiro não aceita solicitações de plano no momento'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Sanitizar array de serviços (apenas campos esperados)
    $servicosSanitizados = [];
    foreach ($servicos as $s) {
        $servicosSanitizados[] = [
            'codigo'     => intval($s['codigo'] ?? 0),
            'nome'       => mb_substr(trim($s['nome'] ?? ''), 0, 150),
            'valor'      => floatval($s['valor'] ?? 0),
            'quantidade' => intval($s['quantidade'] ?? 1),
        ];
    }

    $servicosJson = json_encode($servicosSanitizados, JSON_UNESCAPED_UNICODE);

    $periodicidadesValidas = ['semanal', 'quinzenal', 'mensal', 'bimestral', 'trimestral'];
    if (!in_array($periodicidade, $periodicidadesValidas)) {
        $periodicidade = 'mensal';
    }

    $ehRenovacao = ($tipoSolicitacao === 'renovacao');
    if ($ehRenovacao) {
        $valorMeta = $valorPlanoAtual > 0 ? $valorPlanoAtual : $valorTotal;
        $marcadorRenovacao = '[RENOVACAO|origem=' . max(0, $codigoOrigemRenov) . '|valor_atual=' . number_format($valorMeta, 2, '.', '') . ']';
        $observacoes = trim((string)$observacoes);
        $observacoes = $observacoes !== '' ? ($observacoes . "\n" . $marcadorRenovacao) : $marcadorRenovacao;
    }

    $sql = "INSERT INTO solicitacoes_plano_cliente
                (codigo_barbeiro, cliente_nome, cliente_telefone, servicos,
                 dia_semana, horario_preferido, dia_semana_2, horario_preferido_2,
                 periodicidade, quantidade_sessoes,
                 valor_total_original, observacoes_cliente, status)
            VALUES
                (:barbeiro, :nome, :telefone, :servicos::jsonb,
                 :dia_semana, :horario, :dia2, :horario2,
                 :periodicidade, :qtd,
                 :valor, :obs, 'pendente')
            RETURNING codigo";

    $stmt = $conexao->prepare($sql);
    $stmt->bindParam(':barbeiro',     $codigoBarbeiro, PDO::PARAM_INT);
    $stmt->bindParam(':nome',         $clienteNome);
    $stmt->bindParam(':telefone',     $clienteTelefone);
    $stmt->bindParam(':servicos',     $servicosJson);
    $stmt->bindParam(':dia_semana',    $diaSemana);
    $stmt->bindParam(':horario',      $horarioPreferido);
    $stmt->bindParam(':dia2',          $diaSemana2);
    $stmt->bindParam(':horario2',      $horarioPref2);
    $stmt->bindParam(':periodicidade',$periodicidade);
    $stmt->bindParam(':qtd',          $quantSessoes, PDO::PARAM_INT);
    $stmt->bindParam(':valor',        $valorTotal);
    $stmt->bindParam(':obs',          $observacoes);
    $stmt->execute();

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $codigoSolicitacao = intval($row['codigo']);

    // Notificar barbeiro sobre nova solicitação (sempre via número padrão MustaBarber).
    try {
        $nomeBarbeiro = trim((string)($barb['nome'] ?? 'Barbeiro'));
        $telBarbeiro = trim((string)($barb['telefone'] ?? ''));
        if ($telBarbeiro !== '') {
            $whatsSistema = new WhatsAppService();
            $qtdSessoesTxt = $quantSessoes === 1 ? '1 sessão' : ($quantSessoes . ' sessões');
            $mensagemBarbeiro  = $ehRenovacao
                ? "🔄 *NOVA SOLICITAÇÃO DE RENOVAÇÃO*\n\n"
                : "🔔 *NOVA SOLICITAÇÃO DE PLANO*\n\n";
            $mensagemBarbeiro .= "Olá *{$nomeBarbeiro}*!\n\n";
            $mensagemBarbeiro .= "Cliente: *{$clienteNome}*\n";
            $mensagemBarbeiro .= "Telefone: {$clienteTelefone}\n";
            $mensagemBarbeiro .= "Frequência: " . ucfirst($periodicidade) . "\n";
            $mensagemBarbeiro .= "Sessões: {$qtdSessoesTxt}\n";
            if ($ehRenovacao) {
                $valorRef = $valorPlanoAtual > 0 ? $valorPlanoAtual : $valorTotal;
                $mensagemBarbeiro .= "Valor atual do plano: R$ " . number_format($valorRef, 2, ',', '.') . "\n\n";
            } else {
                $mensagemBarbeiro .= "Valor sugerido: R$ " . number_format($valorTotal, 2, ',', '.') . "\n\n";
            }
            $mensagemBarbeiro .= "Abra a tela de solicitações para responder.\n";
            $mensagemBarbeiro .= "Código da solicitação: #{$codigoSolicitacao}";

            $whatsSistema->enviarMensagem($telBarbeiro, $mensagemBarbeiro);
        }
    } catch (Throwable $eNotif) {
        error_log('[criar_solicitacao_plano][notificacao_barbeiro] ' . $eNotif->getMessage());
    }

    echo json_encode([
        'sucesso'  => true,
        'mensagem' => 'Solicitação enviada com sucesso! O barbeiro irá analisar e retornar em breve.',
        'codigo'   => $codigoSolicitacao
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('[criar_solicitacao_plano] ' . $e->getMessage());
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro interno ao criar solicitação'], JSON_UNESCAPED_UNICODE);
}
?>
