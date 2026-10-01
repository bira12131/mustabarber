<?php
/**
 * API: Verificar embaixador por slug de referral
 * Sistema: MustaBarber
 * 
 * Recebe ?slug=XXXX e retorna se existe um embaixador ativo com esse slug.
 * Usado pelo cadastro para pré-preencher o referral e mostrar mensagem de desconto.
 */

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Método não permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$slug = trim($_GET['slug'] ?? '');

if (empty($slug) || !preg_match('/^[a-z0-9]{4,32}$/', $slug)) {
    echo json_encode([
        'sucesso'    => false,
        'encontrado' => false,
        'mensagem'   => 'Slug inválido.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_once '../conexao.php';

    $stmt = $conexao->prepare(
        "SELECT codigo, nome
         FROM usuarios
         WHERE referral_slug = :slug
           AND embaixador = TRUE
           AND status = 1
         LIMIT 1"
    );
    $stmt->bindParam(':slug', $slug, PDO::PARAM_STR);
    $stmt->execute();
    $embaixador = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($embaixador) {
        $partesNome   = explode(' ', trim($embaixador['nome']));
        $primeiroNome = $partesNome[0];

        // Percentual de desconto fixo para quem vem via link de embaixador
        $desconto = 10; // 10% de desconto para o indicado

        echo json_encode([
            'sucesso'           => true,
            'encontrado'        => true,
            'codigo_embaixador' => (int) $embaixador['codigo'],
            'primeiro_nome'     => $primeiroNome,
            'desconto'          => $desconto,
            'mensagem'          => "Você foi indicado por {$primeiroNome} e terá {$desconto}% de desconto na assinatura!"
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'sucesso'    => true,
            'encontrado' => false,
            'mensagem'   => 'Link de indicação não encontrado ou inativo.'
        ], JSON_UNESCAPED_UNICODE);
    }

} catch (PDOException $e) {
    error_log('[embaixadores/verificar_referral.php] ' . $e->getMessage());
    echo json_encode([
        'sucesso'    => false,
        'encontrado' => false,
        'mensagem'   => 'Erro ao verificar link de indicação.'
    ], JSON_UNESCAPED_UNICODE);
}
