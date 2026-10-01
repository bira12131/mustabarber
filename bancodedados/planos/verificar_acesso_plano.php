<?php
/**
 * API: Verificar Acesso ao Recurso por Plano
 * Sistema: MustaBarber
 * Data: 25/04/2026
 *
 * Endpoint para o frontend consultar se o usuário logado tem acesso
 * a determinado recurso conforme seu plano ativo.
 *
 * Método: GET
 * Parâmetro: recurso (string) – ex: financeiro, produtos, whatsapp, funcionarios
 *
 * Resposta JSON:
 *   sucesso           (bool)
 *   tem_acesso        (bool)
 *   plano_atual       (int)
 *   plano_atual_nome  (string)
 *   plano_minimo      (int)
 *   plano_minimo_nome (string)
 *   max_barbeiros     (int)
 *   recurso           (string)
 */

header('Content-Type: application/json; charset=utf-8');

require_once '../conexao.php';
require_once '../usuarios/sessao.php';
require_once 'plano_manager.php';

try {
    if (!estaLogado()) {
        http_response_code(401);
        echo json_encode([
            'sucesso'  => false,
            'mensagem' => 'Usuário não autenticado.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $usuario       = obterUsuarioLogado();
    $codigoUsuario = (int) $usuario['codigo'];
    $perfilUsuario = (int) $usuario['perfil'];

    $recurso = trim($_GET['recurso'] ?? $_POST['recurso'] ?? '');

    if (empty($recurso)) {
        echo json_encode([
            'sucesso'  => false,
            'mensagem' => 'Parâmetro "recurso" não informado.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Administradores têm acesso irrestrito
    if ($perfilUsuario === 1) {
        echo json_encode([
            'sucesso'           => true,
            'tem_acesso'        => true,
            'plano_atual'       => 1,
            'plano_atual_nome'  => 'Administrador',
            'plano_minimo'      => getPlanoMinimoRecurso($recurso),
            'plano_minimo_nome' => getNomePlano(getPlanoMinimoRecurso($recurso)),
            'max_barbeiros'     => 999,
            'recurso'           => $recurso,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $planoAtivo  = obterPlanoAtivo($conexao, $codigoUsuario);
    $planoMinimo = getPlanoMinimoRecurso($recurso);
    $temAcesso   = $planoAtivo >= $planoMinimo;

    echo json_encode([
        'sucesso'           => true,
        'tem_acesso'        => $temAcesso,
        'plano_atual'       => $planoAtivo,
        'plano_atual_nome'  => getNomePlano($planoAtivo),
        'plano_minimo'      => $planoMinimo,
        'plano_minimo_nome' => getNomePlano($planoMinimo),
        'max_barbeiros'     => getMaxBarbeirosPorPlano($planoAtivo),
        'recurso'           => $recurso,
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'sucesso'  => false,
        'mensagem' => 'Erro no banco de dados.',
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'sucesso'  => false,
        'mensagem' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
