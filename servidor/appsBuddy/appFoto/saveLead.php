<?php
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . DIRECTORY_SEPARATOR . 'appSettings.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'eventRegistry.php';

function responder($status, $payload) {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function limparCampo($valor, $limite) {
    $valor = trim((string) $valor);
    $valor = str_replace(array("\r", "\n"), '', $valor);

    return substr($valor, 0, $limite);
}

// Colunas fixas, sempre nesta ordem. Campos criados no painel entram depois
// delas, entao planilhas e relatorios antigos continuam lendo as mesmas posicoes.
function prepararCabecalhoLead() {
    return array('data_hora', 'evento', 'nome', 'empresa', 'telefone', 'email', 'imagem', 'ip', 'user_agent');
}

function normalizarNomeColunaLead($campo) {
    return strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $campo)));
}

function montarCabecalhoLead($cabecalhoAtual, $colunasExtras) {
    $cabecalho = prepararCabecalhoLead();

    // Colunas que ja existem no arquivo nunca sao removidas, mesmo que o campo
    // tenha sido desligado ou apagado depois: os dados antigos continuam la.
    if (is_array($cabecalhoAtual)) {
        foreach ($cabecalhoAtual as $campo) {
            $campo = normalizarNomeColunaLead($campo);
            if ($campo !== '' && !in_array($campo, $cabecalho, true) && preg_match('/^[a-z][a-z0-9_]{0,39}$/', $campo)) {
                $cabecalho[] = $campo;
            }
        }
    }

    foreach ($colunasExtras as $campo) {
        if (!in_array($campo, $cabecalho, true)) {
            $cabecalho[] = $campo;
        }
    }

    return $cabecalho;
}

function linhaLeadNoCabecalho($mapa, $cabecalho) {
    $linha = array();
    foreach ($cabecalho as $campo) {
        if ($campo === 'empresa' && !isset($mapa[$campo]) && isset($mapa['numero'])) {
            $linha[] = $mapa['numero'];
        } else {
            $linha[] = isset($mapa[$campo]) ? $mapa[$campo] : '';
        }
    }

    return $linha;
}

// Abre o leads.csv com trava exclusiva e garante que o cabecalho tenha todas
// as colunas necessarias. Se precisar mudar o cabecalho, reescreve o arquivo
// (com copia .bak) ainda dentro da trava. Retorna o handle travado.
function abrirCsvLeadComCabecalho($csvPath, $colunasExtras, &$cabecalho, &$erro) {
    $handle = @fopen($csvPath, 'c+');
    if (!$handle) {
        $erro = 'Nao foi possivel salvar o lead.';
        return null;
    }

    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        $erro = 'Nao foi possivel bloquear o arquivo de leads.';
        return null;
    }

    rewind($handle);
    $primeiraLinha = fgetcsv($handle);

    if (!is_array($primeiraLinha) || (count($primeiraLinha) === 1 && trim((string) $primeiraLinha[0]) === '')) {
        $cabecalho = montarCabecalhoLead(null, $colunasExtras);
        ftruncate($handle, 0);
        rewind($handle);
        fputcsv($handle, $cabecalho);
        return $handle;
    }

    $cabecalhoAtual = array_map('normalizarNomeColunaLead', $primeiraLinha);
    $temCabecalho = in_array('imagem', $cabecalhoAtual, true) || in_array('email', $cabecalhoAtual, true);
    $cabecalho = montarCabecalhoLead($temCabecalho ? $cabecalhoAtual : null, $colunasExtras);

    if ($temCabecalho && $cabecalhoAtual === $cabecalho) {
        return $handle;
    }

    $linhas = $temCabecalho ? array() : array($primeiraLinha);
    while (($row = fgetcsv($handle)) !== false) {
        $linhas[] = $row;
    }

    $novo = array();
    foreach ($linhas as $row) {
        if (!is_array($row) || (count($row) === 1 && trim((string) $row[0]) === '')) {
            continue;
        }

        $mapa = array();
        if ($temCabecalho) {
            foreach ($cabecalhoAtual as $index => $nomeCampo) {
                $mapa[$nomeCampo] = isset($row[$index]) ? $row[$index] : '';
            }
        } else {
            // Formato antigo, sem cabecalho.
            $mapa = array(
                'data_hora' => isset($row[0]) ? $row[0] : '',
                'email' => isset($row[1]) ? $row[1] : '',
                'imagem' => isset($row[2]) ? $row[2] : '',
                'ip' => isset($row[3]) ? $row[3] : '',
                'user_agent' => isset($row[4]) ? $row[4] : ''
            );
        }

        $novo[] = linhaLeadNoCabecalho($mapa, $cabecalho);
    }

    // Copia de seguranca escrita a partir do que ja foi lido (copy() falha em
    // arquivo travado no Windows).
    $backup = @fopen($csvPath . '.bak-' . date('Ymd-His'), 'w');
    if ($backup) {
        if ($temCabecalho) {
            fputcsv($backup, $primeiraLinha);
        }
        foreach ($linhas as $row) {
            if (is_array($row)) {
                fputcsv($backup, $row);
            }
        }
        fclose($backup);
    }

    ftruncate($handle, 0);
    rewind($handle);
    fputcsv($handle, $cabecalho);
    foreach ($novo as $row) {
        fputcsv($handle, $row);
    }

    return $handle;
}

// ==================== CÓDIGO ALTERADO (função nova) ====================
// Lista simples de emails em leads/<evento>/emails.txt: um por linha, sem repetir.
// Na primeira vez, o arquivo já nasce com os emails que estão no leads.csv.
function emailsDoCsvLead($csvPath) {
    $emails = array();
    $handle = is_file($csvPath) ? @fopen($csvPath, 'r') : false;
    if (!$handle) {
        return $emails;
    }

    $cabecalho = fgetcsv($handle);
    $indice = is_array($cabecalho) ? array_search('email', array_map('normalizarNomeColunaLead', $cabecalho), true) : false;
    if ($indice !== false) {
        while (($row = fgetcsv($handle)) !== false) {
            $email = isset($row[$indice]) ? strtolower(trim((string) $row[$indice])) : '';
            if ($email !== '' && !in_array($email, $emails, true)) {
                $emails[] = $email;
            }
        }
    }

    fclose($handle);
    return $emails;
}

function salvarEmailNoTxt($txtPath, $csvPath, $email) {
    $email = strtolower(trim((string) $email));
    if ($email === '') {
        return;
    }

    $handle = @fopen($txtPath, 'c+');
    if (!$handle) {
        return;
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return;
    }

    $existentes = array();
    rewind($handle);
    while (($linha = fgets($handle)) !== false) {
        $linha = strtolower(trim($linha));
        if ($linha !== '') {
            $existentes[] = $linha;
        }
    }

    $novos = count($existentes) === 0 ? emailsDoCsvLead($csvPath) : array();
    if (!in_array($email, $novos, true)) {
        $novos[] = $email;
    }

    fseek($handle, 0, SEEK_END);
    foreach ($novos as $novo) {
        if (!in_array($novo, $existentes, true)) {
            fwrite($handle, $novo . "\r\n");
            $existentes[] = $novo;
        }
    }

    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
}
// ================== FIM DO CÓDIGO ALTERADO ==================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder(405, array(
        'ok' => false,
        'message' => 'Metodo nao permitido.'
    ));
}

$raw = file_get_contents('php://input');
$json = json_decode($raw, true);
$dados = is_array($json) ? $json : $_POST;

$imagem = isset($dados['imagem']) ? fotoAppNormalizeImagePath(limparCampo($dados['imagem'], 500)) : '';

if ($imagem === '') {
    responder(400, array(
        'ok' => false,
        'message' => 'Imagem obrigatoria.'
    ));
}

$evento = fotoAppEventFromImage($imagem);
if (!$evento) {
    $evento = fotoAppResolveEventFromInput($dados, true, true);
}

if (!$evento) {
    responder(400, array(
        'ok' => false,
        'message' => 'Evento nao encontrado para esta imagem.'
    ));
}

$settings = fotoAppLoadSettings($evento);
$leadFields = fotoAppEnabledLeadFields($settings);
$valores = array();
$colunasExtras = array();

foreach ($leadFields as $fieldKey => $field) {
    $erroCampo = '';
    $valor = fotoAppSanitizeLeadValue($field, isset($dados[$fieldKey]) ? $dados[$fieldKey] : '', $erroCampo);

    if (!empty($field['required']) && $valor === '') {
        responder(400, array(
            'ok' => false,
            'message' => $field['label'] . ' obrigatorio.'
        ));
    }

    if ($erroCampo !== '') {
        responder(400, array(
            'ok' => false,
            'message' => $erroCampo
        ));
    }

    $valores[$fieldKey] = $valor;
    if (empty($field['builtin'])) {
        $colunasExtras[] = $fieldKey;
    }
}

$leadDir = fotoAppEventLeadDir($evento);
if (!is_dir($leadDir) && !mkdir($leadDir, 0755, true)) {
    responder(500, array(
        'ok' => false,
        'message' => 'Nao foi possivel criar a pasta de leads.'
    ));
}

$csvPath = fotoAppEventLeadCsvPath($evento);
$cabecalho = array();
$erroCsv = '';
$handle = abrirCsvLeadComCabecalho($csvPath, $colunasExtras, $cabecalho, $erroCsv);

if (!$handle) {
    responder(500, array(
        'ok' => false,
        'message' => $erroCsv
    ));
}

$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
$userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? substr(str_replace(array("\r", "\n"), '', $_SERVER['HTTP_USER_AGENT']), 0, 500) : '';

$registro = array_merge($valores, array(
    'data_hora' => date('Y-m-d H:i:s'),
    'evento' => $evento['id'],
    'imagem' => $imagem,
    'ip' => $ip,
    'user_agent' => $userAgent
));

fseek($handle, 0, SEEK_END);
fputcsv($handle, linhaLeadNoCabecalho($registro, $cabecalho));
fflush($handle);

flock($handle, LOCK_UN);
fclose($handle);

// ==================== CÓDIGO ALTERADO ====================
// Também guarda o email em leads/<evento>/emails.txt. Uma falha aqui não
// impede o cadastro, que já foi salvo no leads.csv.
// Para voltar ao original: apague este bloco e a função nova acima.
if (isset($valores['email']) && $valores['email'] !== '') {
    salvarEmailNoTxt($leadDir . DIRECTORY_SEPARATOR . 'emails.txt', $csvPath, $valores['email']);
}
// ================== FIM DO CÓDIGO ALTERADO ==================

responder(200, array(
    'ok' => true,
    'message' => 'Lead salvo com sucesso.'
));
?>
