<?php
ini_set('display_errors', 0);
error_reporting(0);
ob_start();

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once '../conexao.php';

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = trim($input['action'] ?? '');

// Helper — limpa todos os buffers antes de emitir JSON
function jsonSair(array $data): void {
    while (ob_get_level() > 0) ob_end_clean();
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function enviarCodigoWhatsApp(string $telefone, string $nome, string $codigo): bool {
    try {
        $wppFile = __DIR__ . '/../whatsapp/whatsappservice.php';
        if (!file_exists($wppFile)) return false;
        require_once $wppFile;
        $wpp = new WhatsAppService();
        $msg  = "*Recuperacao de Senha - MustaBarber*\n\n";
        $msg .= "Ola *{$nome}*!\n\n";
        $msg .= "Seu codigo de verificacao e:\n\n";
        $msg .= "  *{$codigo}*\n\n";
        $msg .= "Valido por 15 minutos.\n";
        $msg .= "Se nao foi voce, ignore esta mensagem.";
        $res = $wpp->enviarMensagem($telefone, $msg);
        return !empty($res['success']);
    } catch (Exception $e) {
        return false;
    }
}

function enviarCodigoEmail(string $email, string $nome, string $codigo): bool {
    if (empty($email)) return false;
    $assunto = 'Codigo de Recuperacao de Senha - MustaBarber';
    $corpo   = "<html><body style='font-family:Arial,sans-serif;background:#0f0f0f;color:#e8e8e8;padding:30px'>
<div style='max-width:480px;margin:0 auto;background:#1a1816;border-radius:16px;padding:32px'>
  <h2 style='color:#d4a574;margin-bottom:8px'>Recuperacao de Senha</h2>
  <p>Ola <strong>{$nome}</strong>,</p>
  <p>Seu codigo de verificacao para redefinir a senha no <strong>MustaBarber</strong> e:</p>
  <div style='background:#2d2520;border-radius:12px;padding:20px;text-align:center;margin:24px 0'>
    <span style='font-size:2.5rem;font-weight:700;letter-spacing:.4rem;color:#d4a574'>{$codigo}</span>
  </div>
  <p style='color:#9ca3af;font-size:.85rem'>Valido por 15 minutos. Se nao foi voce quem solicitou, ignore este e-mail.</p>
</div>
</body></html>";
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: MustaBarber <noreply@mustabarber.com.br>\r\n";
    return @mail($email, $assunto, $corpo, $headers);
}

// Acao: solicitar_codigo
if ($action === 'solicitar_codigo') {
    try {
        $login = trim($input['login'] ?? '');
        if (empty($login)) {
            jsonSair(['sucesso' => false, 'mensagem' => 'Informe o e-mail ou telefone.']);
        }

        $loginLimpo = preg_replace('/[^0-9]/', '', $login);
        $isTelefone = strlen($loginLimpo) >= 10 && strlen($loginLimpo) <= 11;

        if ($isTelefone) {
            $stmt = $conexao->prepare("SELECT codigo, nome, email, telefone FROM usuarios WHERE telefone = :login AND status = 1 LIMIT 1");
            $stmt->bindParam(':login', $loginLimpo);
        } else {
            $stmt = $conexao->prepare("SELECT codigo, nome, email, telefone FROM usuarios WHERE email = :login AND status = 1 LIMIT 1");
            $stmt->bindParam(':login', $login);
        }
        $stmt->execute();
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$usuario) {
            jsonSair(['sucesso' => false, 'mensagem' => 'Usuario nao encontrado. Verifique o e-mail ou telefone.']);
        }

        $codigo = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiry = time() + 900;

        $_SESSION['recuperacao'] = [
            'codigo'     => $codigo,
            'usuario_id' => (int) $usuario['codigo'],
            'expiry'     => $expiry,
            'verificado' => false,
            'tentativas' => 0,
        ];

        $enviados = [];
        $telefone = preg_replace('/[^0-9]/', '', $usuario['telefone'] ?? '');
        if ($telefone && enviarCodigoWhatsApp($telefone, $usuario['nome'], $codigo)) {
            $enviados[] = 'WhatsApp';
        }
        if (!empty($usuario['email']) && enviarCodigoEmail($usuario['email'], $usuario['nome'], $codigo)) {
            $enviados[] = 'e-mail';
        }

        if (empty($enviados)) {
            error_log("[recuperar_senha] Nenhum canal enviou codigo para usuario {$usuario['codigo']} - codigo: {$codigo}");
        }

        $emailMascarado    = '';
        $telefoneMascarado = '';
        if (!empty($usuario['email'])) {
            $partes = explode('@', $usuario['email']);
            $emailMascarado = substr($partes[0], 0, 2) . '***@' . ($partes[1] ?? '');
        }
        if ($telefone) {
            $telefoneMascarado = substr($telefone, 0, 2) . '****' . substr($telefone, -2);
        }

        $canalMsg = empty($enviados)
            ? 'Codigo gerado. Contate o suporte se nao receber a mensagem.'
            : 'Codigo enviado via ' . implode(' e ', $enviados) . '.';

        jsonSair([
            'sucesso'   => true,
            'mensagem'  => $canalMsg,
            'email'     => $emailMascarado,
            'telefone'  => $telefoneMascarado,
            'canais'    => $enviados,
        ]);
    } catch (Exception $e) {
        jsonSair(['sucesso' => false, 'mensagem' => 'Erro interno: ' . $e->getMessage()]);
    }
}

// Acao: verificar_codigo
if ($action === 'verificar_codigo') {
    try {
        $codigo = trim($input['codigo'] ?? '');

        if (empty($_SESSION['recuperacao'])) {
            jsonSair(['sucesso' => false, 'mensagem' => 'Sessao expirada. Solicite um novo codigo.']);
        }

        $rec = &$_SESSION['recuperacao'];

        if (time() > $rec['expiry']) {
            unset($_SESSION['recuperacao']);
            jsonSair(['sucesso' => false, 'mensagem' => 'Codigo expirado. Solicite um novo.']);
        }

        if ($rec['tentativas'] >= 5) {
            unset($_SESSION['recuperacao']);
            jsonSair(['sucesso' => false, 'mensagem' => 'Muitas tentativas. Solicite um novo codigo.']);
        }

        if ($codigo !== $rec['codigo']) {
            $rec['tentativas']++;
            $restantes = 5 - $rec['tentativas'];
            jsonSair(['sucesso' => false, 'mensagem' => "Codigo incorreto. {$restantes} tentativa(s) restante(s)."]);
        }

        $rec['verificado'] = true;
        jsonSair(['sucesso' => true, 'mensagem' => 'Codigo verificado com sucesso!']);
    } catch (Exception $e) {
        jsonSair(['sucesso' => false, 'mensagem' => 'Erro interno: ' . $e->getMessage()]);
    }
}

// Acao: alterar_senha
if ($action === 'alterar_senha') {
    try {
        if (empty($_SESSION['recuperacao']) || empty($_SESSION['recuperacao']['verificado'])) {
            jsonSair(['sucesso' => false, 'mensagem' => 'Codigo nao verificado. Reinicie o processo.']);
        }

        $rec = $_SESSION['recuperacao'];

        if (time() > $rec['expiry']) {
            unset($_SESSION['recuperacao']);
            jsonSair(['sucesso' => false, 'mensagem' => 'Sessao expirada. Reinicie o processo.']);
        }

        $novaSenha = $input['nova_senha']      ?? '';
        $confirmar = $input['confirmar_senha'] ?? '';

        if (empty($novaSenha) || empty($confirmar)) {
            jsonSair(['sucesso' => false, 'mensagem' => 'Preencha todos os campos.']);
        }
        if ($novaSenha !== $confirmar) {
            jsonSair(['sucesso' => false, 'mensagem' => 'As senhas nao coincidem.']);
        }
        if (strlen($novaSenha) < 6) {
            jsonSair(['sucesso' => false, 'mensagem' => 'A senha deve ter pelo menos 6 caracteres.']);
        }

        $hash   = password_hash($novaSenha, PASSWORD_BCRYPT);
        $userId = (int) $rec['usuario_id'];

        $stmt = $conexao->prepare("UPDATE usuarios SET senha = :senha WHERE codigo = :codigo");
        $stmt->bindParam(':senha',  $hash);
        $stmt->bindParam(':codigo', $userId, PDO::PARAM_INT);
        $stmt->execute();

        unset($_SESSION['recuperacao']);
        jsonSair(['sucesso' => true, 'mensagem' => 'Senha alterada com sucesso! Faca login.']);
    } catch (Exception $e) {
        jsonSair(['sucesso' => false, 'mensagem' => 'Erro interno: ' . $e->getMessage()]);
    }
}

jsonSair(['sucesso' => false, 'mensagem' => 'Acao invalida.']);