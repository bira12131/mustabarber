<?php
/**
 * Listar Barbeiros Públicos de uma Barbearia
 * Sistema: MustaBarber
 * Data: 14/03/2026
 * Endpoint público (sem autenticação) para que clientes possam
 * visualizar os barbeiros disponíveis de uma barbearia via link.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

require_once '../conexao.php';

try {
    $codBarbearia = isset($_GET['cod_barbearia']) ? intval($_GET['cod_barbearia']) : 0;

    if ($codBarbearia <= 0) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Código da barbearia inválido.',
            'barbeiros' => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Verificar se a barbearia existe
    $stmtBarb = $conexao->prepare("SELECT codigo, nome_barbearia FROM barbearia WHERE codigo = :cod_barbearia LIMIT 1");
    $stmtBarb->bindParam(':cod_barbearia', $codBarbearia, PDO::PARAM_INT);
    $stmtBarb->execute();
    $barbearia = $stmtBarb->fetch(PDO::FETCH_ASSOC);

    if (!$barbearia) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Barbearia não encontrada.',
            'barbeiros' => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Buscar barbeiros ativos vinculados à barbearia (funcionários)
    // Inclui também o dono da barbearia (pelo código_usuario da tabela barbearia)
    $sql = "SELECT
                u.codigo,
                u.nome,
                u.foto_caminho,
                u.status
            FROM usuarios u
            WHERE u.codigo_barbearia = :cod_barbearia
              AND u.status = 1
            UNION
            SELECT
                u.codigo,
                u.nome,
                u.foto_caminho,
                u.status
            FROM usuarios u
            INNER JOIN barbearia b ON b.codigo_usuario = u.codigo
            WHERE b.codigo = :cod_barbearia2
              AND u.status = 1
            ORDER BY nome ASC";

    $stmt = $conexao->prepare($sql);
    $stmt->bindParam(':cod_barbearia', $codBarbearia, PDO::PARAM_INT);
    $stmt->bindParam(':cod_barbearia2', $codBarbearia, PDO::PARAM_INT);
    $stmt->execute();

    $barbeiros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($barbeiros as &$b) {
        $b['codigo'] = intval($b['codigo']);
        $b['status'] = intval($b['status']);
    }

    echo json_encode([
        'sucesso' => true,
        'mensagem' => count($barbeiros) . ' barbeiro(s) encontrado(s).',
        'barbearia' => [
            'codigo' => intval($barbearia['codigo']),
            'nome_barbearia' => $barbearia['nome_barbearia']
        ],
        'barbeiros' => $barbeiros
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log('[listar_barbeiros_publico] PDOException: ' . $e->getMessage());
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro no banco de dados.',
        'barbeiros' => []
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('[listar_barbeiros_publico] Exception: ' . $e->getMessage());
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro interno.',
        'barbeiros' => []
    ], JSON_UNESCAPED_UNICODE);
}
?>
