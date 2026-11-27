<?php
// Upload do app Android (camerashot). Sem lead e sem eventos:
// recebe a foto e salva em <esta pasta>/<pasta>/<nome original>.
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

function responder($status, $dados) {
    http_response_code($status);
    echo json_encode($dados);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder(405, array("message" => "Metodo nao permitido."));
}

// Recebe o nome da pasta como parâmetro POST
$nomePasta = isset($_POST['pasta']) ? $_POST['pasta'] : '';

// Sanitiza o nome da pasta para evitar problemas de segurança
$nomePasta = preg_replace('/[^a-zA-Z0-9_-]/', '', $nomePasta);

if ($nomePasta === '') {
    responder(400, array("message" => "Nome da pasta não informado."));
}

// Verifica se o arquivo foi enviado
if (!isset($_FILES['imagem']) || $_FILES['imagem']['error'] !== UPLOAD_ERR_OK) {
    responder(400, array("message" => "Erro no upload do arquivo."));
}

// Só aceita imagens (evita que alguém suba um .php e execute no servidor)
$fileName = basename($_FILES['imagem']['name']);
$fileName = preg_replace('/[^a-zA-Z0-9._-]/', '', $fileName);
$extensao = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

if (!in_array($extensao, array('jpg', 'jpeg', 'png', 'webp'), true)) {
    responder(400, array("message" => "Formato de imagem não permitido."));
}

if (!is_array(@getimagesize($_FILES['imagem']['tmp_name']))) {
    responder(400, array("message" => "Arquivo enviado não é uma imagem válida."));
}

// Diretório onde as imagens serão salvas (dentro desta pasta)
$uploadDirectory = __DIR__ . DIRECTORY_SEPARATOR . $nomePasta;

// Cria o diretório se ele não existir
if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true)) {
    responder(500, array("message" => "Erro ao criar o diretório."));
}

$uploadFilePath = $uploadDirectory . DIRECTORY_SEPARATOR . $fileName;

// Se já existir um arquivo com o mesmo nome, adiciona timestamp
if (file_exists($uploadFilePath)) {
    $fileName = pathinfo($fileName, PATHINFO_FILENAME) . '_' . time() . '.' . $extensao;
    $uploadFilePath = $uploadDirectory . DIRECTORY_SEPARATOR . $fileName;
}

if (!move_uploaded_file($_FILES['imagem']['tmp_name'], $uploadFilePath)) {
    responder(500, array("message" => "Erro ao mover o arquivo para o diretório de uploads."));
}

responder(200, array(
    "message" => "Upload realizado com sucesso.",
    "filePath" => $nomePasta . '/' . $fileName,
    "fileName" => $fileName
));
?>
