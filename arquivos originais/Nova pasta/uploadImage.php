<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

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
    // Só letras, números, "_" e "-" (sem subpastas, sem "..")
    return preg_replace('/[^a-zA-Z0-9_-]/', '', trim((string) $pasta));
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
// Sem eventos: usa só a pasta enviada pelo app (ex.: "TagCultSec").
if (isset($_POST['pasta'])) {
    $pastaLegada = uploadPastaLegada($_POST['pasta']);
}
// ================== FIM DO CÓDIGO ALTERADO ==================

    if ($pastaLegada === '') {
        responderUpload(400, array("ok" => false, "message" => "Evento ou pasta nao informado."));
    }

    $uploadDirectory = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pastaLegada);
    $uploadRelativeDirectory = $pastaLegada;
// } // fim do "else" do CÓDIGO ORIGINAL

if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true)) {
    responderUpload(500, array("ok" => false, "message" => "Erro ao criar o diretorio."));
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
