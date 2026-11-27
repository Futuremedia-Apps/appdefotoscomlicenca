<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// Recebe o nome da pasta como parâmetro POST
$nomePasta = isset($_POST['pasta']) ? $_POST['pasta'] : '';

// Valida se a pasta foi informada
if (empty($nomePasta)) {
    http_response_code(400);
    echo json_encode(array("message" => "Nome da pasta não informado."));
    exit;
}

// Sanitiza o nome da pasta para evitar problemas de segurança
$nomePasta = preg_replace('/[^a-zA-Z0-9_-]/', '', $nomePasta);

// Diretório onde as imagens serão salvas
$uploadDirectory = $nomePasta . '/';

// Cria o diretório se ele não existir
if (!is_dir($uploadDirectory)) {
    if (!mkdir($uploadDirectory, 0755, true)) {
        http_response_code(500);
        echo json_encode(array("message" => "Erro ao criar o diretório."));
        exit;
    }
}

// Verifica se o arquivo foi enviado
if (!isset($_FILES['imagem']) || $_FILES['imagem']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(array("message" => "Erro no upload do arquivo."));
    exit;
}

$fileName = basename($_FILES['imagem']['name']);
$uploadFilePath = $uploadDirectory . $fileName;

// Verifica se já existe um arquivo com o mesmo nome
if (file_exists($uploadFilePath)) {
    // Adiciona timestamp para tornar o nome único
    $fileInfo = pathinfo($fileName);
    $fileName = $fileInfo['filename'] . '_' . time() . '.' . $fileInfo['extension'];
    $uploadFilePath = $uploadDirectory . $fileName;
}

// Move o arquivo para o diretório de uploads
if (move_uploaded_file($_FILES['imagem']['tmp_name'], $uploadFilePath)) {
    // Resposta de sucesso
    http_response_code(200);
    echo json_encode(array(
        "message" => "Upload realizado com sucesso.",
        "filePath" => $uploadFilePath,
        "fileName" => $fileName
    ));
} else {
    // Resposta de erro ao mover o arquivo
    http_response_code(500);
    echo json_encode(array("message" => "Erro ao mover o arquivo para o diretório de uploads."));
}
?>