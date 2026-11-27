<?php
// Baixa os leads de um evento, ou de todos, em Excel (.xlsx) ou CSV (só com login).
//   leads_baixar.php?evento=<id>&formato=xlsx|csv
//   leads_baixar.php?evento=todos&formato=xlsx      todos os eventos numa planilha
//
// Colunas: data e hora, evento, as respostas (na ordem do formulário, e depois
// as de campos que já saíram dele) e o link da foto. "Outras fotos" só aparece
// se alguém abriu mais de uma foto no evento.
//
// O .xlsx é montado aqui mesmo (XML + zip), sem biblioteca: funciona em
// qualquer PHP. A data é data de verdade no Excel (dá para ordenar e filtrar),
// o número é número e o link da foto mostra o nome do arquivo. O CSV usa ";"
// e UTF-8 com BOM, que é como o Excel em português abre sem estragar os acentos.
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'leads_lib.php';
painelIniciarSessao();

$cliente = painelClienteDaPagina();
session_write_close();

$pedido = isset($_GET['evento']) ? (string) $_GET['evento'] : '';
$formato = isset($_GET['formato']) && $_GET['formato'] === 'csv' ? 'csv' : 'xlsx';
$escolhidos = array();
foreach (leadsEventos($cliente) as $evento) {
    if (($pedido === 'todos' && $evento['leads'] > 0) || $evento['id'] === $pedido) {
        $escolhidos[] = $evento;
    }
}
if (!$escolhidos) {
    painelIniciarSessao();
    painelAviso('erro', 'Não há leads para baixar.');
    painelIr('leads.php');
}

// Todos os cadastros, do mais novo para o mais antigo
$leads = array();
$nomesEventos = array();
$colunas = array();
foreach ($escolhidos as $evento) {
    $idEvento = $evento['id'];
    $nomesEventos[$idEvento] = $evento['nome'];
    $cadastros = leadsDoEvento($cliente, $idEvento);
    $leads = array_merge($leads, $cadastros);
    $registro = painelEvento($cliente, $idEvento, true);
    $config = $registro ? leadsConfig($cliente, $registro) : null;
    foreach (leadsColunas($config, $cadastros) as $coluna) {
        // Dois eventos podem dar rótulos diferentes ao mesmo campo.
        $chave = json_encode($coluna);
        if (!isset($colunas[$chave])) {
            $colunas[$chave] = $coluna;
        }
        $colunas[$chave]['eventos'][$idEvento] = true;
    }
}
usort($leads, function ($a, $b) {
    $ta = strtotime($a['em']);
    $tb = strtotime($b['em']);
    return $ta === $tb ? 0 : ($ta < $tb ? 1 : -1);
});
$outrasFotos = false;
foreach ($leads as $lead) {
    $outrasFotos = $outrasFotos || count($lead['fotos']) > 1;
}

// ---------- Tabela (igual para os dois formatos) ----------

$cabecalho = array('Data e hora', 'Evento');
foreach ($colunas as $coluna) {
    $cabecalho[] = $coluna['rotulo'];
}
$cabecalho[] = 'Foto';
if ($outrasFotos) {
    $cabecalho[] = 'Outras fotos';
}

// Cada célula: array(tipo, valor). O link também guarda o nome do arquivo.
$linhas = array();
foreach ($leads as $lead) {
    $linha = array(array('data', strtotime($lead['em'])), array('texto', $nomesEventos[$lead['evento']]));
    foreach ($colunas as $coluna) {
        if (!isset($coluna['eventos'][$lead['evento']])) {
            $linha[] = array('texto', '');
            continue;
        }
        $valor = isset($lead['valores'][$coluna['id']]) ? $lead['valores'][$coluna['id']] : '';
        if ($coluna['tipo'] === 'numero' && is_string($valor) && preg_match('/^-?\d+(\.\d+)?$/', $valor)) {
            $linha[] = array('numero', $valor);
        } else {
            $linha[] = array('texto', leadsValorTexto($lead, $coluna));
        }
    }
    $pasta = painelPastaFotos($cliente, $lead['evento']);
    $urls = array();
    foreach ($lead['fotos'] as $foto) {
        $urls[] = painelNomeFotoValido($foto) && is_file($pasta . DIRECTORY_SEPARATOR . $foto) ? leadsUrlFoto($cliente, $lead['evento'], $foto) : '';
    }
    $linha[] = $urls[0] !== '' ? array('link', $urls[0], $lead['fotos'][0]) : array('texto', 'Foto excluída');
    if ($outrasFotos) {
        $linha[] = array('texto', implode(' ', array_filter(array_slice($urls, 1))));
    }
    $linhas[] = $linha;
}

$arquivo = 'leads-' . ($pedido === 'todos' ? 'todos-os-eventos' : $escolhidos[0]['id']) . '-' . date('Y-m-d') . '.' . $formato;

function leadsDataTexto($tempo) {
    return $tempo ? date('d/m/Y H:i', $tempo) : '';
}

// ---------- CSV ----------

if ($formato === 'csv') {
    // Texto que começa com = + - @ vira fórmula no Excel: vai com um ' na frente
    $celula = function ($tipo, $valor) {
        if ($tipo === 'data') {
            $valor = leadsDataTexto($valor);
        } elseif ($tipo === 'numero') {
            $valor = str_replace('.', ',', $valor);
        } elseif ($tipo === 'texto' && preg_match('/^[=+\-@\t\r]/', $valor)) {
            $valor = "'" . $valor;
        }
        return '"' . str_replace('"', '""', (string) $valor) . '"';
    };
    $saida = "\xEF\xBB\xBF" . implode(';', array_map(function ($t) use ($celula) { return $celula('texto', $t); }, $cabecalho)) . "\r\n";
    foreach ($linhas as $linha) {
        $partes = array();
        foreach ($linha as $item) {
            $partes[] = $celula($item[0], $item[1]);
        }
        $saida .= implode(';', $partes) . "\r\n";
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $arquivo . '"');
    header('Content-Length: ' . strlen($saida));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $saida;
    exit;
}

// ---------- Excel (.xlsx) ----------

function xlsxColuna($indice) {
    $letras = '';
    for ($n = $indice + 1; $n > 0; $n = (int) (($n - 1) / 26)) {
        $letras = chr(65 + ($n - 1) % 26) . $letras;
    }
    return $letras;
}

function xlsxTexto($texto) {
    $texto = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $texto);
    return htmlspecialchars($texto, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function xlsxCelula($ref, $tipo, $valor, $nomeArquivo = '') {
    if ($tipo === 'numero') {
        return '<c r="' . $ref . '"><v>' . $valor . '</v></c>';
    }
    if ($tipo === 'data') {
        if (!$valor) {
            return '<c r="' . $ref . '"/>';
        }
        // Dias desde 30/12/1899, no horário de Brasília (o que o cliente vê no painel)
        $serial = ($valor + (int) date('Z', $valor)) / 86400 + 25569;
        return '<c r="' . $ref . '" s="2"><v>' . number_format($serial, 6, '.', '') . '</v></c>';
    }
    if ($tipo === 'cabecalho') {
        return '<c r="' . $ref . '" s="1" t="inlineStr"><is><t xml:space="preserve">' . xlsxTexto($valor) . '</t></is></c>';
    }
    if ($tipo === 'link') {
        if (strlen($valor) <= 250) {
            // HYPERLINK aceita um endereço de até 255 caracteres.
            $formula = 'HYPERLINK("' . str_replace('"', '""', $valor) . '","' . str_replace('"', '""', $nomeArquivo) . '")';
            return '<c r="' . $ref . '" s="3" t="str"><f>' . xlsxTexto($formula) . '</f><v>' . xlsxTexto($nomeArquivo) . '</v></c>';
        }
        $valor = $nomeArquivo . ' (' . $valor . ')';
    }
    return $valor === '' ? '' : '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . xlsxTexto($valor) . '</t></is></c>';
}

// Zip com os arquivos (nome => conteúdo), comprimidos se o PHP tiver zlib
function xlsxZip($arquivos) {
    $agora = getdate();
    $hora = ($agora['hours'] << 11) | ($agora['minutes'] << 5) | ($agora['seconds'] >> 1);
    $data = (($agora['year'] - 1980) << 9) | ($agora['mon'] << 5) | $agora['mday'];
    $dados = '';
    $central = '';
    foreach ($arquivos as $nome => $conteudo) {
        $crc = crc32($conteudo);
        $comprimido = function_exists('gzdeflate') ? gzdeflate($conteudo, 6) : false;
        $metodo = $comprimido === false ? 0 : 8;
        if ($comprimido === false) {
            $comprimido = $conteudo;
        }
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, $metodo, $hora, $data, $crc, strlen($comprimido), strlen($conteudo), strlen($nome), 0, 0, 0, 0, 0, strlen($dados)) . $nome;
        $dados .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, $metodo, $hora, $data, $crc, strlen($comprimido), strlen($conteudo), strlen($nome), 0) . $nome . $comprimido;
    }
    return $dados . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, count($arquivos), count($arquivos), strlen($central), strlen($dados), 0);
}

// Quantas letras (não bytes) o texto tem
function xlsxLetras($texto) {
    return function_exists('mb_strlen') ? mb_strlen($texto, 'UTF-8') : (int) preg_match_all('/./us', $texto);
}

// Largura das colunas pelo maior texto (de 10 a 50 letras)
$larguras = array();
foreach ($cabecalho as $i => $titulo) {
    $larguras[$i] = max(10, xlsxLetras($titulo) + 4);
}
foreach ($linhas as $linha) {
    foreach ($linha as $i => $item) {
        $texto = isset($item[2]) ? $item[2] : (string) $item[1];
        $tamanho = $item[0] === 'data' ? 16 : xlsxLetras($texto);
        $larguras[$i] = min(50, max($larguras[$i], $tamanho + 2));
    }
}
$ultima = xlsxColuna(count($cabecalho) - 1) . (count($linhas) + 1);

$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
$planilha = $xml . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>';
foreach ($larguras as $i => $largura) {
    $planilha .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $largura . '" customWidth="1"/>';
}
$planilha .= '</cols><sheetData><row r="1">';
foreach ($cabecalho as $i => $titulo) {
    $planilha .= xlsxCelula(xlsxColuna($i) . '1', 'cabecalho', $titulo);
}
$planilha .= '</row>';
foreach ($linhas as $n => $linha) {
    $planilha .= '<row r="' . ($n + 2) . '">';
    foreach ($linha as $i => $item) {
        $planilha .= xlsxCelula(xlsxColuna($i) . ($n + 2), $item[0], $item[1], isset($item[2]) ? $item[2] : '');
    }
    $planilha .= '</row>';
}
$planilha .= '</sheetData><autoFilter ref="A1:' . $ultima . '"/></worksheet>';

$estilos = $xml . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<numFmts count="1"><numFmt numFmtId="164" formatCode="dd/mm/yyyy hh:mm"/></numFmts>'
    . '<fonts count="3"><font><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
    . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/><family val="2"/></font>'
    . '<font><u/><sz val="11"/><color rgb="FF0563C1"/><name val="Calibri"/><family val="2"/></font></fonts>'
    . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FF174786"/><bgColor indexed="64"/></patternFill></fill></fills>'
    . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
    . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
    . '<cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
    . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
    . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
    . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
    . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';

$conteudo = xlsxZip(array(
    '[Content_Types].xml' => $xml . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
    '_rels/.rels' => $xml . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
    'xl/workbook.xml' => $xml . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Leads" sheetId="1" r:id="rId1"/></sheets>'
        . '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">Leads!$A$1:$' . preg_replace('/\d+$/', '', $ultima) . '$' . (count($linhas) + 1) . '</definedName></definedNames></workbook>',
    'xl/_rels/workbook.xml.rels' => $xml . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
    'xl/worksheets/sheet1.xml' => $planilha,
    'xl/styles.xml' => $estilos
));

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $arquivo . '"');
header('Content-Length: ' . strlen($conteudo));
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo $conteudo;
