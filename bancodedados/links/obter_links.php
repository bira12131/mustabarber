<?php
ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../usuarios/sessao.php';

function base64urlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base62Encode(int $num): string
{
    $alfabeto = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    if ($num <= 0) {
        return '0';
    }

    $out = '';
    while ($num > 0) {
        $resto = $num % 62;
        $out = $alfabeto[$resto] . $out;
        $num = intdiv($num, 62);
    }

    return $out;
}

function obterSegredoLinks(): string
{
    $candidatos = [
        getenv('MB_LINK_SECRET') ?: '',
        getenv('APP_KEY') ?: '',
        $_ENV['MB_LINK_SECRET'] ?? '',
        $_SERVER['MB_LINK_SECRET'] ?? ''
    ];

    foreach ($candidatos as $valor) {
        $valor = trim((string)$valor);
        if ($valor !== '') {
            return $valor;
        }
    }

    // Fallback para ambiente sem configuracao explicita.
    return hash('sha256', 'musta-links-default-v1');
}

function gerarCodigoCurtoLink(string $tipo, int $codigoUsuario): string
{
    $tiposPermitidos = ['a', 'p'];
    if (!in_array($tipo, $tiposPermitidos, true)) {
        throw new InvalidArgumentException('Tipo de link curto invalido.');
    }

    $segredo = obterSegredoLinks();
    $chave = (int)sprintf('%u', crc32('uid|' . $segredo));

    $obfuscado = (($codigoUsuario ^ $chave) & 0x7fffffff);
    $miolo = $tipo . base62Encode($obfuscado);

    $crc = (int)sprintf('%u', crc32($miolo . '|' . $segredo));
    $check = base62Encode($crc % (62 * 62));
    $check = str_pad($check, 2, '0', STR_PAD_LEFT);
    $check = substr($check, -2);

    return $miolo . $check;
}

function gerarTokenLink(string $tipo, int $codigoUsuario): string
{
    $payload = [
        't' => $tipo,
        'u' => $codigoUsuario,
        'e' => time() + (86400 * 365 * 2) // 2 anos
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $payloadEncoded = base64urlEncode($json ?: '{}');
    $assinatura = hash_hmac('sha256', $payloadEncoded, obterSegredoLinks(), true);
    $assinaturaEncoded = base64urlEncode($assinatura);

    return $payloadEncoded . '.' . $assinaturaEncoded;
}

function gerarSlugUnico(PDO $conexao, int $tentativas = 30): string
{
    $alfabeto = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $max = strlen($alfabeto) - 1;

    for ($i = 0; $i < $tentativas; $i++) {
        $slug = '';
        for ($j = 0; $j < 8; $j++) {
            $slug .= $alfabeto[random_int(0, $max)];
        }

        $stmt = $conexao->prepare('SELECT 1 FROM usuarios WHERE referral_slug = :slug LIMIT 1');
        $stmt->execute([':slug' => $slug]);
        if (!$stmt->fetchColumn()) {
            return $slug;
        }
    }

    throw new RuntimeException('Nao foi possivel gerar slug unico.');
}

try {
    require_once __DIR__ . '/../conexao.php';
    require_once __DIR__ . '/../planos/plano_manager.php';

    if (!estaLogado()) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Usuario nao autenticado'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $usuario = obterUsuarioLogado();
    $codigoUsuario = (int)($usuario['codigo'] ?? 0);
    $perfilUsuario = (int)($usuario['perfil'] ?? 0);

    if ($codigoUsuario <= 0) {
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Usuario invalido'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
    $appRoot = ($pos = strpos($scriptPath, '/bancodedados/')) !== false
        ? substr($scriptPath, 0, $pos)
        : '';

    $baseUrl = $protocol . '://' . $host . $appRoot;

    // Verificar se colunas de embaixador existem
    $stmtCols = $conexao->prepare("SELECT column_name
                                   FROM information_schema.columns
                                   WHERE table_name = 'usuarios'
                                     AND column_name IN ('embaixador', 'referral_slug')");
    $stmtCols->execute();
    $colunas = array_map('strtolower', $stmtCols->fetchAll(PDO::FETCH_COLUMN) ?: []);

    $temEmbaixador = in_array('embaixador', $colunas, true);
    $temReferralSlug = in_array('referral_slug', $colunas, true);

    $ehEmbaixador = false;
    $referralSlug = null;

    // Regras de acesso por plano/perfil iguais ao sistema principal.
    $acessoSolicitacoesPlano = ($perfilUsuario === 1);
    try {
        $acessoSolicitacoesPlano = temAcessoRecurso(
            $conexao,
            (int)$codigoUsuario,
            (int)$perfilUsuario,
            'solicitacoes_plano'
        );
    } catch (Throwable $e) {
        // Mantem fallback de admin em caso de erro.
    }

    if ($temEmbaixador || $temReferralSlug) {
        $campos = [];
        if ($temEmbaixador) {
            $campos[] = 'embaixador';
        }
        if ($temReferralSlug) {
            $campos[] = 'referral_slug';
        }

        $sqlUser = 'SELECT ' . implode(', ', $campos) . ' FROM usuarios WHERE codigo = :cod LIMIT 1';
        $stmtUser = $conexao->prepare($sqlUser);
        $stmtUser->execute([':cod' => $codigoUsuario]);
        $row = $stmtUser->fetch(PDO::FETCH_ASSOC) ?: [];

        if ($temEmbaixador) {
            $valorEmb = $row['embaixador'] ?? false;
            $ehEmbaixador = ($valorEmb === true || $valorEmb === 't' || $valorEmb === 1 || $valorEmb === '1');
        }

        if ($temReferralSlug) {
            $referralSlug = trim((string)($row['referral_slug'] ?? ''));
        }

        if ($ehEmbaixador && $temReferralSlug && $referralSlug === '') {
            $referralSlug = gerarSlugUnico($conexao);
            $stmtUp = $conexao->prepare('UPDATE usuarios SET referral_slug = :slug WHERE codigo = :cod');
            $stmtUp->execute([':slug' => $referralSlug, ':cod' => $codigoUsuario]);
        }
    }

    $linkAgendamentoLongo = $baseUrl . '/paginas/agendamentoclientes/agendar.html?barbeiro=' . $codigoUsuario;
    $linkPlanoLongo = $baseUrl . '/paginas/agendamentoclientes/solicitar-plano.html?barbeiro=' . $codigoUsuario;

    $codigoCurtoAgendamento = gerarCodigoCurtoLink('a', $codigoUsuario);
    $codigoCurtoPlano = gerarCodigoCurtoLink('p', $codigoUsuario);

    $linkAgendamentoCurto = $baseUrl . '/go.php?c=' . rawurlencode($codigoCurtoAgendamento);
    $linkPlanoCurto = $baseUrl . '/go.php?c=' . rawurlencode($codigoCurtoPlano);

    $links = [
        [
            'id' => 'agendamento_online',
            'titulo' => 'Agendamento Online',
            'descricao' => 'Envie para o cliente agendar servicos diretamente.',
            'long_url' => $linkAgendamentoLongo,
            'short_url' => $linkAgendamentoCurto,
            'permite_encurtar' => true
        ]
    ];

    if ($acessoSolicitacoesPlano) {
        $links[] = [
            'id' => 'solicitacao_plano',
            'titulo' => 'Solicitacao de Plano',
            'descricao' => 'Cliente solicita plano com horarios e servicos.',
            'long_url' => $linkPlanoLongo,
            'short_url' => $linkPlanoCurto,
            'permite_encurtar' => true
        ];
    }

    if ($ehEmbaixador && !empty($referralSlug)) {
        $linkReferralLongo = $baseUrl . '/paginas/usuarios/cadastro-barbeiro.html?ref=' . urlencode($referralSlug);
        $linkReferralCurto = $baseUrl . '/go.php?t=r&s=' . urlencode($referralSlug);

        $links[] = [
            'id' => 'indicacao',
            'titulo' => 'Link de Indicacao',
            'descricao' => 'Compartilhe para novos barbeiros se cadastrarem pelo seu codigo.',
            'long_url' => $linkReferralLongo,
            'short_url' => $linkReferralCurto,
            'permite_encurtar' => true
        ];
    }

    echo json_encode([
        'sucesso' => true,
        'links' => $links,
        'usuario' => [
            'codigo' => $codigoUsuario,
            'nome' => (string)($usuario['nome'] ?? ''),
            'primeiro_nome' => (string)($usuario['primeiro_nome'] ?? '')
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao montar links: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
