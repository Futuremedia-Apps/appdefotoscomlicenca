<?php
// Uma foto da galeria (só com login):
//   foto.php?f=<nome>            a foto inteira
//   foto.php?f=<nome>&mini=1     miniatura (GD, guardada em config/painel/miniaturas/)
//   foto.php?f=<nome>&baixar=1   a foto inteira, como download
//   &ev=<evento>                  foto de um evento (sem ev: fotos sem evento)
//   &lixeira=<AAAA-MM-DD>&ev=<evento ou _sem-evento>   foto na lixeira (lixeira.php)
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib.php';
painelIniciarSessao();

if (!painelLogado() && !painelEquipeLogada()) {
    http_response_code(403);
    exit;
}
$cliente = painelClienteUnico();
// Libera a sessão: o navegador pede dezenas de miniaturas ao mesmo tempo
session_write_close();

$nome = isset($_GET['f']) ? (string) $_GET['f'] : '';
// O evento só vale se estiver na lista do painel (nada de caminho vindo do endereço)
$evento = isset($_GET['ev']) ? (string) $_GET['ev'] : '';
$lixeira = isset($_GET['lixeira']) ? (string) $_GET['lixeira'] : '';
if ($cliente && $lixeira !== '') {
    // Na lixeira: dia e pasta do evento conferidos pelo formato (o evento pode
    // ter sido apagado), então o endereço também não escolhe outra pasta
    $valido = preg_match('/^\d{4}-\d{2}-\d{2}$/', $lixeira) && ($evento === PAINEL_LIXEIRA_SEM_EVENTO || painelIdEventoValido($evento));
    $sub = $lixeira . DIRECTORY_SEPARATOR . $evento;
    $caminho = $valido ? painelPastaLixeira($cliente) . DIRECTORY_SEPARATOR . $sub . DIRECTORY_SEPARATOR . $nome : '';
    $pastaMini = painelPastaMiniaturasLixeira($cliente) . DIRECTORY_SEPARATOR . $sub;
} else {
    $valido = $cliente && ($evento === '' || painelEvento($cliente, $evento));
    $caminho = $valido ? painelPastaFotos($cliente, $evento) . DIRECTORY_SEPARATOR . $nome : '';
    $pastaMini = $valido ? painelPastaMiniaturas($cliente, $evento) : '';
}
if (!$cliente || !$valido || !painelNomeFotoValido($nome) || !is_file($caminho)) {
    http_response_code(404);
    exit;
}

// Miniatura em JPEG com no máximo $largura de largura. Devolve false se não
// der (sem GD, formato estranho, imagem grande demais para a memória).
function fotoGerarMiniatura($origem, $destino, $largura) {
    if (!function_exists('imagecreatetruecolor')) {
        return false;
    }
    $info = @getimagesize($origem);
    if (!$info || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 30000000) {
        return false;
    }
    if ($info[2] === IMAGETYPE_JPEG) {
        $imagem = @imagecreatefromjpeg($origem);
    } elseif ($info[2] === IMAGETYPE_PNG) {
        $imagem = @imagecreatefrompng($origem);
    } elseif (defined('IMAGETYPE_WEBP') && $info[2] === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) {
        $imagem = @imagecreatefromwebp($origem);
    } else {
        $imagem = false;
    }
    if (!$imagem) {
        return false;
    }

    $novaLargura = min($largura, $info[0]);
    $novaAltura = max(1, (int) round($info[1] * $novaLargura / $info[0]));
    $mini = imagecreatetruecolor($novaLargura, $novaAltura);
    imagefill($mini, 0, 0, imagecolorallocate($mini, 255, 255, 255));
    imagecopyresampled($mini, $imagem, 0, 0, 0, 0, $novaLargura, $novaAltura, $info[0], $info[1]);
    imagedestroy($imagem);

    $pasta = dirname($destino);
    if (!is_dir($pasta) && !@mkdir($pasta, 0755, true)) {
        imagedestroy($mini);
        return false;
    }
    $temporario = $destino . '.tmp-' . painelAleatorio(4);
    $ok = imagejpeg($mini, $temporario, 80);
    imagedestroy($mini);
    if (!$ok || !@rename($temporario, $destino)) {
        @unlink($temporario);
        return false;
    }
    return true;
}

function fotoEnviar($caminho, $tipo, $download = '') {
    header('Content-Type: ' . $tipo);
    header('Content-Length: ' . sprintf('%u', filesize($caminho)));
    // As fotos não mudam depois de enviadas (nome com data e código)
    header('Cache-Control: private, max-age=604800');
    header('X-Content-Type-Options: nosniff');
    if ($download !== '') {
        header('Content-Disposition: attachment; filename="' . $download . '"');
    }
    readfile($caminho);
    exit;
}

$extensao = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
$tipos = array('jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp');

if (!empty($_GET['mini'])) {
    $mini = $pastaMini . DIRECTORY_SEPARATOR . $nome . '.jpg';
    if ((is_file($mini) && filemtime($mini) >= filemtime($caminho)) || fotoGerarMiniatura($caminho, $mini, 480)) {
        fotoEnviar($mini, 'image/jpeg');
    }
    // Sem miniatura: manda a foto inteira, o navegador diminui
}

fotoEnviar($caminho, $tipos[$extensao], !empty($_GET['baixar']) ? $nome : '');
