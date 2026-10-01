<?php
/**
 * API de Cadastro de Servi&ccedil;os
 * Sistema: MustaBarber
 * Data: 28/11/2025
 * 
 * Este arquivo processa o cadastro e atualiza&ccedil;&atilde;o de servi&ccedil;os no sistema
 */

header('Content-Type: application/json; charset=utf-8');

// Verifica se a requisi&ccedil;&atilde;o &eacute; do tipo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'M&eacute;todo n&atilde;o permitido. Use POST.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Inclui o arquivo de conex&atilde;o
    require_once '../conexao.php';
    
    // Inclui o sistema de sess&atilde;o para obter usu&aacute;rio logado
    require_once '../usuarios/sessao.php';
    
    // Obt&eacute;m dados do usu&aacute;rio logado
    $usuarioLogado = obterUsuarioLogado();
    
    // Recebe os dados do formul&aacute;rio
    $codigo = isset($_POST['codigo']) ? intval($_POST['codigo']) : null;
    $nome = trim($_POST['nome'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    $preco = floatval($_POST['preco'] ?? 0);
    $duracao = intval($_POST['duracao'] ?? 0);
    
    // C&oacute;digo do barbeiro: prioriza o enviado via POST, sen&atilde;o usa o da sess&atilde;o
    $codigoBarbeiro = isset($_POST['codigo_barbeiro']) ? intval($_POST['codigo_barbeiro']) : null;
    if (!$codigoBarbeiro && $usuarioLogado) {
        $codigoBarbeiro = intval($usuarioLogado['codigo']);
    }
    
    // Valida&ccedil;&otilde;es b&aacute;sicas
    if (empty($nome)) {
        throw new Exception('O nome do servi&ccedil;o &eacute; obrigat&oacute;rio.');
    }
    
    if ($preco <= 0) {
        throw new Exception('O pre&ccedil;o deve ser maior que zero.');
    }
    
    if ($duracao <= 0) {
        throw new Exception('A dura&ccedil;&atilde;o deve ser maior que zero.');
    }
    
    // Valida tamanho m&aacute;ximo do nome
    if (strlen($nome) > 150) {
        throw new Exception('O nome do servi&ccedil;o deve ter no m&aacute;ximo 150 caracteres.');
    }
    
    // Processa o upload da foto
    $fotoCaminho = null;
    $fotoAntiga = null;
    
    // Se for edi&ccedil;&atilde;o, busca foto antiga
    if ($codigo) {
        $stmt = $conexao->prepare("SELECT foto_caminho FROM servicos WHERE codigo = :codigo");
        $stmt->bindParam(':codigo', $codigo, PDO::PARAM_INT);
        $stmt->execute();
        $servicoAtual = $stmt->fetch();
        if ($servicoAtual) {
            $fotoAntiga = $servicoAtual['foto_caminho'];
        }
    }
    
    if (isset($_FILES['imagem']) && $_FILES['imagem']['error'] === UPLOAD_ERR_OK) {
        $foto = $_FILES['imagem'];
        
        // Valida tipo de arquivo
        $tiposPermitidos = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
        if (!in_array($foto['type'], $tiposPermitidos)) {
            throw new Exception('Tipo de arquivo n&atilde;o permitido. Use apenas JPEG, PNG ou GIF.');
        }
        
        // Valida tamanho do arquivo (m&aacute;ximo 5MB)
        if ($foto['size'] > 5 * 1024 * 1024) {
            throw new Exception('Arquivo muito grande. Tamanho m&aacute;ximo: 5MB.');
        }
        
        // Define diretorio de upload
        $diretorioUpload = '../../uploads/servicos/';
        
        // Cria diretorio se nao existir
        if (!is_dir($diretorioUpload)) {
            mkdir($diretorioUpload, 0777, true);
        }
        
        // Gera nome unico para o arquivo
        $extensao = pathinfo($foto['name'], PATHINFO_EXTENSION);
        $nomeArquivo = uniqid('servico_', true) . '.' . $extensao;
        $caminhoCompleto = $diretorioUpload . $nomeArquivo;
        
        // Move arquivo para diretorio de upload
        if (!move_uploaded_file($foto['tmp_name'], $caminhoCompleto)) {
            throw new Exception('Erro ao salvar imagem. Tente novamente.');
        }
        
        // Salva apenas o caminho relativo no banco
        $fotoCaminho = 'uploads/servicos/' . $nomeArquivo;
        
        // Remove foto antiga se existir
        if ($fotoAntiga && file_exists('../../' . $fotoAntiga)) {
            unlink('../../' . $fotoAntiga);
        }
    } else {
        // Se n&atilde;o houver nova foto, mant&eacute;m a antiga
        $fotoCaminho = $fotoAntiga;
    }
    
    // Verifica se &eacute; atualiza&ccedil;&atilde;o ou cadastro
    if ($codigo) {
        // Atualiza&ccedil;&atilde;o de servi&ccedil;o existente
        $sql = "UPDATE servicos 
                SET nome = :nome, 
                    descricao = :descricao, 
                    preco = :preco, 
                    duracao = :duracao, 
                    foto_caminho = :foto_caminho,
                    codigo_barbeiro = :codigo_barbeiro
                WHERE codigo = :codigo";
        
        $stmt = $conexao->prepare($sql);
        
        // Bind dos par&acirc;metros
        $stmt->bindParam(':codigo', $codigo, PDO::PARAM_INT);
        $stmt->bindParam(':nome', $nome, PDO::PARAM_STR);
        $stmt->bindParam(':descricao', $descricao, PDO::PARAM_STR);
        $stmt->bindParam(':preco', $preco);
        $stmt->bindParam(':duracao', $duracao, PDO::PARAM_INT);
        $stmt->bindParam(':foto_caminho', $fotoCaminho, PDO::PARAM_STR);
        
        if ($codigoBarbeiro) {
            $stmt->bindParam(':codigo_barbeiro', $codigoBarbeiro, PDO::PARAM_INT);
        } else {
            $stmt->bindValue(':codigo_barbeiro', null, PDO::PARAM_NULL);
        }
        
        // Executa a query
        $stmt->execute();
        
        // Retorna sucesso
        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Servi&ccedil;o atualizado com sucesso!',
            'dados' => [
                'codigo' => $codigo,
                'nome' => $nome,
                'preco' => $preco,
                'duracao' => $duracao,
                'foto_caminho' => $fotoCaminho
            ]
        ], JSON_UNESCAPED_UNICODE);
        
    } else {
        // Cadastro de novo servi&ccedil;o
        $sql = "INSERT INTO servicos (nome, descricao, preco, duracao, foto_caminho, codigo_barbeiro, status) 
                VALUES (:nome, :descricao, :preco, :duracao, :foto_caminho, :codigo_barbeiro, :status)
                RETURNING codigo";
        
        $stmt = $conexao->prepare($sql);
        
        // Bind dos par&acirc;metros
        $stmt->bindParam(':nome', $nome, PDO::PARAM_STR);
        $stmt->bindParam(':descricao', $descricao, PDO::PARAM_STR);
        $stmt->bindParam(':preco', $preco);
        $stmt->bindParam(':duracao', $duracao, PDO::PARAM_INT);
        $stmt->bindParam(':foto_caminho', $fotoCaminho, PDO::PARAM_STR);
        
        if ($codigoBarbeiro) {
            $stmt->bindParam(':codigo_barbeiro', $codigoBarbeiro, PDO::PARAM_INT);
        } else {
            $stmt->bindValue(':codigo_barbeiro', null, PDO::PARAM_NULL);
        }
        
        // Status padr&atilde;o: 1 (Ativo)
        $status = 1;
        $stmt->bindParam(':status', $status, PDO::PARAM_INT);
        
        // Executa a query
        $stmt->execute();
        
        // Obt&eacute;m o c&oacute;digo do servi&ccedil;o inserido
        $resultado = $stmt->fetch();
        $codigoServico = $resultado['codigo'];
        
        // Retorna sucesso
        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Servi&ccedil;o cadastrado com sucesso!',
            'dados' => [
                'codigo' => $codigoServico,
                'nome' => $nome,
                'preco' => $preco,
                'duracao' => $duracao,
                'foto_caminho' => $fotoCaminho
            ]
        ], JSON_UNESCAPED_UNICODE);
    }
    
} catch (Exception $e) {
    // Em caso de erro, retorna mensagem
    echo json_encode([
        'sucesso' => false,
        'mensagem' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    // Erro de banco de dados
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao processar cadastro. Tente novamente.',
        'erro_debug' => $e->getMessage() // Remover em produ&ccedil;&atilde;o
    ], JSON_UNESCAPED_UNICODE);
}
?>
