<?php
// Desabilitar exibi��o de erros para n�o corromper JSON
ini_set('display_errors', 0);
error_reporting(0);

session_start();
require_once '../conexao.php';

// Limpar qualquer sa�da anterior
ob_clean();

// Definir header JSON
header('Content-Type: application/json; charset=utf-8');

function json_exit($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['usuario_codigo'])) {
    json_exit(['sucesso' => false, 'mensagem' => 'Usu&aacute;rio n&atilde;o autenticado']);
}

$codigo_barbeiro = $_SESSION['usuario_codigo'];
$dados = json_decode(file_get_contents('php://input'), true);

if (!isset($dados['configuracoes'])) {
    json_exit(['sucesso' => false, 'mensagem' => 'Configura&ccedil;&otilde;es inv&aacute;lidas']);
}

try {
    $conn->beginTransaction();

    foreach ($dados['configuracoes'] as $cfg) {

        $dia_semana        = (int)$cfg['dia_semana'];
        $horario_inicio    = $cfg['horario_inicio'];
        $horario_fim       = $cfg['horario_fim'];
        $intervalo         = (int)$cfg['intervalo_minutos'];
        $ativo             = $cfg['ativo'] ? 1 : 0;

        $almoco_ativo  = !empty($cfg['almoco_ativo']) ? 1 : 0;
        $almoco_inicio = $almoco_ativo && !empty($cfg['almoco_inicio']) ? $cfg['almoco_inicio'] : null;
        $almoco_fim    = $almoco_ativo && !empty($cfg['almoco_fim'])    ? $cfg['almoco_fim']    : null;

        if ($almoco_ativo && (!$almoco_inicio || !$almoco_fim)) {
            throw new Exception('Informe o in\u00edcio e o fim do hor\u00e1rio de almo\u00e7o.');
        }

        if ($almoco_ativo && $almoco_inicio >= $almoco_fim) {
            throw new Exception('O in\u00edcio do almo\u00e7o deve ser antes do fim.');
        }

        // Verifica se existe
        $stmt = $conn->prepare("SELECT codigo FROM horarios_configuracao
                                WHERE codigo_barbeiro = ? AND dia_semana = ?");
        $stmt->execute([$codigo_barbeiro, $dia_semana]);
        $row = $stmt->fetch();

        if ($row) {
            // Atualiza
            $stmt = $conn->prepare("
                UPDATE horarios_configuracao SET
                    horario_inicio = ?, 
                    horario_fim = ?, 
                    intervalo_minutos = ?, 
                    ativo = ?,
                    almoco_ativo = ?,
                    almoco_inicio = ?,
                    almoco_fim = ?
                WHERE codigo = ?
            ");
            $stmt->execute([$horario_inicio, $horario_fim, $intervalo, $ativo, $almoco_ativo, $almoco_inicio, $almoco_fim, $row['codigo']]);

        } else {
            // Insere
            $stmt = $conn->prepare("
                INSERT INTO horarios_configuracao
                (codigo_barbeiro, dia_semana, horario_inicio, horario_fim, intervalo_minutos, ativo, almoco_ativo, almoco_inicio, almoco_fim)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$codigo_barbeiro, $dia_semana, $horario_inicio, $horario_fim, $intervalo, $ativo, $almoco_ativo, $almoco_inicio, $almoco_fim]);
        }
    }

    $conn->commit();
    json_exit(['sucesso' => true, 'mensagem' => 'Configura&ccedil;&otilde;es salvas com sucesso']);

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    json_exit(['sucesso' => false, 'mensagem' => $e->getMessage()]);
}
