<?php
/**
 * Listar Todas as Permissões do Sistema
 * Sistema: MustaBarber - Painel Administrativo
 * Data: 29/01/2026
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

require_once '../conexao.php';
require_once '../usuarios/sessao.php';

try {
    // Verificar se usuário está logado
    if (!estaLogado()) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Usuário não autenticado',
            'permissoes' => []
        ]);
        exit;
    }

    // Verificar se é administrador
    $usuarioLogado = obterUsuarioLogado();
    if ($usuarioLogado['perfil'] != 1) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Acesso negado. Apenas administradores podem acessar esta funcionalidade.',
            'permissoes' => []
        ]);
        exit;
    }

    // Buscar todas as permissões do sistema (distintas por nome, módulo e ação)
    // Agrupa permissões duplicadas e verifica se pertencem ao perfil solicitado
    $perfilId = $_GET['perfil'] ?? null;
    
    $sql = "SELECT DISTINCT ON (nome, modulo, acao)
                codigo,
                codigo_perfil,
                nome,
                modulo,
                acao,
                descricao,
                data_cadastro
            FROM permissao 
            ORDER BY nome, modulo, acao, data_cadastro ASC";

    $stmt = $conexao->prepare($sql);
    $stmt->execute();
    
    $permissoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Se um perfil foi especificado, verificar quais permissões ele possui
    if ($perfilId) {
        $sqlPerfil = "SELECT nome, modulo, acao FROM permissao WHERE codigo_perfil = :perfil";
        $stmtPerfil = $conexao->prepare($sqlPerfil);
        $stmtPerfil->bindParam(':perfil', $perfilId, PDO::PARAM_INT);
        $stmtPerfil->execute();
        $permissoesPerfil = $stmtPerfil->fetchAll(PDO::FETCH_ASSOC);
        
        // Criar array de permissões do perfil para comparação rápida
        $permissoesPerfilSet = array_map(function($p) {
            return $p['nome'] . '|' . $p['modulo'] . '|' . $p['acao'];
        }, $permissoesPerfil);
        
        // Marcar permissões que pertencem ao perfil
        foreach ($permissoes as &$permissao) {
            $chave = $permissao['nome'] . '|' . $permissao['modulo'] . '|' . $permissao['acao'];
            $permissao['pertence_perfil'] = in_array($chave, $permissoesPerfilSet);
            $permissao['codigo_perfil'] = $permissao['pertence_perfil'] ? intval($perfilId) : null;
        }
    }
    
    // Formatar dados
    foreach ($permissoes as &$permissao) {
        $permissao['codigo'] = intval($permissao['codigo']);
        if (isset($permissao['codigo_perfil']) && $permissao['codigo_perfil']) {
            $permissao['codigo_perfil'] = intval($permissao['codigo_perfil']);
        }
    }

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Permissões carregadas com sucesso',
        'permissoes' => $permissoes,
        'total' => count($permissoes)
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro no banco de dados: ' . $e->getMessage(),
        'permissoes' => []
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => $e->getMessage(),
        'permissoes' => []
    ], JSON_UNESCAPED_UNICODE);
}
?>
