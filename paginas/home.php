<?php
/**
 * P&aacute;gina Principal do Sistema - Dashboard
 * Sistema: MustaBarber
 * Data: 28/11/2025
 */

header('X-Frame-Options: SAMEORIGIN');

// Incluir sistema de sess&atilde;o
require_once '../bancodedados/usuarios/sessao.php';

// Verificar se usu&aacute;rio est&aacute; logado (redireciona automaticamente se n&atilde;o estiver)
requererLogin('../login.html');

// Obter dados do usu&aacute;rio logado
$usuario = obterUsuarioLogado();

// Dados do usu&aacute;rio para exibi&ccedil;&atilde;o
$nomeUsuario = $usuario['nome'] ?? 'Usu&aacute;rio';
$emailUsuario = $usuario['email'] ?? '';
$codigoUsuario = $usuario['codigo'] ?? 0;
$perfilUsuario = $usuario['perfil'] ?? 0;

// Extrair primeira letra do nome para o avatar
$primeiraLetra = strtoupper(substr($nomeUsuario, 0, 1));

// Obter apenas o primeiro nome para exibi&ccedil;&atilde;o mais limpa
$primeiroNome = explode(' ', $nomeUsuario)[0];

// Verificacao de assinatura para perfis com cobranca
$assinaturaVencida   = false;
$dadosAssinaturaPHP  = null;
$isentoPagamento     = false;
$planoPremium        = false;
$semAssinaturaAtiva  = false;
$assinaturaProxVencer = false;
$diasProxVencer       = 0;

if ($perfilUsuario !== 1 && $perfilUsuario !== 5) {
    try {
        require_once '../bancodedados/conexao.php';

        // Verificar se � isento de pagamento (5 indica��es convertidas e ativas)
        $stmtIsento = $conexao->prepare(
            "SELECT COALESCE(isento_pagamento, FALSE) AS isento_pagamento,
                    data_cadastro,
                    dia_vencimento
             FROM usuarios WHERE codigo = :cod LIMIT 1"
        );
        $stmtIsento->bindParam(':cod', $codigoUsuario, PDO::PARAM_INT);
        $stmtIsento->execute();
        $dadosIsento = $stmtIsento->fetch(PDO::FETCH_ASSOC);
        if ($dadosIsento) {
            $isentoPagamento = ($dadosIsento['isento_pagamento'] === true || $dadosIsento['isento_pagamento'] === 't');
        }

        // Se não está marcado, verificar se tem 5 indicados ativos e promover automaticamente
        if (!$isentoPagamento) {
            $stmtCheckInd = $conexao->prepare(
                "SELECT COUNT(*) AS total
                 FROM indicacoes i
                 INNER JOIN usuarios u ON u.codigo = i.codigo_indicado
                 WHERE i.codigo_indicador = :cod
                   AND i.convertido = TRUE
                   AND u.status = 1"
            );
            $stmtCheckInd->bindParam(':cod', $codigoUsuario, PDO::PARAM_INT);
            $stmtCheckInd->execute();
            $checkInd = $stmtCheckInd->fetch(PDO::FETCH_ASSOC);
            if ((int) $checkInd['total'] >= 5) {
                $isentoPagamento = true;
                $conexao->prepare("UPDATE usuarios SET isento_pagamento = TRUE WHERE codigo = :cod AND isento_pagamento = FALSE")
                    ->execute([':cod' => $codigoUsuario]);
            }
        }
        // Isenção já concedida (manualmente pelo admin ou por indicações) — não revogar automaticamente no login

        // Plano derivado diretamente do perfil (fonte de verdade)
        // perfil >= 3 equivale a Solo Pro ou superior (antes era "premium")
        $planoPremium = ($perfilUsuario >= 3);

        $stmtAssin = $conexao->prepare(
            "SELECT status_pagamento, data_vencimento, valor_mensalidade
             FROM assinaturas
             WHERE codigo_barbeiro = :cod
             ORDER BY codigo DESC LIMIT 1"
        );
        $stmtAssin->bindParam(':cod', $codigoUsuario, PDO::PARAM_INT);
        $stmtAssin->execute();
        $dadosAssinaturaPHP = $stmtAssin->fetch(PDO::FETCH_ASSOC);

        if ($dadosAssinaturaPHP && !$isentoPagamento) {
            $hoje      = new DateTime('today');
            $venc      = new DateTime($dadosAssinaturaPHP['data_vencimento']);

            // diasParaVencer > 0 = dias restantes; <= 0 = dias desde o vencimento
            $diasParaVencer = (int)$hoje->diff($venc)->days * ($venc >= $hoje ? 1 : -1);

          // Bloqueia apenas quando a data de vencimento passou.
          if ($venc < $hoje) {
            $assinaturaVencida = true;
          }

            // Aviso: vence em breve (0-2 dias, somente se status pago)
          if ($diasParaVencer >= 0 && $diasParaVencer <= 2) {
                $assinaturaProxVencer = true;
                $diasProxVencer = $diasParaVencer;
            }
        } else {
          // Sem assinatura nenhuma: bloquear assim que o trial de 7 dias expirar
            if (!$isentoPagamento) {
                $dataCadastroStr = $dadosIsento['data_cadastro'] ?? null;
                if ($dataCadastroStr) {
                    $dataCad   = new DateTime($dataCadastroStr);
                    $hojeT     = new DateTime('today');
              $dataCad->setTime(0, 0, 0);
              $diasDecorridos = (int)$hojeT->diff($dataCad)->days;

              if ($dataCad <= $hojeT && $diasDecorridos > 7) {
                $semAssinaturaAtiva = true;
              }
                } else {
                    // Sem data de cadastro: bloquear por seguran�a
                    $semAssinaturaAtiva = true;
                }
            }
        }
    } catch (Exception $e) {
        error_log('[home.php] Erro ao verificar assinatura: ' . $e->getMessage());
    }
}

// Verifica��o "vence em breve" para planos premium (perfil >= 3: Solo Pro, Equipe, etc.)
if (!$assinaturaProxVencer && $perfilUsuario >= 3) {
    try {
        if (!isset($conexao)) require_once '../bancodedados/conexao.php';
        $stmtAv = $conexao->prepare(
            "SELECT status_pagamento, data_vencimento
             FROM assinaturas
             WHERE codigo_barbeiro = :cod
             ORDER BY codigo DESC LIMIT 1"
        );
        $stmtAv->bindParam(':cod', $codigoUsuario, PDO::PARAM_INT);
        $stmtAv->execute();
        $dadosAv = $stmtAv->fetch(PDO::FETCH_ASSOC);
        if ($dadosAv) {
            $hojeAv  = new DateTime('today');
            $vencAv  = new DateTime($dadosAv['data_vencimento']);
            $diasAv  = (int)$hojeAv->diff($vencAv)->days * ($vencAv >= $hojeAv ? 1 : -1);
            if ((int)$dadosAv['status_pagamento'] === 2 && $diasAv >= 0 && $diasAv <= 2) {
                $assinaturaProxVencer = true;
                $diasProxVencer       = $diasAv;
            }
        }
    } catch (Exception $e) {
        error_log('[home.php] Erro ao verificar assinatura prox vencer: ' . $e->getMessage());
    }
}

// Contar notifica��es pendentes de convites de barbearia
$qtdNotificacoes = 0;
try {
    if (!isset($conexao)) require_once '../bancodedados/conexao.php';
    $stmtNotif = $conexao->prepare(
        "SELECT COUNT(*) FROM convites WHERE codigo_usuario_convidado = :cod AND status = 'pendente'"
    );
    $stmtNotif->bindParam(':cod', $codigoUsuario, PDO::PARAM_INT);
    $stmtNotif->execute();
    $qtdNotificacoes = (int)$stmtNotif->fetchColumn();
} catch (Exception $e) {
    $qtdNotificacoes = 0; // Tabela pode n�o existir ainda
}

// Verificar se o usu�rio � dono de uma barbearia
$ehDonoBarbearia = false;
try {
    if (!isset($conexao)) require_once '../bancodedados/conexao.php';
    $stmtDono = $conexao->prepare(
        "SELECT 1 FROM barbearia WHERE codigo_usuario = :cod LIMIT 1"
    );
    $stmtDono->bindParam(':cod', $codigoUsuario, PDO::PARAM_INT);
    $stmtDono->execute();
    $ehDonoBarbearia = (bool)$stmtDono->fetchColumn();
} catch (Exception $e) {
    $ehDonoBarbearia = false;
}

// Controle de acesso por plano para modulos com regra especial.
$acessoWhatsapp = ($perfilUsuario === 1);
$acessoSolicitacoesPlano = ($perfilUsuario === 1);
try {
  if (!isset($conexao)) require_once '../bancodedados/conexao.php';
  require_once '../bancodedados/planos/plano_manager.php';
  $acessoWhatsapp = temAcessoRecurso($conexao, (int)$codigoUsuario, (int)$perfilUsuario, 'whatsapp');
  $acessoSolicitacoesPlano = temAcessoRecurso($conexao, (int)$codigoUsuario, (int)$perfilUsuario, 'solicitacoes_plano');
} catch (Exception $e) {
  error_log('[home.php] Erro ao verificar acesso por plano: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <meta http-equiv="X-Content-Type-Options" content="nosniff" />
  <meta http-equiv="Referrer-Policy" content="strict-origin-when-cross-origin" />
  
  <meta name="theme-color" content="#000000">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="MustaBarber">
  <link rel="icon" type="image/png" href="../uploads/logo/logo.png">
  <link rel="apple-touch-icon" href="../uploads/logo/logo.png">
  <link rel="manifest" href="../manifest.json">
  
  <title>MustaBarber - Sistema de Gerenciamento | Dashboard</title>
  
  <!-- DNS Prefetch e Preconnect para recursos externos -->
  <link rel="dns-prefetch" href="https://fonts.googleapis.com" />
  <link rel="dns-prefetch" href="https://fonts.gstatic.com" />
  <link rel="dns-prefetch" href="https://cdnjs.cloudflare.com" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link rel="preconnect" href="https://cdnjs.cloudflare.com" />
  
  <!-- Fontes e &#65533;cones com display swap para carregamento mais r&#65533;pido -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet" />
  
  <!-- Prefetch da p&#65533;gina dashboard para carregamento instant&#65533;neo -->
  <link rel="prefetch" href="dashboard/dashboard.html" as="document" />
  <script>
    window.MB_LEGAL_BASE_PATH = '../';
  </script>
  <script src="../assets/lgpd.js"></script>

  <style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{
      font-family:'Inter',sans-serif;
      background:linear-gradient(135deg,#1f1f1f 0%,#141414 100%);
      color:#e8e8e8;min-height:100vh;overflow-x:hidden
    }
    .container{display:flex;min-height:100vh}
    .sidebar{
      width:280px;background:rgba(25,25,28,.95);
      backdrop-filter:blur(10px);
      border-right:1px solid rgba(255,255,255,.15);
      padding:2rem 0;position:fixed;height:100vh;
      left:0;top:0;z-index:1000;overflow-y:auto;
      transition:transform 0.3s ease-in-out;
    }
    .sidebar.collapsed{
      transform:translateX(-100%);
    }
    .logo-section{text-align:center;margin-bottom:3rem}
    .logo{font-size:2rem;font-weight:700;background:linear-gradient(135deg,#f0f0f0,#d0d0d0);
      -webkit-background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:.5rem}
    .subtitle{font-size:.9rem;color: #e8e8e8;text-transform:uppercase;letter-spacing:2px}
    .nav-menu{list-style:none;padding:0 1rem}
    .nav-item{margin-bottom:.5rem}
    .nav-link{
      display:flex;align-items:center;padding:1rem 1.5rem;
      color: #e8e8e8;text-decoration:none;border-radius:12px;transition:.3s;
    }
    .nav-link:hover{background:rgba(255,255,255,.12);color:#f0f0f0;transform:translateX(5px)}
    .nav-link.active{
      background:linear-gradient(135deg,#404040,#1f1f1f);
      color:#fff;box-shadow:0 5px 15px rgba(100,100,110,.4)
    }
    .nav-icon{width:20px;margin-right:1rem;text-align:center}
    .main-content{flex:1;margin-left:280px;min-height:100vh;display:flex;flex-direction:column;transition:margin-left 0.3s ease-in-out}
    .main-content.expanded{margin-left:0}
    .header{
      background:rgba(25,25,28,.85);backdrop-filter:blur(10px);
      border-bottom:1px solid rgba(255,255,255,.15);
      padding:1.5rem 2rem;display:flex;justify-content:space-between;align-items:center;
      position:relative;z-index:900
    }
    .header-title{font-size:1.8rem;font-weight:600;display:flex;align-items:center;gap:1rem;color:#e8e8e8}
    .mobile-greeting{display:none;color:#e8e8e8;font-size:1.02rem;font-weight:600}
    .user-info{display:flex;align-items:center;gap:1rem;position:relative;z-index:10002}
    .user-avatar{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#4a4a4a,#1f1f1f);
      display:flex;align-items:center;justify-content:center;color:#fff;font-weight:600;overflow:hidden;cursor:pointer;transition:all 0.3s}
    .user-avatar:hover{transform:scale(1.05);box-shadow:0 0 15px rgba(200,200,220,0.4)}
    .user-avatar img{width:100%;height:100%;object-fit:cover}
    .avatar-wrapper{position:relative;display:inline-flex;}
    .avatar-notif-badge{
      position:absolute;top:-4px;right:-4px;
      background:#ef4444;color:#fff;
      border-radius:50%;min-width:18px;height:18px;
      font-size:0.62rem;font-weight:700;
      display:flex;align-items:center;justify-content:center;
      border:2px solid #1a1a1a;z-index:10004;line-height:1;
      pointer-events:none;
    }
    .menu-notif-badge{
      margin-left:auto;background:#ef4444;color:#fff;
      border-radius:12px;padding:1px 7px;
      font-size:0.7rem;font-weight:700;
    }
    .nav-plano-badge {
      margin-left: auto;
      background: #ef4444; color: #fff;
      border-radius: 12px; padding: 1px 7px;
      font-size: 0.7rem; font-weight: 700;
      display: none;
    }
    .user-details{display:flex;flex-direction:column;align-items:flex-start;}
    .user-name{font-weight:600;color:#e8e8e8;font-size:0.9rem;}
    .user-email{font-size:0.75rem;color: #e8e8e8;margin-top:2px;}
    .user-menu{position:absolute;top:100%;right:0;margin-top:0.5rem;background:rgba(25,25,28,0.98);backdrop-filter:blur(10px);
      border:1px solid rgba(255,255,255,0.2);border-radius:12px;min-width:200px;opacity:0;visibility:hidden;
      transform:translateY(-10px);transition:all 0.3s;z-index:10003;box-shadow:0 10px 30px rgba(0,0,0,0.5)}
    .user-menu.show{opacity:1 !important;visibility:visible !important;transform:translateY(0) !important}
    .user-menu-item{padding:0.875rem 1.25rem;color: #e8e8e8;text-decoration:none;display:flex;align-items:center;
      gap:0.75rem;transition:all 0.3s;cursor:pointer;border-bottom:1px solid rgba(255,255,255,0.1)}
    .user-menu-item:last-child{border-bottom:none}
    .user-menu-item:hover{background:rgba(255,255,255,0.12);color:#f0f0f0}
    .user-menu-item.danger{color:#ef4444}
    .user-menu-item.danger:hover{background:rgba(239,68,68,0.1);color:#ef4444}
    .logout-btn{
      border:1px solid rgba(239,68,68,.5);color:#ef4444;padding:.5rem 1rem;
      border-radius:6px;text-decoration:none;transition:.3s;display:flex;align-items:center;gap:0.5rem;
    }
    .logout-btn:hover{background:#ef4444;color:#fff}
    .logout-btn{display:none}
    
    .sidebar-overlay {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.5);
      z-index: 999;
      opacity: 0;
      transition: opacity 0.3s;
    }
    
    .sidebar-overlay.active {
      display: block;
      opacity: 1;
    }
    
    .menu-toggle {
      display: none;
      background: transparent;
      border: none;
      color: #e8e8e8;
      font-size: 1.5rem;
      cursor: pointer;
      padding: 0.5rem;
      transition: all 0.3s;
    }
    
    .menu-toggle:hover {
      color: #f0f0f0;
    }

    .mobile-bottom-nav,
    .mobile-more-backdrop,
    .mobile-more-panel {
      display: none;
    }

    .mobile-bottom-nav {
      position: fixed;
      left: 12px;
      right: 12px;
      bottom: calc(env(safe-area-inset-bottom, 0px) + 10px);
      z-index: 1500;
      background: rgba(25,25,28,.95);
      border: 1px solid rgba(255,255,255,.15);
      border-radius: 18px;
      box-shadow: 0 14px 30px rgba(0,0,0,.35);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      padding: 7px 6px;
      grid-template-columns: repeat(5, minmax(0, 1fr));
      gap: 4px;
      align-items: stretch;
    }

    .mobile-nav-btn {
      border: none;
      background: transparent;
      color: #d1d5db;
      border-radius: 12px;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 4px;
      min-height: 56px;
      text-decoration: none;
      font-size: 0.66rem;
      font-weight: 600;
      letter-spacing: 0.01em;
      transition: all 0.25s ease;
      padding: 6px 4px;
      cursor: pointer;
    }

    .mobile-nav-btn i {
      font-size: 1rem;
    }

    .mobile-nav-btn:hover {
      color: #f0f0f0;
      background: rgba(255,255,255,.12);
      transform: translateY(-1px);
    }

    .mobile-nav-btn.active {
      color: #fff;
      background: linear-gradient(135deg,#404040,#1f1f1f);
      box-shadow: 0 5px 15px rgba(100,100,110,.32);
    }

    .mobile-more-backdrop {
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,.5);
      z-index: 1497;
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.25s ease;
    }

    .mobile-more-backdrop.show {
      opacity: 1;
      pointer-events: auto;
    }

    .mobile-more-panel {
      position: fixed;
      left: 12px;
      right: 12px;
      bottom: calc(env(safe-area-inset-bottom, 0px) + 84px);
      z-index: 1499;
      background: rgba(25,25,28,.98);
      border: 1px solid rgba(255,255,255,.2);
      border-radius: 16px;
      box-shadow: 0 24px 48px rgba(0,0,0,.45);
      padding: 12px;
      transform: translateY(18px) scale(0.98);
      opacity: 0;
      pointer-events: none;
      transition: transform 0.25s ease, opacity 0.25s ease;
      max-height: min(58vh, 420px);
      overflow-y: auto;
    }

    .mobile-more-panel.show {
      transform: translateY(0) scale(1);
      opacity: 1;
      pointer-events: auto;
    }

    .mobile-more-title {
      color: #e8e8e8;
      font-size: 0.78rem;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      margin: 0 0 10px;
      padding: 0 2px;
      opacity: 0.85;
    }

    .mobile-more-list {
      display: grid;
      grid-template-columns: 1fr;
      gap: 6px;
    }

    .mobile-more-item {
      display: flex;
      align-items: center;
      gap: 10px;
      border-radius: 12px;
      padding: 11px 12px;
      color: #e8e8e8;
      text-decoration: none;
      background: rgba(255,255,255,.06);
      transition: all 0.2s ease;
      border: 1px solid transparent;
    }

    .mobile-more-item i {
      width: 18px;
      text-align: center;
    }

    .mobile-more-item .mobile-more-label {
      font-size: 0.9rem;
      font-weight: 600;
    }

    .mobile-more-item .mobile-more-badge {
      margin-left: auto;
      background: #ef4444;
      color: #fff;
      border-radius: 12px;
      padding: 1px 7px;
      font-size: 0.7rem;
      font-weight: 700;
      display: none;
    }

    .mobile-more-item:hover {
      background: rgba(255,255,255,.12);
      color: #f0f0f0;
      border-color: rgba(255,255,255,.18);
    }
    
    @media (max-width: 768px) {
      .sidebar{display:none}
      .main-content{margin-left:0}
      .user-details{display:none;}
      .menu-toggle {display: none;}
      .header-title{display:none;}
      .mobile-greeting{display:block;}
      .header{padding:0.55rem 1rem;}
      .user-avatar{width:34px;height:34px;font-size:0.85rem;}
      .main-content{padding-bottom:calc(86px + env(safe-area-inset-bottom, 0px));}
      .mobile-bottom-nav{display:grid;}
      .mobile-more-backdrop,
      .mobile-more-panel{display:block;}
      #banner-conexao {
        bottom: calc(86px + env(safe-area-inset-bottom, 0px));
      }
    }
    
    .iframe-container{
      flex:1;padding:0;overflow:hidden;
      background:transparent;position:relative;
      min-height:calc(100vh - 120px);
    }
    .iframe-container iframe{
      width:100%;height:100%;border:none;
      background:transparent;display:block;
      min-height:calc(100vh - 120px);
      border-radius:8px;
      box-shadow:0 2px 8px rgba(0,0,0,0.1);
    }
    .iframe-loading{
      position:absolute;top:50%;left:50%;
      transform:translate(-50%,-50%);
      background:rgba(25,25,28,.95);
      border-radius:12px;padding:2rem;
      display:flex;flex-direction:column;
      align-items:center;gap:1rem;z-index:10;
      backdrop-filter:blur(8px);
      border:1px solid rgba(255,255,255,0.15);
      transition:opacity 0.2s ease-out;
    }
    .iframe-loading .spinner{
      width:40px;height:40px;
      border:3px solid rgba(200,200,220,.3);
      border-top:3px solid #e0e0e0;
      border-radius:50%;animation:spin 1s linear infinite
    }
    .iframe-loading p{
      color:#e8e8e8;margin:0;font-size:0.9rem;
    }
    @keyframes spin{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}
    .iframe-loading.hidden{
      opacity:0;
      pointer-events:none;
      display:none;
    }
    
    .notification{
      position:fixed;top:1rem;right:1rem;
      background:rgba(25,25,28,.95);backdrop-filter:blur(10px);
      border:1px solid rgba(255,255,255,.3);border-left:4px solid #e0e0e0;
      color:#e8e8e8;padding:1rem 1.5rem;border-radius:8px;
      transform:translateX(100%);transition:.3s ease-in-out;
      z-index:10000;max-width:300px
    }
    .notification.show{transform:translateX(0)}
    .notification .close-btn{
      position:absolute;top:.5rem;right:.5rem;
      background:none;border:none;color: #e8e8e8;
      cursor:pointer;font-size:1.2rem;transition:.2s
    }
    .notification .close-btn:hover{color:#ef4444}
    
    /* Modal de Confirma&ccedil;&atilde;o */
    .modal-overlay {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(0, 0, 0, 0.7);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 10010;
      backdrop-filter: blur(4px);
    }
    .modal-overlay.show {
      display: flex;
      animation: fadeIn 0.3s ease-in-out;
    }
    .modal {
      background: rgba(25,25,28,0.98);
      border-radius: 16px;
      padding: 32px;
      max-width: 400px;
      width: 90%;
      box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
      animation: slideUp 0.3s ease-out;
      border: 1px solid rgba(255,255,255,0.2);
    }
    @keyframes slideUp {
      from {
        opacity: 0;
        transform: translateY(20px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }
    .modal-header {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 16px;
    }
    .modal-icon {
      width: 48px;
      height: 48px;
      border-radius: 50%;
      background: rgba(239, 68, 68, 0.2);
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .modal-icon svg {
      width: 24px;
      height: 24px;
      fill: #ef4444;
    }
    .modal-title {
      color: #e8e8e8;
      font-size: 20px;
      font-weight: 600;
      margin: 0;
    }
    .modal-message {
      color: #e8e8e8;
      font-size: 15px;
      line-height: 1.5;
      margin-bottom: 24px;
    }
    .modal-buttons {
      display: flex;
      gap: 12px;
      justify-content: flex-end;
    }
    .modal-btn {
      padding: 10px 20px;
      border: none;
      border-radius: 8px;
      font-size: 14px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s ease;
    }
    .modal-btn-cancel {
      background: rgba(45,45,50,0.9);
      color: #e8e8e8;
      border: 2px solid rgba(255,255,255,0.3);
    }
    .modal-btn-cancel:hover {
      background: rgba(255,255,255,0.15);
    }
    .modal-btn-confirm {
      background: linear-gradient(135deg, #ef4444, #dc2626);
      color: #fff;
    }
    .modal-btn-confirm:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(239, 68, 68, 0.4);
    }
    
    /* Tema Claro */
    body.tema-claro {
      background: linear-gradient(135deg, #fdfcfb 0%, #f5f3f0 100%);
      color: #2d2520;
    }
    body.tema-claro .sidebar {
      background: rgba(255, 255, 255, 0.98);
      border-right: 1px solid rgba(212, 165, 116, 0.2);
      box-shadow: 2px 0 10px rgba(0, 0, 0, 0.05);
    }
    body.tema-claro .logo {
      background: linear-gradient(135deg, #c4935b, #d4a574);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }
    body.tema-claro .subtitle {
      color: #8b7968;
    }
    body.tema-claro .nav-link {
      color: #6b5d52;
    }
    body.tema-claro .nav-link:hover {
      background: rgba(212, 165, 116, 0.15);
      color: #c4935b;
    }
    body.tema-claro .nav-link.active {
      background: linear-gradient(135deg, #d4a574, #c4935b);
      color: #fff;
      box-shadow: 0 4px 12px rgba(212, 165, 116, 0.3);
    }
    body.tema-claro .header {
      background: rgba(255, 255, 255, 0.95);
      border-bottom: 1px solid rgba(212, 165, 116, 0.2);
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    }
    body.tema-claro .header-title {
      color: #2d2520;
    }
    body.tema-claro .user-name {
      color: #2d2520;
    }
    body.tema-claro .user-email {
      color: #8b7968;
    }
    body.tema-claro .user-menu {
      background: rgba(255, 255, 255, 0.98);
      border: 1px solid rgba(212, 165, 116, 0.2);
      box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
    }
    body.tema-claro .user-menu-item {
      color: #6b5d52;
      border-bottom: 1px solid rgba(212, 165, 116, 0.15);
    }
    body.tema-claro .user-menu-item:hover {
      background: rgba(212, 165, 116, 0.12);
      color: #c4935b;
    }
    body.tema-claro .user-menu-item.danger {
      color: #d63031;
    }
    body.tema-claro .user-menu-item.danger:hover {
      background: rgba(214, 48, 49, 0.08);
      color: #d63031;
    }
    body.tema-claro .avatar-notif-badge {
      border-color: #f5f3f0;
    }
    body.tema-claro .menu-toggle {
      color: #2d2520;
    }
    body.tema-claro .menu-toggle:hover {
      color: #c4935b;
    }
    body.tema-claro .mobile-bottom-nav {
      background: rgba(255, 255, 255, 0.96);
      border-color: rgba(212, 165, 116, 0.2);
      box-shadow: 0 16px 34px rgba(0,0,0,.12);
    }
    body.tema-claro .mobile-nav-btn {
      color: #8b7968;
    }
    body.tema-claro .mobile-nav-btn:hover {
      color: #b17d43;
      background: rgba(212, 165, 116, 0.16);
    }
    body.tema-claro .mobile-nav-btn.active {
      color: #fff;
      background: linear-gradient(135deg, #d4a574, #c4935b);
      box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.22);
    }
    body.tema-claro .mobile-more-backdrop {
      background: rgba(0,0,0,.45);
    }
    body.tema-claro .mobile-more-panel {
      background: rgba(255, 255, 255, 0.98);
      border-color: rgba(212, 165, 116, 0.2);
      box-shadow: 0 24px 46px rgba(0,0,0,.18);
    }
    body.tema-claro .mobile-more-title {
      color: #8b6f52;
    }
    body.tema-claro .mobile-more-item {
      background: rgba(212, 165, 116, 0.1);
      color: #6b5d52;
    }
    body.tema-claro .mobile-more-item:hover {
      background: rgba(212, 165, 116, 0.18);
      color: #c4935b;
      border-color: rgba(212, 165, 116, 0.28);
    }
    body.tema-claro .mobile-greeting {
      color: #2d2520;
    }
    body.tema-claro .modal-foto {
      background: rgba(255, 255, 255, 0.98);
      border: 1px solid rgba(212, 165, 116, 0.2);
    }
    body.tema-claro .modal-foto-title {
      color: #2d2520;
    }
    body.tema-claro .modal-foto-close {
      color: #6b5d52;
    }
    body.tema-claro .modal-foto-close:hover {
      color: #c4935b;
    }
    body.tema-claro .file-name {
      color: #8b7968;
    }
    body.tema-claro .btn-foto-cancelar {
      background: rgba(245, 243, 240, 0.8);
      color: #2d2520;
      border: 2px solid rgba(212, 165, 116, 0.3);
    }
    body.tema-claro .btn-foto-cancelar:hover {
      background: rgba(212, 165, 116, 0.15);
    }
    
    /* Modal de Foto */
    .modal-foto-overlay {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.7);
      display: none;
      justify-content: center;
      align-items: center;
      z-index: 10005;
    }
    .modal-foto-overlay.show {
      display: flex;
    }
    .modal-foto {
      background: rgba(25, 25, 28, 0.98);
      border: 1px solid rgba(255, 255, 255, 0.3);
      border-radius: 16px;
      padding: 2rem;
      max-width: 500px;
      width: 90%;
      box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
    }
    .modal-foto-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1.5rem;
    }
    .modal-foto-title {
      font-size: 1.5rem;
      font-weight: 600;
      color: #e8e8e8;
    }
    .modal-foto-close {
      background: transparent;
      border: none;
      color: #e8e8e8;
      font-size: 1.5rem;
      cursor: pointer;
      padding: 0.5rem;
      transition: all 0.3s;
    }
    .modal-foto-close:hover {
      color: #f0f0f0;
      transform: rotate(90deg);
    }
    .foto-preview {
      width: 150px;
      height: 150px;
      border-radius: 50%;
      margin: 0 auto 1.5rem;
      background: linear-gradient(135deg, #4a4a4a, #1f1f1f);
      display: flex;
      align-items: center;
      justify-content: center;
      color: #fff;
      font-size: 3rem;
      font-weight: 600;
      overflow: hidden;
    }
    .foto-preview img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
    .file-input-wrapper {
      text-align: center;
      margin-bottom: 1.5rem;
    }
    .file-input-label {
      display: inline-block;
      padding: 0.875rem 1.5rem;
      background: linear-gradient(135deg, #4a4a4a, #1f1f1f);
      color: #fff;
      border-radius: 10px;
      cursor: pointer;
      transition: all 0.3s;
      font-weight: 600;
    }
    .file-input-label:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(150, 150, 170, 0.3);
    }
    .file-input-label input[type="file"] {
      display: none;
    }
    .file-name {
      color: #e8e8e8;
      font-size: 0.875rem;
      margin-top: 0.5rem;
    }
    .modal-foto-buttons {
      display: flex;
      gap: 1rem;
      justify-content: flex-end;
    }
    .btn-foto {
      padding: 0.875rem 1.5rem;
      border: none;
      border-radius: 10px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s;
    }
    .btn-foto-cancelar {
      background: rgba(45, 45, 50, 0.9);
      color: #e8e8e8;
      border: 2px solid rgba(255, 255, 255, 0.3);
    }
    .btn-foto-cancelar:hover {
      background: rgba(255, 255, 255, 0.15);
    }
    .btn-foto-salvar {
      background: linear-gradient(135deg, #4a4a4a, #1f1f1f);
      color: #fff;
      box-shadow: 0 4px 12px rgba(150, 150, 170, 0.3);
    }
    .btn-foto-salvar:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(150, 150, 170, 0.4);
    }
    .btn-foto-salvar:disabled {
      opacity: 0.5;
      cursor: not-allowed;
      transform: none;
    }
  </style>

  <style>
    /* ======================================================
       Overlay de Assinatura Vencida
       ====================================================== */
    #overlay-assinatura {
      position: fixed; inset: 0; z-index: 99999;
      background: rgba(10,10,10,0.97);
      display: flex; align-items: center; justify-content: center;
      padding: 1rem;
    }
    .overlay-card {
      background: #1a1816;
      border: 1px solid rgba(212,165,116,0.3);
      border-radius: 20px;
      max-width: 480px; width: 100%;
      padding: 2.5rem 2rem;
      text-align: center;
      box-shadow: 0 20px 60px rgba(0,0,0,0.6);
      animation: fadeInUp 0.4s ease;
    }
    @keyframes fadeInUp {
      from { opacity:0; transform:translateY(30px) }
      to   { opacity:1; transform:translateY(0) }
    }
    .overlay-icon {
      font-size: 3.5rem; margin-bottom: 1rem;
      animation: pulseIcon 2s infinite;
    }
    @keyframes pulseIcon {
      0%,100% { transform:scale(1) }
      50%      { transform:scale(1.1) }
    }
    .overlay-title {
      font-size: 1.5rem; font-weight: 700;
      color: #e8e8e8; margin-bottom: .5rem;
    }
    .overlay-subtitle {
      font-size: .9rem; color: #aaa; margin-bottom: 1.5rem; line-height: 1.5;
    }
    .overlay-info {
      background: rgba(212,165,116,0.08);
      border: 1px solid rgba(212,165,116,0.2);
      border-radius: 12px; padding: 1rem 1.5rem;
      margin-bottom: 1.5rem; text-align: left;
    }
    .overlay-info-row {
      display: flex; justify-content: space-between;
      font-size: .85rem; color: #ccc; margin-bottom: .4rem;
    }
    .overlay-info-row:last-child { margin-bottom: 0 }
    .overlay-info-row span:last-child { color: #d4a574; font-weight: 600 }
    .overlay-btn-pagar {
      width: 100%; padding: 1rem;
      background: linear-gradient(135deg, #d4a574, #b8843d);
      color: #fff; border: none; border-radius: 12px;
      font-size: 1rem; font-weight: 700; cursor: pointer;
      transition: .3s; display: flex; align-items: center;
      justify-content: center; gap: .6rem; margin-bottom: .75rem;
    }
    .overlay-btn-pagar:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(212,165,116,.4) }
    .overlay-btn-pagar:disabled { opacity:.6; cursor:wait; transform:none }
    .overlay-btn-sair {
      width: 100%; padding: .7rem;
      background: transparent; color: #888;
      border: 1px solid rgba(255,255,255,.15); border-radius: 12px;
      font-size: .85rem; cursor: pointer; transition: .3s;
    }
    .overlay-btn-sair:hover { background: rgba(255,255,255,.05); color: #ccc }
    .overlay-status-msg {
      margin-top: 1rem; padding: .75rem 1rem;
      border-radius: 8px; font-size: .85rem;
      display: none;
    }
    .overlay-status-msg.success {
      background: rgba(16,185,129,.15);
      border: 1px solid rgba(16,185,129,.3);
      color: #10b981; display: block;
    }
    .overlay-status-msg.error {
      background: rgba(239,68,68,.15);
      border: 1px solid rgba(239,68,68,.3);
      color: #ef4444; display: block;
    }
    .overlay-status-msg.info {
      background: rgba(212,165,116,.1);
      border: 1px solid rgba(212,165,116,.2);
      color: #d4a574; display: block;
    }
    .overlay-spinner {
      display: inline-block; width: 16px; height: 16px;
      border: 2px solid rgba(255,255,255,.3);
      border-top-color: #fff; border-radius: 50%;
      animation: spin .7s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg) } }
    .overlay-isento-badge {
      background: rgba(16,185,129,.15);
      border: 1px solid rgba(16,185,129,.3);
      color: #10b981;
      border-radius: 10px;
      padding: .75rem 1rem;
      font-size: .85rem;
      font-weight: 600;
      margin-bottom: 1rem;
      display: flex;
      align-items: center;
      gap: .5rem;
      justify-content: center;
    }
    .overlay-isento-badge i {
      font-size: 1rem;
    }
    .overlay-btn-renovar {
      width: 100%; padding: 1rem;
      background: linear-gradient(135deg, #22c55e, #16a34a);
      color: #fff; border: none; border-radius: 12px;
      font-size: 1rem; font-weight: 700; cursor: pointer;
      transition: .3s; display: flex; align-items: center;
      justify-content: center; gap: .6rem; margin-bottom: .75rem;
    }
    .overlay-btn-renovar:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(34,197,94,.4) }
    .overlay-btn-renovar:disabled { opacity:.6; cursor:wait; transform:none }

    /* ===== Banner de Conex�o ===== */
    #banner-conexao {
      position: fixed; bottom: 0; left: 0; right: 0;
      z-index: 99998;
      padding: .6rem 1rem;
      display: flex; align-items: center; justify-content: center; gap: .5rem;
      font-size: .85rem; font-weight: 600;
      transition: transform .35s ease, opacity .35s ease;
      transform: translateY(100%); opacity: 0; pointer-events: none;
    }
    #banner-conexao.show { transform: translateY(0); opacity: 1; pointer-events: auto; }
    #banner-conexao.offline { background: #ef4444; color: #fff; }
    #banner-conexao.online  { background: #10b981; color: #fff; }

    /* ===== Banner Assinatura Prestes a Vencer ===== */
    #banner-prox-vencer {
      display: flex; align-items: center; gap: .6rem;
      padding: .5rem 1rem;
      background: linear-gradient(135deg, #f59e0b, #d97706);
      color: #fff;
      font-size: .8rem; font-weight: 600;
      flex-wrap: wrap;
    }
    #banner-prox-vencer .banner-texto { flex: 1; min-width: 0; }
    #banner-prox-vencer a {
      color: #fff; text-decoration: underline; cursor: pointer; font-weight: 700; white-space: nowrap;
    }
    #banner-prox-vencer a:hover { opacity: .85; }
    #banner-prox-vencer .banner-fechar {
      background: none; border: none; color: #fff; cursor: pointer;
      font-size: 1rem; padding: 0 .25rem; opacity: .8; flex-shrink: 0;
    }
    #banner-prox-vencer .banner-fechar:hover { opacity: 1; }
  </style>
</head>
<body>

<!-- Banner de status de conex�o -->
<div id="banner-conexao">
  <i class="fas fa-wifi" id="banner-conexao-icon"></i>
  <span id="banner-conexao-texto">Verificando conex�o...</span>
</div>



<?php if (($assinaturaVencida && $dadosAssinaturaPHP) || $semAssinaturaAtiva): ?>
<?php if ($dadosAssinaturaPHP):
  $vencFormatado  = date('d/m/Y', strtotime($dadosAssinaturaPHP['data_vencimento']));
  $valorFormatado = number_format((float)$dadosAssinaturaPHP['valor_mensalidade'], 2, ',', '.');
  $hoje2    = new DateTime('today');
  $venc2    = new DateTime($dadosAssinaturaPHP['data_vencimento']);
  $diffDias = (int)$hoje2->diff($venc2)->days;
  $diasTexto = ($venc2 < $hoje2) ? "Vencida h&aacute; {$diffDias} dia(s)" : "Vence hoje";
endif; ?>
<div id="overlay-assinatura">
  <div class="overlay-card">
    <div class="overlay-icon">&#128274;</div>
    <?php if ($semAssinaturaAtiva): ?>
    <div class="overlay-title">Acesso Bloqueado</div>
    <div class="overlay-subtitle">Voc&ecirc; ainda n&atilde;o possui uma assinatura ativa. Gere seu pagamento para come&ccedil;ar a usar o sistema.</div>
    <?php else: ?>
    <div class="overlay-title">Assinatura Vencida</div>
    <div class="overlay-subtitle">Sua mensalidade est&aacute; em aberto. Regularize para continuar usando o sistema.</div>
    <?php endif; ?>
    <?php if ($dadosAssinaturaPHP): ?>
    <div class="overlay-info">
      <div class="overlay-info-row"><span>Valor da mensalidade</span><span>R$ <?= $valorFormatado ?></span></div>
      <div class="overlay-info-row"><span>Vencimento</span><span><?= $vencFormatado ?></span></div>
      <div class="overlay-info-row"><span>Situa&ccedil;&atilde;o</span><span><?= $diasTexto ?></span></div>
    </div>
    <?php endif; ?>
    <?php if ($isentoPagamento): ?>
    <div class="overlay-isento-badge">
      <i class="fas fa-gift"></i> Voc&ecirc; est&aacute; isento de pagamento por ter 5 indica&ccedil;&otilde;es ativas!
    </div>
    <button class="overlay-btn-renovar" id="btnRenovarGratuita" onclick="renovarGratuitaOverlay()">
      <i class="fas fa-sync-alt"></i> Renovar Gratuitamente
    </button>
    <?php else: ?>
    <button class="overlay-btn-pagar" id="btnGerarCobranca" onclick="gerarCobrancaOverlay()">
      <i class="fas fa-barcode"></i> Gerar Pagamento (PIX / Cart&atilde;o)
    </button>
    <?php endif; ?>
    <button class="overlay-btn-sair" onclick="realizarLogoutOverlay()">
      <i class="fas fa-sign-out-alt"></i> Sair do sistema
    </button>
    <div class="overlay-status-msg" id="overlayStatusMsg"></div>
  </div>
</div>
<?php endif; ?>
  <div class="container">
    <aside class="sidebar">
      <div class="logo-section">
        <img src="../uploads/logo/logo.png" alt="MustaBarber" style="width: 200px; height: auto; margin-bottom: 0.5rem;">
        <p class="subtitle">GERENCIAMENTO</p>
      </div>
      <nav class="navigation">
        <ul class="nav-menu">
          <!-- Dashboard: todos os planos -->
          <li class="nav-item">
            <a href="#" class="nav-link active" data-page="dashboard">
              <i class="fas fa-home nav-icon"></i>
              <span class="nav-text">Dashboard</span>
            </a>
          </li>
          <!-- Agendamentos: todos os planos -->
          <li class="nav-item">
            <a href="#" class="nav-link" data-page="agendamentos">
              <i class="fas fa-calendar-alt nav-icon"></i>
              <span class="nav-text">Agendamentos</span>
            </a>
          </li>
          <li class="nav-item">
            <a href="#" class="nav-link" data-page="agenda_visualizacao">
              <i class="fas fa-calendar-day nav-icon"></i>
              <span class="nav-text">Agenda</span>
            </a>
          </li>
          <!-- Hor�rios: todos os planos -->
          <li class="nav-item">
            <a href="#" class="nav-link" data-page="horarios">
              <i class="fas fa-clock nav-icon"></i>
              <span class="nav-text">Hor&aacute;rios</span>
            </a>
          </li>
          <!-- Servi�os: todos os planos -->
          <li class="nav-item">
            <a href="#" class="nav-link" data-page="servicos">
              <i class="fas fa-cut nav-icon"></i>
              <span class="nav-text">Servi&ccedil;os</span>
            </a>
          </li>
          <!-- Clientes: todos os planos -->
          <li class="nav-item">
            <a href="#" class="nav-link" data-page="clientes">
              <i class="fas fa-address-book nav-icon"></i>
              <span class="nav-text">Clientes</span>
            </a>
          </li>
          <li class="nav-item">
            <a href="#" class="nav-link" data-page="links_compartilhamento">
              <i class="fas fa-link nav-icon"></i>
              <span class="nav-text">Links para Clientes</span>
            </a>
          </li>
          <!-- Solicitacoes de Plano: Solo Pro+ e Solo Basico alocado em barbearia -->
          <?php if ($acessoSolicitacoesPlano): ?>
          <li class="nav-item" id="nav-solicitacoes-plano">
            <a href="#" class="nav-link" data-page="solicitacoes_plano">
              <i class="fas fa-tags nav-icon"></i>
              <span class="nav-text">Planos Clientes</span>
              <span class="nav-plano-badge" id="nav-plano-badge"></span>
            </a>
          </li>
          <?php endif; ?>
          <!-- Produtos: Solo Pro ou superior (perfil >= 3) -->
          <?php if ($perfilUsuario === 1 || $perfilUsuario >= 3): ?>
          <li class="nav-item">
            <a href="#" class="nav-link" data-page="produtos">
              <i class="fas fa-box nav-icon"></i>
              <span class="nav-text">Produtos</span>
            </a>
          </li>
          <?php endif; ?>
          <!-- Financeiro: Solo Pro ou superior (perfil >= 3) -->
          <?php if ($perfilUsuario === 1 || $perfilUsuario >= 3): ?>
          <li class="nav-item">
            <a href="#" class="nav-link" data-page="financeiro">
              <i class="fas fa-dollar-sign nav-icon"></i>
              <span class="nav-text">Financeiro</span>
            </a>
          </li>
          <?php endif; ?>
          <?php if ($acessoWhatsapp): ?>
          <li class="nav-item">
            <a href="#" class="nav-link" data-page="whatsapp_conexao">
              <i class="fab fa-whatsapp nav-icon"></i>
              <span class="nav-text">WhatsApp</span>
            </a>
          </li>
          <?php endif; ?>
          <!-- Estabelecimento/Equipe: somente dono de barbearia (perfil >= 4) ou admin -->
          <?php if ($perfilUsuario === 1 || ($perfilUsuario >= 4 && $ehDonoBarbearia)): ?>
          <li class="nav-item">
            <a href="#" class="nav-link" data-page="barbearia">
              <i class="fas fa-store nav-icon"></i>
              <span class="nav-text">Estabelecimento</span>
            </a>
          </li>
          <?php endif; ?>
          <!-- Administrador: perfil 1 apenas -->
          <?php if ($perfilUsuario === 1): ?>
          <li class="nav-item">
            <a href="#" class="nav-link" data-page="administrador">
              <i class="fas fa-user-shield nav-icon"></i>
              <span class="nav-text">Administrador</span>
            </a>
          </li>
          <?php endif; ?>
          <!-- Embaixador(a): somente quem tem embaixador=TRUE -->
          <?php
          $ehEmbaixador = false;
          try {
              if (!isset($conexao)) require_once '../bancodedados/conexao.php';
              $stmtEmbCheck = $conexao->prepare("SELECT embaixador FROM usuarios WHERE codigo = :cod LIMIT 1");
              $stmtEmbCheck->bindParam(':cod', $codigoUsuario, PDO::PARAM_INT);
              $stmtEmbCheck->execute();
              $embRow = $stmtEmbCheck->fetch(PDO::FETCH_ASSOC);
              $ehEmbaixador = ($embRow && ($embRow['embaixador'] === true || $embRow['embaixador'] === 't'));
          } catch (Exception $e) { $ehEmbaixador = false; }
          ?>
          <?php if ($ehEmbaixador || $perfilUsuario === 1): ?>
          <li class="nav-item">
            <a href="#" class="nav-link" data-page="embaixador">
              <i class="fas fa-star nav-icon"></i>
              <span class="nav-text">Embaixador(a)</span>
            </a>
          </li>
          <?php endif; ?>
          <li class="nav-item">
            <a href="#" class="nav-link" data-page="configuracoes" style="border-top:1px solid rgba(255,255,255,0.08);margin-top:0.5rem;padding-top:1rem">
              <i class="fas fa-cog nav-icon"></i>
              <span class="nav-text">Configura&ccedil;&otilde;es</span>
            </a>
          </li>
          <li class="nav-item" id="install-app-link" style="display:none">
            <a href="../instalacao-app.html?origem=sistema" class="nav-link install-app-btn" style="border-top:1px solid rgba(212,165,116,0.1);margin-top:0.5rem;padding-top:1rem">
              <i class="fas fa-download nav-icon"></i>
              <span class="nav-text">Instalar App</span>
            </a>
          </li>
        </ul>
      </nav>
    </aside>

    <div class="sidebar-overlay" id="sidebar-overlay" onclick="toggleSidebar()"></div>

    <main class="main-content">
      <header class="header">
        <div style="display: flex; align-items: center; gap: 1rem;">
          <button class="menu-toggle" id="menu-toggle" onclick="toggleSidebar()">
            <i class="fas fa-bars"></i>
          </button>
          <div class="mobile-greeting">Ola, <?php echo htmlspecialchars($primeiroNome); ?></div>
          <div class="header-title">
            <span id="page-title">Dashboard</span>
          </div>
        </div>
        <div class="user-info">
          <div class="avatar-wrapper">
            <div class="user-avatar" onclick="toggleUserMenu()">
              <?php if (!empty($usuario['foto_caminho']) && file_exists('../' . $usuario['foto_caminho'])): ?>
                <img src="../<?php echo htmlspecialchars($usuario['foto_caminho']); ?>" alt="Foto do usu&aacute;rio">
              <?php else: ?>
                <?php echo $primeiraLetra; ?>
              <?php endif; ?>
            </div>
            <span class="avatar-notif-badge" id="avatarNotifBadge" style="<?php echo $qtdNotificacoes > 0 ? '' : 'display:none'; ?>"><?php echo $qtdNotificacoes > 0 ? $qtdNotificacoes : ''; ?></span>
          </div>
          <div class="user-details">
            <div class="user-name"><?php echo htmlspecialchars($primeiroNome); ?></div>
            <div class="user-email"><?php echo htmlspecialchars($emailUsuario); ?></div>
          </div>
          <div class="user-menu" id="userMenu">
            <div class="user-menu-item" style="border-bottom:1px solid rgba(212,165,116,0.2);padding:1rem 1.25rem;cursor:default">
              <div>
                <div style="font-weight:600;color:#f5f1ed;margin-bottom:0.25rem"><?php echo htmlspecialchars($nomeUsuario); ?></div>
                <div style="font-size:0.75rem;color: #e8e8e8"><?php echo htmlspecialchars($emailUsuario); ?></div>
              </div>
            </div>
            <div class="user-menu-item" onclick="alternarTema()">
              <i class="fas fa-moon" id="iconeTema"></i>
              <span id="textoTema">Tema Escuro</span>
            </div>
            <div class="user-menu-item" onclick="abrirNotificacoes()">
              <i class="fas fa-bell"></i>
              <span>Notifica&ccedil;&otilde;es</span>
              <?php if ($qtdNotificacoes > 0): ?>
              <span class="menu-notif-badge" id="menuNotifBadge"><?php echo $qtdNotificacoes; ?></span>
              <?php else: ?>
              <span class="menu-notif-badge" id="menuNotifBadge" style="display:none">0</span>
              <?php endif; ?>
            </div>
            <div class="user-menu-item" onclick="document.getElementById('userMenu').classList.remove('show'); navegar('configuracoes')">
              <i class="fas fa-cog"></i>
              <span>Configura&ccedil;&otilde;es</span>
            </div>
            <div class="user-menu-item" onclick="abrirModalFoto()">
              <i class="fas fa-camera"></i>
              <span>Alterar Foto</span>
            </div>
            <div class="user-menu-item danger" onclick="fazerLogout()">
              <i class="fas fa-sign-out-alt"></i>
              <span>Sair do Sistema</span>
            </div>
          </div>
        </div>
      </header>

      <?php if ($assinaturaProxVencer && !$assinaturaVencida): ?>
      <div id="banner-prox-vencer">
        <i class="fas fa-exclamation-triangle" style="flex-shrink:0"></i>
        <span class="banner-texto">
          <?php if ($diasProxVencer === 0): ?>
            Sua mensalidade vence <strong>hoje</strong>! <a onclick="navegar('configuracoes')">Renovar agora</a>
          <?php else: ?>
            Sua mensalidade vence em <strong><?= $diasProxVencer ?> dia(s)</strong>. <a onclick="navegar('configuracoes')">Renovar antecipadamente</a>
          <?php endif; ?>
        </span>
        <button class="banner-fechar" onclick="document.getElementById('banner-prox-vencer').remove()" title="Fechar"><i class="fas fa-times"></i></button>
      </div>
      <?php endif; ?>

      <div id="content-area" class="iframe-container">
        <div class="iframe-loading" id="iframe-loading">
          <div class="spinner"></div>
          <p>Carregando...</p>
          <button onclick="forceBackToDashboard()" style="margin-top:1rem;background:rgba(239,68,68,.8);color:#fff;border:none;padding:.5rem 1rem;border-radius:6px;cursor:pointer;">
            <i class="fas fa-home"></i> Voltar ao Dashboard
          </button>
        </div>
        
        <iframe id="main-frame" src="" style="display:none;" loading="eager" importance="high"></iframe>
      </div>
    </main>
  </div>

  <nav class="mobile-bottom-nav" id="mobileBottomNav" aria-label="Navegacao principal do app">
    <a href="#" class="mobile-nav-btn nav-link active" data-page="dashboard">
      <i class="fas fa-home"></i>
      <span>Dashboard</span>
    </a>
    <a href="#" class="mobile-nav-btn nav-link" data-page="agenda_visualizacao">
      <i class="fas fa-calendar-day"></i>
      <span>Agenda</span>
    </a>
    <a href="#" class="mobile-nav-btn nav-link" data-page="financeiro">
      <i class="fas fa-dollar-sign"></i>
      <span>Financeiro</span>
    </a>
    <a href="#" class="mobile-nav-btn nav-link" data-page="horarios">
      <i class="fas fa-clock"></i>
      <span>Horarios</span>
    </a>
    <button type="button" class="mobile-nav-btn" id="mobile-more-trigger" aria-controls="mobileMorePanel" aria-expanded="false">
      <i class="fas fa-bars"></i>
      <span>Mais</span>
    </button>
  </nav>

  <div class="mobile-more-backdrop" id="mobileMoreBackdrop" onclick="toggleMoreMenu(false)"></div>
  <section class="mobile-more-panel" id="mobileMorePanel" aria-hidden="true">
    <h3 class="mobile-more-title">Mais funcoes</h3>
    <div class="mobile-more-list" id="mobileMoreList"></div>
  </section>

  <!-- Modal de Confirma&ccedil;&atilde;o de Logout -->
  <div class="modal-overlay" id="logoutModal">
    <div class="modal">
      <div class="modal-header">
        <div class="modal-icon">
          <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
          </svg>
        </div>
        <h3 class="modal-title">Sair do Sistema</h3>
      </div>
      <p class="modal-message">
        Deseja realmente sair do sistema? Voc&ecirc; ser&aacute; redirecionado para a tela de login.
      </p>
      <div class="modal-buttons">
        <button type="button" class="modal-btn modal-btn-cancel" onclick="closeLogoutModal()">
          N&atilde;o
        </button>
        <button type="button" class="modal-btn modal-btn-confirm" onclick="confirmLogout()">
          Sim, Sair
        </button>
      </div>
    </div>
  </div>

  <!-- Modal de Sess&atilde;o Expirada (auto-redirect) -->
  <div class="modal-overlay" id="sessionExpiredModal" style="display:none;">
    <div class="modal" style="text-align:center;max-width:360px;">
      <div class="modal-header" style="justify-content:center;flex-direction:column;gap:.5rem;margin-bottom:1rem;">
        <div class="modal-icon" style="margin:0 auto;"><svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg></div>
        <h3 class="modal-title">Sess&atilde;o Expirada</h3>
      </div>
      <p class="modal-message">Sua sess&atilde;o expirou. Redirecionando para o login em <strong id="sessaoCountdown">5</strong>s...</p>
      <div class="modal-buttons" style="justify-content:center;">
        <button type="button" class="modal-btn modal-btn-confirm" onclick="forcarLogout('manual')">
          Ir para o Login Agora
        </button>
      </div>
    </div>
  </div>

  <script>
    // ===== SISTEMA DE ATUALIZA&#65533;&#65533;O AUTOM&#65533;TICA PWA =====
    if ('serviceWorker' in navigator) {
      let refreshing = false;
      
      // Detectar quando o Service Worker &#65533; atualizado
      navigator.serviceWorker.addEventListener('controllerchange', () => {
        if (refreshing) return;
        console.log('[PWA] Nova vers&#65533;o detectada! Recarregando...');
        refreshing = true;
        
        // Mostrar notifica&#65533;&#65533;o antes de recarregar
        showNotification('Sistema atualizado! Recarregando...', 'success', 2000);
        setTimeout(() => window.location.reload(), 1500);
      });
      
      // Registrar Service Worker
      navigator.serviceWorker.register('/sw.js', { scope: '/' })
        .then((registration) => {
          console.log('[PWA] Service Worker registrado:', registration.scope);
          
          // Verificar atualiza&#65533;&#65533;es a cada 60 segundos
          setInterval(() => {
            registration.update();
          }, 60000);
          
          // Detectar nova vers&#65533;o instalando
          registration.addEventListener('updatefound', () => {
            const newWorker = registration.installing;
            console.log('[PWA] Nova vers&#65533;o encontrada, instalando...');
            
            newWorker.addEventListener('statechange', () => {
              if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                console.log('[PWA] Nova vers&#65533;o dispon&#65533;vel, aplicando...');
                showNotification('Nova vers&#65533;o dispon&#65533;vel! Atualizando...', 'info', 2000);
                
                // Ativar automaticamente ap&#65533;s 2 segundos
                setTimeout(() => {
                  newWorker.postMessage({ type: 'SKIP_WAITING' });
                }, 2000);
              }
            });
          });
          
          // Receber mensagens do Service Worker
          navigator.serviceWorker.addEventListener('message', (event) => {
            if (event.data && event.data.type === 'SW_UPDATED') {
              console.log('[PWA] Atualizado para vers&#65533;o:', event.data.version);
            }
          });
        })
        .catch(err => console.log('[PWA] Service Worker n&#65533;o registrado:', err));
    }
    
    // Dados do usu&aacute;rio logado (vem do PHP)
    const usuarioLogado = {
      codigo: <?php echo $codigoUsuario; ?>,
      nome: "<?php echo addslashes($nomeUsuario); ?>",
      email: "<?php echo addslashes($emailUsuario); ?>",
      perfil: <?php echo $perfilUsuario; ?>,
      primeiroNome: "<?php echo addslashes($primeiroNome); ?>"
    };
    
    console.log('Usu&#65533;rio logado:', usuarioLogado);

    let currentPage = 'dashboard';
    const mobilePrimaryPages = ['dashboard', 'agenda_visualizacao', 'financeiro', 'horarios'];

    function closeMobileMoreMenu() {
      const panel = document.getElementById('mobileMorePanel');
      const backdrop = document.getElementById('mobileMoreBackdrop');
      const trigger = document.getElementById('mobile-more-trigger');

      if (!panel || !backdrop || !trigger) return;

      panel.classList.remove('show');
      backdrop.classList.remove('show');
      panel.setAttribute('aria-hidden', 'true');
      trigger.setAttribute('aria-expanded', 'false');
    }

    function toggleMoreMenu(forceState) {
      const panel = document.getElementById('mobileMorePanel');
      const backdrop = document.getElementById('mobileMoreBackdrop');
      const trigger = document.getElementById('mobile-more-trigger');

      if (!panel || !backdrop || !trigger || window.innerWidth > 768) return;

      const shouldOpen = typeof forceState === 'boolean' ? forceState : !panel.classList.contains('show');

      if (shouldOpen) {
        panel.classList.add('show');
        backdrop.classList.add('show');
        panel.setAttribute('aria-hidden', 'false');
        trigger.setAttribute('aria-expanded', 'true');
      } else {
        closeMobileMoreMenu();
      }
    }
    window.toggleMoreMenu = toggleMoreMenu;

    function buildMobileMoreMenu() {
      const list = document.getElementById('mobileMoreList');
      if (!list) return;

      list.innerHTML = '';

      const sidebarLinks = Array.from(document.querySelectorAll('.sidebar .nav-link'));

      sidebarLinks.forEach(link => {
        const page = link.getAttribute('data-page');
        const isInstallBtn = link.classList.contains('install-app-btn');
        const navItem = link.closest('.nav-item');

        if (page && mobilePrimaryPages.includes(page)) {
          return;
        }

        if (page === 'configuracoes') {
          return;
        }

        if (isInstallBtn && navItem && window.getComputedStyle(navItem).display === 'none') {
          return;
        }

        const icon = link.querySelector('i');
        const iconClass = icon ? icon.className : 'fas fa-circle';
        const labelEl = link.querySelector('.nav-text');
        const label = labelEl ? labelEl.textContent.trim() : 'Abrir';
        const hasPlanoBadge = !!link.querySelector('#nav-plano-badge');

        if (isInstallBtn) {
          const installItem = document.createElement('a');
          installItem.className = 'mobile-more-item';
          installItem.href = link.getAttribute('href') || '../instalacao-app.html?origem=sistema';
          installItem.innerHTML = `<i class="${iconClass}"></i><span class="mobile-more-label">${label}</span>`;
          list.appendChild(installItem);
          return;
        }

        if (!page) {
          return;
        }

        const item = document.createElement('a');
        item.href = '#';
        item.className = 'mobile-more-item nav-link';
        item.setAttribute('data-page', page);
        item.innerHTML = `<i class="${iconClass}"></i><span class="mobile-more-label">${label}</span>`;

        if (hasPlanoBadge) {
          const badge = document.createElement('span');
          badge.className = 'mobile-more-badge';
          badge.id = 'mobile-plano-badge';
          item.appendChild(badge);
        }

        list.appendChild(item);
      });
    }

    // Fun&ccedil;&otilde;es do Modal de Logout
    function abrirNotificacoes() {
      document.getElementById('userMenu').classList.remove('show');
      navegar('notificacoes');
    }

    function atualizarBadgeNotificacoes(total) {
      const badge  = document.getElementById('avatarNotifBadge');
      const mbadge = document.getElementById('menuNotifBadge');
      if (!badge || !mbadge) return;
      if (total > 0) {
        badge.textContent  = total;
        mbadge.textContent = total;
        badge.style.display  = 'flex';
        mbadge.style.display = 'inline-block';
      } else {
        badge.style.display  = 'none';
        mbadge.style.display = 'none';
      }
    }

    function atualizarBadgePlanos(total) {
      const badges = [
        document.getElementById('nav-plano-badge'),
        document.getElementById('mobile-plano-badge')
      ].filter(Boolean);
      if (!badges.length) return;

      badges.forEach(badge => {
        if (total > 0) {
          badge.textContent = total;
          badge.style.display = 'inline-block';
        } else {
          badge.style.display = 'none';
        }
      });
    }
    window.atualizarBadgePlanos = atualizarBadgePlanos;

    async function buscarPendentesPlano() {
      try {
        const r = await fetch('../bancodedados/solicitacoes_plano/contar_pendentes.php');
        const d = await r.json();
        if (d.sucesso) atualizarBadgePlanos(d.total);
      } catch(e) {}
    }

    // Busca de notifica��es (pode ser chamada a qualquer momento)
    async function buscarNotificacoes() {
      try {
        const r = await fetch('../bancodedados/notificacoes/contar_notificacoes.php');
        const d = await r.json();
        if (d.sucesso) atualizarBadgeNotificacoes(d.total);
      } catch (e) {}
    }

    // Polling de notifica��es a cada 60 segundos
    setInterval(buscarNotificacoes, 60000);

    function fazerLogout() {
      document.getElementById('logoutModal').classList.add('show');
      // Fechar o menu do usu&aacute;rio
      document.getElementById('userMenu').classList.remove('show');
    }

    function closeLogoutModal() {
      document.getElementById('logoutModal').classList.remove('show');
    }

    function confirmLogout() {
      closeLogoutModal();
      fetch('../bancodedados/usuarios/sessao.php?action=logout').finally(function() {
        window.location.href = '../login.html';
      });
    }

    // Fun&ccedil;&otilde;es do modal de sess&atilde;o expirada
    let _sessaoExpiradaAtiva = false;
    let _sessaoCountdownTimer = null;

    function showSessionExpiredModal() {
      if (_sessaoExpiradaAtiva) return; // evitar chamadas duplicadas
      _sessaoExpiradaAtiva = true;

      // Parar timers de keep-alive e check
      clearInterval(_keepAliveInterval);
      clearInterval(_checkSessaoInterval);

      const modal = document.getElementById('sessionExpiredModal');
      modal.style.display = 'flex';

      let segundos = 5;
      const countdownEl = document.getElementById('sessaoCountdown');
      _sessaoCountdownTimer = setInterval(() => {
        segundos--;
        if (countdownEl) countdownEl.textContent = segundos;
        if (segundos <= 0) {
          clearInterval(_sessaoCountdownTimer);
          forcarLogout('timeout');
        }
      }, 1000);
    }

    function redirectToLogin() {
      forcarLogout('manual');
    }

    /**
     * Realiza logout e redireciona para o login.
     * Pode ser chamada por iframes filhos via window.parent.forcarLogout()
     */
    function forcarLogout(motivo) {
      clearInterval(_keepAliveInterval);
      clearInterval(_checkSessaoInterval);
      clearInterval(_sessaoCountdownTimer);
      fetch('../bancodedados/usuarios/sessao.php?action=logout').finally(() => {
        window.location.href = '../login.html';
      });
    }
    window.forcarLogout = forcarLogout;

    // ===== Keep-alive: renova a sess�o a cada 2 minutos =====
    let _keepAliveInterval;
    async function _keepAlive() {
      try {
        const r = await fetch('../bancodedados/usuarios/sessao.php?action=keep_alive');
        if (r.status === 401) {
          showSessionExpiredModal();
        }
      } catch (e) {
        // falha de rede � n�o logar fora, o banner offline j� avisa
      }
    }
    _keepAliveInterval = setInterval(_keepAlive, 120000); // 2 min

    // ===== Verificar sess&atilde;o a cada 90 segundos =====
    let _checkSessaoInterval;
    _checkSessaoInterval = setInterval(async function() {
      // N�o verificar se estiver offline
      if (!navigator.onLine) return;
      try {
        const response = await fetch('../bancodedados/usuarios/sessao.php?action=check_session');
        const data = await response.json();
        if (!data.logado) {
          showSessionExpiredModal();
        }
      } catch (error) {
        // falha de rede tempor�ria � n�o deslogar
        console.warn('[Sess\u00e3o] Falha ao verificar (rede?):', error);
      }
    }, 90000); // 90 segundos

    // ===== Detec&ccedil;&atilde;o de conex&atilde;o offline/online =====
    (function() {
      const banner    = document.getElementById('banner-conexao');
      const bannerIcon= document.getElementById('banner-conexao-icon');
      const bannerTxt = document.getElementById('banner-conexao-texto');
      let hideTimer;

      function mostrarOffline() {
        clearTimeout(hideTimer);
        banner.className = 'offline show';
        bannerIcon.className = 'fas fa-wifi-slash';
        bannerTxt.textContent = 'Sem conex�o com a internet. Algumas fun��es podem n�o funcionar.';
      }

      function mostrarOnline() {
        clearTimeout(hideTimer);
        banner.className = 'online show';
        bannerIcon.className = 'fas fa-wifi';
        bannerTxt.textContent = 'Conex�o restaurada!';
        hideTimer = setTimeout(() => { banner.className = ''; }, 3000);
        // Recarregar iframe ao voltar online
        const iframe = document.getElementById('main-frame');
        if (iframe && iframe.src) {
          navegar(currentPage);
        }
      }

      window.addEventListener('offline', mostrarOffline);
      window.addEventListener('online',  mostrarOnline);

      // Estado inicial
      if (!navigator.onLine) mostrarOffline();
    })();

    const pageConfig = {
      dashboard: {
        title: 'Dashboard',
        url: 'dashboard/dashboard.html?v=3'
      },
      barbeiros: {
        title: 'Gest&#65533;o de Barbeiros',
        url: 'barbeiros/gestao_barbeiros.html'
      },
      agendamentos: {
        title: 'Agendamentos',
        url: 'agendamentos/agendamentos.html'
      },
      agenda_visualizacao: {
        title: 'Agenda',
        url: 'agendamentos/agenda-visualizacao.html'
      },
      horarios: {
        title: 'Gest&atilde;o de Hor&aacute;rios',
        url: 'horarios/gestao-horarios.html'
      },
      servicos: {
        title: 'Servi&ccedil;os',
        url: 'servicos/servicos.html'
      },
      clientes: {
        title: 'Clientes',
        url: 'clientes/clientes.html'
      },
      links_compartilhamento: {
        title: 'Links para Clientes',
        url: 'links/central-links.html'
      },
      produtos: {
        title: 'Produtos',
        url: 'produtos/produtos.html'
      },
      financeiro: {
        title: 'Gest&atilde;o Financeira',
        url: 'financeiro/gestao-financeiro.html'
      },
      <?php if ($acessoWhatsapp): ?>
      whatsapp_conexao: {
        title: 'WhatsApp',
        url: 'whatsapp/conectar-whatsapp.html'
      },
      <?php endif; ?>
      barbearia: {
        title: 'Estabelecimento',
        url: 'barbearia/gestao-barbearia.html'
      },
      administrador: {
        title: 'Administrador',
        url: 'administrador/gestao-usuarios.html'
      },
      incluirReceita: {
        title: 'Incluir Receita',
        url: 'financeiro/incluir-receita.html'
      },
      incluirDespesa: {
        title: 'Incluir Despesa',
        url: 'financeiro/incluir-despesa.html'
      },
      configuracoes: {
        title: 'Configura&ccedil;&otilde;es',
        url: 'configuracoes/configuracoes.html'
      },
      notificacoes: {
        title: 'Notifica\u00e7\u00f5es',
        url: 'notificacoes/notificacoes.html'
      },
      <?php if ($acessoSolicitacoesPlano): ?>
      solicitacoes_plano: {
        title: 'Solicita\u00e7\u00f5es de Plano',
        url: 'solicitacoes-plano/solicitacoes-plano.html'
      },
      <?php endif; ?>
      embaixador: {
        title: 'Painel Embaixador(a)',
        url: 'embaixador/embaixador.html'
      }
    };

    function navegar(pagina, params) {
      console.log('navegar() chamada com:', pagina, params);
      
      if (!pageConfig[pagina]) {
        console.error('P&#65533;gina n&#65533;o encontrada no pageConfig:', pagina);
        showNotification('P&#65533;gina n&#65533;o encontrada', 'error');
        return;
      }

      // Atualizar notifica��es ao abrir o Dashboard
      if (pagina === 'dashboard') {
        buscarNotificacoes();
        <?php if ($acessoSolicitacoesPlano): ?>buscarPendentesPlano();<?php endif; ?>
      }

      const config = pageConfig[pagina];
      const iframe = document.getElementById('main-frame');
      const loading = document.getElementById('iframe-loading');

      currentPage = pagina;
      
      document.getElementById('page-title').innerHTML = config.title;
      document.title = `MustaBarber - ${config.title}`;
      
      updateActiveNavItem(pagina);

      loading.classList.remove('hidden');
      iframe.style.display = 'none';

      let pageUrl = config.url;
      
      if (params) {
        const queryString = new URLSearchParams(params).toString();
        pageUrl += (pageUrl.includes('?') ? '&' : '?') + queryString;
      }

      let timeoutId;
      
      iframe.onload = function() {
        clearTimeout(timeoutId);
        // Remover loading imediatamente - otimizado para PWA
        loading.classList.add('hidden');
        iframe.style.display = 'block';

        // Fechar menu de usuário ao interagir dentro do iframe
        try {
          const iframeDoc = iframe.contentWindow.document;
          iframeDoc.addEventListener('click', () => {
            const userMenu = document.getElementById('userMenu');
            if (userMenu) userMenu.classList.remove('show');
          });
        } catch (e) {
          // Ignorar: se em algum cenário o iframe não permitir acesso ao documento
        }
        
        // Aplicar tema salvo ao iframe ap&#65533;s carregar
        const temaSalvo = localStorage.getItem('tema');
        if (temaSalvo === 'claro') {
          aplicarTemaNoIframe('claro');
        }
      };

      iframe.onerror = function() {
        clearTimeout(timeoutId);
        loading.classList.add('hidden');
        showNotification(`Erro ao carregar ${config.title}`, 'error');
        setTimeout(() => navegar('dashboard'), 500);
      };

      // Timeout de seguran\u00e7a - otimizado para PWA
      timeoutId = setTimeout(() => {
        if (!loading.classList.contains('hidden')) {
          loading.classList.add('hidden');
          iframe.style.display = 'block';
        }
      }, 1000);

      iframe.src = pageUrl;

      if (window.innerWidth <= 768) {
        const sidebar = document.querySelector('.sidebar');
        const overlay = document.getElementById('sidebar-overlay');
        if (sidebar) sidebar.classList.remove('active', 'mobile-open');
        if (overlay) overlay.classList.remove('active');
        closeMobileMoreMenu();
        document.body.style.overflow = '';
      }

      // Removido localStorage.setItem('currentPage', pagina) para sempre iniciar no dashboard
      
      if (pagina !== 'dashboard') {
        if (params) {
          const queryString = new URLSearchParams(params).toString();
          window.location.hash = pagina + '?' + queryString;
        } else {
          window.location.hash = pagina;
        }
      } else {
        if (window.location.hash) {
          history.replaceState('', document.title, window.location.pathname);
        }
      }
    }

    function navigateToPage(pageName) {
      navegar(pageName);
    }

    function forceBackToDashboard() {
      navegar('dashboard');
      showNotification('Voltando ao dashboard', 'info', 2000);
    }

    function updateActiveNavItem(pageName) {
      document.querySelectorAll('.nav-link').forEach(link => {
        link.classList.remove('active');
      });

      document.querySelectorAll(`[data-page="${pageName}"]`).forEach(link => {
        link.classList.add('active');
      });

      const mobileButtons = document.querySelectorAll('.mobile-bottom-nav .mobile-nav-btn');
      mobileButtons.forEach(btn => btn.classList.remove('active'));

      if (mobilePrimaryPages.includes(pageName)) {
        const mainBtn = document.querySelector(`.mobile-bottom-nav .mobile-nav-btn[data-page="${pageName}"]`);
        if (mainBtn) mainBtn.classList.add('active');
      } else {
        const moreBtn = document.getElementById('mobile-more-trigger');
        if (moreBtn) moreBtn.classList.add('active');
      }
    }

    function showNotification(message, type = 'info', duration = 3000) {
      const existing = document.querySelector('.notification');
      if (existing) {
        existing.remove();
      }
      
      const notification = document.createElement('div');
      notification.className = 'notification';
      notification.innerHTML = `
        <button class="close-btn" onclick="this.parentElement.remove()">
          <i class="fas fa-times"></i>
        </button>
        <div style="margin-right: 1.5rem;">
          <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}" style="margin-right: 0.5rem;"></i>
          ${message}
        </div>
      `;
      
      if (type === 'success') {
        notification.style.borderLeftColor = '#10b981';
      } else if (type === 'error') {
        notification.style.borderLeftColor = '#ef4444';
      }
      
      document.body.appendChild(notification);
      
      setTimeout(() => notification.classList.add('show'), 100);
      
      setTimeout(() => {
        notification.classList.remove('show');
        setTimeout(() => notification.remove(), 300);
      }, duration);
    }

    function toggleSidebar() {
      const sidebar = document.querySelector('.sidebar');
      const overlay = document.getElementById('sidebar-overlay');
      
      // S� toggle no mobile
      if (window.innerWidth <= 768) {
        sidebar.classList.toggle('active');
        overlay.classList.toggle('active');
        
        if (sidebar.classList.contains('active')) {
          document.body.style.overflow = 'hidden';
          <?php if ($acessoSolicitacoesPlano): ?>buscarPendentesPlano();<?php endif; ?>
        } else {
          document.body.style.overflow = '';
        }
      }
    }
    
    function toggleUserMenu() {
      const userMenu = document.getElementById('userMenu');
      userMenu.classList.toggle('show');
    }
    
    // Fechar menus ao clicar fora
    document.addEventListener('click', function(event) {
      const userMenu = document.getElementById('userMenu');
      const userAvatarWrapper = document.querySelector('.avatar-wrapper');
      
      // Fechar user menu se clicar fora
      if (
        userMenu &&
        !userMenu.contains(event.target) &&
        (!userAvatarWrapper || !userAvatarWrapper.contains(event.target))
      ) {
        userMenu.classList.remove('show');
      }
    });

    document.addEventListener('keydown', function(event) {
      if (event.key === 'Escape') {
        closeMobileMoreMenu();
      }
    });

    // Detectar se est&aacute; sendo executado no navegador (n&atilde;o como PWA)
    function checkIfBrowser() {
      const isStandalone = window.matchMedia('(display-mode: standalone)').matches;
      const isIOSStandalone = window.navigator.standalone === true;
      const isInBrowser = !isStandalone && !isIOSStandalone;
      
      if (isInBrowser) {
        document.getElementById('install-app-link').style.display = 'block';
      }
    }

    document.addEventListener('DOMContentLoaded', function() {
      const mobileMoreTrigger = document.getElementById('mobile-more-trigger');
      if (mobileMoreTrigger) {
        mobileMoreTrigger.addEventListener('click', function() {
          toggleMoreMenu();
        });
      }

      // Verificar se est&aacute; no navegador
      checkIfBrowser();
      buildMobileMoreMenu();
      
      document.querySelectorAll('.nav-link').forEach(link => {
        link.addEventListener('click', function(event) {
          // Permitir navega&ccedil;&atilde;o normal para link de instala&ccedil;&atilde;o
          if (this.classList.contains('install-app-btn')) {
            return; // Deixa o link funcionar normalmente
          }
          
          event.preventDefault();
          const pageName = this.getAttribute('data-page');
          if (pageName) {
            navigateToPage(pageName);
            
            // Fechar sidebar no mobile ap&#65533;s clicar
            if (window.innerWidth <= 768) {
              const sidebar = document.querySelector('.sidebar');
              const overlay = document.getElementById('sidebar-overlay');
              if (sidebar) sidebar.classList.remove('active');
              if (overlay) overlay.classList.remove('active');
              closeMobileMoreMenu();
              document.body.style.overflow = '';
            }
          }
        });
      });

      // Sempre iniciar pelo dashboard, ignorando hash e p&#65533;gina salva
      setTimeout(() => {
        navegar('dashboard');
      }, 100);
      
      window.addEventListener('hashchange', function() {
        const hash = window.location.hash.substring(1);
        if (!hash) {
          navegar('dashboard');
          return;
        }
        
        const [page, queryString] = hash.split('?');
        
        if (page && pageConfig[page]) {
          if (queryString) {
            const params = Object.fromEntries(new URLSearchParams(queryString));
            navegar(page, params);
          } else {
            navegar(page);
          }
        }
      });
      
      // Fechar sidebar ao redimensionar para desktop
      window.addEventListener('resize', () => {
        if (window.innerWidth > 768) {
          const sidebar = document.querySelector('.sidebar');
          const overlay = document.getElementById('sidebar-overlay');
          if (sidebar) sidebar.classList.remove('active');
          if (overlay) overlay.classList.remove('active');
          closeMobileMoreMenu();
          document.body.style.overflow = '';
        }
      });
    });

    window.toggleSidebar = toggleSidebar;
    window.toggleUserMenu = toggleUserMenu;
    window.fazerLogout = fazerLogout;
    window.closeLogoutModal = closeLogoutModal;
    window.confirmLogout = confirmLogout;
    
    // Fun&#65533;&#65533;o para alternar tema
    function alternarTema() {
      const body = document.body;
      const iconeTema = document.getElementById('iconeTema');
      const textoTema = document.getElementById('textoTema');
      
      body.classList.toggle('tema-claro');
      
      const temaAtual = body.classList.contains('tema-claro') ? 'claro' : 'escuro';
      
      if (temaAtual === 'claro') {
        iconeTema.className = 'fas fa-sun';
        textoTema.textContent = 'Tema Claro';
        localStorage.setItem('tema', 'claro');
      } else {
        iconeTema.className = 'fas fa-moon';
        textoTema.textContent = 'Tema Escuro';
        localStorage.setItem('tema', 'escuro');
      }
      
      // Aplicar tema no iframe imediatamente
      aplicarTemaNoIframe(temaAtual);
      
      // Fechar menu
      toggleUserMenu();
      
      // Simular clique na aba ativa para recarregar o conte&#65533;do com o novo tema
      setTimeout(() => {
        if (currentPage) {
          navegar(currentPage);
        }
      }, 50);
    }
    
    // Fun&#65533;&#65533;o para aplicar tema no iframe
    function aplicarTemaNoIframe(tema) {
      const iframe = document.getElementById('content-frame');
      if (!iframe) return;
      
      // Tentar aplicar o tema v&#65533;rias vezes para garantir que funcione
      const tentarAplicarTema = (tentativas = 0) => {
        try {
          if (iframe.contentWindow && iframe.contentWindow.document && iframe.contentWindow.document.body) {
            const iframeDoc = iframe.contentWindow.document;
            const iframeBody = iframeDoc.body;
            
            if (tema === 'claro') {
              iframeBody.classList.add('tema-claro');
            } else {
              iframeBody.classList.remove('tema-claro');
            }
            
            // Salvar tema no localStorage do iframe tamb&#65533;m
            try {
              iframe.contentWindow.localStorage.setItem('tema', tema);
            } catch (e) {
              console.log('N&#65533;o foi poss&#65533;vel salvar tema no localStorage do iframe');
            }
            
            return true;
          }
        } catch (e) {
          console.log('Tentativa ' + (tentativas + 1) + ' falhou:', e);
        }
        
        // Se falhou e ainda tem tentativas, tentar novamente
        if (tentativas < 3) {
          setTimeout(() => tentarAplicarTema(tentativas + 1), 100);
        }
        
        return false;
      };
      
      tentarAplicarTema();
    }
    
    // Aplicar tema salvo ao carregar p&#65533;gina
    document.addEventListener('DOMContentLoaded', function() {
      const temaSalvo = localStorage.getItem('tema');
      const iconeTema = document.getElementById('iconeTema');
      const textoTema = document.getElementById('textoTema');
      
      if (temaSalvo === 'claro') {
        document.body.classList.add('tema-claro');
        iconeTema.className = 'fas fa-sun';
        textoTema.textContent = 'Tema Claro';
        
        // Aplicar tema no iframe quando carregar
        const iframe = document.getElementById('content-frame');
        if (iframe) {
          iframe.addEventListener('load', function() {
            aplicarTemaNoIframe('claro');
          });
        }
      }
      
      // Observer para detectar mudan&#65533;as de tema no body e aplicar no iframe
      const observerTema = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
          if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
            const temaAtual = document.body.classList.contains('tema-claro') ? 'claro' : 'escuro';
            aplicarTemaNoIframe(temaAtual);
          }
        });
      });
      
      // Iniciar observa&#65533;&#65533;o do body
      observerTema.observe(document.body, {
        attributes: true,
        attributeFilter: ['class']
      });
    });
    
    // Fun&#65533;&#65533;o para abrir modal de foto
    function abrirModalFoto() {
      document.getElementById('modalFotoOverlay').classList.add('show');
      toggleUserMenu();
    }
    
    // Fun&#65533;&#65533;o para fechar modal de foto
    function fecharModalFoto() {
      document.getElementById('modalFotoOverlay').classList.remove('show');
      document.getElementById('fotoInput').value = '';
      document.getElementById('fileNameDisplay').textContent = 'Nenhum arquivo selecionado';
    }
    
    // Fun&#65533;&#65533;o para preview da foto
    function previewFoto(event) {
      const file = event.target.files[0];
      const preview = document.getElementById('fotoPreview');
      const fileNameDisplay = document.getElementById('fileNameDisplay');
      
      if (file) {
        // Validar tamanho (m&#65533;ximo 5MB)
        if (file.size > 5 * 1024 * 1024) {
          alert('A imagem deve ter no m&#65533;ximo 5MB');
          event.target.value = '';
          return;
        }
        
        // Validar tipo
        if (!file.type.startsWith('image/')) {
          alert('Por favor, selecione apenas arquivos de imagem');
          event.target.value = '';
          return;
        }
        
        fileNameDisplay.textContent = file.name;
        
        const reader = new FileReader();
        reader.onload = function(e) {
          preview.innerHTML = '<img src="' + e.target.result + '" alt="Preview">';
        };
        reader.readAsDataURL(file);
      }
    }
    
    // Fun&#65533;&#65533;o para salvar foto
    async function salvarFoto() {
      const fileInput = document.getElementById('fotoInput');
      const file = fileInput.files[0];
      
      if (!file) {
        alert('Por favor, selecione uma foto');
        return;
      }
      
      const btnSalvar = document.getElementById('btnSalvarFoto');
      btnSalvar.disabled = true;
      btnSalvar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
      
      try {
        const formData = new FormData();
        formData.append('foto', file);
        
        const response = await fetch('../bancodedados/usuarios/atualizar_foto.php', {
          method: 'POST',
          body: formData
        });
        
        const resultado = await response.json();
        
        if (resultado.sucesso) {
          alert('Foto atualizada com sucesso!');
          fecharModalFoto();
          // Atualizar foto no header
          location.reload();
        } else {
          alert('Erro: ' + resultado.mensagem);
        }
      } catch (error) {
        console.error('Erro ao salvar foto:', error);
        alert('Erro ao salvar foto. Tente novamente.');
      } finally {
        btnSalvar.disabled = false;
        btnSalvar.innerHTML = '<i class="fas fa-save"></i> Salvar';
      }
    }
    
    window.alternarTema = alternarTema;
    window.abrirModalFoto = abrirModalFoto;
    window.fecharModalFoto = fecharModalFoto;
    window.previewFoto = previewFoto;
    window.salvarFoto = salvarFoto;
  </script>
  
  <!-- Modal de Alterar Foto -->
  <div class="modal-foto-overlay" id="modalFotoOverlay">
    <div class="modal-foto">
      <div class="modal-foto-header">
        <h2 class="modal-foto-title">Alterar Foto de Perfil</h2>
        <button class="modal-foto-close" onclick="fecharModalFoto()">
          <i class="fas fa-times"></i>
        </button>
      </div>
      
      <div class="foto-preview" id="fotoPreview">
        <?php if (!empty($usuario['foto_caminho']) && file_exists('../' . $usuario['foto_caminho'])): ?>
          <img src="../<?php echo htmlspecialchars($usuario['foto_caminho']); ?>" alt="Foto atual">
        <?php else: ?>
          <?php echo $primeiraLetra; ?>
        <?php endif; ?>
      </div>
      
      <div class="file-input-wrapper">
        <label class="file-input-label">
          <i class="fas fa-camera"></i> Escolher Foto
          <input type="file" id="fotoInput" accept="image/*" onchange="previewFoto(event)">
        </label>
        <div class="file-name" id="fileNameDisplay">Nenhum arquivo selecionado</div>
      </div>
      
      <div class="modal-foto-buttons">
        <button class="btn-foto btn-foto-cancelar" onclick="fecharModalFoto()">
          <i class="fas fa-times"></i> Cancelar
        </button>
        <button class="btn-foto btn-foto-salvar" id="btnSalvarFoto" onclick="salvarFoto()">
          <i class="fas fa-save"></i> Salvar
        </button>
      </div>
    </div>
  </div>

  <script>
    // --- Renovar assinatura gratuitamente (usu\u00e1rio isento) ---
    async function renovarGratuitaOverlay() {
      var btn = document.getElementById('btnRenovarGratuita');
      var msg = document.getElementById('overlayStatusMsg');
      if (!btn) return;

      btn.disabled = true;
      btn.innerHTML = '<span class="overlay-spinner"></span> Renovando...';
      msg.className = 'overlay-status-msg';
      msg.textContent = '';

      try {
        var resp = await fetch('../bancodedados/pagamentos/renovar_gratuita.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({})
        });
        var dados = await resp.json();

        if (dados.sucesso) {
          mostrarSucessoPagamento();
        } else {
          msg.className = 'overlay-status-msg error';
          msg.textContent = dados.mensagem || 'Erro ao renovar. Tente novamente.';
          btn.disabled = false;
          btn.innerHTML = '<i class="fas fa-sync-alt"></i> Renovar Gratuitamente';
        }
      } catch (err) {
        msg.className = 'overlay-status-msg error';
        msg.textContent = 'Erro de conex\u00e3o. Tente novamente.';
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-sync-alt"></i> Renovar Gratuitamente';
      }
    }

    // --- Gerar cobran\u00e7a e abrir checkout Asaas ---
    async function gerarCobrancaOverlay() {
      var btn = document.getElementById('btnGerarCobranca');
      var msg = document.getElementById('overlayStatusMsg');
      if (!btn) return;

      btn.disabled = true;
      btn.innerHTML = '<span class="overlay-spinner"></span> Gerando pagamento...';
      msg.className = 'overlay-status-msg';
      msg.textContent = '';

      try {
        var resp = await fetch('../bancodedados/pagamentos/criar_cobranca.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({})
        });
        var dados = await resp.json();

        if (!dados.sucesso) {
          msg.className = 'overlay-status-msg error';
          msg.textContent = dados.mensagem || 'Erro ao gerar cobran\u00e7a.';
          btn.disabled = false;
          btn.innerHTML = '<i class="fas fa-barcode"></i> Gerar Pagamento (PIX / Cart\u00e3o)';
          return;
        }

        // Detectar se &#65533; mobile
        var isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);

        if (isMobile) {
          // No mobile: redirecionar na mesma aba para evitar bloqueio de popup e abertura de apps nativos
          // Guardar estado para saber que estamos aguardando pagamento
          sessionStorage.setItem('asaas_aguardando_pagamento', '1');
          window.location.href = dados.invoiceUrl;
          return;
        }

        // Desktop: abrir checkout em nova janela
        var janela = window.open(dados.invoiceUrl, 'pagamento_asaas', 'width=900,height=700,scrollbars=yes');

        msg.className = 'overlay-status-msg info';
        msg.textContent = '\u23F3 Janela de pagamento aberta. Ap\u00f3s pagar, aguarde a confirma\u00e7\u00e3o autom\u00e1tica...';
        btn.innerHTML = '<span class="overlay-spinner"></span> Aguardando pagamento...';

        // Polling inicia automaticamente - Asaas n&#65533;o usa postMessage

        // Monitor r\u00e1pido: detecta fechamento da janela a cada 1.5s
        var windowChecker = setInterval(async function() {
          if (!janela || !janela.closed) return;
          clearInterval(windowChecker);
          // \u00daltima verifica\u00e7\u00e3o para garantir que n\u00e3o pagou antes de fechar
          var okFinal = await checarAssinaturaAtiva();
          if (okFinal) {
            if (window._mpPolling) clearInterval(window._mpPolling);
            mostrarSucessoPagamento();
          } else {
            if (window._mpPolling) clearInterval(window._mpPolling);
            msg.className = 'overlay-status-msg error';
            msg.textContent = 'Pagamento cancelado. A janela de pagamento foi fechada.';
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-barcode"></i> Gerar Pagamento (PIX / Cart\u00e3o)';
          }
        }, 1500);

        // Polling de confirma\u00e7\u00e3o (verifica a cada 8s por at\u00e9 10 min)
        var tentativas = 0;
        var maxTentativas = 75;
        var polling = setInterval(async function() {
          tentativas++;
          if (tentativas > maxTentativas) {
            clearInterval(polling);
            clearInterval(windowChecker);
            msg.className = 'overlay-status-msg error';
            msg.textContent = 'Tempo esgotado. Se voc\u00ea pagou, aguarde alguns minutos e recarregue a p\u00e1gina.';
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-barcode"></i> Tentar Novamente';
            return;
          }
          var ok = await checarAssinaturaAtiva();
          if (ok) {
            clearInterval(polling);
            clearInterval(windowChecker);
            if (janela && !janela.closed) janela.close();
            mostrarSucessoPagamento();
          }
        }, 8000);

        // Guardar os intervals para poder parar se postMessage chegar primeiro
        window._mpPolling = polling;
        window._mpWindowChecker = windowChecker;

      } catch (err) {
        msg.className = 'overlay-status-msg error';
        msg.textContent = 'Erro de conex\u00e3o. Tente novamente.';
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-barcode"></i> Gerar Pagamento (PIX / Cart\u00e3o)';
      }
    }

    // Verifica&#65533;&#65533;o manual/p&#65533;s-postMessage
    async function verificarPagamentoOverlay() {
      if (window._mpPolling) clearInterval(window._mpPolling);
      if (window._mpWindowChecker) clearInterval(window._mpWindowChecker);
      var ok = await checarAssinaturaAtiva();
      if (ok) {
        mostrarSucessoPagamento();
      } else {
        var msg = document.getElementById('overlayStatusMsg');
        var btn = document.getElementById('btnGerarCobranca');
        msg.className = 'overlay-status-msg info';
        msg.textContent = 'Pagamento em processamento. Aguarde alguns instantes...';
        
        // Tentar mais 3 vezes
        var tries = 0;
        var t = setInterval(async function() {
          tries++;
          var ok2 = await checarAssinaturaAtiva();
          if (ok2 || tries >= 3) {
            clearInterval(t);
            if (ok2) mostrarSucessoPagamento();
            else {
              msg.className = 'overlay-status-msg error';
              msg.textContent = 'Confirme o pagamento e recarregue a p\u00e1gina se necess\u00e1rio.';
              btn.disabled = false;
              btn.innerHTML = '<i class="fas fa-barcode"></i> Gerar Pagamento (PIX / Cart\u00e3o)';
            }
          }
        }, 5000);
      }
    }

    // Consultar PHP se assinatura est&#65533; ativa
    async function checarAssinaturaAtiva() {
      try {
        var r = await fetch('../bancodedados/pagamentos/verificar_assinatura.php');
        var d = await r.json();
        return d.sucesso && d.assinatura_ok === true;
      } catch(e) {
        return false;
      }
    }

    // Remover overlay e liberar o sistema
    function mostrarSucessoPagamento() {
      var overlay = document.getElementById('overlay-assinatura');
      if (!overlay) return;
      var card = overlay.querySelector('.overlay-card');
      card.innerHTML = '<div class="overlay-icon">\u2705</div>' +
        '<div class="overlay-title">Pagamento Confirmado!</div>' +
        '<div class="overlay-subtitle">Sua assinatura foi renovada com sucesso. O sistema ser\u00e1 liberado em instantes.</div>';
      setTimeout(function() {
        overlay.style.transition = 'opacity 0.5s';
        overlay.style.opacity = '0';
        setTimeout(function() { overlay.remove(); }, 500);
      }, 2000);
    }

    // Logout pelo overlay
    function realizarLogoutOverlay() {
      fetch('../bancodedados/usuarios/sessao.php?action=logout')
        .finally(function() { window.location.href = '../login.html'; });
    }

    // --- Tratar retorno do pagamento via mobile (mesma aba) ---
    (function tratarRetornoMobile() {
      var params = new URLSearchParams(window.location.search);
      var mpStatus = params.get('asaas_status');
      if (!mpStatus) return;

      // Limpar a URL para n&#65533;o processar novamente ao recarregar
      var urlLimpa = window.location.pathname;
      window.history.replaceState({}, '', urlLimpa);

      // Limpar flag do sessionStorage
      sessionStorage.removeItem('asaas_aguardando_pagamento');

      if (mpStatus === 'approved') {
        // Pagamento aprovado - verificar e mostrar sucesso
        checarAssinaturaAtiva().then(function(ok) {
          if (ok) {
            mostrarSucessoPagamento();
          } else {
            // Webhook pode demorar um pouco, tentar mais 3 vezes
            var tries = 0;
            var t = setInterval(function() {
              tries++;
              checarAssinaturaAtiva().then(function(ok2) {
                if (ok2 || tries >= 5) {
                  clearInterval(t);
                  if (ok2) {
                    mostrarSucessoPagamento();
                  } else {
                    var msg = document.getElementById('overlayStatusMsg');
                    if (msg) {
                      msg.className = 'overlay-status-msg info';
                      msg.textContent = 'Pagamento em processamento. Aguarde e recarregue a p\u00e1gina.';
                    }
                    var btn = document.getElementById('btnGerarCobranca');
                    if (btn) {
                      btn.disabled = false;
                      btn.innerHTML = '<i class="fas fa-sync"></i> Verificar Pagamento';
                    }
                  }
                }
              });
            }, 4000);
          }
        });
      } else if (mpStatus === 'pending') {
        var msg = document.getElementById('overlayStatusMsg');
        if (msg) {
          msg.className = 'overlay-status-msg info';
          msg.textContent = 'Pagamento pendente. Se voc\u00ea j\u00e1 pagou, aguarde alguns instantes...';
        }
        // Polling para verificar se o pagamento foi confirmado
        var tries2 = 0;
        var t2 = setInterval(function() {
          tries2++;
          checarAssinaturaAtiva().then(function(ok3) {
            if (ok3 || tries2 >= 10) {
              clearInterval(t2);
              if (ok3) {
                mostrarSucessoPagamento();
              } else {
                if (msg) {
                  msg.className = 'overlay-status-msg error';
                  msg.textContent = 'Confirme o pagamento e recarregue a p\u00e1gina.';
                }
                var btn = document.getElementById('btnGerarCobranca');
                if (btn) {
                  btn.disabled = false;
                  btn.innerHTML = '<i class="fas fa-barcode"></i> Gerar Pagamento (PIX / Cart\u00e3o)';
                }
              }
            }
          });
        }, 5000);
      } else {
        // rejected ou outro status
        var msg = document.getElementById('overlayStatusMsg');
        if (msg) {
          msg.className = 'overlay-status-msg error';
          msg.textContent = 'Pagamento n\u00e3o aprovado. Tente novamente.';
        }
        var btn = document.getElementById('btnGerarCobranca');
        if (btn) {
          btn.disabled = false;
          btn.innerHTML = '<i class="fas fa-barcode"></i> Gerar Pagamento (PIX / Cart\u00e3o)';
        }
      }
    })();
  </script>
</body>
</html>
