<?php
/**
 * Listar Todos os Usu�rios
 * Sistema: MustaBarber - Painel Administrativo
 * Data: 21/12/2025
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

try {
    // Incluir arquivo de conexão
    require_once '../conexao.php';
    require_once '../usuarios/sessao.php';
    // Verificar se usu�rio est� logado
    if (!estaLogado()) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Usu�rio n�o autenticado',
            'usuarios' => []
        ]);
        exit;
    }

    // Verificar se � administrador (perfil 1)
    $usuarioLogado = obterUsuarioLogado();
    if ($usuarioLogado['perfil'] != 1) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Acesso negado. Apenas administradores podem acessar esta funcionalidade.',
            'usuarios' => []
        ]);
        exit;
    }

    // Verificar quais colunas opcionais (adicionadas por migrations) existem na tabela
    $stmtColunas = $conexao->query("
        SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name   = 'usuarios'
          AND column_name IN ('ultimo_acesso','isento_pagamento','dia_vencimento','embaixador','referral_slug')
    ");
    $colunasExistentes = $stmtColunas->fetchAll(PDO::FETCH_COLUMN);

    $sUltimoAcesso = in_array('ultimo_acesso',   $colunasExistentes) ? 'ultimo_acesso,'                             : 'NULL::timestamp AS ultimo_acesso,';
    $sIsento       = in_array('isento_pagamento',$colunasExistentes) ? 'isento_pagamento,'                          : 'FALSE AS isento_pagamento,';
    $sDiaVenc      = in_array('dia_vencimento',  $colunasExistentes) ? 'dia_vencimento,'                            : '1 AS dia_vencimento,';
    $sEmbaixador   = in_array('embaixador',      $colunasExistentes) ? 'COALESCE(embaixador, FALSE) AS embaixador,' : 'FALSE AS embaixador,';
    $sReferral     = in_array('referral_slug',   $colunasExistentes) ? 'referral_slug'                              : 'NULL AS referral_slug';

    // Buscar todos os usu�rios do sistema
    $sql = "SELECT
                codigo,
                nome,
                cpf,
                email,
                telefone,
                perfil,
                status,
                foto_caminho,
                data_cadastro,
                data_atualizacao,
                {$sDiaVenc}
                {$sUltimoAcesso}
                {$sIsento}
                {$sEmbaixador}
                {$sReferral}
            FROM usuarios
            ORDER BY data_cadastro DESC";

    $stmt = $conexao->prepare($sql);
    $stmt->execute();
    
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formatar dados
    foreach ($usuarios as &$usuario) {
        $usuario['codigo'] = intval($usuario['codigo']);
        $usuario['perfil'] = intval($usuario['perfil']);
        $usuario['status'] = intval($usuario['status']);
        $usuario['dia_vencimento'] = intval($usuario['dia_vencimento'] ?? 1);
        // Booleanos PostgreSQL chegam como 't'/'f', true/false, '1'/'0' ou null
        $v = $usuario['isento_pagamento'] ?? null;
        $usuario['isento_pagamento'] = ($v === true || $v === 't' || $v === '1' || $v === 1);
        $v = $usuario['embaixador'] ?? null;
        $usuario['embaixador'] = ($v === true || $v === 't' || $v === '1' || $v === 1);
    }

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Usu�rios carregados com sucesso',
        'usuarios' => $usuarios,
        'total' => count($usuarios)
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro no banco de dados: ' . $e->getMessage(),
        'usuarios' => []
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => $e->getMessage(),
        'usuarios' => []
    ], JSON_UNESCAPED_UNICODE);
}
?>
