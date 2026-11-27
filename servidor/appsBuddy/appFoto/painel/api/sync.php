<?php
// Endpoint do app (botão Sincronizar da tela de admin).
// GET ?dispositivo=<id do robô>&versao=<versão que o robô tem>
// Responde a configuração do painel. Os caminhos de arquivo são relativos à
// pasta appFoto/ e o nome de cada arquivo é o hash do conteúdo, então o robô
// só baixa o que ainda não tem.
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'licenca.php';

function responderSync($status, $dados) {
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$cliente = painelClienteUnico();
if (!$cliente) {
    responderSync(500, array('ok' => false, 'message' => 'O painel não está configurado no servidor (config/painel.local.php ou permissão de gravação em config/painel/).'));
}

$dispositivo = substr(preg_replace('/[^a-zA-Z0-9]/', '', isset($_GET['dispositivo']) ? (string) $_GET['dispositivo'] : ''), 0, 40);

// O código do robô vem da licença, mesmo em painéis com licença opcional.
$codigo = '';
$exigirLicenca = licencaExigida();
if ($exigirLicenca || !empty($_SERVER['HTTP_X_LICENCA'])) {
    $motivo = licencaConferir(2, $dispositivo, $codigo);
    if ($motivo !== null) {
        $visuais = isset($cliente['config']['visuais_robos']) ? $cliente['config']['visuais_robos'] : array();
        $proprios = array_filter($visuais, function ($visual) { return !empty($visual['proprio']); });
        if ($exigirLicenca || $proprios) {
            responderSync(403, array('ok' => false, 'licenca' => false, 'message' => $motivo));
        }
    }
}

// Anota a sincronização do robô, para o painel mostrar quem está atualizado
if ($dispositivo !== '') {
    $atualizado = painelAtualizarCliente($cliente['id'], function ($c) use ($dispositivo) {
        $dispositivos = isset($c['dispositivos']) && is_array($c['dispositivos']) ? $c['dispositivos'] : array();
        $dispositivos[$dispositivo] = array(
            'ultima_sincronizacao' => date('c'),
            'versao' => (int) $c['config']['versao'],
            'ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''
        );

        // Guarda só os 30 robôs que sincronizaram por último
        uasort($dispositivos, function ($a, $b) {
            return strcmp($b['ultima_sincronizacao'], $a['ultima_sincronizacao']);
        });
        $c['dispositivos'] = array_slice($dispositivos, 0, 30, true);
        return $c;
    });
    if ($atualizado) {
        $cliente = $atualizado;
    }
}

$config = painelConfigDoRobo($cliente, $codigo);
$base = 'painel/midia/' . $cliente['id'] . '/';
$slots = painelSlots();

$midia = array();
foreach ($config['midia'] as $chave => $item) {
    if (isset($slots[$chave])) {
        $midia[$chave] = array('arquivo' => $item['arquivo'], 'caminho' => $base . $item['arquivo']);
    }
}

$molduras = array();
foreach ($config['molduras'] as $item) {
    $molduras[] = array('arquivo' => $item['arquivo'], 'caminho' => $base . $item['arquivo']);
}

responderSync(200, array(
    'ok' => true,
    'cliente' => $cliente['nome'],
    'versao' => (int) $config['versao'],
    'atualizado_em' => $config['atualizado_em'],
    'pasta_fotos' => $cliente['pasta_fotos'],
    'cor_primaria' => $config['cor_primaria'],
    'pin_admin' => (string) $config['pin_admin'],
    'tempo_inatividade' => painelTempoInatividade($config),
    'midia' => (object) $midia,
    'molduras' => $molduras,
    // Posição e tamanho de cada botão/elemento, em frações da tela (0 a 1)
    'layout' => (object) painelLayout($config)
));
