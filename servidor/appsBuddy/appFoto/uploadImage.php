<?php
// Sem "Access-Control-Allow-Origin": o robô envia pelo app (não precisa de
// CORS) e assim uma página de outro site não consegue enviar fotos pelo
// navegador de quem a visita.
header("Content-Type: application/json; charset=UTF-8");
header("X-Content-Type-Options: nosniff");

date_default_timezone_set('America/Sao_Paulo');

// ==================== CÓDIGO ORIGINAL ====================
// Para voltar ao padrão (com eventos): descomente esta linha, apague os
// blocos "CÓDIGO ALTERADO" deste arquivo e suba o eventRegistry.php junto.
//
// require_once __DIR__ . DIRECTORY_SEPARATOR . 'eventRegistry.php';
// ================== FIM DO CÓDIGO ORIGINAL ==================

// ==================== CÓDIGO ALTERADO ====================
// Em 2026-10-29 o eventRegistry.php não existe mais no servidor e o require
// derrubava o upload com erro 500 (resposta vazia). Agora o arquivo funciona
// sozinho: salva em <esta pasta>/<pasta>/photo_<data>_<código>.jpg.
function uploadNormalizarPasta($pasta) {
    // Letras, números, "_" e "-", com subpastas separadas por "/" (ex.:
    // "clienteFotos/buddyApp"). Pontos são removidos, então ".." não passa.
    $partes = array();
    foreach (explode('/', str_replace('\\', '/', trim((string) $pasta))) as $parte) {
        $parte = preg_replace('/[^a-zA-Z0-9_-]/', '', $parte);
        if ($parte !== '') {
            $partes[] = $parte;
        }
    }
    return implode('/', $partes);
}

// A consulta do app e o upload usam os mesmos vínculos do painel. Para uma
// instalação antiga sem cadastro de cliente, aceita o arquivo legado.
function uploadEventoDaFoto($cliente, $codigo) {
    if ($cliente) {
        $evento = painelEventoDoEnvio($cliente, $codigo);
        return $evento ? PAINEL_PASTA_EVENTOS . '/' . $evento['id'] : '';
    }
    $dados = painelLerArquivo(PAINEL_DADOS . DIRECTORY_SEPARATOR . 'evento_atual.php');
    $evento = isset($dados['pasta']) ? (string) $dados['pasta'] : '';
    if ($evento === '' && isset($dados['encerrado']['pasta'], $dados['encerrado']['em'])
        && time() - (int) $dados['encerrado']['em'] <= PAINEL_TOLERANCIA_ENCERRAR) {
        $evento = (string) $dados['encerrado']['pasta'];
    }
    return preg_match('#^eventos/\d{4}-\d{2}-\d{2}(-[a-z0-9]+)*$#', $evento) && strlen($evento) <= 90 ? $evento : '';
}

// Pasta das fotos configurada neste painel (config/painel.local.php,
// 'pasta_fotos'). Quem manda é o servidor, não o robô: mudar a pasta vale na
// hora, sem sincronizar. null se o painel não tem a configuração.
function uploadPastaDoPainel() {
    $arquivo = __DIR__ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'painel.local.php';
    $config = is_file($arquivo) ? include $arquivo : null;
    if (!is_array($config) || !array_key_exists('pasta_fotos', $config)) {
        return null;
    }
    return uploadNormalizarPasta((string) $config['pasta_fotos']);
}

// Página em branco nas pastas de fotos, para o navegador não listar as fotos
function uploadProtegerPasta($pasta) {
    $indice = $pasta . DIRECTORY_SEPARATOR . 'index.html';
    if (is_dir($pasta) && !is_file($indice)) {
        @file_put_contents($indice, '');
    }
}

// .htaccess na pasta das fotos do painel (ex.: fotos/): as fotos abrem
// normalmente, mas nenhum script roda ali dentro. O mesmo texto está na
// central (licencas/lib.php, licHtaccessFotos). Só para a pasta configurada
// no painel, nunca para a pasta deste arquivo (onde está o código).
function uploadBloquearScripts($pastaDoPainel) {
    $primeira = strtolower((string) strtok((string) $pastaDoPainel, '/'));
    $sistema = array('painel', 'config', 'licencas', 'login', 'leads', 'backup', 'cache', 'tools', 'cgi-bin', 'midia', 'galeria');
    if ($primeira === '' || !preg_match('/^[a-z0-9_-]+$/', $primeira) || in_array($primeira, $sistema, true)) {
        return;
    }
    $pasta = __DIR__ . DIRECTORY_SEPARATOR . strtok((string) $pastaDoPainel, '/');
    $arquivo = $pasta . DIRECTORY_SEPARATOR . '.htaccess';
    // Pasta que tem código (outro painel, por engano de configuração): não mexe
    if (!is_dir($pasta) || is_file($arquivo) || is_dir($pasta . DIRECTORY_SEPARATOR . 'painel') || glob($pasta . DIRECTORY_SEPARATOR . '*.php')) {
        return;
    }
    @file_put_contents($arquivo, "# Fotos dos robôs: só imagens. Nenhum script roda nesta pasta (nem nas de dentro).\n"
        . "<FilesMatch \"\\.(php[0-9]?|pht|phtml|phar|phps|shtml|cgi|pl|py|sh|asp|aspx|jsp)(\\.|$)\">\n"
        . "    <IfModule mod_authz_core.c>\n"
        . "        Require all denied\n"
        . "    </IfModule>\n"
        . "    <IfModule !mod_authz_core.c>\n"
        . "        Order deny,allow\n"
        . "        Deny from all\n"
        . "    </IfModule>\n"
        . "</FilesMatch>\n");
}

function uploadRandomHex($bytes) {
    if (function_exists('random_bytes')) {
        return bin2hex(random_bytes($bytes));
    }
    if (function_exists('openssl_random_pseudo_bytes')) {
        return bin2hex(openssl_random_pseudo_bytes($bytes));
    }
    return substr(md5(uniqid((string) mt_rand(), true)), 0, $bytes * 2);
}
// ================== FIM DO CÓDIGO ALTERADO ==================

function responderUpload($status, $payload) {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function uploadEventoInformado($dados) {
    foreach (array('evento', 'event', 'evento_id', 'event_id') as $key) {
        if (isset($dados[$key]) && trim((string) $dados[$key]) !== '') {
            return true;
        }
    }

    return false;
}

function uploadPastaLegada($pasta) {
    // ==================== CÓDIGO ORIGINAL ====================
    // $pasta = fotoAppNormalizeRelativeFolder($pasta);
    // ================== FIM DO CÓDIGO ORIGINAL ==================
    // ==================== CÓDIGO ALTERADO ====================
    $pasta = uploadNormalizarPasta($pasta);
    // ================== FIM DO CÓDIGO ALTERADO ==================
    return $pasta === '' ? '' : $pasta;
}

function uploadExtensaoPermitida($fileName) {
    $ext = strtolower(pathinfo((string) $fileName, PATHINFO_EXTENSION));
    return in_array($ext, array('jpg', 'jpeg', 'png', 'webp'), true) ? $ext : '';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderUpload(405, array("ok" => false, "message" => "Metodo nao permitido."));
}

// Licença do robô (cabeçalho X-Licenca), se o painel exigir
// ('exigir_licenca' em config/painel.local.php)
require_once __DIR__ . DIRECTORY_SEPARATOR . 'painel' . DIRECTORY_SEPARATOR . 'licenca.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'painel' . DIRECTORY_SEPARATOR . 'lib.php';
$cliente = painelClienteUnico();
if ($cliente) {
    // O upload não pode recriar uma pasta que o painel acabou de excluir.
    $travaEvento = painelTravarCliente($cliente['id'], LOCK_SH);
    $cliente = $travaEvento ? painelCliente($cliente['id']) : null;
}
if (!$cliente && painelConfigLocal()) {
    responderUpload(503, array("ok" => false, "message" => "Não foi possível consultar o evento. Tente de novo."));
}
$motivoLicenca = licencaConferirEvento(0, null, $cliente && painelEventosDosRobos($cliente), $codigo);
if ($motivoLicenca !== null) {
    responderUpload(403, array("ok" => false, "licenca" => false, "message" => $motivoLicenca));
}

// Calcula o destino uma vez para que uma alteração simultânea no painel não
// mude a pasta depois de o envio ter sido autorizado.
$pastaEvento = uploadEventoDaFoto($cliente, $codigo);
if ($pastaEvento === '') {
    responderUpload(409, array("ok" => false, "evento" => false, "message" => "Nenhum evento ativo para este robô. Escolha ou reabra o evento no painel."));
}

if (!isset($_FILES['imagem']) || $_FILES['imagem']['error'] !== UPLOAD_ERR_OK) {
    responderUpload(400, array("ok" => false, "message" => "Erro no upload do arquivo."));
}

$extensao = uploadExtensaoPermitida($_FILES['imagem']['name']);
if ($extensao === '') {
    responderUpload(400, array("ok" => false, "message" => "Formato de imagem nao permitido."));
}

$evento = null;
$pastaLegada = '';

// ==================== CÓDIGO ORIGINAL ====================
// if (uploadEventoInformado($_POST)) {
//     $evento = fotoAppResolveEventFromInput($_POST, false, true);
//     if (!$evento) {
//         responderUpload(404, array("ok" => false, "message" => "Evento nao encontrado."));
//     }
// } elseif (isset($_POST['pasta']) && trim((string) $_POST['pasta']) !== '') {
//     $evento = fotoAppFindEvent($_POST['pasta'], null, true);
//     if (!$evento) {
//         $pastaLegada = uploadPastaLegada($_POST['pasta']);
//         if ($pastaLegada === 'fotos') {
//             $evento = fotoAppResolveEventFromInput($_POST, true, true);
//             $pastaLegada = '';
//         }
//     }
// } else {
//     $evento = fotoAppResolveEventFromInput($_POST, true, true);
// }
//
// if ($evento) {
//     $uploadDirectory = fotoAppEventAbsoluteFolder($evento);
//     $uploadRelativeDirectory = fotoAppEventRelativeFolder($evento);
// } else {
// ================== FIM DO CÓDIGO ORIGINAL ==================

// ==================== CÓDIGO ALTERADO ====================
// Sem eventos: usa só a pasta enviada pelo app (ex.: "clienteFotos/buddyApp").
// Pasta vazia = salva na própria pasta deste arquivo.
if (isset($_POST['pasta'])) {
    // A pasta configurada no painel vale mais que a enviada pelo robô
    $pastaDoPainel = uploadPastaDoPainel();
    $pastaLegada = $pastaDoPainel !== null ? $pastaDoPainel : uploadPastaLegada($_POST['pasta']);
    // Evento escolhido para este robô: <pasta>/eventos/<evento>.
    $pastaLegada = $pastaLegada === '' ? $pastaEvento : $pastaLegada . '/' . $pastaEvento;
}
// ================== FIM DO CÓDIGO ALTERADO ==================

    // ==================== CÓDIGO ORIGINAL ====================
    // if ($pastaLegada === '') {
    // ================== FIM DO CÓDIGO ORIGINAL ==================
    // ==================== CÓDIGO ALTERADO ====================
    if (!isset($_POST['pasta'])) {
    // ================== FIM DO CÓDIGO ALTERADO ==================
        responderUpload(400, array("ok" => false, "message" => "Evento ou pasta nao informado."));
    }

    // ==================== CÓDIGO ORIGINAL ====================
    // $uploadDirectory = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pastaLegada);
    // ================== FIM DO CÓDIGO ORIGINAL ==================
    // ==================== CÓDIGO ALTERADO ====================
    $uploadDirectory = $pastaLegada === '' ? __DIR__ : __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pastaLegada);
    // ================== FIM DO CÓDIGO ALTERADO ==================
    $uploadRelativeDirectory = $pastaLegada;
// } // fim do "else" do CÓDIGO ORIGINAL

if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true)) {
    responderUpload(500, array("ok" => false, "message" => "Erro ao criar o diretorio."));
}
// index.html em branco na pasta da foto e nas pastas acima dela (até a deste
// arquivo), para ninguém listar as fotos pelo navegador
for ($pastaIndice = $uploadDirectory; strlen($pastaIndice) > strlen(__DIR__); $pastaIndice = dirname($pastaIndice)) {
    uploadProtegerPasta($pastaIndice);
}
if (isset($pastaDoPainel) && $pastaDoPainel !== null && $pastaDoPainel !== '') {
    uploadBloquearScripts($pastaDoPainel);
}

$imageInfo = @getimagesize($_FILES['imagem']['tmp_name']);
if (!is_array($imageInfo)) {
    responderUpload(400, array("ok" => false, "message" => "Arquivo enviado nao parece ser uma imagem valida."));
}

$baseName = 'photo_' . date('Y-m-d_H-i-s');

// Bloco de eventos (original). Com o CÓDIGO ALTERADO acima, $evento é sempre
// null, então este trecho não roda e não precisa do eventRegistry.php.
if ($evento) {
    $storeResult = null;
    $storeError = '';
    $preferredFileName = $baseName . '.' . $extensao;
    if (fotoAppStoreEventPhoto($_FILES['imagem']['tmp_name'], $evento, $preferredFileName, false, $storeResult, $storeError)) {
        $duplicate = !empty($storeResult['duplicate']);
        responderUpload(200, array(
            "ok" => true,
            "message" => $duplicate ? "Esta imagem ja estava na galeria." : "Upload realizado com sucesso.",
            "duplicate" => $duplicate,
            "filePath" => $storeResult['filePath'],
            "fileName" => $storeResult['fileName'],
            "evento" => $evento['public_key'],
            "eventoId" => $evento['id'],
            "eventoNome" => $evento['name']
        ));
    }

    responderUpload(500, array(
        "ok" => false,
        "message" => $storeError !== '' ? $storeError : "Erro ao mover o arquivo para o diretorio de uploads."
    ));
}

$fileName = '';
$uploadFilePath = '';
for ($i = 0; $i < 6; $i++) {
    // ==================== CÓDIGO ORIGINAL ====================
    // $fileName = $baseName . '_' . fotoAppRandomHex(8) . '.' . $extensao;
    // ================== FIM DO CÓDIGO ORIGINAL ==================
    // ==================== CÓDIGO ALTERADO ====================
    $fileName = $baseName . '_' . uploadRandomHex(4) . '.' . $extensao;
    // ================== FIM DO CÓDIGO ALTERADO ==================
    $uploadFilePath = $uploadDirectory . DIRECTORY_SEPARATOR . $fileName;
    if (!file_exists($uploadFilePath)) {
        break;
    }
}

if ($fileName === '' || file_exists($uploadFilePath)) {
    responderUpload(500, array("ok" => false, "message" => "Nao foi possivel gerar um nome seguro para a imagem."));
}

if (move_uploaded_file($_FILES['imagem']['tmp_name'], $uploadFilePath)) {
    $relativePath = trim($uploadRelativeDirectory . '/' . $fileName, '/');
    // ==================== CÓDIGO ALTERADO ====================
    // Página que o QR Code abre (painel/qr.php: cadastro de leads e a foto),
    // a partir da pasta deste arquivo. O app 1.0.3 em diante usa "pagina" no
    // QR Code; o app antigo ignora e continua com a página antiga.
    $pagina = '';
    if (preg_match('#(?:^|/)eventos/(\d{4}-\d{2}-\d{2}(?:-[a-z0-9]+)*)$#', $uploadRelativeDirectory, $eventoDaPasta)
        && is_file(__DIR__ . DIRECTORY_SEPARATOR . 'painel' . DIRECTORY_SEPARATOR . 'qr.php')) {
        $pagina = 'painel/qr.php?e=' . rawurlencode($eventoDaPasta[1]) . '&f=' . rawurlencode($fileName);
    }
    // ================== FIM DO CÓDIGO ALTERADO ==================
    responderUpload(200, array(
        "ok" => true,
        "message" => "Upload realizado com sucesso.",
        "duplicate" => false,
        "filePath" => $relativePath,
        "fileName" => $fileName,
        "evento" => '',
        "eventoId" => '',
        "eventoNome" => '',
        "pagina" => $pagina
    ));
}

responderUpload(500, array("ok" => false, "message" => "Erro ao mover o arquivo para o diretorio de uploads."));
?>
