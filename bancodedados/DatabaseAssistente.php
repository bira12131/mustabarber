<?php
/**
 * Assistente de Banco de Dados - Connection Pool Manager
 * Sistema: MustaBarber
 * Data: 29/01/2026
 * 
 * Gerenciador de conex�es com o banco de dados PostgreSQL.
 * Implementa padr�o Singleton para garantir que apenas uma conex�o seja usada
 * por todo o sistema, evitando sobrecarga no banco de dados.
 * 
 * Funcionalidades:
 * - Conex�o �nica reutiliz�vel (Singleton)
 * - Reconex�o autom�tica em caso de falha
 * - Pooling de conex�es
 * - Tratamento de erros robusto
 * - Health check da conex�o
 */

class DatabaseAssistente {
    
    // Inst�ncia �nica (Singleton)
    private static $instance = null;
    
    // Conex�o PDO
    private $conexao = null;
    
    // Configura��es do banco
    private $host = '';
    private $port = '5432';
    private $database = '';
    private $user = '';
    private $password = '';
    
    // Configura��es de reconex�o
    private $maxReconnectAttempts = 3;
    private $reconnectDelay = 1; // segundos
    
    // Timestamp da �ltima verifica��o
    private $lastHealthCheck = null;
    private $healthCheckInterval = 300; // 5 minutos
    
    /**
     * Construtor privado (Singleton)
     */
    private function __construct() {
        // Credenciais lidas de variaveis de ambiente (veja .env.example)
        $this->host = getenv('DB_HOST') ?: 'localhost';
        $this->port = getenv('DB_PORT') ?: '5432';
        $this->database = getenv('DB_NAME') ?: '';
        $this->user = getenv('DB_USER') ?: '';
        $this->password = getenv('DB_PASSWORD') ?: '';
        $this->conectar();
    }
    
    /**
     * Previne clonagem (Singleton)
     */
    private function __clone() {}
    
    /**
     * Previne unserialize (Singleton)
     */
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
    
    /**
     * Obt�m a inst�ncia �nica do Assistente
     * 
     * @return DatabaseAssistente
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Estabelece conex�o com o banco de dados
     * 
     * @throws PDOException
     */
    private function conectar() {
        try {
            $dsn = "pgsql:host={$this->host};port={$this->port};dbname={$this->database}";
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => true, // Conex�o persistente
                PDO::ATTR_TIMEOUT => 5,
            ];
            
            $this->conexao = new PDO($dsn, $this->user, $this->password, $options);
            
            // Configura��es adicionais
            $this->conexao->exec("SET NAMES 'UTF8'");
            $this->conexao->exec("SET TIME ZONE 'America/Sao_Paulo'");
            
            $this->lastHealthCheck = time();
            
            error_log("[DatabaseAssistente] Conex�o estabelecida com sucesso");
            
        } catch (PDOException $e) {
            error_log("[DatabaseAssistente] ERRO ao conectar: " . $e->getMessage());
            throw new PDOException("Falha ao conectar com banco de dados: " . $e->getMessage());
        }
    }
    
    /**
     * Obt�m a conex�o PDO
     * Verifica sa�de da conex�o antes de retornar
     * 
     * @return PDO
     */
    public function getConexao() {
        // Verificar se precisa fazer health check
        if ($this->needsHealthCheck()) {
            $this->healthCheck();
        }
        
        return $this->conexao;
    }
    
    /**
     * Verifica se � necess�rio fazer health check
     * 
     * @return bool
     */
    private function needsHealthCheck() {
        if ($this->lastHealthCheck === null) {
            return true;
        }
        
        $timeSinceLastCheck = time() - $this->lastHealthCheck;
        return $timeSinceLastCheck >= $this->healthCheckInterval;
    }
    
    /**
     * Verifica a sa�de da conex�o
     * Reconecta se necess�rio
     */
    private function healthCheck() {
        try {
            // Tenta executar uma query simples
            $stmt = $this->conexao->query("SELECT 1");
            $stmt->fetch();
            
            $this->lastHealthCheck = time();
            
        } catch (PDOException $e) {
            error_log("[DatabaseAssistente] Health check falhou: " . $e->getMessage());
            $this->reconectar();
        }
    }
    
    /**
     * Reconecta ao banco de dados
     * Tenta m�ltiplas vezes com delay
     */
    private function reconectar() {
        $tentativas = 0;
        
        while ($tentativas < $this->maxReconnectAttempts) {
            try {
                error_log("[DatabaseAssistente] Tentativa de reconex�o " . ($tentativas + 1) . "/{$this->maxReconnectAttempts}");
                
                $this->conexao = null;
                sleep($this->reconnectDelay);
                
                $this->conectar();
                
                error_log("[DatabaseAssistente] Reconex�o bem-sucedida");
                return;
                
            } catch (PDOException $e) {
                $tentativas++;
                
                if ($tentativas >= $this->maxReconnectAttempts) {
                    error_log("[DatabaseAssistente] FALHA: Todas tentativas de reconex�o esgotadas");
                    throw new PDOException("N�o foi poss�vel reconectar ao banco de dados ap�s {$this->maxReconnectAttempts} tentativas");
                }
            }
        }
    }
    
    /**
     * Executa uma query com tratamento de erro e reconex�o autom�tica
     * 
     * @param string $sql SQL query
     * @param array $params Par�metros da query
     * @return PDOStatement
     */
    public function executarQuery($sql, $params = []) {
        try {
            $stmt = $this->getConexao()->prepare($sql);
            $stmt->execute($params);
            return $stmt;
            
        } catch (PDOException $e) {
            // Se for erro de conex�o, tenta reconectar e executar novamente
            if ($this->isConnectionError($e)) {
                error_log("[DatabaseAssistente] Erro de conex�o detectado, tentando reconectar...");
                $this->reconectar();
                
                // Tenta executar novamente ap�s reconex�o
                $stmt = $this->getConexao()->prepare($sql);
                $stmt->execute($params);
                return $stmt;
            }
            
            // Se n�o for erro de conex�o, propaga o erro
            throw $e;
        }
    }
    
    /**
     * Verifica se o erro � relacionado � conex�o
     * 
     * @param PDOException $e
     * @return bool
     */
    private function isConnectionError($e) {
        $connectionErrors = [
            'SQLSTATE[HY000]',
            'SQLSTATE[08001]',
            'SQLSTATE[08006]',
            'server has gone away',
            'connection lost',
            'connection was lost'
        ];
        
        $errorMessage = $e->getMessage();
        
        foreach ($connectionErrors as $error) {
            if (stripos($errorMessage, $error) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Inicia uma transa��o
     * 
     * @return bool
     */
    public function beginTransaction() {
        return $this->getConexao()->beginTransaction();
    }
    
    /**
     * Confirma uma transa��o
     * 
     * @return bool
     */
    public function commit() {
        return $this->getConexao()->commit();
    }
    
    /**
     * Desfaz uma transa��o
     * 
     * @return bool
     */
    public function rollback() {
        return $this->getConexao()->rollBack();
    }
    
    /**
     * Verifica se est� em uma transa��o
     * 
     * @return bool
     */
    public function inTransaction() {
        return $this->getConexao()->inTransaction();
    }
    
    /**
     * Retorna o �ltimo ID inserido
     * 
     * @param string $name Nome da sequ�ncia (PostgreSQL)
     * @return string
     */
    public function lastInsertId($name = null) {
        return $this->getConexao()->lastInsertId($name);
    }
    
    /**
     * Obt�m informa��es sobre o estado da conex�o
     * 
     * @return array
     */
    public function getConnectionInfo() {
        return [
            'host' => $this->host,
            'database' => $this->database,
            'user' => $this->user,
            'last_health_check' => $this->lastHealthCheck ? date('Y-m-d H:i:s', $this->lastHealthCheck) : 'Nunca',
            'connection_active' => $this->conexao !== null
        ];
    }
    
    /**
     * Fecha a conex�o explicitamente
     * (Normalmente n�o � necess�rio devido ao PDO::ATTR_PERSISTENT)
     */
    public function close() {
        $this->conexao = null;
        error_log("[DatabaseAssistente] Conex�o fechada");
    }
}
?>
