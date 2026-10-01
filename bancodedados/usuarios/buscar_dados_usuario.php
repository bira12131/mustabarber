<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
error_reporting(0);

require_once 'sessao.php';
require_once '../conexao.php';

try {
    if (!estaLogado()) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Usu&aacute;rio n&atilde;o autenticado'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $usuario = obterUsuarioLogado();
    $codigoUsuario = intval($usuario['codigo']);

    $stmt = $conexao->prepare(
        "SELECT codigo, nome, email, telefone, cpf, foto_caminho, perfil, data_cadastro, finalizacao_automatica,
                dia_vencimento,
                COALESCE(permite_solicitacao_plano, FALSE) AS permite_solicitacao_plano,
                endereco_cep,
                endereco_logradouro,
                endereco_numero,
                endereco_complemento,
                endereco_bairro,
                endereco_cidade,
                endereco_estado,
                COALESCE(pagamento_auto_cartao, FALSE) AS pagamento_auto_cartao,
                cartao_bandeira,
                cartao_final,
                cartao_titular,
                CASE WHEN asaas_cartao_token IS NOT NULL AND asaas_cartao_token <> '' THEN TRUE ELSE FALSE END AS possui_cartao_token
         FROM usuarios
         WHERE codigo = :codigo
         LIMIT 1"
    );
    $stmt->bindParam(':codigo', $codigoUsuario, PDO::PARAM_INT);
    $stmt->execute();
    $dados = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$dados) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Usu&aacute;rio n&atilde;o encontrado'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $camposEnderecoObrigatorios = [
        trim((string)($dados['endereco_cep'] ?? '')),
        trim((string)($dados['endereco_logradouro'] ?? '')),
        trim((string)($dados['endereco_numero'] ?? '')),
        trim((string)($dados['endereco_bairro'] ?? '')),
        trim((string)($dados['endereco_cidade'] ?? '')),
        trim((string)($dados['endereco_estado'] ?? '')),
    ];
    $enderecoIncompleto = false;
    foreach ($camposEnderecoObrigatorios as $campoEndereco) {
        if ($campoEndereco === '') {
            $enderecoIncompleto = true;
            break;
        }
    }

    if ($enderecoIncompleto && (int)($dados['perfil'] ?? 0) !== 1) {
        $tituloNotificacao = 'Endereco fiscal pendente';
        $mensagemNotificacao = 'Seu endereco fiscal esta incompleto. Atualize em Configuracoes para evitar falhas na emissao de notas e cobrancas.';

        $stmtExisteNotif = $conexao->prepare(
            "SELECT 1
             FROM notificacoes_sistema
             WHERE codigo_usuario = :codigo
               AND titulo = :titulo
               AND mensagem = :mensagem
             LIMIT 1"
        );
        $stmtExisteNotif->bindParam(':codigo', $codigoUsuario, PDO::PARAM_INT);
        $stmtExisteNotif->bindParam(':titulo', $tituloNotificacao);
        $stmtExisteNotif->bindParam(':mensagem', $mensagemNotificacao);
        $stmtExisteNotif->execute();

        if (!$stmtExisteNotif->fetchColumn()) {
            $stmtInsNotif = $conexao->prepare(
                "INSERT INTO notificacoes_sistema (codigo_usuario, tipo, titulo, mensagem)
                 VALUES (:codigo, 'aviso', :titulo, :mensagem)"
            );
            $stmtInsNotif->bindParam(':codigo', $codigoUsuario, PDO::PARAM_INT);
            $stmtInsNotif->bindParam(':titulo', $tituloNotificacao);
            $stmtInsNotif->bindParam(':mensagem', $mensagemNotificacao);
            $stmtInsNotif->execute();
        }
    }

    // Buscar assinatura ativa
    $stmtAssin = $conexao->prepare(
        "SELECT status_pagamento, data_vencimento, valor_mensalidade, codigo_perfil, data_pagamento, observacoes
         FROM assinaturas
         WHERE codigo_barbeiro = :codigo
         ORDER BY codigo DESC LIMIT 1"
    );
    $stmtAssin->bindParam(':codigo', $codigoUsuario, PDO::PARAM_INT);
    $stmtAssin->execute();
    $assinatura = $stmtAssin->fetch(PDO::FETCH_ASSOC);

    // Derivar o plano a partir do perfil do usuario (fonte de verdade)
    $perfilParaPlano = [
        2 => 'starter',
        3 => 'solo-pro',
        4 => 'equipe',
        6 => 'estabelecimento',
        7 => 'master',
    ];

    // Adicionar o plano do usuario na assinatura para exibicao
    if ($assinatura) {
        $assinatura['plano'] = $perfilParaPlano[(int)($dados['perfil'] ?? 2)] ?? 'starter';
    }

    // Remover senha do retorno por seguran&ccedil;a
    unset($dados['senha']);

    echo json_encode([
        'sucesso' => true,
        'usuario' => $dados,
        'assinatura' => $assinatura ?: null
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao buscar dados: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
