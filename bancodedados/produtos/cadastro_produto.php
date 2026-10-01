<?php
/**
 * API de Cadastro de Produtos
 * Sistema: MustaBarber
 * Data: 29/11/2025
 * 
 * Este arquivo processa o cadastro e atualiza��o de produtos no sistema
 */

header('Content-Type: application/json; charset=utf-8');

// Verifica se a requisi��o � do tipo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'M�todo n�o permitido. Use POST.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Inclui o arquivo de conex�o
    require_once '../conexao.php';
    
    // Inclui o arquivo de sess�o para pegar o usu�rio logado
    require_once '../usuarios/sessao.php';
    
    // Verifica se usu�rio est� logado
    if (!estaLogado()) {
        throw new Exception('Usu�rio n�o est� logado.');
    }
    
    // Pega o código do barbeiro logado
    $usuario_logado = obterUsuarioLogado();
    $codigo_barbeiro = $usuario_logado['codigo'];

    // Verificar acesso por plano (produtos/estoque exige Solo Pro ou superior)
    require_once '../planos/plano_manager.php';
    exigirPlanoOuBloquear($conexao, (int)$codigo_barbeiro, (int)$usuario_logado['perfil'], 'produtos');
    
    // Recebe os dados do formul�rio
    $codigo = isset($_POST['codigo']) ? intval($_POST['codigo']) : null;
    $nome = trim($_POST['nome'] ?? '');
    $marca = trim($_POST['marca'] ?? '');
    $preco = floatval($_POST['preco'] ?? 0);
    $quantidade_estoque = intval($_POST['quantidade_estoque'] ?? 0);
    $status = isset($_POST['status']) ? intval($_POST['status']) : 1;
    
    // Valida��es b�sicas
    if (empty($nome)) {
        throw new Exception('O nome do produto � obrigat�rio.');
    }
    
    if ($preco <= 0) {
        throw new Exception('O pre�o deve ser maior que zero.');
    }
    
    if ($quantidade_estoque < 0) {
        throw new Exception('A quantidade em estoque n�o pode ser negativa.');
    }
    
    // Valida tamanho m�ximo do nome
    if (strlen($nome) > 255) {
        throw new Exception('O nome do produto deve ter no m�ximo 255 caracteres.');
    }
    
    // Processa o upload da foto
    $fotoCaminho = null;
    $fotoAntiga = null;
    
    // Se for edi��o, busca foto antiga
    if ($codigo) {
        $stmt = $conexao->prepare("SELECT foto_caminho FROM produtos WHERE codigo = :codigo");
        $stmt->bindParam(':codigo', $codigo, PDO::PARAM_INT);
        $stmt->execute();
        $produtoAtual = $stmt->fetch();
        if ($produtoAtual) {
            $fotoAntiga = $produtoAtual['foto_caminho'];
        }
    }
    
    if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
        $foto = $_FILES['foto'];
        
        // Valida tipo de arquivo
        $tiposPermitidos = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
        if (!in_array($foto['type'], $tiposPermitidos)) {
            throw new Exception('Tipo de arquivo n�o permitido. Use apenas JPEG, PNG ou GIF.');
        }
        
        // Valida tamanho do arquivo (m�ximo 5MB)
        if ($foto['size'] > 5 * 1024 * 1024) {
            throw new Exception('Arquivo muito grande. Tamanho m�ximo: 5MB.');
        }
        
        // Define diretorio de upload
        $diretorioUpload = '../../uploads/produtos/';
        
        // Cria diretorio se nao existir
        if (!is_dir($diretorioUpload)) {
            mkdir($diretorioUpload, 0777, true);
        }
        
        // Gera nome unico para o arquivo
        $extensao = pathinfo($foto['name'], PATHINFO_EXTENSION);
        $nomeArquivo = uniqid('produto_', true) . '.' . $extensao;
        $caminhoCompleto = $diretorioUpload . $nomeArquivo;
        
        // Move arquivo para diretorio de upload
        if (!move_uploaded_file($foto['tmp_name'], $caminhoCompleto)) {
            throw new Exception('Erro ao salvar imagem. Tente novamente.');
        }
        
        // Salva apenas o caminho relativo no banco
        $fotoCaminho = 'uploads/produtos/' . $nomeArquivo;
        
        // Remove foto antiga se existir
        if ($fotoAntiga && file_exists('../../' . $fotoAntiga)) {
            unlink('../../' . $fotoAntiga);
        }
    } else {
        // Se n�o houver nova foto, mant�m a antiga
        $fotoCaminho = $fotoAntiga;
    }
    
    // Verifica se � atualiza��o ou cadastro
    if ($codigo) {
        // Atualiza��o de produto existente
        $sql = "UPDATE produtos 
                SET nome = :nome, 
                    marca = :marca, 
                    preco = :preco, 
                    quantidade_estoque = :quantidade_estoque, 
                    foto_caminho = :foto_caminho,
                    codigo_barbeiro = :codigo_barbeiro,
                    status = :status
                WHERE codigo = :codigo";
        
        $stmt = $conexao->prepare($sql);
        $stmt->bindParam(':nome', $nome, PDO::PARAM_STR);
        $stmt->bindParam(':marca', $marca, PDO::PARAM_STR);
        $stmt->bindParam(':preco', $preco);
        $stmt->bindParam(':quantidade_estoque', $quantidade_estoque, PDO::PARAM_INT);
        $stmt->bindParam(':foto_caminho', $fotoCaminho, PDO::PARAM_STR);
        $stmt->bindParam(':codigo_barbeiro', $codigo_barbeiro, PDO::PARAM_INT);
        $stmt->bindParam(':status', $status, PDO::PARAM_INT);
        $stmt->bindParam(':codigo', $codigo, PDO::PARAM_INT);
        
        if ($stmt->execute()) {
            echo json_encode([
                'sucesso' => true,
                'mensagem' => 'Produto atualizado com sucesso!',
                'codigo' => $codigo
            ], JSON_UNESCAPED_UNICODE);
        } else {
            throw new Exception('Erro ao atualizar produto no banco de dados.');
        }
    } else {
        // Cadastro de novo produto
        $sql = "INSERT INTO produtos (nome, marca, preco, quantidade_estoque, foto_caminho, codigo_barbeiro, status) 
                VALUES (:nome, :marca, :preco, :quantidade_estoque, :foto_caminho, :codigo_barbeiro, :status)";
        
        $stmt = $conexao->prepare($sql);
        $stmt->bindParam(':nome', $nome, PDO::PARAM_STR);
        $stmt->bindParam(':marca', $marca, PDO::PARAM_STR);
        $stmt->bindParam(':preco', $preco);
        $stmt->bindParam(':quantidade_estoque', $quantidade_estoque, PDO::PARAM_INT);
        $stmt->bindParam(':foto_caminho', $fotoCaminho, PDO::PARAM_STR);
        $stmt->bindParam(':codigo_barbeiro', $codigo_barbeiro, PDO::PARAM_INT);
        $stmt->bindParam(':status', $status, PDO::PARAM_INT);
        
        if ($stmt->execute()) {
            $novoCodigo = $conexao->lastInsertId();
            echo json_encode([
                'sucesso' => true,
                'mensagem' => 'Produto cadastrado com sucesso!',
                'codigo' => $novoCodigo
            ], JSON_UNESCAPED_UNICODE);
        } else {
            throw new Exception('Erro ao cadastrar produto no banco de dados.');
        }
    }
    
} catch (PDOException $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro no banco de dados: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
