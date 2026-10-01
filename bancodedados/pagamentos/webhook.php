<?php
/**
 * Webhook - Asaas
 * Sistema: MustaBarber
 *
 * Recebe notifica&#65533;&#65533;es autom&#65533;ticas do Asaas e atualiza o status
 * da assinatura quando o pagamento for aprovado.
 *
 * URL p&#65533;blica que deve ser configurada no painel do Asaas:
 *   https://mustech.com.br/mustabarber/bancodedados/pagamentos/webhook.php
 */

// Desabilitar sa&#65533;da de erros para n&#65533;o quebrar a resposta JSON
@error_reporting(0);
@ini_set('display_errors', 0);

require_once __DIR__ . '/../conexao.php';

// ============================================================
// CONFIGURA&#65533;&#65533;O &#65533; mesmo token usado em criar_cobranca.php
// ============================================================
define('ASAAS_API_KEY', getenv('ASAAS_API_KEY') ?: '');
define('ASAAS_WEBHOOK_TOKEN', getenv('ASAAS_WEBHOOK_TOKEN') ?: '');
define('ASAAS_API_URL', 'https://api.asaas.com/v3');

//  Log em arquivo para diagn&#65533;stico
$webhookLogFile = __DIR__ . '/debug_webhook.log';

function webhookLog(string $msg): void {
    global $webhookLogFile;
    $linha = date('Y-m-d H:i:s') . " - {$msg}";
    file_put_contents($webhookLogFile, $linha . "\n", FILE_APPEND);
    error_log('[MustaBarber Webhook] ' . $msg);
}

// ============================================================
// Responder 200 rapidamente para o Asaas n&#65533;o reenviar
// ============================================================
http_response_code(200);
header('Content-Type: application/json');

// Validar token de autentica&#65533;&#65533;o do Asaas
$asaasToken = $_SERVER['HTTP_ASAAS_ACCESS_TOKEN'] ?? '';
if (ASAAS_WEBHOOK_TOKEN && $asaasToken !== ASAAS_WEBHOOK_TOKEN) {
    webhookLog("Token inv&#65533;lido recebido: {$asaasToken}");
    echo json_encode(['ok' => false, 'msg' => 'token inv&#65533;lido']);
    exit;
}

$rawBody = file_get_contents('php://input');
$dados   = json_decode($rawBody, true);

webhookLog("Recebido: body={$rawBody}");

// Validar se recebeu dados v&#65533;lidos
if (!$dados || !isset($dados['event'])) {
    echo json_encode(['ok' => true, 'msg' => 'ignorado - sem evento']);
    exit;
}

$evento   = $dados['event'] ?? '';
$payment  = $dados['payment'] ?? [];

webhookLog("Evento: {$evento} | Payment ID: " . ($payment['id'] ?? 'N/A') . " | Status: " . ($payment['status'] ?? 'N/A') . " | Ref: " . ($payment['externalReference'] ?? 'N/A'));

// Eventos que indicam pagamento confirmado no Asaas
$eventosAprovados = [
    'PAYMENT_RECEIVED',   // Pagamento recebido (PIX, boleto)
    'PAYMENT_CONFIRMED',  // Pagamento confirmado
];

if (!in_array($evento, $eventosAprovados)) {
    // Logar eventos de falha para diagn&#65533;stico
    if (in_array($evento, ['PAYMENT_OVERDUE', 'PAYMENT_DELETED', 'PAYMENT_REFUNDED', 'PAYMENT_CHARGEBACK_REQUESTED'])) {
        webhookLog("Evento de falha/cancelamento: {$evento}");
    }
    echo json_encode(['ok' => true, 'msg' => 'evento ignorado: ' . $evento]);
    exit;
}

// Extrair dados do pagamento
$externalRef    = $payment['externalReference'] ?? '';
$billingType    = $payment['billingType'] ?? '';  // PIX, CREDIT_CARD, BOLETO
$paymentId      = $payment['id'] ?? '';
$valor          = $payment['value'] ?? 0;

if (!$externalRef) {
    webhookLog("Pagamento sem externalReference - ignorando. ID: {$paymentId}");
    echo json_encode(['ok' => true, 'msg' => 'sem refer&#65533;ncia externa']);
    exit;
}

$codigoBarbeiro = (int) $externalRef;
$formaPagamento = traduzirFormaPagamento($billingType);

// Expor payment_id globalmente para idempot&#234;ncia dentro de processarAprovacao()
$GLOBALS['_ASAAS_PAYMENT_ID'] = $paymentId;
$GLOBALS['_ASAAS_PAYMENT_VALOR'] = (float)$valor;
$GLOBALS['_ASAAS_PAYMENT_RAW'] = $payment;

processarAprovacao($codigoBarbeiro, $formaPagamento, $conexao);

echo json_encode(['ok' => true, 'msg' => 'pagamento processado']);

// ============================================================
// Fun&#65533;&#65533;o principal: atualiza a assinatura no banco de dados
// ============================================================
function processarAprovacao(int $codigoBarbeiro, string $formaPagamento, $conexao): void {
    if ($codigoBarbeiro <= 0) return;

    try {
        // Buscar assinatura mais recente
        $stmt = $conexao->prepare(
            "SELECT a.codigo, a.data_vencimento, a.observacoes, a.data_pagamento,
                    a.valor_mensalidade,
                    COALESCE(u.dia_vencimento, 1) AS dia_vencimento
             FROM assinaturas a
             INNER JOIN usuarios u ON u.codigo = a.codigo_barbeiro
             WHERE a.codigo_barbeiro = :cod
             ORDER BY a.codigo DESC LIMIT 1"
        );
        $stmt->bindParam(':cod', $codigoBarbeiro, PDO::PARAM_INT);
        $stmt->execute();
        $assinatura = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$assinatura) {
            webhookLog("Assinatura n&#65533;o encontrada para barbeiro {$codigoBarbeiro}");
            return;
        }

        // --- Idempot&#234;ncia: verificar se este pagamento Asaas j&#225; foi processado ---
        // O payment_id fica salvo nas observa&#231;&#245;es como "asaas_payment_id:XXXXX"
        $obsAtual  = $assinatura['observacoes'] ?? '';
        $paymentId = $GLOBALS['_ASAAS_PAYMENT_ID'] ?? '';

        if ($paymentId && $assinatura['data_pagamento'] === date('Y-m-d')) {
            // Verificar se a data de pagamento j&#225; foi registrada hoje com mesmo payment_id
            if (strpos($obsAtual, "processed:{$paymentId}") !== false) {
                webhookLog("Pagamento {$paymentId} do barbeiro {$codigoBarbeiro} j&#225; foi processado - ignorando retry do Asaas");
                return;
            }
        }

        // --- Calcular novo vencimento ---
        $diaVenc = (int) ($assinatura['dia_vencimento'] ?? 1);
        if ($diaVenc < 1 || $diaVenc > 28) $diaVenc = 1;

        $isPrimeiraCobranca = ($assinatura['data_pagamento'] === null);

        if ($isPrimeiraCobranca) {
            // Primeira cobrança paga: próximo vencimento = próxima ocorrência do dia preferido
            $hojeObj = new \DateTime(date('Y-m-d'));
            $ano     = (int) $hojeObj->format('Y');
            $mes     = (int) $hojeObj->format('n');
            $diasNoMesAtual = cal_days_in_month(CAL_GREGORIAN, $mes, $ano);
            $proxVenc = new \DateTime(sprintf('%04d-%02d-%02d', $ano, $mes, min($diaVenc, $diasNoMesAtual)));
            if ($proxVenc <= $hojeObj) {
                $proxVenc->modify('+1 month');
                $diasProx = cal_days_in_month(CAL_GREGORIAN, (int)$proxVenc->format('n'), (int)$proxVenc->format('Y'));
                $proxVenc->setDate((int)$proxVenc->format('Y'), (int)$proxVenc->format('n'), min($diaVenc, $diasProx));
            }
            $novoVencimento = $proxVenc->format('Y-m-d');
            webhookLog("Primeiro pagamento barbeiro {$codigoBarbeiro}: próx venc={$novoVencimento} (dia_vencimento={$diaVenc})");
        } else {
            // Renovação padrão: +1 mês no dia preferido do barbeiro
            $hoje        = strtotime(date('Y-m-d'));
            $vencAtual   = strtotime($assinatura['data_vencimento']);
            $baseCalculo = ($vencAtual >= $hoje) ? $vencAtual : $hoje;

            $mesBase = (int) date('m', $baseCalculo);
            $anoBase = (int) date('Y', $baseCalculo);
            $mesProx = $mesBase + 1;
            $anoProx = $anoBase;
            if ($mesProx > 12) { $mesProx = 1; $anoProx++; }

            $diasNoMes      = cal_days_in_month(CAL_GREGORIAN, $mesProx, $anoProx);
            $diaFinal       = min($diaVenc, $diasNoMes);
            $novoVencimento = sprintf('%04d-%02d-%02d', $anoProx, $mesProx, $diaFinal);
        }

        // Marcar como processado para evitar reprocessamento de retries do Asaas
        $marcadorProcessado = $paymentId ? "|processed:{$paymentId}" : '';
        // Preservar apenas campos relevantes das observa&#231;&#245;es (n&#227;o acumular marcadores antigos)
        $obsLimpa = preg_replace('/\|?processed:[^\|]+/', '', $obsAtual);
        // Remover valor_prox_cobranca (consumido ao gerar o charge) e marcador de primeira cobrança
        $obsLimpa = preg_replace('/\|?valor_prox_cobranca:[\d.]+/', '', $obsLimpa);
        $obsLimpa = preg_replace('/\|?primeira_cobranca:true/', '', $obsLimpa);
        $novaObs  = $obsLimpa . $marcadorProcessado;

        $stmtUp = $conexao->prepare(
            "UPDATE assinaturas SET
                status_pagamento = 2,
                data_pagamento   = CURRENT_DATE,
                data_vencimento  = :novo_venc,
                forma_pagamento  = :forma,
                observacoes      = :obs
             WHERE codigo_barbeiro = :cod
               AND codigo = :codAssin"
        );
        $stmtUp->bindParam(':novo_venc', $novoVencimento);
        // Garantir que a string est&#65533; em UTF-8 para o PostgreSQL
        if (!mb_check_encoding($formaPagamento, 'UTF-8')) {
            $formaPagamento = mb_convert_encoding($formaPagamento, 'UTF-8', 'Windows-1252');
        }
        $stmtUp->bindParam(':forma',    $formaPagamento);
        $stmtUp->bindParam(':obs',      $novaObs);
        $stmtUp->bindParam(':cod',      $codigoBarbeiro, \PDO::PARAM_INT);
        $stmtUp->bindParam(':codAssin', $assinatura['codigo'], \PDO::PARAM_INT);
        $stmtUp->execute();

        webhookLog("Assinatura {$assinatura['codigo']} do barbeiro {$codigoBarbeiro} renovada. Venc anterior: {$assinatura['data_vencimento']} | Novo venc: {$novoVencimento} | Forma: {$formaPagamento}");

        // --- Verificar pending_upgrade: aplicar novo plano se cobranca era de upgrade ---
        if ($obsAtual && strpos($obsAtual, 'pending_upgrade:') !== false) {
            preg_match('/pending_upgrade:([a-z]+)/', $obsAtual, $matchesPlano);
            preg_match('/valor_upgrade:([\d.]+)/', $obsAtual, $matchesValor);
            $novoPlano  = $matchesPlano[1] ?? null;
            $valorPlano = isset($matchesValor[1]) ? (float) $matchesValor[1] : null;

            $planosValidos = [
                'starter'         => ['valor' => 52.90, 'perfil' => 2],
                'solo-pro'        => ['valor' => 64.90, 'perfil' => 3],
                'equipe'          => ['valor' => 94.90, 'perfil' => 4],
                'estabelecimento' => ['valor' => 136.90, 'perfil' => 6],
                'master'          => ['valor' => 199.90, 'perfil' => 7],
                // compatibilidade com nomes antigos
                'essencial' => ['valor' => 52.90, 'perfil' => 2],
                'premium'   => ['valor' => 64.90, 'perfil' => 3],
            ];
            if ($novoPlano && array_key_exists($novoPlano, $planosValidos)) {
                $valorFinal  = $planosValidos[$novoPlano]['valor'];
                $novoPerfil  = $planosValidos[$novoPlano]['perfil'];

                $stmtPlano = $conexao->prepare(
                    "UPDATE usuarios SET plano_trial = :plano, perfil = :perfil WHERE codigo = :cod_user"
                );
                $stmtPlano->bindParam(':plano',    $novoPlano);
                $stmtPlano->bindParam(':perfil',   $novoPerfil, \PDO::PARAM_INT);
                $stmtPlano->bindParam(':cod_user', $codigoBarbeiro, \PDO::PARAM_INT);
                $stmtPlano->execute();

                // Limpar pending_upgrade e valor_upgrade das observacoes
                $novasObs = preg_replace('/\|?pending_upgrade:[a-z]+/', '', $obsAtual);
                $novasObs = preg_replace('/\|?valor_upgrade:[\d.]+/', '', $novasObs);
                $novasObs = trim($novasObs, '|');

                $stmtObsUp = $conexao->prepare(
                    "UPDATE assinaturas SET valor_mensalidade = :valor, observacoes = :obs
                     WHERE codigo = :cod_assin"
                );
                $stmtObsUp->bindParam(':valor',     $valorFinal);
                $stmtObsUp->bindParam(':obs',       $novasObs);
                $stmtObsUp->bindParam(':cod_assin', $assinatura['codigo'], \PDO::PARAM_INT);
                $stmtObsUp->execute();

                webhookLog("Upgrade aplicado: barbeiro {$codigoBarbeiro} -> plano {$novoPlano} | valor_mensalidade={$valorFinal}");
            }
        }
        // --- Fim logica de upgrade ---

        // --- L\xF3gica de indica\xE7\xF5es: verificar convers\xE3o e isen\xE7\xE3o ---
        $sqlInd = "SELECT codigo, codigo_indicador
                   FROM indicacoes
                   WHERE codigo_indicado = :cod_barbeiro
                     AND convertido = FALSE
                   LIMIT 1";
        $stmtInd = $conexao->prepare($sqlInd);
        $stmtInd->bindParam(':cod_barbeiro', $codigoBarbeiro, \PDO::PARAM_INT);
        $stmtInd->execute();
        $indicacao = $stmtInd->fetch(\PDO::FETCH_ASSOC);

        if ($indicacao) {
            // Marca a indica\xE7\xE3o como convertida
            $sqlConv = "UPDATE indicacoes
                        SET convertido = TRUE, data_conversao = CURRENT_TIMESTAMP
                        WHERE codigo = :cod_ind";
            $stmtConv = $conexao->prepare($sqlConv);
            $stmtConv->bindParam(':cod_ind', $indicacao['codigo'], \PDO::PARAM_INT);
            $stmtConv->execute();

            // Conta quantas indica\xE7\xF5es convertidas o indicador possui
            $codIndicador = (int) $indicacao['codigo_indicador'];
            $sqlCount = "SELECT COUNT(*) AS total_conversoes
                         FROM indicacoes
                         WHERE codigo_indicador = :cod_indicador
                           AND convertido = TRUE";
            $stmtCount = $conexao->prepare($sqlCount);
            $stmtCount->bindParam(':cod_indicador', $codIndicador, \PDO::PARAM_INT);
            $stmtCount->execute();
            $contagem = $stmtCount->fetch(\PDO::FETCH_ASSOC);

            // Se atingiu 5 convers\xF5es, concede isen\xE7\xE3o de pagamento ao indicador
            if ((int) $contagem['total_conversoes'] >= 5) {
                $sqlIsencao = "UPDATE usuarios
                               SET isento_pagamento = TRUE
                               WHERE codigo = :cod_indicador
                                 AND isento_pagamento = FALSE";
                $stmtIsencao = $conexao->prepare($sqlIsencao);
                $stmtIsencao->bindParam(':cod_indicador', $codIndicador, \PDO::PARAM_INT);
                $stmtIsencao->execute();
                webhookLog("Indicador {$codIndicador} atingiu 5 convers\xF5es - isen\xE7\xE3o concedida!");
            }

            webhookLog("Indica\xE7\xE3o {$indicacao['codigo']} convertida para barbeiro {$codigoBarbeiro}. Indicador: {$codIndicador}");
        }
        // --- Fim l\xF3gica de indica\xE7\xF5es ---

        $paymentId = (string)($GLOBALS['_ASAAS_PAYMENT_ID'] ?? '');
        $valorPagamento = (float)($GLOBALS['_ASAAS_PAYMENT_VALOR'] ?? 0);
        $paymentRaw = $GLOBALS['_ASAAS_PAYMENT_RAW'] ?? [];
        if ($paymentId !== '') {
            emitirNotaFiscalPagamento($conexao, $codigoBarbeiro, (int)$assinatura['codigo'], $paymentId, $valorPagamento, $paymentRaw);
        }
    } catch (\Exception $e) {
        webhookLog("ERRO ao processar aprova&#65533;&#65533;o barbeiro {$codigoBarbeiro}: " . $e->getMessage());
    }
}

function emitirNotaFiscalPagamento($conexao, int $codigoBarbeiro, int $codigoAssinatura, string $paymentId, float $valor, array $paymentRaw): void {
    try {
        if ($paymentId === '') {
            return;
        }

        $stmtUsuario = $conexao->prepare(
            "SELECT nome, email, cpf, endereco_cep, endereco_logradouro, endereco_numero,
                    endereco_complemento, endereco_bairro, endereco_cidade, endereco_estado,
                    asaas_customer_id
             FROM usuarios
             WHERE codigo = :codigo
             LIMIT 1"
        );
        $stmtUsuario->bindValue(':codigo', $codigoBarbeiro, PDO::PARAM_INT);
        $stmtUsuario->execute();
        $usuario = $stmtUsuario->fetch(PDO::FETCH_ASSOC);

        if (!$usuario) {
            webhookLog("Nota fiscal: usuario {$codigoBarbeiro} nao encontrado");
            return;
        }

        $faltando = [];
        foreach (['endereco_cep', 'endereco_logradouro', 'endereco_numero', 'endereco_bairro', 'endereco_cidade', 'endereco_estado'] as $campoEndereco) {
            if (trim((string)($usuario[$campoEndereco] ?? '')) === '') {
                $faltando[] = $campoEndereco;
            }
        }
        if (!empty($faltando)) {
            salvarRegistroNotaFiscal(
                $conexao,
                $codigoBarbeiro,
                $codigoAssinatura,
                $paymentId,
                null,
                'erro',
                $valor,
                null,
                null,
                null,
                null,
                'Endereco incompleto para emissao fiscal',
                ['faltando' => $faltando],
                null
            );
            webhookLog("Nota fiscal nao emitida para payment {$paymentId}: endereco incompleto");
            return;
        }

        $stmtExistente = $conexao->prepare(
            "SELECT codigo, status, mensagem, numero_nota, url_nota, url_pdf
             FROM notas_fiscais_pagamentos
             WHERE asaas_payment_id = :payment
             LIMIT 1"
        );
        $stmtExistente->bindValue(':payment', $paymentId);
        $stmtExistente->execute();
        $nfExistente = $stmtExistente->fetch(PDO::FETCH_ASSOC);
        if ($nfExistente && in_array(strtolower((string)$nfExistente['status']), ['emitida', 'processando'], true)) {
            webhookLog("Nota fiscal ja registrada para payment {$paymentId} com status {$nfExistente['status']}");

            enviarNotaFiscalPorEmailSePossivel(
                $conexao,
                $codigoBarbeiro,
                $usuario,
                $paymentId,
                (string)($nfExistente['numero_nota'] ?? ''),
                (string)($nfExistente['url_pdf'] ?? ''),
                (string)($nfExistente['url_nota'] ?? ''),
                (string)($nfExistente['mensagem'] ?? '')
            );
            return;
        }

        $payloadNota = [
            'payment' => $paymentId,
            'customer' => $usuario['asaas_customer_id'] ?? null,
            'description' => 'Mensalidade MustaBarber',
            'externalReference' => (string)$codigoBarbeiro,
            'municipalServiceId' => null,
            'municipalServiceCode' => '2800',
            'taxes' => [
                'iss' => 2,
                'cofins' => 0,
                'csll' => 0,
                'inss' => 0,
                'ir' => 0,
                'pis' => 0,
            ],
            'observations' => 'Emissao automatica apos confirmacao de pagamento.',
        ];

        $resultado = asaasPost('/invoices', $payloadNota);
        if (!$resultado['success']) {
            salvarRegistroNotaFiscal(
                $conexao,
                $codigoBarbeiro,
                $codigoAssinatura,
                $paymentId,
                null,
                'erro',
                $valor,
                null,
                null,
                null,
                null,
                $resultado['message'],
                $payloadNota,
                $resultado['body']
            );
            webhookLog("Falha ao emitir nota para payment {$paymentId}: {$resultado['message']}");
            return;
        }

        $body = is_array($resultado['body']) ? $resultado['body'] : [];
        $asaasInvoiceId = (string)($body['id'] ?? '');
        $numeroNota = (string)($body['invoiceNumber'] ?? $body['number'] ?? '');
        $urlNota = (string)($body['invoiceUrl'] ?? $body['url'] ?? '');
        $urlXml = (string)($body['xmlUrl'] ?? '');
        $urlPdf = (string)($body['pdfUrl'] ?? '');
        $statusNota = strtolower((string)($body['status'] ?? 'processando'));
        if (!in_array($statusNota, ['emitida', 'processing', 'processando', 'erro', 'error'], true)) {
            $statusNota = 'processando';
        }
        if ($statusNota === 'processing') {
            $statusNota = 'processando';
        }
        if ($statusNota === 'error') {
            $statusNota = 'erro';
        }

        salvarRegistroNotaFiscal(
            $conexao,
            $codigoBarbeiro,
            $codigoAssinatura,
            $paymentId,
            $asaasInvoiceId !== '' ? $asaasInvoiceId : null,
            $statusNota,
            $valor,
            $numeroNota !== '' ? $numeroNota : null,
            $urlNota !== '' ? $urlNota : null,
            $urlXml !== '' ? $urlXml : null,
            $urlPdf !== '' ? $urlPdf : null,
            $statusNota === 'erro' ? 'Falha na criacao da nota fiscal' : 'Nota fiscal criada no Asaas. Aguardando emissao.',
            $payloadNota,
            $body
        );

        if ($asaasInvoiceId === '') {
            webhookLog("Nota fiscal criada sem invoice_id para payment {$paymentId}");
            return;
        }

        // Emitir/autorizar a NFS-e somente apos o pagamento confirmado.
        $resultadoAutorizacao = autorizarNotaFiscalAsaas($asaasInvoiceId);
        $bodyAutorizacao = is_array($resultadoAutorizacao['body'] ?? null) ? $resultadoAutorizacao['body'] : [];

        if (!$resultadoAutorizacao['success']) {
            salvarRegistroNotaFiscal(
                $conexao,
                $codigoBarbeiro,
                $codigoAssinatura,
                $paymentId,
                $asaasInvoiceId,
                'erro',
                $valor,
                $numeroNota !== '' ? $numeroNota : null,
                $urlNota !== '' ? $urlNota : null,
                $urlXml !== '' ? $urlXml : null,
                $urlPdf !== '' ? $urlPdf : null,
                'Nota criada, mas falhou ao emitir: ' . $resultadoAutorizacao['message'],
                $payloadNota,
                ['criacao' => $body, 'autorizacao' => $bodyAutorizacao]
            );
            webhookLog("Nota criada, mas falhou ao autorizar invoice {$asaasInvoiceId}: {$resultadoAutorizacao['message']}");
            return;
        }

        $statusAutorizado = normalizarStatusNotaAsaas((string)($bodyAutorizacao['status'] ?? $statusNota));
        $numeroNotaAuth = (string)($bodyAutorizacao['invoiceNumber'] ?? $bodyAutorizacao['number'] ?? $numeroNota);
        $urlNotaAuth = (string)($bodyAutorizacao['invoiceUrl'] ?? $bodyAutorizacao['url'] ?? $urlNota);
        $urlXmlAuth = (string)($bodyAutorizacao['xmlUrl'] ?? $urlXml);
        $urlPdfAuth = (string)($bodyAutorizacao['pdfUrl'] ?? $urlPdf);

        salvarRegistroNotaFiscal(
            $conexao,
            $codigoBarbeiro,
            $codigoAssinatura,
            $paymentId,
            $asaasInvoiceId,
            $statusAutorizado,
            $valor,
            $numeroNotaAuth !== '' ? $numeroNotaAuth : null,
            $urlNotaAuth !== '' ? $urlNotaAuth : null,
            $urlXmlAuth !== '' ? $urlXmlAuth : null,
            $urlPdfAuth !== '' ? $urlPdfAuth : null,
            $statusAutorizado === 'emitida' ? 'Nota fiscal emitida automaticamente' : 'Nota fiscal enviada para emissao. Aguardando autorizacao da prefeitura.',
            $payloadNota,
            ['criacao' => $body, 'autorizacao' => $bodyAutorizacao]
        );

        if ($statusAutorizado === 'emitida') {
            enviarNotaFiscalPorEmailSePossivel(
                $conexao,
                $codigoBarbeiro,
                $usuario,
                $paymentId,
                $numeroNotaAuth,
                $urlPdfAuth,
                $urlNotaAuth,
                'Nota fiscal emitida automaticamente'
            );
        }

        webhookLog("Nota fiscal criada e autorizada para payment {$paymentId}. status={$statusAutorizado} invoice={$asaasInvoiceId}");
    } catch (Throwable $e) {
        webhookLog('Erro inesperado na emissao da nota fiscal (barbeiro ' . $codigoBarbeiro . ', payment ' . $paymentId . '): ' . $e->getMessage());
    }
}

function enviarNotaFiscalPorEmailSePossivel(
    $conexao,
    int $codigoBarbeiro,
    array $usuario,
    string $paymentId,
    string $numeroNota,
    string $urlPdf,
    string $urlNota,
    string $mensagemAtual
): void {
    try {
        $email = trim((string)($usuario['email'] ?? ''));

        if (strpos($mensagemAtual, '[email_enviado:1]') !== false) {
            return;
        }

        $destinatarios = [];
        if ($email !== '') {
            $destinatarios[] = $email;
        }
        $emailEmpresa = 'developer.mustech@gmail.com';
        if (!in_array($emailEmpresa, $destinatarios, true)) {
            $destinatarios[] = $emailEmpresa;
        }
        if (empty($destinatarios)) {
            return;
        }

        $nome = trim((string)($usuario['nome'] ?? 'Cliente'));
        $assunto = 'Sua nota fiscal - MustaBarber';
        $numeroExibicao = $numeroNota !== '' ? $numeroNota : 'em processamento';
        $urlPreferida = $urlPdf !== '' ? $urlPdf : $urlNota;

        $corpoHtml = "<html><body style='font-family:Arial,sans-serif;background:#0f0f0f;color:#e8e8e8;padding:30px'>"
            . "<div style='max-width:580px;margin:0 auto;background:#1a1816;border-radius:16px;padding:28px'>"
            . "<h2 style='color:#d4a574;margin:0 0 8px 0'>Nota Fiscal do seu pagamento</h2>"
            . "<p>Ola <strong>" . htmlspecialchars($nome, ENT_QUOTES, 'UTF-8') . "</strong>,</p>"
            . "<p>Sua nota fiscal foi gerada para o pagamento confirmado no MustaBarber.</p>"
            . "<p><strong>Numero da nota:</strong> " . htmlspecialchars($numeroExibicao, ENT_QUOTES, 'UTF-8') . "</p>"
            . "<p><strong>Referencia do pagamento:</strong> " . htmlspecialchars($paymentId, ENT_QUOTES, 'UTF-8') . "</p>";

        if ($urlPreferida !== '') {
            $corpoHtml .= "<p><a href='" . htmlspecialchars($urlPreferida, ENT_QUOTES, 'UTF-8') . "' style='display:inline-block;background:#d4a574;color:#1b120a;text-decoration:none;padding:12px 16px;border-radius:8px;font-weight:700'>Abrir nota fiscal</a></p>";
            $corpoHtml .= "<p style='color:#9ca3af;font-size:.82rem'>Se o anexo nao estiver disponivel, use o botao acima para acessar online.</p>";
        }

        $corpoHtml .= "</div></body></html>";

        $envioComSucesso = false;
        foreach ($destinatarios as $destinatario) {
            $enviado = enviarEmailComAnexoOpcional($destinatario, $assunto, $corpoHtml, $urlPdf, $paymentId);
            if ($enviado) {
                $envioComSucesso = true;
                webhookLog("E-mail de nota fiscal enviado para {$destinatario} (payment {$paymentId})");
            } else {
                webhookLog("Falha ao enviar e-mail de nota fiscal para {$destinatario} (payment {$paymentId})");
            }
        }

        if ($envioComSucesso) {
            marcarEmailNotaComoEnviado($conexao, $paymentId);
        }
    } catch (Throwable $e) {
        webhookLog('Erro ao enviar e-mail da nota fiscal: ' . $e->getMessage());
    }
}

function enviarEmailComAnexoOpcional(string $email, string $assunto, string $corpoHtml, string $urlPdf, string $paymentId): bool {
    $from = 'MustaBarber <noreply@mustabarber.com.br>';
    $headersBase = "MIME-Version: 1.0\r\n";
    $headersBase .= "From: {$from}\r\n";

    $anexoConteudo = '';
    if ($urlPdf !== '') {
        $anexoConteudo = baixarArquivoParaAnexo($urlPdf);
    }

    if ($anexoConteudo === '') {
        $headers = $headersBase . "Content-Type: text/html; charset=UTF-8\r\n";
        return @mail($email, $assunto, $corpoHtml, $headers);
    }

    $boundary = 'mb-' . md5(uniqid((string)mt_rand(), true));
    $headers = $headersBase;
    $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";

    $nomeArquivo = 'nota-fiscal-' . preg_replace('/[^a-zA-Z0-9\-_]/', '', $paymentId) . '.pdf';
    $anexoBase64 = chunk_split(base64_encode($anexoConteudo));

    $mensagem = "--{$boundary}\r\n";
    $mensagem .= "Content-Type: text/html; charset=UTF-8\r\n";
    $mensagem .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $mensagem .= $corpoHtml . "\r\n";
    $mensagem .= "--{$boundary}\r\n";
    $mensagem .= "Content-Type: application/pdf; name=\"{$nomeArquivo}\"\r\n";
    $mensagem .= "Content-Disposition: attachment; filename=\"{$nomeArquivo}\"\r\n";
    $mensagem .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $mensagem .= $anexoBase64 . "\r\n";
    $mensagem .= "--{$boundary}--";

    return @mail($email, $assunto, $mensagem, $headers);
}

function baixarArquivoParaAnexo(string $url): string {
    if (!function_exists('curl_init')) {
        return '';
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $conteudo = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err || $status < 200 || $status >= 300 || !is_string($conteudo)) {
        return '';
    }

    if (strlen($conteudo) > 5 * 1024 * 1024) {
        return '';
    }

    return $conteudo;
}

function marcarEmailNotaComoEnviado($conexao, string $paymentId): void {
    $stmtSel = $conexao->prepare(
        "SELECT mensagem FROM notas_fiscais_pagamentos WHERE asaas_payment_id = :payment LIMIT 1"
    );
    $stmtSel->bindValue(':payment', $paymentId);
    $stmtSel->execute();
    $row = $stmtSel->fetch(PDO::FETCH_ASSOC);

    $mensagem = trim((string)($row['mensagem'] ?? ''));
    if (strpos($mensagem, '[email_enviado:1]') !== false) {
        return;
    }

    $sufixo = '[email_enviado:1][' . date('Y-m-d H:i:s') . ']';
    $mensagemNova = $mensagem === '' ? $sufixo : ($mensagem . ' | ' . $sufixo);

    $stmtUp = $conexao->prepare(
        "UPDATE notas_fiscais_pagamentos
         SET mensagem = :mensagem, data_atualizacao = NOW()
         WHERE asaas_payment_id = :payment"
    );
    $stmtUp->bindValue(':mensagem', $mensagemNova);
    $stmtUp->bindValue(':payment', $paymentId);
    $stmtUp->execute();
}

function salvarRegistroNotaFiscal(
    $conexao,
    int $codigoBarbeiro,
    int $codigoAssinatura,
    string $paymentId,
    ?string $asaasInvoiceId,
    string $status,
    float $valor,
    ?string $numeroNota,
    ?string $urlNota,
    ?string $urlXml,
    ?string $urlPdf,
    ?string $mensagem,
    ?array $payloadEnvio,
    $payloadRetorno
): void {
    $statusEmissao = $status === 'emitida' ? 'emitida' : 'outro';

    $stmt = $conexao->prepare(
        "INSERT INTO notas_fiscais_pagamentos
            (codigo_barbeiro, codigo_assinatura, asaas_payment_id, asaas_invoice_id, status, valor,
             numero_nota, url_nota, url_xml, url_pdf, mensagem, payload_envio, payload_retorno,
             data_emissao)
         VALUES
            (:codigo_barbeiro, :codigo_assinatura, :payment_id, :invoice_id, :status, :valor,
             :numero_nota, :url_nota, :url_xml, :url_pdf, :mensagem, :payload_envio::jsonb, :payload_retorno::jsonb,
             CASE WHEN :status_emissao = 'emitida' THEN NOW() ELSE NULL END)
         ON CONFLICT (asaas_payment_id)
         DO UPDATE SET
            asaas_invoice_id = EXCLUDED.asaas_invoice_id,
            status = EXCLUDED.status,
            valor = EXCLUDED.valor,
            numero_nota = EXCLUDED.numero_nota,
            url_nota = EXCLUDED.url_nota,
            url_xml = EXCLUDED.url_xml,
            url_pdf = EXCLUDED.url_pdf,
            mensagem = EXCLUDED.mensagem,
            payload_envio = EXCLUDED.payload_envio,
            payload_retorno = EXCLUDED.payload_retorno,
            data_emissao = CASE
                WHEN EXCLUDED.status = 'emitida' THEN COALESCE(notas_fiscais_pagamentos.data_emissao, NOW())
                ELSE notas_fiscais_pagamentos.data_emissao
            END,
            data_atualizacao = NOW()"
    );

    $payloadEnvioJson = $payloadEnvio ? json_encode($payloadEnvio, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $payloadRetornoJson = is_array($payloadRetorno)
        ? json_encode($payloadRetorno, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : (is_string($payloadRetorno) ? $payloadRetorno : null);

    $stmt->bindValue(':codigo_barbeiro', $codigoBarbeiro, PDO::PARAM_INT);
    $stmt->bindValue(':codigo_assinatura', $codigoAssinatura > 0 ? $codigoAssinatura : null, $codigoAssinatura > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $stmt->bindValue(':payment_id', $paymentId);
    $stmt->bindValue(':invoice_id', $asaasInvoiceId, $asaasInvoiceId !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':status', $status);
    $stmt->bindValue(':status_emissao', $statusEmissao);
    $stmt->bindValue(':valor', $valor);
    $stmt->bindValue(':numero_nota', $numeroNota, $numeroNota !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':url_nota', $urlNota, $urlNota !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':url_xml', $urlXml, $urlXml !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':url_pdf', $urlPdf, $urlPdf !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':mensagem', $mensagem, $mensagem !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':payload_envio', $payloadEnvioJson, $payloadEnvioJson !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':payload_retorno', $payloadRetornoJson, $payloadRetornoJson !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->execute();
}


function autorizarNotaFiscalAsaas(string $invoiceId): array {
    if ($invoiceId === '') {
        return ['success' => false, 'message' => 'invoice_id vazio', 'body' => null, 'status' => 0];
    }

    return asaasPost('/invoices/' . urlencode($invoiceId) . '/authorize', []);
}

function normalizarStatusNotaAsaas(string $statusAsaas): string {
    $status = strtoupper(trim($statusAsaas));

    if (in_array($status, ['AUTHORIZED', 'ISSUED', 'EMITTED', 'EMITIDA'], true)) {
        return 'emitida';
    }

    if (in_array($status, ['ERROR', 'ERRO', 'CANCELED', 'CANCELLED'], true)) {
        return 'erro';
    }

    return 'processando';
}

function asaasPost(string $endpoint, array $payload): array {
    if (!function_exists('curl_init')) {
        return ['success' => false, 'message' => 'cURL indisponivel', 'body' => null, 'status' => 0];
    }

    $url = ASAAS_API_URL . $endpoint;
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'accept: application/json',
            'access_token: ' . ASAAS_API_KEY,
            'User-Agent: MustaBarber/1.0'
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $resp = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    $body = json_decode((string)$resp, true);
    if ($err) {
        return ['success' => false, 'message' => 'Erro cURL: ' . $err, 'body' => $body, 'status' => $status];
    }

    if ($status < 200 || $status >= 300) {
        $mensagem = 'Erro HTTP ' . $status;
        if (is_array($body) && !empty($body['errors'][0]['description'])) {
            $mensagem = (string)$body['errors'][0]['description'];
        } elseif (is_array($body) && !empty($body['message'])) {
            $mensagem = (string)$body['message'];
        }
        return ['success' => false, 'message' => $mensagem, 'body' => $body, 'status' => $status];
    }

    return ['success' => true, 'message' => 'ok', 'body' => $body, 'status' => $status];
}

function traduzirFormaPagamento(string $tipo): string {
    $mapa = [
        'PIX'         => 'PIX',
        'CREDIT_CARD' => 'Cartao de Credito',
        'BOLETO'      => 'Boleto',
        'UNDEFINED'   => 'Asaas',
        'DEBIT_CARD'  => 'Cartao de Debito',
    ];
    return $mapa[$tipo] ?? 'Asaas';
}
?>
