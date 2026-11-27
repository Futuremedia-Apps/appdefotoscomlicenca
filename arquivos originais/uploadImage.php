<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . DIRECTORY_SEPARATOR . 'eventRegistry.php';

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
    $pasta = fotoAppNormalizeRelativeFolder($pasta);
    return $pasta === '' ? '' : $pasta;
}

function uploadExtensaoPermitida($fileName) {
    $ext = strtolower(pathinfo((string) $fileName, PATHINFO_EXTENSION));
    return in_array($ext, array('jpg', 'jpeg', 'png', 'webp'), true) ? $ext : '';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderUpload(405, array("ok" => false, "message" => "Metodo nao permitido."));
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

if (uploadEventoInformado($_POST)) {
    $evento = fotoAppResolveEventFromInput($_POST, false, true);
    if (!$evento) {
        responderUpload(404, array("ok" => false, "message" => "Evento nao encontrado."));
    }
} elseif (isset($_POST['pasta']) && trim((string) $_POST['pasta']) !== '') {
    $evento = fotoAppFindEvent($_POST['pasta'], null, true);
    if (!$evento) {
        $pastaLegada = uploadPastaLegada($_POST['pasta']);
        if ($pastaLegada === 'fotos') {
            $evento = fotoAppResolveEventFromInput($_POST, true, true);
            $pastaLegada = '';
        }
    }
} else {
    $evento = fotoAppResolveEventFromInput($_POST, true, true);
}

if ($evento) {
    $uploadDirectory = fotoAppEventAbsoluteFolder($evento);
    $uploadRelativeDirectory = fotoAppEventRelativeFolder($evento);
} else {
    if ($pastaLegada === '') {
        responderUpload(400, array("ok" => false, "message" => "Evento ou pasta nao informado."));
    }

    $uploadDirectory = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pastaLegada);
    $uploadRelativeDirectory = $pastaLegada;
}

if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true)) {
    responderUpload(500, array("ok" => false, "message" => "Erro ao criar o diretorio."));
}

$imageInfo = @getimagesize($_FILES['imagem']['tmp_name']);
if (!is_array($imageInfo)) {
    responderUpload(400, array("ok" => false, "message" => "Arquivo enviado nao parece ser uma imagem valida."));
}

$baseName = 'photo_' . date('Y-m-d_H-i-s');

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
    $fileName = $baseName . '_' . fotoAppRandomHex(8) . '.' . $extensao;
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
    responderUpload(200, array(
        "ok" => true,
        "message" => "Upload realizado com sucesso.",
        "duplicate" => false,
        "filePath" => $relativePath,
        "fileName" => $fileName,
        "evento" => '',
        "eventoId" => '',
        "eventoNome" => ''
    ));
}

responderUpload(500, array("ok" => false, "message" => "Erro ao mover o arquivo para o diretorio de uploads."));
?>
