<?php
/**
 * Script para atualizar foto do usuário
 * Sistema: MustaBarber
 * Data: 11/12/2025
 */

header('Content-Type: application/json; charset=utf-8');

// Incluir sessão
require_once 'sessao.php';
require_once '../conexao.php';

// Verificar se usuário está logado
if (!estaLogado()) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Usuário não autenticado'
    ]);
    exit;
}

// Verificar se é uma requisição POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Método não permitido'
    ]);
    exit;
}

try {
    // Obter código do usuário logado
    $usuarioLogado = obterUsuarioLogado();
    $codigoUsuario = $usuarioLogado['codigo'];
    
    // Verificar se foi enviado um arquivo
    if (!isset($_FILES['foto']) || $_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Nenhuma foto foi enviada');
    }
    
    $foto = $_FILES['foto'];
    
    // Validar tamanho (máximo 5MB)
    if ($foto['size'] > 5 * 1024 * 1024) {
        throw new Exception('A foto deve ter no máximo 5MB');
    }
    
    // Validar tipo de arquivo
    $tiposPermitidos = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $tipoArquivo = finfo_file($finfo, $foto['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($tipoArquivo, $tiposPermitidos)) {
        throw new Exception('Tipo de arquivo não permitido. Use apenas JPG, PNG, GIF ou WEBP');
    }
    
    // Definir diretório de upload
    $diretorioUpload = '../../uploads/fotos/';
    
    // Criar diretório se não existir
    if (!is_dir($diretorioUpload)) {
        mkdir($diretorioUpload, 0755, true);
    }
    
    // Gerar nome único para o arquivo
    $extensao = pathinfo($foto['name'], PATHINFO_EXTENSION);
    $nomeArquivo = 'usuario_' . $codigoUsuario . '_' . uniqid() . '.' . $extensao;
    $caminhoCompleto = $diretorioUpload . $nomeArquivo;
    $caminhoRelativo = 'uploads/fotos/' . $nomeArquivo;
    
    // Buscar foto antiga para deletar
    $stmt = $conn->prepare("SELECT foto_caminho FROM usuarios WHERE codigo = :codigo");
    $stmt->execute([':codigo' => $codigoUsuario]);
    $usuarioAtual = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($usuarioAtual && !empty($usuarioAtual['foto_caminho'])) {
        $fotoAntiga = '../../' . $usuarioAtual['foto_caminho'];
        if (file_exists($fotoAntiga)) {
            unlink($fotoAntiga);
        }
    }
    
    // Mover arquivo para o diretório de upload
    if (!move_uploaded_file($foto['tmp_name'], $caminhoCompleto)) {
        throw new Exception('Erro ao salvar foto no servidor');
    }
    
    // Atualizar caminho da foto no banco de dados
    $stmt = $conn->prepare("UPDATE usuarios SET foto_caminho = :foto_caminho WHERE codigo = :codigo");
    $stmt->execute([
        ':foto_caminho' => $caminhoRelativo,
        ':codigo' => $codigoUsuario
    ]);
    
    // Atualizar sessão
    $_SESSION['usuario_foto'] = $caminhoRelativo;
    
    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Foto atualizada com sucesso!',
        'caminho' => $caminhoRelativo
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => $e->getMessage()
    ]);
}
?>
