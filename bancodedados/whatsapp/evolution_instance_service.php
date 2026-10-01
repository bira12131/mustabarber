<?php

class EvolutionInstanceService {
    private $apiUrl = 'http://localhost:8080'; // definida via EVOLUTION_API_URL
    private $apiKey = ''; // definida via EVOLUTION_API_KEY
    private $apiCreateKey = ''; // definida via EVOLUTION_API_CREATE_KEY
    private $instanceToken = '';

    private function getEnvPrimeiroValor(array $nomes) {
        foreach ($nomes as $nome) {
            $valor = trim((string)getenv($nome));
            if ($valor !== '') {
                return $valor;
            }
        }

        return '';
    }

    public function __construct() {
        $envApiUrl = $this->getEnvPrimeiroValor(['EVOLUTION_API_URL', 'EVOLUTION_URL']);
        if ($envApiUrl !== '') {
            $this->apiUrl = $envApiUrl;
        }

        $envApiKey = $this->getEnvPrimeiroValor(['EVOLUTION_API_KEY', 'EVOLUTION_APIKEY']);
        if ($envApiKey !== '') {
            $this->apiKey = $envApiKey;
        }

        $envApiCreateKey = $this->getEnvPrimeiroValor([
            'EVOLUTION_API_CREATE_KEY',
            'EVOLUTION_CREATE_API_KEY',
            'EVOLUTION_MANAGER_API_KEY'
        ]);
        if ($envApiCreateKey !== '') {
            $this->apiCreateKey = $envApiCreateKey;
        } else {
            $this->apiCreateKey = $this->apiKey;
        }

        $envInstanceToken = $this->getEnvPrimeiroValor(['EVOLUTION_INSTANCE_TOKEN', 'EVOLUTION_TOKEN']);
        if ($envInstanceToken !== '') {
            $this->instanceToken = $envInstanceToken;
        }
    }

    public static function gerarNomeInstanciaPadrao($nomeBarbeiro, $codigoBarbeiro) {
        $nomeBase = trim((string)$nomeBarbeiro);
        if ($nomeBase === '') {
            $nomeBase = 'barbeiro-' . (int)$codigoBarbeiro;
        }

        $nomeBase = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nomeBase);
        $nomeBase = strtolower((string)$nomeBase);
        $nomeBase = preg_replace('/[^a-z0-9]+/', '-', $nomeBase);
        $nomeBase = trim((string)$nomeBase, '-');

        if ($nomeBase === '') {
            $nomeBase = 'barbeiro-' . (int)$codigoBarbeiro;
        }

        return $nomeBase . '-mustabarber';
    }

    public static function gerarRotuloInstancia($nomeBarbeiro) {
        $nomeBase = trim((string)$nomeBarbeiro);
        if ($nomeBase === '') {
            $nomeBase = 'Barbeiro';
        }

        return $nomeBase . ' - mustabarber';
    }

    public function criarInstancia($instanceName) {
        $payloads = [
            [
                'instanceName' => $instanceName,
                'integration' => 'WHATSAPP-BAILEYS',
                'qrcode' => true
            ],
            [
                'instanceName' => $instanceName,
                'qrcode' => true
            ]
        ];

        $ultimoErro = null;
        $authDiagnostico = null;

        foreach ($payloads as $payload) {
            if ($this->instanceToken !== '') {
                $payload['token'] = $this->instanceToken;
            }

            $res = $this->request('POST', '/instance/create', $payload, $this->apiCreateKey);
            if ($res['success']) {
                return $res;
            }

            $ultimoErro = $res;

            if ((int)($res['status'] ?? 0) === 403) {
                $authDiagnostico = $this->diagnosticarChaveCriacao();
            }

            if (!$this->erroInstanciaJaExiste($res['body'])) {
                continue;
            }

            $statusAtual = $this->consultarStatus($instanceName);
            if (empty($statusAtual['connected'])) {
                $this->forcarNovoPareamento($instanceName);
            }

            return [
                'success' => true,
                'status' => 200,
                'body' => ['alreadyExists' => true]
            ];
        }

        $mensagem = 'Nao foi possivel criar a instancia.';
        if (is_array($ultimoErro)) {
            $mensagemApi = $this->extrairMensagemErro($ultimoErro['body'] ?? null);
            if ($mensagemApi !== null) {
                $mensagem = $mensagemApi;
            }
        }

        return [
            'success' => false,
            'status' => (int)($ultimoErro['status'] ?? 500),
            'body' => [
                'message' => $this->normalizarMensagemErro($mensagem, (int)($ultimoErro['status'] ?? 500), $authDiagnostico),
                'api_error' => $ultimoErro['body'] ?? null
            ]
        ];
    }

    private function diagnosticarChaveCriacao() {
        $res = $this->request('GET', '/instance/fetchInstances', null, $this->apiCreateKey);
        if ($res['success']) {
            return 'sem_permissao_criacao';
        }

        if ((int)($res['status'] ?? 0) === 401 || (int)($res['status'] ?? 0) === 403) {
            return 'chave_invalida_ou_bloqueada';
        }

        return 'indeterminado';
    }

    public function buscarQrcode($instanceName) {
        $tentativas = [
            ['GET', '/instance/connect/' . rawurlencode($instanceName), null],
            ['POST', '/instance/connect/' . rawurlencode($instanceName), []],
            ['GET', '/instance/qrcode/' . rawurlencode($instanceName), null],
            ['GET', '/instance/qr/' . rawurlencode($instanceName), null],
            ['GET', '/instance/qrCode/' . rawurlencode($instanceName), null]
        ];

        foreach ($tentativas as $tentativa) {
            $res = $this->request($tentativa[0], $tentativa[1], $tentativa[2]);
            if (!$res['success']) {
                continue;
            }

            $qr = $this->extrairQrCode($res['body']);
            if ($qr !== null) {
                return [
                    'success' => true,
                    'status' => $res['status'],
                    'qrcode' => $qr,
                    'raw' => $res['body']
                ];
            }
        }

        return [
            'success' => false,
            'status' => 404,
            'qrcode' => null,
            'raw' => null
        ];
    }

    public function consultarStatus($instanceName) {
        $res = $this->request('GET', '/instance/connectionState/' . rawurlencode($instanceName));
        $state = $this->extrairEstadoConexao($res['body']);

        if ($state === null) {
            $resState = $this->request('GET', '/instance/state/' . rawurlencode($instanceName));
            $state = $this->extrairEstadoConexao($resState['body']);
            if ($state !== null) {
                $res = $resState;
            }
        }

        if ($state === null) {
            $resFetch = $this->request('GET', '/instance/fetchInstances');
            if ($resFetch['success']) {
                $state = $this->extrairEstadoDaLista($resFetch['body'], $instanceName);
                if ($state !== null) {
                    $res = $resFetch;
                }
            }
        }

        return [
            'success' => $res['success'],
            'status' => $res['status'],
            'state' => $state,
            'connected' => in_array($state, ['open', 'opened', 'connected', 'online', 'ready', 'isopen'], true),
            'raw' => $res['body']
        ];
    }

    public function excluirInstancia($instanceName) {
        $tentativas = [
            ['DELETE', '/instance/delete/' . rawurlencode($instanceName), null],
            ['DELETE', '/instance/' . rawurlencode($instanceName), null],
            ['POST', '/instance/delete/' . rawurlencode($instanceName), []]
        ];

        foreach ($tentativas as $tentativa) {
            $res = $this->request($tentativa[0], $tentativa[1], $tentativa[2]);
            if ($res['success']) {
                return $res;
            }
        }

        return [
            'success' => false,
            'status' => 500,
            'body' => ['message' => 'Nao foi possivel excluir a instancia.']
        ];
    }

    private function erroInstanciaJaExiste($body) {
        $texto = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($texto)) {
            return false;
        }

        $texto = strtolower($texto);
        return (str_contains($texto, 'already') && str_contains($texto, 'exist'))
            || str_contains($texto, 'exists')
            || str_contains($texto, 'duplicate')
            || str_contains($texto, 'conflit')
            || str_contains($texto, '409');
    }

    private function extrairEstadoConexao($body) {
        if (!is_array($body)) {
            return null;
        }

        $campos = ['instance', 'state', 'status', 'connectionStatus', 'instanceStatus'];
        foreach ($campos as $campo) {
            if (!empty($body[$campo]) && is_string($body[$campo])) {
                return strtolower($body[$campo]);
            }
        }

        if (isset($body['instance']) && is_array($body['instance'])) {
            foreach (['state', 'status', 'connectionStatus'] as $campoInterno) {
                if (!empty($body['instance'][$campoInterno]) && is_string($body['instance'][$campoInterno])) {
                    return strtolower($body['instance'][$campoInterno]);
                }
            }
        }

        return null;
    }

    private function forcarNovoPareamento($instanceName) {
        $tentativas = [
            ['DELETE', '/instance/logout/' . rawurlencode($instanceName), null],
            ['POST', '/instance/logout/' . rawurlencode($instanceName), null],
            ['GET', '/instance/logout/' . rawurlencode($instanceName), null],
            ['GET', '/instance/connect/' . rawurlencode($instanceName), null],
            ['POST', '/instance/connect/' . rawurlencode($instanceName), []]
        ];

        foreach ($tentativas as $tentativa) {
            $this->request($tentativa[0], $tentativa[1], $tentativa[2]);
        }
    }

    private function extrairEstadoDaLista($body, $instanceName) {
        if (!is_array($body)) {
            return null;
        }

        $listas = [];
        if ($this->ehLista($body)) {
            $listas[] = $body;
        }

        foreach (['instances', 'data', 'result'] as $campoLista) {
            if (isset($body[$campoLista]) && is_array($body[$campoLista]) && $this->ehLista($body[$campoLista])) {
                $listas[] = $body[$campoLista];
            }
        }

        foreach ($listas as $lista) {
            foreach ($lista as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $nome = $item['instanceName'] ?? $item['name'] ?? $item['instance'] ?? null;
                if (!is_string($nome) || trim($nome) !== $instanceName) {
                    continue;
                }

                foreach (['connectionStatus', 'state', 'status', 'instanceStatus'] as $campoStatus) {
                    if (!empty($item[$campoStatus]) && is_string($item[$campoStatus])) {
                        return strtolower(trim($item[$campoStatus]));
                    }
                }

                if (!empty($item['instance']) && is_array($item['instance'])) {
                    foreach (['connectionStatus', 'state', 'status', 'instanceStatus'] as $campoStatus) {
                        if (!empty($item['instance'][$campoStatus]) && is_string($item['instance'][$campoStatus])) {
                            return strtolower(trim($item['instance'][$campoStatus]));
                        }
                    }
                }
            }
        }

        return null;
    }

    private function ehLista(array $valor) {
        return array_keys($valor) === range(0, count($valor) - 1);
    }

    private function extrairQrCode($body) {
        if (!is_array($body)) {
            return null;
        }

        $candidatos = [];

        if (!empty($body['qrcode']) && is_string($body['qrcode'])) {
            $candidatos[] = $body['qrcode'];
        }
        if (!empty($body['qr']) && is_string($body['qr'])) {
            $candidatos[] = $body['qr'];
        }
        if (!empty($body['qrCode']) && is_string($body['qrCode'])) {
            $candidatos[] = $body['qrCode'];
        }
        if (!empty($body['base64']) && is_string($body['base64'])) {
            $candidatos[] = $body['base64'];
        }
        if (isset($body['qrcode']) && is_array($body['qrcode'])) {
            if (!empty($body['qrcode']['base64']) && is_string($body['qrcode']['base64'])) {
                $candidatos[] = $body['qrcode']['base64'];
            }
            if (!empty($body['qrcode']['code']) && is_string($body['qrcode']['code'])) {
                $candidatos[] = $body['qrcode']['code'];
            }
        }
        if (isset($body['qrCode']) && is_array($body['qrCode'])) {
            if (!empty($body['qrCode']['base64']) && is_string($body['qrCode']['base64'])) {
                $candidatos[] = $body['qrCode']['base64'];
            }
            if (!empty($body['qrCode']['code']) && is_string($body['qrCode']['code'])) {
                $candidatos[] = $body['qrCode']['code'];
            }
        }

        foreach ($candidatos as $valor) {
            $valor = trim($valor);
            if ($valor === '') {
                continue;
            }

            if (str_starts_with($valor, 'data:image/')) {
                return $valor;
            }

            if (preg_match('/^[A-Za-z0-9+\/]+=*$/', $valor) === 1) {
                return 'data:image/png;base64,' . $valor;
            }
        }

        return null;
    }

    private function extrairMensagemErro($body) {
        if (!is_array($body)) {
            return null;
        }

        $candidatos = ['message', 'error', 'detail', 'details', 'msg', 'raw'];
        foreach ($candidatos as $campo) {
            if (!empty($body[$campo]) && is_string($body[$campo])) {
                return trim($body[$campo]);
            }
        }

        if (!empty($body['response']) && is_array($body['response'])) {
            foreach ($candidatos as $campo) {
                if (!empty($body['response'][$campo]) && is_string($body['response'][$campo])) {
                    return trim($body['response'][$campo]);
                }
            }
        }

        return null;
    }

    private function normalizarMensagemErro($mensagem, $status, $authDiagnostico = null) {
        $mensagem = trim((string)$mensagem);
        if ($mensagem === '') {
            $mensagem = 'Nao foi possivel criar a instancia.';
        }

        if ($status === 403) {
            $msgLower = strtolower($mensagem);
            if ($msgLower === 'forbidden' || str_contains($msgLower, 'forbidden')) {
                if ($authDiagnostico === 'sem_permissao_criacao') {
                    return 'Forbidden (403): a chave autentica na Evolution, mas nao possui permissao para criar instancia. Use uma chave manager/global em EVOLUTION_API_CREATE_KEY.';
                }

                if ($authDiagnostico === 'chave_invalida_ou_bloqueada') {
                    return 'Forbidden (403): chave da Evolution API invalida, sem permissao ou bloqueada. Verifique EVOLUTION_API_CREATE_KEY (ou EVOLUTION_API_KEY) no ambiente.';
                }

                return 'Forbidden (403): a Evolution API recusou a criacao da instancia. Verifique permissoes da chave e, se necessario, configure EVOLUTION_API_CREATE_KEY com uma chave manager/global.';
            }
        }

        return $mensagem;
    }

    private function request($method, $path, $payload = null, $apiKeyOverride = null) {
        $url = rtrim($this->apiUrl, '/') . $path;
        $apiKeyAtual = trim((string)$apiKeyOverride);
        if ($apiKeyAtual === '') {
            $apiKeyAtual = $this->apiKey;
        }

        $headersBase = [
            'Content-Type: application/json; charset=UTF-8',
            'Accept: application/json',
            'User-Agent: MustaBarber/1.0'
        ];

        $variacoesAuth = [
            ['apikey: ' . $apiKeyAtual],
            ['x-api-key: ' . $apiKeyAtual],
            ['Authorization: Bearer ' . $apiKeyAtual],
            ['Authorization: Apikey ' . $apiKeyAtual]
        ];

        $ultimaResposta = null;
        foreach ($variacoesAuth as $headersAuth) {
            $res = $this->executarRequest($method, $url, array_merge($headersBase, $headersAuth), $payload);
            $ultimaResposta = $res;

            if ($res['status'] !== 401 && $res['status'] !== 403) {
                return $res;
            }
        }

        return $ultimaResposta ?: [
            'success' => false,
            'status' => 0,
            'body' => ['message' => 'Falha de autenticacao na Evolution API.']
        ];
    }

    private function executarRequest($method, $url, $headers, $payload = null) {
        $ch = curl_init();
        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ];

        if ($payload !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        curl_setopt_array($ch, $opts);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return [
                'success' => false,
                'status' => 0,
                'body' => ['message' => $curlError]
            ];
        }

        $body = json_decode((string)$response, true);
        if (!is_array($body)) {
            $body = ['raw' => $response];
        }

        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'status' => $httpCode,
            'body' => $body
        ];
    }
}
