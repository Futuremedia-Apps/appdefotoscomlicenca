<?php
// Baixa as fotos num .zip (só com login):
//   fotos_zip.php              todas, em pastas por dia (fotos/2026-10-30/...)
//   fotos_zip.php?dia=AAAA-MM-DD  só as fotos daquele dia
//   &evento=<id>                 de um evento ("-" = sem evento; sem nada, o atual)
//
// O .zip é montado enquanto é enviado, sem arquivo temporário e sem depender
// do ZipArchive: as fotos vão "guardadas" (sem compressão, JPEG já é
// comprimido), então o tamanho final é conhecido antes e o navegador mostra o
// progresso. Passando de 4 GB ou 65.535 fotos, usa ZIP64.
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib.php';
painelIniciarSessao();

$cliente = painelEquipeLogada() ? painelClienteUnico() : painelClienteDaPagina();
$dia = isset($_GET['dia']) ? (string) $_GET['dia'] : '';
if ($dia !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia)) {
    $dia = '';
}

$evento = painelEventoDaPagina($cliente, isset($_GET['evento']) ? (string) $_GET['evento'] : null);
$fotos = array();
foreach (painelFotos($cliente, $evento) as $foto) {
    if ($dia === '' || $foto['dia'] === $dia) {
        $fotos[] = $foto;
    }
}
if (!$fotos) {
    painelAviso('erro', 'Não há fotos para baixar.');
    painelIr(painelLogado() ? 'fotos.php' : 'equipe.php');
}
session_write_close();

// Inteiros sem sinal, byte a byte: funciona até em PHP de 32 bits, onde os
// tamanhos acima de 2 GB viram float
function zipU16($n) {
    return pack('v', $n);
}
function zipU32($n) {
    $bytes = '';
    for ($i = 0; $i < 4; $i++) {
        $bytes .= chr((int) fmod($n, 256));
        $n = floor($n / 256);
    }
    return $bytes;
}
function zipU64($n) {
    return zipU32(fmod($n, 4294967296)) . zipU32(floor($n / 4294967296));
}

// Data e hora no formato do MS-DOS, que o .zip usa
function zipDataDos($tempo) {
    $d = getdate($tempo);
    if ($d['year'] < 1980) {
        return array(0, 33); // 01/01/1980
    }
    return array(
        ($d['hours'] << 11) | ($d['minutes'] << 5) | ($d['seconds'] >> 1),
        (($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday']
    );
}

// Acima disto, o campo de 32 bits leva 0xFFFFFFFF e o valor vai no ZIP64
$limite32 = 4294967295;
$no64 = 4294967295;

// Primeiro, o plano: onde cada arquivo começa, para saber o tamanho total
$entradas = array();
$posicao = 0;
foreach ($fotos as $foto) {
    $nomeNoZip = $dia === '' ? 'fotos/' . $foto['dia'] . '/' . $foto['nome'] : $foto['nome'];
    $entradas[] = array('foto' => $foto, 'nome' => $nomeNoZip, 'inicio' => $posicao);
    $posicao += 30 + strlen($nomeNoZip) + $foto['tamanho'];
}
$inicioDiretorio = $posicao;
$tamanhoDiretorio = 0;
foreach ($entradas as $entrada) {
    $tamanhoDiretorio += 46 + strlen($entrada['nome']) + ($entrada['inicio'] > $limite32 ? 12 : 0);
}
$zip64 = count($entradas) > 65535 || $inicioDiretorio > $limite32 || $tamanhoDiretorio > $limite32;
$total = $inicioDiretorio + $tamanhoDiretorio + ($zip64 ? 56 + 20 : 0) + 22;

// Envio
@set_time_limit(0);
@ini_set('zlib.output_compression', 'Off');
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
while (ob_get_level() > 0) {
    ob_end_clean();
}
$arquivoZip = 'fotos-' . ($evento !== '' ? $evento . '-' : '') . ($dia === '' ? 'todas-' . date('Y-m-d') : $dia) . '.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $arquivoZip . '"');
header('Content-Length: ' . sprintf('%.0f', $total));
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$diretorio = '';
foreach ($entradas as $entrada) {
    $foto = $entrada['foto'];
    list($hora, $data) = zipDataDos($foto['tempo']);
    $crc = unpack('N', hash_file('crc32b', $foto['caminho'], true));
    $crc = (float) sprintf('%u', $crc[1]);
    $tamanho = zipU32($foto['tamanho']);

    // Cabeçalho local + a foto
    $longe = $entrada['inicio'] > $limite32;
    echo pack('V', 0x04034b50) . zipU16($longe ? 45 : 20) . zipU16(0) . zipU16(0) . zipU16($hora) . zipU16($data)
        . zipU32($crc) . $tamanho . $tamanho . zipU16(strlen($entrada['nome'])) . zipU16(0) . $entrada['nome'];
    readfile($foto['caminho']);
    flush();

    // Entrada do diretório central (vai no fim)
    $extra = $longe ? zipU16(1) . zipU16(8) . zipU64($entrada['inicio']) : '';
    $diretorio .= pack('V', 0x02014b50) . zipU16($longe ? 45 : 20) . zipU16($longe ? 45 : 20) . zipU16(0) . zipU16(0)
        . zipU16($hora) . zipU16($data) . zipU32($crc) . $tamanho . $tamanho
        . zipU16(strlen($entrada['nome'])) . zipU16(strlen($extra)) . zipU16(0) . zipU16(0) . zipU16(0) . zipU32(0)
        . zipU32($longe ? $no64 : $entrada['inicio']) . $entrada['nome'] . $extra;
}
echo $diretorio;

$quantidade = count($entradas);
if ($zip64) {
    $inicioFim64 = $inicioDiretorio + $tamanhoDiretorio;
    echo pack('V', 0x06064b50) . zipU64(44) . zipU16(45) . zipU16(45) . zipU32(0) . zipU32(0)
        . zipU64($quantidade) . zipU64($quantidade) . zipU64($tamanhoDiretorio) . zipU64($inicioDiretorio);
    echo pack('V', 0x07064b50) . zipU32(0) . zipU64($inicioFim64) . zipU32(1);
}
echo pack('V', 0x06054b50) . zipU16(0) . zipU16(0)
    . zipU16(min($quantidade, 65535)) . zipU16(min($quantidade, 65535))
    . zipU32($tamanhoDiretorio > $limite32 ? $no64 : $tamanhoDiretorio) . zipU32($inicioDiretorio > $limite32 ? $no64 : $inicioDiretorio) . zipU16(0);
