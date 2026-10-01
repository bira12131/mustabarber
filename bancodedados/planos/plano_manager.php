<?php
/**
 * Gerenciador de Controle de Acesso por Plano
 * Sistema: MustaBarber
 * Data: 25/04/2026
 *
 * Controla o acesso aos módulos do sistema conforme o plano da assinatura
 * ativa do usuário. Perfil 1 (Administrador) tem acesso irrestrito a tudo.
 *
 * Hierarquia de planos (codigo_perfil na tabela perfil):
 *   2 = Solo Básico  (Starter)       – R$  52,90 – 1 barbeiro
 *   3 = Solo Pro     (Essencial)     – R$  64,90 – 1 barbeiro
 *   4 = Equipe                       – R$  94,90 – até 3 barbeiros
 *   6 = Estabelecimento (Premium)    – R$ 136,90 – até 8 barbeiros
 *   7 = Master                       – R$ 199,90 – até 12 barbeiros
 */

// ---------------------------------------------------------------------------
//  Constantes de plano (mapeadas ao campo codigo na tabela perfil)
// ---------------------------------------------------------------------------
if (!defined('PLANO_SOLO_BASICO'))     define('PLANO_SOLO_BASICO',     2);
if (!defined('PLANO_SOLO_PRO'))        define('PLANO_SOLO_PRO',        3);
if (!defined('PLANO_EQUIPE'))          define('PLANO_EQUIPE',          4);
if (!defined('PLANO_ESTABELECIMENTO')) define('PLANO_ESTABELECIMENTO', 6);
if (!defined('PLANO_MASTER'))          define('PLANO_MASTER',          7);
if (!defined('PLANO_BLOQUEADO'))       define('PLANO_BLOQUEADO',       0);

// ---------------------------------------------------------------------------
// Máximo de barbeiros (funcionários) permitidos por plano
// ---------------------------------------------------------------------------
function getMaxBarbeirosPorPlano(int $plano): int
{
    $mapa = [
        1                     => 12, // Administrador — mesmo limite do plano Master
        PLANO_BLOQUEADO       => 0,
        PLANO_SOLO_BASICO     => 1,
        PLANO_SOLO_PRO        => 1,
        PLANO_EQUIPE          => 3,
        PLANO_ESTABELECIMENTO => 8,
        PLANO_MASTER          => 12,
    ];
    return $mapa[$plano] ?? 1;
}

// ---------------------------------------------------------------------------
// Plano mínimo exigido por recurso/módulo
// ---------------------------------------------------------------------------
function getPlanoMinimoRecurso(string $recurso): int
{
    $mapa = [
        // Disponível em todos os planos
        'dashboard'            => PLANO_SOLO_BASICO,
        'agendamentos'         => PLANO_SOLO_BASICO,
        'clientes'             => PLANO_SOLO_BASICO,
        'servicos'             => PLANO_SOLO_BASICO,
        'horarios'             => PLANO_SOLO_BASICO,

        // A partir do Solo Pro (Essencial)
        'financeiro'           => PLANO_SOLO_PRO,
        'produtos'             => PLANO_SOLO_PRO,
        'whatsapp'             => PLANO_SOLO_PRO,
        'solicitacoes_plano'   => PLANO_SOLO_PRO,

        // A partir do Equipe
        'funcionarios'         => PLANO_EQUIPE,
        'relatorio_barbeiro'   => PLANO_EQUIPE,
        'permissoes_perfil'    => PLANO_EQUIPE,

        // A partir do Estabelecimento (Premium)
        'relatorios_avancados' => PLANO_ESTABELECIMENTO,

        // Exclusivo do Master
        'multiplas_unidades'   => PLANO_MASTER,
        'gerente_unidade'      => PLANO_MASTER,
    ];
    return $mapa[$recurso] ?? PLANO_SOLO_BASICO;
}

// ---------------------------------------------------------------------------
// Verifica se o usuario esta alocado em uma barbearia (membro de equipe).
// ---------------------------------------------------------------------------
function usuarioEstaAlocadoEmBarbearia(PDO $conexao, int $codigoUsuario): bool
{
    static $cacheAlocacao = [];

    if (isset($cacheAlocacao[$codigoUsuario])) {
        return $cacheAlocacao[$codigoUsuario];
    }

    try {
        $stmt = $conexao->prepare(
            "SELECT 1
             FROM usuarios
             WHERE codigo = :codigo
               AND codigo_barbearia IS NOT NULL
             LIMIT 1"
        );
        $stmt->bindParam(':codigo', $codigoUsuario, PDO::PARAM_INT);
        $stmt->execute();

        $cacheAlocacao[$codigoUsuario] = (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        // Coluna codigo_barbearia pode nao existir em bancos antigos.
        $cacheAlocacao[$codigoUsuario] = false;
    }

    return $cacheAlocacao[$codigoUsuario];
}

// ---------------------------------------------------------------------------
// Nomes de exibição dos planos
// ---------------------------------------------------------------------------
function getNomePlano(int $codigoPlano): string
{
    $nomes = [
        1                     => 'Administrador',
        PLANO_BLOQUEADO       => 'Sem Assinatura Ativa',
        PLANO_SOLO_BASICO     => 'Solo Básico (Starter)',
        PLANO_SOLO_PRO        => 'Solo Pro (Essencial)',
        PLANO_EQUIPE          => 'Equipe',
        PLANO_ESTABELECIMENTO => 'Estabelecimento (Premium)',
        PLANO_MASTER          => 'Master',
    ];
    return $nomes[$codigoPlano] ?? 'Desconhecido';
}

// ---------------------------------------------------------------------------
// Resolve o usuário titular do plano para validação de recursos.
// Se o usuário estiver vinculado a uma barbearia (equipe), herda o plano
// do dono da barbearia. Caso contrário, usa o próprio usuário.
// ---------------------------------------------------------------------------
function obterCodigoTitularPlano(PDO $conexao, int $codigoUsuario): int
{
    static $cacheTitular = [];

    if (isset($cacheTitular[$codigoUsuario])) {
        return $cacheTitular[$codigoUsuario];
    }

    $titular = $codigoUsuario;

    try {
        $stmtDono = $conexao->prepare(
            "SELECT codigo FROM barbearia WHERE codigo_usuario = :codigo LIMIT 1"
        );
        $stmtDono->bindParam(':codigo', $codigoUsuario, PDO::PARAM_INT);
        $stmtDono->execute();

        if ($stmtDono->fetch(PDO::FETCH_ASSOC)) {
            $cacheTitular[$codigoUsuario] = $codigoUsuario;
            return $codigoUsuario;
        }
    } catch (Exception $e) {
        // Tabela pode ainda nao existir em algum ambiente.
    }

    try {
        $stmtEquipe = $conexao->prepare(
            "SELECT b.codigo_usuario AS codigo_titular
             FROM usuarios u
             INNER JOIN barbearia b ON b.codigo = u.codigo_barbearia
             WHERE u.codigo = :codigo
               AND u.codigo_barbearia IS NOT NULL
             LIMIT 1"
        );
        $stmtEquipe->bindParam(':codigo', $codigoUsuario, PDO::PARAM_INT);
        $stmtEquipe->execute();
        $rowEquipe = $stmtEquipe->fetch(PDO::FETCH_ASSOC);

        if ($rowEquipe && !empty($rowEquipe['codigo_titular'])) {
            $titular = (int)$rowEquipe['codigo_titular'];
        }
    } catch (Exception $e) {
        // Coluna codigo_barbearia pode nao existir em bancos antigos.
    }

    $cacheTitular[$codigoUsuario] = $titular;
    return $titular;
}

// ---------------------------------------------------------------------------
// Obtém o código do plano ativo do usuário
//
// Ordem de verificação:
//   1. Assinatura com data_vencimento >= hoje
//   2. Isenção de pagamento (isento_pagamento = true) → usa perfil do usuário
//   3. Período de trial (7 dias desde data_cadastro) → usa perfil do usuário
//   4. Sem nenhuma das condições acima → PLANO_BLOQUEADO (acesso bloqueado)
// ---------------------------------------------------------------------------
function obterPlanoAtivo(PDO $conexao, int $codigoUsuario): int
{
    static $cache = [];

    if (isset($cache[$codigoUsuario])) {
        return $cache[$codigoUsuario];
    }

    $codigoTitular = obterCodigoTitularPlano($conexao, $codigoUsuario);
    if ($codigoTitular !== $codigoUsuario && isset($cache[$codigoTitular])) {
        $cache[$codigoUsuario] = $cache[$codigoTitular];
        return $cache[$codigoUsuario];
    }

    // 1. Assinatura paga e dentro do prazo
    // Bloco isolado: se a tabela/coluna não existir no banco, continua para isenção/trial
    try {
        $stmtAssin = $conexao->prepare(
            "SELECT a.codigo_perfil
             FROM assinaturas a
             WHERE a.codigo_barbeiro = :codigo
               AND a.data_vencimento >= CURRENT_DATE
             ORDER BY a.codigo DESC
             LIMIT 1"
        );
        $stmtAssin->bindParam(':codigo', $codigoTitular, PDO::PARAM_INT);
        $stmtAssin->execute();
        $assinatura = $stmtAssin->fetch(PDO::FETCH_ASSOC);

        if ($assinatura) {
            $plano = intval($assinatura['codigo_perfil']);
            $planosValidos = [PLANO_SOLO_BASICO, PLANO_SOLO_PRO, PLANO_EQUIPE, PLANO_ESTABELECIMENTO, PLANO_MASTER];
            if (in_array($plano, $planosValidos)) {
                if ($codigoTitular !== $codigoUsuario) {
                    $cache[$codigoTitular] = $plano;
                }
                $cache[$codigoUsuario] = $plano;
                return $plano;
            }
        }
    } catch (Exception $e) {
        // Coluna/tabela pode não existir no banco ao vivo — segue para verificar isenção
        error_log('[PlanoManager] Aviso: falha ao verificar assinatura (usuario=' . $codigoTitular . '): ' . $e->getMessage());
    }

    // 2/3. Isenção ou trial — usa o perfil do usuário diretamente como fonte de verdade
    try {
        $stmtUser = $conexao->prepare(
            "SELECT data_cadastro,
                    COALESCE(isento_pagamento, FALSE) AS isento_pagamento,
                    perfil
             FROM usuarios
             WHERE codigo = :codigo
             LIMIT 1"
        );
        $stmtUser->bindParam(':codigo', $codigoTitular, PDO::PARAM_INT);
        $stmtUser->execute();
        $dadosUser = $stmtUser->fetch(PDO::FETCH_ASSOC);

        if (!$dadosUser) {
            $cache[$codigoUsuario] = PLANO_BLOQUEADO;
            return PLANO_BLOQUEADO;
        }

        $isento = ($dadosUser['isento_pagamento'] === true || $dadosUser['isento_pagamento'] === 't');

        // Obtém o plano diretamente do perfil do usuário
        $perfilAtual  = intval($dadosUser['perfil']);
        $planosValidos = [PLANO_SOLO_BASICO, PLANO_SOLO_PRO, PLANO_EQUIPE, PLANO_ESTABELECIMENTO, PLANO_MASTER];
        // Se perfil não é um código de plano válido (ex: 1=Admin, 5=Cliente), assume Solo Pro
        $planoDoUsuario = in_array($perfilAtual, $planosValidos) ? $perfilAtual : PLANO_SOLO_PRO;

        if ($isento) {
            if ($codigoTitular !== $codigoUsuario) {
                $cache[$codigoTitular] = $planoDoUsuario;
            }
            $cache[$codigoUsuario] = $planoDoUsuario;
            return $planoDoUsuario;
        }

        // Verificar trial de 7 dias
        $dataCadastro   = new DateTime($dadosUser['data_cadastro']);
        $hoje           = new DateTime('today');
        $dataCadastro->setTime(0, 0, 0);
        $diasDecorridos = (int)$hoje->diff($dataCadastro)->days;

        if ($dataCadastro > $hoje || $diasDecorridos <= 7) {
            if ($codigoTitular !== $codigoUsuario) {
                $cache[$codigoTitular] = $planoDoUsuario;
            }
            $cache[$codigoUsuario] = $planoDoUsuario;
            return $planoDoUsuario;
        }

        // 4. Sem plano ativo
        $cache[$codigoUsuario] = PLANO_BLOQUEADO;
        return PLANO_BLOQUEADO;

    } catch (Exception $e) {
        error_log('[PlanoManager] Erro ao verificar isenção/trial (usuario=' . $codigoTitular . '): ' . $e->getMessage());
        return PLANO_BLOQUEADO;
    }
}

// ---------------------------------------------------------------------------
// Verifica se o usuário tem acesso ao recurso.
// Administradores (perfilUsuario = 1) sempre têm acesso irrestrito.
// ---------------------------------------------------------------------------
function temAcessoRecurso(PDO $conexao, int $codigoUsuario, int $perfilUsuario, string $recurso): bool
{
    if ($perfilUsuario === 1) {
        return true;
    }

    // Excecao solicitada de negocio:
    // Solo Basico alocado em barbearia pode acessar WhatsApp e Solicitacoes de Plano.
    if (
        $perfilUsuario === PLANO_SOLO_BASICO
        && in_array($recurso, ['whatsapp', 'solicitacoes_plano'], true)
        && usuarioEstaAlocadoEmBarbearia($conexao, $codigoUsuario)
    ) {
        return true;
    }

    $planoMinimo = getPlanoMinimoRecurso($recurso);
    $planoAtivo  = obterPlanoAtivo($conexao, $codigoUsuario);

    if ($planoAtivo === PLANO_BLOQUEADO) {
        return false;
    }

    return $planoAtivo >= $planoMinimo;
}

// ---------------------------------------------------------------------------
// Bloqueia a requisição com HTTP 403 se o usuário não tiver o plano necessário.
// Deve ser chamado APÓS verificar autenticação ($perfilUsuario e $codigoUsuario válidos).
// Encerra a execução do script quando bloqueia.
// ---------------------------------------------------------------------------
function exigirPlanoOuBloquear(PDO $conexao, int $codigoUsuario, int $perfilUsuario, string $recurso): void
{
    if (!temAcessoRecurso($conexao, $codigoUsuario, $perfilUsuario, $recurso)) {
        $planoMinimo = getPlanoMinimoRecurso($recurso);
        $planoAtivo  = obterPlanoAtivo($conexao, $codigoUsuario);
        $nomeRecurso = ucwords(str_replace('_', ' ', $recurso));

        http_response_code(403);
        echo json_encode([
            'sucesso'           => false,
            'bloqueado_plano'   => true,
            'mensagem'          => "O módulo \"$nomeRecurso\" não está disponível no plano " . getNomePlano($planoAtivo) . ". Faça upgrade para o plano " . getNomePlano($planoMinimo) . " ou superior para ter acesso.",
            'plano_atual'       => $planoAtivo,
            'plano_atual_nome'  => getNomePlano($planoAtivo),
            'plano_minimo'      => $planoMinimo,
            'plano_minimo_nome' => getNomePlano($planoMinimo),
            'recurso'           => $recurso,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
