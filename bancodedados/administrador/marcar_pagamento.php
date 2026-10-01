<?php
/**
 * Marcar Pagamento
 * Sistema: MustaBarber - Painel Administrativo
 * Data: 30/01/2026
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

// Incluir arquivo de conexão
require_once '../conexao.php';
require_once '../usuarios/sessao.php';

try {
    // Verificar se usuário está logado
    if (!estaLogado()) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Usuário não autenticado'
        ]);
        exit;
    }

    // Verificar se é administrador (perfil 1)
    $usuarioLogado = obterUsuarioLogado();
    if ($usuarioLogado['perfil'] != 1) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Acesso negado. Apenas administradores podem acessar esta funcionalidade.'
        ]);
        exit;
    }

    // Receber dados
    $dados = json_decode(file_get_contents('php://input'), true);

    $codigo_barbeiro = $dados['codigo_barbeiro'] ?? null;
    $forma_pagamento = $dados['forma_pagamento'] ?? null;

    // Validações
    if (!$codigo_barbeiro) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Código do barbeiro é obrigatório'
        ]);
        exit;
    }

    // Verificar se assinatura existe
    $sqlVerifica = "SELECT codigo, data_vencimento FROM assinaturas WHERE codigo_barbeiro = :codigo_barbeiro";
    $stmtVerifica = $conexao->prepare($sqlVerifica);
    $stmtVerifica->bindParam(':codigo_barbeiro', $codigo_barbeiro, PDO::PARAM_INT);
    $stmtVerifica->execute();
    $assinatura = $stmtVerifica->fetch(PDO::FETCH_ASSOC);

    if (!$assinatura) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Assinatura não encontrada para este barbeiro'
        ]);
        exit;
    }

    // Calcular novo vencimento com base no dia preferido do barbeiro
    $sqlDia = "SELECT COALESCE(dia_vencimento, 1) AS dia_vencimento FROM usuarios WHERE codigo = :cod LIMIT 1";
    $stmtDia = $conexao->prepare($sqlDia);
    $stmtDia->bindParam(':cod', $codigo_barbeiro, PDO::PARAM_INT);
    $stmtDia->execute();
    $diaRow = $stmtDia->fetch(PDO::FETCH_ASSOC);
    $diaVenc = (int) ($diaRow['dia_vencimento'] ?? 1);
    if ($diaVenc < 1 || $diaVenc > 28) $diaVenc = 1;

    // Data base: vencimento atual se ainda não venceu, senão hoje
    $vencimentoAtual = strtotime($assinatura['data_vencimento']);
    $hoje = strtotime(date('Y-m-d'));
    $baseTimestamp = ($vencimentoAtual >= $hoje) ? $vencimentoAtual : $hoje;

    // Avança um mês a partir da data base e aplica o dia preferido
    $mesBase = (int) date('m', $baseTimestamp);
    $anoBase = (int) date('Y', $baseTimestamp);
    $mesProx = $mesBase + 1;
    $anoProx = $anoBase;
    if ($mesProx > 12) { $mesProx = 1; $anoProx++; }

    // Garante que o dia não exceda os dias disponíveis no mês destino
    $diasNoMes = cal_days_in_month(CAL_GREGORIAN, $mesProx, $anoProx);
    $diaFinal = min($diaVenc, $diasNoMes);
    $novoVencimento = sprintf('%04d-%02d-%02d', $anoProx, $mesProx, $diaFinal);

    // Atualizar assinatura
    $sql = "UPDATE assinaturas SET 
                status_pagamento = 2,
                data_pagamento = CURRENT_DATE,
                data_vencimento = :novo_vencimento,
                forma_pagamento = :forma_pagamento
            WHERE codigo_barbeiro = :codigo_barbeiro";
    
    $stmt = $conexao->prepare($sql);
    $stmt->bindParam(':novo_vencimento', $novoVencimento);
    $stmt->bindParam(':forma_pagamento', $forma_pagamento);
    $stmt->bindParam(':codigo_barbeiro', $codigo_barbeiro, PDO::PARAM_INT);
    
    $stmt->execute();

    // --- Lógica de indicações: verificar conversão e isenção ---
    // Verifica se este barbeiro foi indicado por alguém e ainda não está marcado como convertido
    $sqlIndicacao = "SELECT codigo, codigo_indicador
                     FROM indicacoes
                     WHERE codigo_indicado = :codigo_barbeiro
                       AND convertido = FALSE
                     LIMIT 1";
    $stmtInd = $conexao->prepare($sqlIndicacao);
    $stmtInd->bindParam(':codigo_barbeiro', $codigo_barbeiro, PDO::PARAM_INT);
    $stmtInd->execute();
    $indicacao = $stmtInd->fetch(PDO::FETCH_ASSOC);

    if ($indicacao) {
        // Marca a indicação como convertida
        $sqlConv = "UPDATE indicacoes
                    SET convertido = TRUE, data_conversao = CURRENT_TIMESTAMP
                    WHERE codigo = :codigo_indicacao";
        $stmtConv = $conexao->prepare($sqlConv);
        $stmtConv->bindParam(':codigo_indicacao', $indicacao['codigo'], PDO::PARAM_INT);
        $stmtConv->execute();

        // Conta quantas indicações convertidas o indicador possui
        $codigoIndicador = (int) $indicacao['codigo_indicador'];
        $sqlCount = "SELECT COUNT(*) AS total_conversoes
                     FROM indicacoes
                     WHERE codigo_indicador = :codigo_indicador
                       AND convertido = TRUE";
        $stmtCount = $conexao->prepare($sqlCount);
        $stmtCount->bindParam(':codigo_indicador', $codigoIndicador, PDO::PARAM_INT);
        $stmtCount->execute();
        $contagem = $stmtCount->fetch(PDO::FETCH_ASSOC);

        // Se atingiu 5 conversões, concede isenção de pagamento ao indicador
        if ((int) $contagem['total_conversoes'] >= 5) {
            $sqlIsencao = "UPDATE usuarios
                           SET isento_pagamento = TRUE
                           WHERE codigo = :codigo_indicador
                             AND isento_pagamento = FALSE";
            $stmtIsencao = $conexao->prepare($sqlIsencao);
            $stmtIsencao->bindParam(':codigo_indicador', $codigoIndicador, PDO::PARAM_INT);
            $stmtIsencao->execute();
        }
    }
    // --- Fim lógica de indicações ---

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Pagamento registrado com sucesso. Novo vencimento: ' . date('d/m/Y', strtotime($novoVencimento)),
        'novo_vencimento' => $novoVencimento
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro no banco de dados: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
