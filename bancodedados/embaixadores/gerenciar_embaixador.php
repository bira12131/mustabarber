<?php
/**
 * API Admin: Promover/rebaixar usuário a embaixador e gerar slug
 * Sistema: MustaBarber
 * Acesso: somente perfil 1 (admin)
 */

header('Content-Type: application/json; charset=utf-8');

require_once '../conexao.php';
require_once '../usuarios/sessao.php';

requererLogin();

$usuario = obterUsuarioLogado();
if ((int)($usuario['perfil'] ?? 0) !== 1) {
    http_response_code(403);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Acesso negado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Método não permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$codigoAlvo = (int)($input['codigo_usuario'] ?? 0);
$acao       = trim($input['acao'] ?? ''); // 'promover' ou 'rebaixar'

if ($codigoAlvo <= 0 || !in_array($acao, ['promover', 'rebaixar'], true)) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Parâmetros inválidos.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($acao === 'promover') {
        // Verificar se já tem slug; se não, gerar
        $stmtSlug = $conexao->prepare("SELECT referral_slug FROM usuarios WHERE codigo = :cod LIMIT 1");
        $stmtSlug->bindParam(':cod', $codigoAlvo, PDO::PARAM_INT);
        $stmtSlug->execute();
        $row = $stmtSlug->fetch(PDO::FETCH_ASSOC);

        $slug = $row['referral_slug'] ?? null;
        if (empty($slug)) {
            $slug = gerarSlugUnico($conexao);
        }

        $stmt = $conexao->prepare(
            "UPDATE usuarios SET embaixador = TRUE, referral_slug = :slug WHERE codigo = :cod"
        );
        $stmt->bindParam(':slug', $slug, PDO::PARAM_STR);
        $stmt->bindParam(':cod', $codigoAlvo, PDO::PARAM_INT);
        $stmt->execute();

        echo json_encode([
            'sucesso'       => true,
            'mensagem'      => 'Usuário promovido a embaixador com sucesso!',
            'referral_slug' => $slug
        ], JSON_UNESCAPED_UNICODE);

    } else {
        // rebaixar: remover status de embaixador (mantém slug por histórico)
        $stmt = $conexao->prepare(
            "UPDATE usuarios SET embaixador = FALSE WHERE codigo = :cod"
        );
        $stmt->bindParam(':cod', $codigoAlvo, PDO::PARAM_INT);
        $stmt->execute();

        echo json_encode([
            'sucesso'  => true,
            'mensagem' => 'Status de embaixador removido.'
        ], JSON_UNESCAPED_UNICODE);
    }

} catch (PDOException $e) {
    error_log('[embaixadores/gerenciar_embaixador.php] ' . $e->getMessage());
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro ao atualizar embaixador.'], JSON_UNESCAPED_UNICODE);
}

function gerarSlugUnico(PDO $conexao): string {
    $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    do {
        $slug = '';
        for ($i = 0; $i < 8; $i++) {
            $slug .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $stmtCheck = $conexao->prepare("SELECT 1 FROM usuarios WHERE referral_slug = :slug LIMIT 1");
        $stmtCheck->bindParam(':slug', $slug, PDO::PARAM_STR);
        $stmtCheck->execute();
    } while ($stmtCheck->fetchColumn() !== false);
    return $slug;
}
