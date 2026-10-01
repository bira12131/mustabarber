<?php
/**
 * API de Cadastro de Usuarios (Barbeiros)
 * Sistema: MustaBarber
 * Data: 27/11/2025
 * 
 *  Este arquivo processa o cadastro de novos barbeiros no sistema
 */

header('Content-Type: application/json; charset=utf-8');

function normalizarBoolPost($valor) {
    return in_array((string)$valor, ['1', 'true', 'on', 'yes'], true);
}

function obterIpCliente() {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if (strpos($ip, ',') !== false) {
        $partes = explode(',', $ip);
        $ip = trim($partes[0]);
    }
    return substr($ip, 0, 64);
}

function registrarConsentimentoLgpd(PDO $conexao, int $codigoUsuario, string $versaoTermos, string $versaoPrivacidade, bool $aceitaMarketing) {
    $sqlCreate = "
        CREATE TABLE IF NOT EXISTS lgpd_consentimentos (
            codigo SERIAL PRIMARY KEY,
            codigo_usuario INTEGER NOT NULL REFERENCES usuarios(codigo) ON DELETE CASCADE,
            versao_termos VARCHAR(20) NOT NULL,
            versao_privacidade VARCHAR(20) NOT NULL,
            aceita_marketing BOOLEAN NOT NULL DEFAULT FALSE,
            ip_origem VARCHAR(64),
            user_agent TEXT,
            data_aceite TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ";
    $conexao->exec($sqlCreate);

    $sqlInsert = "
        INSERT INTO lgpd_consentimentos
            (codigo_usuario, versao_termos, versao_privacidade, aceita_marketing, ip_origem, user_agent)
        VALUES
            (:codigo_usuario, :versao_termos, :versao_privacidade, :aceita_marketing, :ip_origem, :user_agent)
    ";
    $stmt = $conexao->prepare($sqlInsert);

    $ip = obterIpCliente();
    $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000);

    $stmt->bindValue(':codigo_usuario', $codigoUsuario, PDO::PARAM_INT);
    $stmt->bindValue(':versao_termos', $versaoTermos, PDO::PARAM_STR);
    $stmt->bindValue(':versao_privacidade', $versaoPrivacidade, PDO::PARAM_STR);
    $stmt->bindValue(':aceita_marketing', $aceitaMarketing, PDO::PARAM_BOOL);
    $stmt->bindValue(':ip_origem', $ip, PDO::PARAM_STR);
    $stmt->bindValue(':user_agent', $userAgent, PDO::PARAM_STR);
    $stmt->execute();
}

// Verifica se a requisicao e do tipo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Metodo nao permitido. Use POST.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Inclui o arquivo de conexao
    require_once '../conexao.php';
    
    // Recebe os dados do formulario
    $nome = trim($_POST['nome'] ?? '');
    $cpf = trim($_POST['cpf'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telefone = trim($_POST['telefone'] ?? '');
    $senha = trim($_POST['senha'] ?? '');
    $confirmarSenha = trim($_POST['confirmarSenha'] ?? '');
    $perfil = intval($_POST['perfil'] ?? 2); // Perfil padrao: 2 (Solo Basico)
    $indicacao = trim($_POST['indicacao'] ?? ''); // E-mail ou telefone de quem indicou (opcional)
    $aceitaTermos = normalizarBoolPost($_POST['aceita_termos'] ?? '0');
    $aceitaPrivacidade = normalizarBoolPost($_POST['aceita_privacidade'] ?? '0');
    $aceitaMarketing = normalizarBoolPost($_POST['aceita_marketing'] ?? '0');
    $versaoTermos = trim($_POST['versao_termos'] ?? '2026.05');
    $versaoPrivacidade = trim($_POST['versao_privacidade'] ?? '2026.05');

    // Referral de embaixador (via link de indica��o)
    $codigoEmbaixador    = intval($_POST['codigo_embaixador'] ?? 0);
    $descontoEmbaixador  = floatval($_POST['desconto_embaixador'] ?? 0);
    // Limitar desconto ao intervalo v�lido para evitar manipula��o
    if ($codigoEmbaixador <= 0) { $codigoEmbaixador = 0; $descontoEmbaixador = 0; }
    if ($descontoEmbaixador < 0 || $descontoEmbaixador > 100) { $descontoEmbaixador = 0; }

    // Plano selecionado no trial - determina o perfil
    $planoTrial = trim($_POST['plano_trial'] ?? 'starter');
    $planosValidos = ['starter', 'solo-pro', 'equipe', 'barbearia-pro', 'essencial', 'premium'];
    if (!in_array($planoTrial, $planosValidos, true)) {
        $planoTrial = 'starter';
    }

    // Derivar perfil com base no plano selecionado (fonte de verdade)
    $planoParaPerfil = [
        'starter'       => 2,
        'solo-pro'      => 3,
        'equipe'        => 4,
        'barbearia-pro' => 6,
        // compatibilidade com nomes antigos
        'essencial'     => 2,
        'premium'       => 3,
    ];
    // Sobrescreve o perfil recebido do POST, garantindo consistencia com o plano
    if ($perfil === 1 || $perfil === 5) {
        // Administrador (1) e Cliente (5) chegam via rota propria, manter como veio
    } else {
        $perfil = $planoParaPerfil[$planoTrial] ?? 2;
    }

    // Dia de vencimento da mensalidade (1 a 28)
    $diaVencimento = intval($_POST['dia_vencimento'] ?? 1);
    if ($diaVencimento < 1 || $diaVencimento > 28) {
        $diaVencimento = 1;
    }

    // Endereco fiscal
    $enderecoCep = preg_replace('/[^0-9]/', '', trim($_POST['endereco_cep'] ?? ''));
    $enderecoLogradouro = trim($_POST['endereco_logradouro'] ?? '');
    $enderecoNumero = trim($_POST['endereco_numero'] ?? '');
    $enderecoComplemento = trim($_POST['endereco_complemento'] ?? '');
    $enderecoBairro = trim($_POST['endereco_bairro'] ?? '');
    $enderecoCidade = trim($_POST['endereco_cidade'] ?? '');
    $enderecoEstado = strtoupper(trim($_POST['endereco_estado'] ?? ''));
    
    // Validacoes basicas
    if (empty($nome) || empty($cpf) || empty($email) || empty($telefone) || empty($senha)) {
        throw new Exception('Todos os campos obrigatorios devem ser preenchidos.');
    }

    if (
        empty($enderecoCep) ||
        empty($enderecoLogradouro) ||
        empty($enderecoNumero) ||
        empty($enderecoBairro) ||
        empty($enderecoCidade) ||
        empty($enderecoEstado)
    ) {
        throw new Exception('Preencha todos os campos obrigatorios do endereco.');
    }

    if (strlen($enderecoCep) !== 8) {
        throw new Exception('CEP invalido.');
    }

    if (!preg_match('/^[A-Z]{2}$/', $enderecoEstado)) {
        throw new Exception('Estado (UF) invalido.');
    }

    if (!$aceitaTermos || !$aceitaPrivacidade) {
        throw new Exception('Para criar a conta, e obrigatorio aceitar os Termos de Uso e a Politica de Privacidade.');
    }
    
    // Valida se as senhas conferem
    if ($senha !== $confirmarSenha) {
        throw new Exception('As senhas nao conferem.');
    }
    
    // Valida tamanho minimo da senha
    if (strlen($senha) < 6) {
        throw new Exception('A senha deve ter no minimo 6 caracteres.');
    }
    
    // Remove formatacao do CPF
    $cpf = preg_replace('/[^0-9]/', '', $cpf);
    
    // Valida CPF
    if (strlen($cpf) !== 11) {
        throw new Exception('CPF invalido.');
    }
    
    // Remove formatacao do telefone
    $telefone = preg_replace('/[^0-9]/', '', $telefone);
    
    // Verifica se CPF ja esta cadastrado
    $stmt = $conexao->prepare("SELECT codigo FROM usuarios WHERE cpf = :cpf");
    $stmt->bindParam(':cpf', $cpf, PDO::PARAM_STR);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        throw new Exception('CPF ja cadastrado no sistema.');
    }
    
    // Verifica se email ja esta cadastrado
    $stmt = $conexao->prepare("SELECT codigo FROM usuarios WHERE email = :email");
    $stmt->bindParam(':email', $email, PDO::PARAM_STR);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        throw new Exception('E-mail ja cadastrado no sistema.');
    }
    
    // Processa o upload da foto
    $fotoCaminho = null;
    
    if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
        $foto = $_FILES['foto'];
        
        // Valida tipo de arquivo
        $tiposPermitidos = ['image/jpeg', 'image/jpg', 'image/png'];
        if (!in_array($foto['type'], $tiposPermitidos)) {
            throw new Exception('Tipo de arquivo nao permitido. Use apenas JPEG ou PNG.');
        }
        
        // Valida tamanho do arquivo (maximo 5MB)
        if ($foto['size'] > 5 * 1024 * 1024) {
            throw new Exception('Arquivo muito grande. Tamanho maximo: 5MB.');
        }
        
        // Define diretorio de upload
        $diretorioUpload = '../../uploads/fotos/';
        
        // Cria diretorio se nao existir
        if (!is_dir($diretorioUpload)) {
            mkdir($diretorioUpload, 0777, true);
        }
        
        // Gera nome unico para o arquivo
        $extensao = pathinfo($foto['name'], PATHINFO_EXTENSION);
        $nomeArquivo = uniqid('barbeiro_', true) . '.' . $extensao;
        $caminhoCompleto = $diretorioUpload . $nomeArquivo;
        
        // Move arquivo para diretorio de upload
        if (!move_uploaded_file($foto['tmp_name'], $caminhoCompleto)) {
            throw new Exception('Erro ao salvar foto. Tente novamente.');
        }
        
        // Salva apenas o caminho relativo no banco
        $fotoCaminho = 'uploads/fotos/' . $nomeArquivo;
    }
    
    // Hash da senha usando bcrypt
    $senhaHash = password_hash($senha, PASSWORD_BCRYPT);
    
    // Prepara query de insercao
    $sql = "INSERT INTO usuarios (
                nome, cpf, email, telefone, senha, perfil, foto_caminho, status, plano_trial, dia_vencimento,
                endereco_cep, endereco_logradouro, endereco_numero, endereco_complemento, endereco_bairro, endereco_cidade, endereco_estado
            ) 
            VALUES (
                :nome, :cpf, :email, :telefone, :senha, :perfil, :foto_caminho, :status, :plano_trial, :dia_vencimento,
                :endereco_cep, :endereco_logradouro, :endereco_numero, :endereco_complemento, :endereco_bairro, :endereco_cidade, :endereco_estado
            )
            RETURNING codigo";
    
    $stmt = $conexao->prepare($sql);
    
    // Bind dos parametros
    $stmt->bindParam(':nome', $nome, PDO::PARAM_STR);
    $stmt->bindParam(':cpf', $cpf, PDO::PARAM_STR);
    $stmt->bindParam(':email', $email, PDO::PARAM_STR);
    $stmt->bindParam(':telefone', $telefone, PDO::PARAM_STR);
    $stmt->bindParam(':senha', $senhaHash, PDO::PARAM_STR);
    $stmt->bindParam(':perfil', $perfil, PDO::PARAM_INT);
    $stmt->bindParam(':foto_caminho', $fotoCaminho, PDO::PARAM_STR);
    $stmt->bindParam(':plano_trial', $planoTrial, PDO::PARAM_STR);
    $stmt->bindParam(':dia_vencimento', $diaVencimento, PDO::PARAM_INT);
    $stmt->bindParam(':endereco_cep', $enderecoCep, PDO::PARAM_STR);
    $stmt->bindParam(':endereco_logradouro', $enderecoLogradouro, PDO::PARAM_STR);
    $stmt->bindParam(':endereco_numero', $enderecoNumero, PDO::PARAM_STR);
    $stmt->bindParam(':endereco_complemento', $enderecoComplemento, PDO::PARAM_STR);
    $stmt->bindParam(':endereco_bairro', $enderecoBairro, PDO::PARAM_STR);
    $stmt->bindParam(':endereco_cidade', $enderecoCidade, PDO::PARAM_STR);
    $stmt->bindParam(':endereco_estado', $enderecoEstado, PDO::PARAM_STR);
    
    // Status padrao: 1 (Ativo)
    $status = 1;
    $stmt->bindParam(':status', $status, PDO::PARAM_INT);
    
    // Executa a query
    $stmt->execute();
    
    // Obtem o codigo do usuario inserido
    $resultado = $stmt->fetch();
    $codigoUsuario = $resultado['codigo'];

    registrarConsentimentoLgpd(
        $conexao,
        (int)$codigoUsuario,
        $versaoTermos,
        $versaoPrivacidade,
        $aceitaMarketing
    );

    // Registra indica��o se foi informada (campo manual)
    if (!empty($indicacao)) {
        $contatoSemFormatacao = preg_replace('/[^0-9]/', '', $indicacao);

        $stmtIndicador = $conexao->prepare(
            "SELECT codigo FROM usuarios
             WHERE status = 1
               AND (
                   LOWER(email) = LOWER(:contato_email)
                   OR REGEXP_REPLACE(telefone, '[^0-9]', '', 'g') = :contato_tel
               )
             LIMIT 1"
        );
        $stmtIndicador->bindParam(':contato_email', $indicacao, PDO::PARAM_STR);
        $stmtIndicador->bindParam(':contato_tel', $contatoSemFormatacao, PDO::PARAM_STR);
        $stmtIndicador->execute();
        $indicador = $stmtIndicador->fetch(PDO::FETCH_ASSOC);

        if ($indicador) {
            $codigoIndicador = (int) $indicador['codigo'];
            $stmtInd = $conexao->prepare(
                "INSERT INTO indicacoes (codigo_indicador, contato_informado, codigo_indicado, convertido)
                 VALUES (:codigo_indicador, :contato_informado, :codigo_indicado, FALSE)"
            );
            $stmtInd->bindParam(':codigo_indicador', $codigoIndicador, PDO::PARAM_INT);
            $stmtInd->bindParam(':contato_informado', $indicacao, PDO::PARAM_STR);
            $stmtInd->bindParam(':codigo_indicado', $codigoUsuario, PDO::PARAM_INT);
            $stmtInd->execute();
        }
    }

    // Registra indica��o por embaixador (via link de referral)
    if ($codigoEmbaixador > 0) {
        // Confirmar que o c�digo � mesmo de um embaixador ativo
        $stmtConfEmb = $conexao->prepare(
            "SELECT 1 FROM usuarios WHERE codigo = :cod AND embaixador = TRUE AND status = 1 LIMIT 1"
        );
        $stmtConfEmb->bindParam(':cod', $codigoEmbaixador, PDO::PARAM_INT);
        $stmtConfEmb->execute();

        if ($stmtConfEmb->fetchColumn() !== false) {
            $contatoEmb = $email; // usar email do novo cadastro como contato registrado
            $stmtIndEmb = $conexao->prepare(
                "INSERT INTO indicacoes
                    (codigo_indicador, contato_informado, codigo_indicado, convertido, codigo_embaixador, desconto_aplicado)
                 VALUES
                    (:cod_indicador, :contato, :cod_indicado, FALSE, :cod_embaixador, :desconto)"
            );
            $stmtIndEmb->bindParam(':cod_indicador',  $codigoEmbaixador, PDO::PARAM_INT);
            $stmtIndEmb->bindParam(':contato',        $contatoEmb,       PDO::PARAM_STR);
            $stmtIndEmb->bindParam(':cod_indicado',   $codigoUsuario,    PDO::PARAM_INT);
            $stmtIndEmb->bindParam(':cod_embaixador', $codigoEmbaixador, PDO::PARAM_INT);
            $stmtIndEmb->bindParam(':desconto',       $descontoEmbaixador, PDO::PARAM_STR);
            $stmtIndEmb->execute();

            // Salvar o desconto na sess�o/par�metro para ser aplicado quando o barbeiro assinar
            // (armazenado em coluna tempor�ria que ser� lida ao gerar assinatura)
            // Por ora registramos o c�digo do embaixador e o desconto para uso futuro
        }
    }

    // -------------------------------------------------------
    // Gerar notificação de boas-vindas com cronograma de cobranças
    // -------------------------------------------------------
    try {
        // Preço base pelo perfil
        $precoPorPerfilNotif = [2 => 52.90, 3 => 64.90, 4 => 94.90, 6 => 136.90, 7 => 199.90];
        $valorMensNotif = $precoPorPerfilNotif[$perfil] ?? 52.90;
        // Aplicar desconto de embaixador, se houver
        if ($codigoEmbaixador > 0 && $descontoEmbaixador > 0) {
            $valorMensNotif = round($valorMensNotif * (1 - $descontoEmbaixador / 100), 2);
        }

        // 1ª cobrança: cadastro + 7 dias
        $primeiraCobrancaDate = new DateTime();
        $primeiraCobrancaDate->modify('+7 days');

        // 2ª cobrança: próxima ocorrência do dia_vencimento após a 1ª cobrança
        $diaV    = max(1, min(28, $diaVencimento));
        $anoRef  = (int) $primeiraCobrancaDate->format('Y');
        $mesRef  = (int) $primeiraCobrancaDate->format('n');
        $diaNMes = cal_days_in_month(CAL_GREGORIAN, $mesRef, $anoRef);
        $segunda = new DateTime(sprintf('%04d-%02d-%02d', $anoRef, $mesRef, min($diaV, $diaNMes)));
        if ($segunda <= $primeiraCobrancaDate) {
            $mesRef++;
            if ($mesRef > 12) { $mesRef = 1; $anoRef++; }
            $diaNMes = cal_days_in_month(CAL_GREGORIAN, $mesRef, $anoRef);
            $segunda = new DateTime(sprintf('%04d-%02d-%02d', $anoRef, $mesRef, min($diaV, $diaNMes)));
        }

        $valorSegunda = $valorMensNotif;

        $fmtV = function(float $v): string {
            return 'R$ ' . number_format($v, 2, ',', '.');
        };
        $fmt1  = $primeiraCobrancaDate->format('d/m/Y');
        $fmt2  = $segunda->format('d/m/Y');

        $linha2 = "📅 2ª cobrança: {$fmt2} — {$fmtV($valorSegunda)}";

        $tituloNotif   = 'Bem-vindo! Seu cronograma de cobranças';
        $mensagemNotif = "🎉 Seus 7 dias gratuitos estão ativados! Aproveite todos os recursos sem precisar de cartão.\n\n"
            . "📅 1ª cobrança: {$fmt1} — {$fmtV($valorMensNotif)} (após o período de teste)\n"
            . $linha2 . "\n"
            . "🔄 A partir daí: todo dia {$diaVencimento} — {$fmtV($valorMensNotif)}/mês\n\n"
            . "ℹ️ Você pode efetuar o pagamento via PIX, Cartão de Crédito ou Boleto.";

        $stmtNotif = $conexao->prepare(
            "INSERT INTO notificacoes_sistema (codigo_usuario, tipo, titulo, mensagem)
             VALUES (:cod, 'cobranca_boas_vindas', :titulo, :mensagem)"
        );
        $stmtNotif->bindParam(':cod',      $codigoUsuario,   PDO::PARAM_INT);
        $stmtNotif->bindParam(':titulo',   $tituloNotif,     PDO::PARAM_STR);
        $stmtNotif->bindParam(':mensagem', $mensagemNotif,   PDO::PARAM_STR);
        $stmtNotif->execute();
    } catch (Exception $eNotif) {
        // Falha na notificação não deve impedir o cadastro
        error_log('[MustaBarber] Erro ao criar notificação de cadastro: ' . $eNotif->getMessage());
    }

    // Retorna sucesso
    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Usuario cadastrado com sucesso!',
        'dados' => [
            'codigo' => $codigoUsuario,
            'nome' => $nome,
            'email' => $email
        ]
    ], JSON_UNESCAPED_UNICODE);
    
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
        'mensagem' => 'Erro ao processar cadastro. Tente novamente.'
    ], JSON_UNESCAPED_UNICODE);
}
?>
