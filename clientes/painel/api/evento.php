<?php
// Endpoint do app: o robô pergunta qual evento ele pode usar (ao abrir, a cada
// 30 segundos na tela inicial e a cada 10 enquanto não houver evento).
// GET ?dispositivo=<id do robô>
//
// As fotos só podem ser tiradas com um evento (desde 23/11/2026). Sem evento,
// o robô mostra "Nenhum evento ativo" no lugar da tela inicial, e o
// uploadImage.php também recusa a foto (para o app antigo, que não pergunta).
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'licenca.php';

function responderEvento($status, $dados) {
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    responderEvento(405, array('ok' => false, 'message' => 'Método não permitido.'));
}

$cliente = painelClienteUnico();
if (!$cliente) {
    responderEvento(500, array('ok' => false, 'message' => 'O painel não está configurado no servidor.'));
}

$dispositivo = substr(preg_replace('/[^a-zA-Z0-9]/', '', isset($_GET['dispositivo']) ? (string) $_GET['dispositivo'] : ''), 0, 40);
$motivo = licencaConferirEvento(2, $dispositivo, (bool) painelEventosDosRobos($cliente), $codigo);
if ($motivo !== null) {
    responderEvento(403, array('ok' => false, 'licenca' => false, 'message' => $motivo));
}

painelGravarEventoAtual($cliente);
$evento = painelEventoDoRobo($cliente, $codigo);

responderEvento(200, array(
    'ok' => true,
    'evento' => $evento ? array('nome' => $evento['nome']) : null
));
