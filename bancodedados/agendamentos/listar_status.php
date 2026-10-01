<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

try {
    include('../conexao.php');
    
    // Buscar todos os status da tabela status_agendamento
    $query = "SELECT codigo, nome FROM status_agendamento WHERE ativo = TRUE ORDER BY ordem_exibicao, codigo";
    $stmt = $conexao->prepare($query);
    $stmt->execute();
    
    $statusList = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Mapear para incluir ícone Font Awesome
    $statusComIcones = [];
    $iconMap = [
        'agendado' => 'fa-clock',
        'confirmado' => 'fa-check',
        'em_atendimento' => 'fa-scissors',
        'concluido' => 'fa-check-circle',
        'cancelado' => 'fa-times-circle',
        'falta' => 'fa-ban'
    ];
    
    foreach ($statusList as $status) {
        $nomeStatus = str_replace(' ', '_', strtolower($status['nome']));
        $statusComIcones[] = [
            'codigo' => $status['codigo'],
            'nome' => $status['nome'],
            'nome_padrao' => $nomeStatus,
            'icone' => $iconMap[$nomeStatus] ?? 'fa-question'
        ];
    }
    
    echo json_encode(['sucesso' => true, 'dados' => $statusComIcones]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => 'Erro ao buscar status: ' . $e->getMessage()]);
}
?>
