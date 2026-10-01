<?php
@error_reporting(0);
@ini_set('display_errors', 0);

require_once __DIR__ . '/../conexao.php';

define('ASAAS_API_KEY', getenv('ASAAS_API_KEY') ?: '');
define('ASAAS_API_URL', 'https://api.asaas.com/v3');

header('Content-Type: application/json; charset=utf-8');

$logFile = __DIR__ . '/debug_sincronizar_notas.log';

function logSync($msg) {
    global $logFile;
    file_put_contents($logFile, date('Y-m-d H:i:s') . " - " . $msg . "\n", FILE_APPEND);
}

function asaasGet($endpoint) {
    $ch = curl_init(ASAAS_API_URL . $endpoint);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => [
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
        return ['success' => false, 'message' => 'Erro cURL: ' . $err, 'body' => null];
    }

    if ($status < 200 || $status >= 300) {
        return ['success' => false, 'message' => 'HTTP ' . $status, 'body' => $body];
    }

    return ['success' => true, 'message' => 'ok', 'body' => $body];
}

function normalizarStatusNota($statusAsaas) {
    $status = strtoupper(trim((string)$statusAsaas));

    if (in_array($status, ['AUTHORIZED', 'ISSUED', 'EMITTED'], true)) {
        return 'emitida';
    }

    if (in_array($status, ['ERROR', 'CANCELED', 'CANCELLED'], true)) {
        return 'erro';
    }

    return 'processando';
}

function enviarEmailNotaFiscal($emailCliente, $nomeCliente, $numeroNota, $urlPdf, $urlXml = null) {
    if (empty($emailCliente) || empty($urlPdf)) {
        return false;
    }

    $numeroNotaTexto = $numeroNota ?: 'Não informado';

    $assunto = "Nota Fiscal emitida - MustaBarber";

    $mensagem = "
Olá {$nomeCliente},

Sua nota fiscal foi emitida com sucesso.

Número da nota: {$numeroNotaTexto}

PDF da nota:
{$urlPdf}
";

    if (!empty($urlXml)) {
        $mensagem .= "

XML da nota:
{$urlXml}
";
    }

    $mensagem .= "

Atenciosamente,
Mustech Tecnologia
";

    $headers = "From: Mustech Tecnologia <contato@mustechtecnologia.com>\r\n";
    $headers .= "Reply-To: contato@mustechtecnologia.com\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

    $envioCliente = mail($emailCliente, $assunto, $mensagem, $headers);

    $envioEmpresa = mail(
        "contato@mustechtecnologia.com",
        "Cópia - Nota Fiscal emitida {$numeroNotaTexto}",
        $mensagem,
        $headers
    );

    return $envioCliente && $envioEmpresa;
}

try {
    $stmt = $conexao->prepare("
        SELECT codigo, asaas_payment_id, asaas_invoice_id, status
        FROM notas_fiscais_pagamentos
        WHERE asaas_invoice_id IS NOT NULL
          AND asaas_invoice_id <> ''
          AND (
                LOWER(status) IN ('processando', 'processing', 'pendente', 'scheduled', 'erro')
                OR (
                    LOWER(status) = 'emitida'
                    AND COALESCE(email_enviado, FALSE) = FALSE
                )
          )
        ORDER BY codigo DESC
        LIMIT 30
    ");
    $stmt->execute();

    $notas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total = 0;
    $atualizadas = 0;
    $emailsEnviados = 0;
    $erros = [];

    foreach ($notas as $nota) {
        $total++;

        $codigo = (int)$nota['codigo'];
        $invoiceId = trim((string)$nota['asaas_invoice_id']);

        if ($invoiceId === '') {
            continue;
        }

        $resultado = asaasGet('/invoices/' . urlencode($invoiceId));

        if (!$resultado['success']) {
            $erros[] = [
                'codigo' => $codigo,
                'invoice' => $invoiceId,
                'erro' => $resultado['message']
            ];
            logSync("Erro ao consultar invoice {$invoiceId}: {$resultado['message']}");
            continue;
        }

        $body = $resultado['body'] ?? [];

        $statusAsaas = $body['status'] ?? '';
        $statusBanco = normalizarStatusNota($statusAsaas);

        $numeroNota = $body['invoiceNumber'] ?? $body['number'] ?? null;
        $urlNota = $body['invoiceUrl'] ?? $body['url'] ?? null;
        $urlXml = $body['xmlUrl'] ?? null;
        $urlPdf = $body['pdfUrl'] ?? null;

        $mensagem = $body['statusDescription']
            ?? $body['message']
            ?? ($statusBanco === 'emitida' ? 'Nota fiscal emitida com sucesso' : 'Nota fiscal em processamento');

        $payloadRetorno = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $stmtUp = $conexao->prepare("
            UPDATE notas_fiscais_pagamentos
            SET
                status = :status,
                numero_nota = COALESCE(:numero_nota, numero_nota),
                url_nota = COALESCE(:url_nota, url_nota),
                url_xml = COALESCE(:url_xml, url_xml),
                url_pdf = COALESCE(:url_pdf, url_pdf),
                mensagem = :mensagem,
                payload_retorno = :payload_retorno::jsonb,
                data_emissao = CASE
                    WHEN :status_emissao = 'emitida'
                    THEN COALESCE(data_emissao, NOW())
                    ELSE data_emissao
                END,
                data_atualizacao = NOW()
            WHERE codigo = :codigo
        ");

        $stmtUp->bindValue(':status', $statusBanco);
        $stmtUp->bindValue(':status_emissao', $statusBanco);
        $stmtUp->bindValue(':numero_nota', $numeroNota);
        $stmtUp->bindValue(':url_nota', $urlNota);
        $stmtUp->bindValue(':url_xml', $urlXml);
        $stmtUp->bindValue(':url_pdf', $urlPdf);
        $stmtUp->bindValue(':mensagem', $mensagem);
        $stmtUp->bindValue(':payload_retorno', $payloadRetorno);
        $stmtUp->bindValue(':codigo', $codigo, PDO::PARAM_INT);
        $stmtUp->execute();

        $atualizadas++;

        logSync("Nota {$codigo} sincronizada. Invoice={$invoiceId} StatusAsaas={$statusAsaas} StatusBanco={$statusBanco}");

        if ($statusBanco === 'emitida') {
            $stmtEmail = $conexao->prepare("
                SELECT 
                    nf.codigo,
                    nf.numero_nota,
                    nf.url_pdf,
                    nf.url_xml,
                    nf.email_enviado,
                    u.nome,
                    u.email
                FROM notas_fiscais_pagamentos nf
                INNER JOIN usuarios u ON u.codigo = nf.codigo_barbeiro
                WHERE nf.codigo = :codigo
                LIMIT 1
            ");
            $stmtEmail->bindValue(':codigo', $codigo, PDO::PARAM_INT);
            $stmtEmail->execute();

            $dadosEmail = $stmtEmail->fetch(PDO::FETCH_ASSOC);

            if (
                $dadosEmail &&
                empty($dadosEmail['email_enviado']) &&
                !empty($dadosEmail['email']) &&
                !empty($dadosEmail['url_pdf'])
            ) {
                $emailOk = enviarEmailNotaFiscal(
                    $dadosEmail['email'],
                    $dadosEmail['nome'],
                    $dadosEmail['numero_nota'],
                    $dadosEmail['url_pdf'],
                    $dadosEmail['url_xml']
                );

                if ($emailOk) {
                    $stmtMarcaEmail = $conexao->prepare("
                        UPDATE notas_fiscais_pagamentos
                        SET 
                            email_enviado = TRUE,
                            data_envio_email = NOW(),
                            data_atualizacao = NOW()
                        WHERE codigo = :codigo
                    ");
                    $stmtMarcaEmail->bindValue(':codigo', $codigo, PDO::PARAM_INT);
                    $stmtMarcaEmail->execute();

                    $emailsEnviados++;
                    logSync("E-mail da nota {$codigo} enviado para {$dadosEmail['email']}");
                } else {
                    logSync("Falha ao enviar e-mail da nota {$codigo} para {$dadosEmail['email']}");
                }
            }
        }
    }

    echo json_encode([
        'ok' => true,
        'total_consultadas' => $total,
        'atualizadas' => $atualizadas,
        'emails_enviados' => $emailsEnviados,
        'erros' => $erros
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    logSync("Erro geral: " . $e->getMessage());

    echo json_encode([
        'ok' => false,
        'erro' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}