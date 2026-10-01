<?php
/**
 * Sistema de Sess&#65533;o de Usu&#65533;rios
 * Sistema: MustaBarber
 * Data: 28/11/2025
 * 
 * Gerencia sess&#65533;es de usu&#65533;rios logados no sistema
 */

//  Configuracao segura de sessao antes do session_start
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
    );

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

/**
 * Cria uma sess&#65533;o para o usu&#65533;rio logado
 */
function criarSessao($usuario) {
    $_SESSION['usuario_logado'] = true;
    $_SESSION['usuario_codigo'] = $usuario['codigo'];
    $_SESSION['usuario_nome'] = $usuario['nome'];
    $_SESSION['usuario_email'] = $usuario['email'];
    $_SESSION['usuario_perfil'] = $usuario['perfil'];
    $_SESSION['usuario_foto'] = $usuario['foto_caminho'];
    $_SESSION['login_time'] = time();
    
    // Regenerar ID da sess&#65533;o para seguran&#65533;a
    session_regenerate_id(true);
    
    return true;
}

/**
 * Verifica se usu&#65533;rio est&#65533; logado
 */
function sessaoBasicaAtiva() {
    return isset($_SESSION['usuario_logado']) && $_SESSION['usuario_logado'] === true;
}

/**
 * Verifica se usu&#65533;rio est&#65533; logado.
 */
function estaLogado() {
    return sessaoBasicaAtiva();
}

/**
 * Obt&#65533;m dados do usu&#65533;rio logado
 */
function obterUsuarioLogado() {
    if (!estaLogado()) {
        return null;
    }
    
    return [
        'codigo' => $_SESSION['usuario_codigo'] ?? 0,
        'nome' => $_SESSION['usuario_nome'] ?? '',
        'email' => $_SESSION['usuario_email'] ?? '',
        'perfil' => $_SESSION['usuario_perfil'] ?? 0,
        'foto_caminho' => $_SESSION['usuario_foto'] ?? null
    ];
}

/**
 * Destroi a sess&#65533;o do usu&#65533;rio
 */
function destruirSessao() {
    // Limpar todas as vari&#65533;veis de sess&#65533;o
    $_SESSION = array();
    
    // Destruir cookie de sess&#65533;o
    if (isset($_COOKIE[session_name()])) {
        $isHttps = (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        );
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => '/',
            'domain' => '',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }
    
    // Destruir sess&#65533;o
    session_destroy();
    
    return true;
}

/**
 * Requer que usu&#65533;rio esteja logado, sen&#65533;o redireciona
 * Usa janela deslizante: inatividade > 4h expira a sess&#65533;o
 */
function requererLogin($urlRedirecionamento = '../login.html') {
    if (!estaLogado()) {
        header('Location: ' . $urlRedirecionamento);
        exit;
    }

    // Timeout baseado na &#250;ltima atividade (janela deslizante de 4 horas)
    $referencia = $_SESSION['ultima_atividade'] ?? $_SESSION['login_time'] ?? null;
    if ($referencia !== null) {
        $tempoDecorrido = time() - $referencia;
        $timeout = 14400; // 4 horas de inatividade
        
        if ($tempoDecorrido > $timeout) {
            destruirSessao();
            header('Location: ' . $urlRedirecionamento . '?erro=sessao_expirada');
            exit;
        }
    }
    
    // Atualizar atividade a cada requisi&#231;&#227;o em home.php
    atualizarAtividade();
    
    return true;
}

/**
 * Atualiza o tempo de &#250;ltima atividade (mant&#233;m sess&#227;o ativa)
 */
function atualizarAtividade() {
    if (estaLogado()) {
        $_SESSION['ultima_atividade'] = time();
    }
}

/**
 * Verifica se o usuario pode manter acesso ao sistema de acordo com assinatura.
 */
function validarAcessoPorAssinaturaSessao() {
    if (!sessaoBasicaAtiva()) {
        return false;
    }

    $codigoUsuario = (int)($_SESSION['usuario_codigo'] ?? 0);

    if ($codigoUsuario <= 0) {
        return false;
    }

    try {
        require_once __DIR__ . '/../conexao.php';

        $stmtUser = $conexao->prepare(
            "SELECT data_cadastro, COALESCE(isento_pagamento, FALSE) AS isento_pagamento
             FROM usuarios
             WHERE codigo = :cod
             LIMIT 1"
        );
        $stmtUser->bindValue(':cod', $codigoUsuario, PDO::PARAM_INT);
        $stmtUser->execute();
        $dadosUser = $stmtUser->fetch(PDO::FETCH_ASSOC);

        if (!$dadosUser) {
            return false;
        }

        $isento = ($dadosUser['isento_pagamento'] === true || $dadosUser['isento_pagamento'] === 't');
        if ($isento) {
            return true;
        }

        $stmtAssin = $conexao->prepare(
            "SELECT data_vencimento
             FROM assinaturas
             WHERE codigo_barbeiro = :cod
             ORDER BY codigo DESC
             LIMIT 1"
        );
        $stmtAssin->bindValue(':cod', $codigoUsuario, PDO::PARAM_INT);
        $stmtAssin->execute();
        $assinatura = $stmtAssin->fetch(PDO::FETCH_ASSOC);

        $hoje = new DateTime('today');

        if ($assinatura) {
            $venc = new DateTime((string)$assinatura['data_vencimento']);
            return ($venc >= $hoje);
        }

        // Sem assinatura: liberar apenas durante trial de 7 dias
        $dataCadastro = $dadosUser['data_cadastro'] ?? null;
        if (!$dataCadastro) {
            return false;
        }

        $cadastro = new DateTime((string)$dataCadastro);
        $cadastro->setTime(0, 0, 0);
        $diasDecorridos = (int)$hoje->diff($cadastro)->days;
        if ($cadastro > $hoje) {
            return true;
        }
        return ($diasDecorridos <= 7);
    } catch (Throwable $e) {
        error_log('[sessao.php] Falha ao validar assinatura da sessao: ' . $e->getMessage());
        // Falha na validacao deve bloquear acesso para evitar liberar usuario vencido.
        return false;
    }
}

// Processar requisi&#65533;&#65533;es AJAX
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    switch ($_GET['action']) {
        case 'check_session':
            echo json_encode([
                'logado' => estaLogado(),
                'usuario' => obterUsuarioLogado()
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'logout':
            destruirSessao();
            echo json_encode([
                'success' => true,
                'mensagem' => 'Logout realizado com sucesso'
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'keep_alive':
            // Renova a sess&#227;o sem retornar dados sens&#237;veis
            if (estaLogado()) {
                atualizarAtividade();
                echo json_encode(['logado' => true], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(401);
                echo json_encode(['logado' => false], JSON_UNESCAPED_UNICODE);
            }
            break;
            
        case 'get_user':
            if (estaLogado()) {
                echo json_encode([
                    'success' => true,
                    'usuario' => obterUsuarioLogado()
                ], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode([
                    'success' => false,
                    'mensagem' => 'Usu&#65533;rio n&#65533;o est&#65533; logado'
                ], JSON_UNESCAPED_UNICODE);
            }
            break;
            
        default:
            echo json_encode([
                'success' => false,
                'mensagem' => 'A&#65533;&#65533;o n&#65533;o reconhecida'
            ], JSON_UNESCAPED_UNICODE);
            break;
    }
    exit;
}

// Atualizar atividade automaticamente em cada requisi&#65533;&#65533;o
atualizarAtividade();
?>
