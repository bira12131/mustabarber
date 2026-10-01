<?php
/**
 * Classe para envio de mensagens via WhatsApp usando Evolution API (EasyPanel Fork)
 * Corrigido para suportar emojis e Markdown corretamente (UTF-8 puro)
 */

class WhatsAppService {

    private $apiUrl = 'http://localhost:8080';
    private $apiKey = ''; // definida via EVOLUTION_API_KEY
    private $instanceName = 'barbearia';
    private $defaultInstanceName = 'barbearia';
    private $codigoBarbeiro = null;
    private $usaInstanciaPersonalizada = false;
    private $modoEstritoInstanciaPersonalizada = false;

    public function __construct($codigoBarbeiro = null) {
        $this->apiUrl = getenv('EVOLUTION_API_URL') ?: $this->apiUrl;
        $this->apiKey = getenv('EVOLUTION_API_KEY') ?: $this->apiKey;
        if ($codigoBarbeiro === null && session_status() === PHP_SESSION_ACTIVE) {
            $codigoSessao = $_SESSION['usuario_codigo'] ?? null;
            if (is_numeric($codigoSessao)) {
                $codigoBarbeiro = (int)$codigoSessao;
            }
        }

        if (is_numeric($codigoBarbeiro) && (int)$codigoBarbeiro > 0) {
            $this->codigoBarbeiro = (int)$codigoBarbeiro;
            $dadosInstancia = $this->buscarDadosInstanciaPersonalizada($this->codigoBarbeiro);
            if (!empty($dadosInstancia['instancia_nome'])) {
                $this->instanceName = (string)$dadosInstancia['instancia_nome'];
                $this->usaInstanciaPersonalizada = true;
                // Quem configurou numero proprio nunca deve cair no numero padrao do app.
                $this->modoEstritoInstanciaPersonalizada = true;
            }
        }
    }

    private function buscarDadosInstanciaPersonalizada($codigoBarbeiro) {
        try {
            if (!isset($GLOBALS['conexao']) || !($GLOBALS['conexao'] instanceof PDO)) {
                require_once __DIR__ . '/../conexao.php';
            }

            $conexao = $GLOBALS['conexao'] ?? null;
            if (!($conexao instanceof PDO)) {
                return null;
            }

            $stmt = $conexao->prepare(
                "SELECT whatsapp_instancia_nome, whatsapp_instancia_conectada
                 FROM usuarios
                 WHERE codigo = :codigo
                 LIMIT 1"
            );
            $stmt->execute([':codigo' => $codigoBarbeiro]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                return [
                    'instancia_nome' => null,
                    'conectada' => false,
                ];
            }

            $conectadaRaw = isset($row['whatsapp_instancia_conectada'])
                ? filter_var($row['whatsapp_instancia_conectada'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                : false;

            $conectada = ($conectadaRaw === true);

            $instanciaNome = !empty($row['whatsapp_instancia_nome'])
                ? trim((string)$row['whatsapp_instancia_nome'])
                : null;

            if ($instanciaNome === $this->defaultInstanceName) {
                $instanciaNome = null;
            }

            return [
                'instancia_nome' => $instanciaNome,
                'conectada' => $conectada,
            ];

        } catch (Throwable $e) {
            // Se a migration ainda nao foi aplicada ou houve falha de leitura, usa instancia padrao.
            return [
                'instancia_nome' => null,
                'conectada' => false,
            ];
        }
    }

    private function marcarInstanciaComoDesconectada() {
        try {
            if (!$this->usaInstanciaPersonalizada || !$this->codigoBarbeiro) {
                return;
            }

            if (!isset($GLOBALS['conexao']) || !($GLOBALS['conexao'] instanceof PDO)) {
                require_once __DIR__ . '/../conexao.php';
            }

            $conexao = $GLOBALS['conexao'] ?? null;
            if (!($conexao instanceof PDO)) {
                return;
            }

            $stmt = $conexao->prepare(
                "UPDATE usuarios
                 SET whatsapp_instancia_conectada = FALSE,
                     whatsapp_instancia_status = 'disconnected',
                     whatsapp_instancia_atualizada_em = NOW()
                 WHERE codigo = :codigo"
            );
            $stmt->execute([':codigo' => $this->codigoBarbeiro]);
        } catch (Throwable $e) {
            // Nao interromper o fluxo de envio por falha de atualizacao de status.
        }
    }

    public function usaInstanciaPersonalizadaAtiva() {
        return $this->usaInstanciaPersonalizada === true && $this->instanceName !== $this->defaultInstanceName;
    }

    public function usaModoEstritoInstanciaPersonalizada() {
        return $this->modoEstritoInstanciaPersonalizada === true;
    }

    private function enviarParaInstancia($instanceName, $telefone, $mensagem) {
        $url = "{$this->apiUrl}/message/sendText/{$instanceName}";

        $data = [
            "number" => $telefone,
            "text" => $mensagem
        ];

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json; charset=UTF-8",
                "Accept-Charset: UTF-8",
                "Accept: application/json",
                "User-Agent: MustaBarber/1.0",
                "apikey: {$this->apiKey}",
                "x-api-key: {$this->apiKey}",
                "Authorization: Bearer {$this->apiKey}"
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new Exception("Erro cURL: {$curlError}");
        }

        $result = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                "success" => true,
                "message" => "Mensagem enviada com sucesso!",
                "data"    => $result,
                "instance" => $instanceName
            ];
        }

        throw new Exception("Erro HTTP {$httpCode}: {$response}");
    }

    /**
     * Enviar mensagem simples
     */
    public function enviarMensagem($telefone, $mensagem) {
        try {
            $telefoneOriginal = $telefone;

            // Normalizar n&#65533;mero
            $telefone = preg_replace('/[^0-9]/', '', $telefone);

            if (!str_starts_with($telefone, '55')) {
                $telefone = '55' . $telefone;
            }

            // Decodificar qualquer HTML entity que possa existir
            $mensagem = html_entity_decode($mensagem, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            
            // Garantir que &#65533; UTF-8 v&#65533;lido
            $mensagem = mb_convert_encoding($mensagem, 'UTF-8', 'UTF-8');

            $instanciasTentativa = [];
            $instanciasTentativa[] = $this->instanceName;

            // Regra de seguranca: se o usuario configurou numero proprio,
            // nunca enviar pelo numero padrao do aplicativo.
            if (!$this->modoEstritoInstanciaPersonalizada && $this->instanceName !== $this->defaultInstanceName) {
                $instanciasTentativa[] = $this->defaultInstanceName;
            }

            $ultimoErro = null;
            foreach ($instanciasTentativa as $instanciaAtual) {
                try {
                    $resultado = $this->enviarParaInstancia($instanciaAtual, $telefone, $mensagem);
                    error_log('[WHATSAPP] envio_ok cod_barbeiro=' . (string)$this->codigoBarbeiro .
                        ' instancia=' . $instanciaAtual .
                        ' strict=' . ($this->modoEstritoInstanciaPersonalizada ? '1' : '0') .
                        ' telefone=' . $telefoneOriginal);
                    return $resultado;
                } catch (Exception $erroEnvio) {
                    $ultimoErro = $erroEnvio;
                    error_log('[WHATSAPP] envio_falhou_tentativa cod_barbeiro=' . (string)$this->codigoBarbeiro .
                        ' instancia=' . $instanciaAtual .
                        ' strict=' . ($this->modoEstritoInstanciaPersonalizada ? '1' : '0') .
                        ' telefone=' . $telefoneOriginal .
                        ' erro=' . $erroEnvio->getMessage());

                    if ($instanciaAtual === $this->instanceName && $this->usaInstanciaPersonalizada) {
                        $this->marcarInstanciaComoDesconectada();
                    }
                }
            }

            if ($this->modoEstritoInstanciaPersonalizada) {
                error_log('[WHATSAPP] envio_bloqueado_sem_fallback cod_barbeiro=' . (string)$this->codigoBarbeiro .
                    ' instancia=' . $this->instanceName .
                    ' telefone=' . $telefoneOriginal);
                throw new Exception('Nao foi possivel enviar pela instancia do numero proprio. Verifique se o WhatsApp esta conectado na aba WhatsApp.');
            }

            throw new Exception($ultimoErro ? $ultimoErro->getMessage() : 'Falha ao enviar mensagem WhatsApp.');

        } catch (Exception $e) {
            return [
                "success" => false,
                "message" => $e->getMessage()
            ];
        }
    }

    /**
     * Notifica&#65533;&#65533;o para o usu&#65533;rio
     */
    public function notificarNovoCadastro($telefone, $nome) {

        $msg  = "Ola *{$nome}*! \xF0\x9F\x98\x8A\n\n";
        $msg .= "Seu cadastro foi recebido com sucesso!\n";
        $msg .= "Nossa equipe esta analisando sua solicitacao.\n\n";
        $msg .= "Assim que estiver aprovado, notificaremos aqui.\n\n";
        $msg .= "Obrigado! \xF0\x9F\x99\x8F";

        return $this->enviarMensagem($telefone, $msg);
    }

    /**
     * Notifica&#65533;&#65533;o ao administrador
     */
    public function notificarAdminNovoCadastro($nomeUsuario, $email, $telefone) {

        $telefoneAdmin = (getenv('ADMIN_WHATSAPP') ?: '');

        $msg  = "\xF0\x9F\x94\x94 *NOVO CADASTRO PENDENTE*\n\n";
        $msg .= "\xF0\x9F\x91\xA4 *Nome:* {$nomeUsuario}\n";
        $msg .= "\xF0\x9F\x93\xA7 *Email:* {$email}\n";
        $msg .= "\xF0\x9F\x93\xB1 *Telefone:* {$telefone}\n\n";
        $msg .= "Acesse o painel para aprovar.";

        return $this->enviarMensagem($telefoneAdmin, $msg);
    }

    /**
     * Cadastro aprovado
     */
    public function notificarCadastroAprovado($telefone, $nome) {

        $msg  = "\xE2\x9C\x85 *CADASTRO APROVADO*\n\n";
        $msg .= "Ola {$nome}, seu acesso foi liberado com sucesso! \xF0\x9F\x8E\x89\n";
        $msg .= "Agora voce ja pode usar o sistema normalmente.";

        return $this->enviarMensagem($telefone, $msg);
    }

    /**
     * Cadastro rejeitado
     */
    public function notificarCadastroRejeitado($telefone, $nome, $motivo = '') {

        $msg  = "\xE2\x9D\x8C *CADASTRO NAO APROVADO*\n\n";
        $msg .= "Ola {$nome}, infelizmente seu cadastro nao pode ser aprovado.\n\n";

        if ($motivo !== '') {
            $msg .= "\xF0\x9F\x93\x8C *Motivo:* {$motivo}\n\n";
        }

        $msg .= "Se tiver duvidas, entre em contato com nossa equipe.";

        return $this->enviarMensagem($telefone, $msg);
    }
}
?>
