<?php
/**
 * Buscar dados de um barbeiro espec�fico (sem autentica��o)
 * Sistema: MustaBarber
 * Uso: Tela p�blica de agendamento
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

// Incluir arquivo de conex�o
require_once '../conexao.php';

try {
    // Verificar se o c�digo do barbeiro foi informado
    if (!isset($_GET['codigo']) || empty($_GET['codigo'])) {
        throw new Exception('C�digo do barbeiro n�o informado');
    }

    $codigoBarbeiro = intval($_GET['codigo']);

    // Buscar dados do barbeiro
    // Aceita qualquer perfil profissional (barbeiro/admin em qualquer plano).
    // Exclui apenas o perfil 5 (Cliente), garantindo que dados p�blicos m�nimos
    // (nome e foto) apare�am tanto para donos quanto para funcion�rios.
    $sql = "SELECT 
                codigo,
                nome,
                email,
                telefone,
                foto_caminho,
                perfil,
                status
            FROM usuarios 
            WHERE codigo = :codigo 
            AND status = 1
            AND perfil <> 5";

    $stmt = $conexao->prepare($sql);
    $stmt->bindParam(':codigo', $codigoBarbeiro, PDO::PARAM_INT);
    $stmt->execute();
    
    $barbeiro = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$barbeiro) {
        throw new Exception('Barbeiro n�o encontrado ou inativo');
    }

    // Formatar dados
    $barbeiro['codigo'] = intval($barbeiro['codigo']);
    $barbeiro['perfil'] = intval($barbeiro['perfil']);
    $barbeiro['status'] = intval($barbeiro['status']);
    
    // Retornar caminho da foto cru (como salvo no banco, ex: "uploads/fotos/xxx.jpg").
    // O cliente (HTML de agendamento) � respons�vel por aplicar o prefixo relativo
    // apropriado, mantendo compatibilidade com o fluxo p�blico da barbearia.
    if (empty($barbeiro['foto_caminho'])) {
        $barbeiro['foto_caminho'] = null;
    }

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Barbeiro encontrado com sucesso',
        'barbeiro' => $barbeiro
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => $e->getMessage(),
        'barbeiro' => null
    ], JSON_UNESCAPED_UNICODE);
}
?>
