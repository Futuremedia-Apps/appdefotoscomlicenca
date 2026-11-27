<?php
// Central de Licenças da Futuremedia. Só a equipe da Futuremedia entra aqui:
// cadastra as licenças (cliente, painel, validade, quantos robôs) e vê os robôs
// de cada uma. Os robôs ativam e renovam a licença pelo api/ativar.php.
//
// Cada resposta ao robô leva um "token" assinado com a chave privada (RSA,
// SHA-256). O app e os painéis conferem a assinatura com a chave pública, que
// está no app (lib/licenca.dart) e em cada painel (painel/licenca.php).
// Compatível com PHP 5.6.

date_default_timezone_set('America/Sao_Paulo');

define('LIC_RAIZ', __DIR__);
define('LIC_DADOS', LIC_RAIZ . DIRECTORY_SEPARATOR . 'dados');
define('LIC_ARQUIVO', LIC_DADOS . DIRECTORY_SEPARATOR . 'licencas.php');
define('LIC_TENTATIVAS', LIC_DADOS . DIRECTORY_SEPARATOR . 'tentativas.php');
// Cópias de segurança de licencas.php (uma por dia, mais as de antes de excluir/restaurar)
define('LIC_BACKUPS', LIC_DADOS . DIRECTORY_SEPARATOR . 'backup');
// Registro de ações (quem fez o quê), uma linha JSON por ação
define('LIC_REGISTRO', LIC_DADOS . DIRECTORY_SEPARATOR . 'registro.php');
define('LIC_REGISTRO_ANTERIOR', LIC_DADOS . DIRECTORY_SEPARATOR . 'registro-anterior.php');
// Espaço usado pelas fotos de cada painel (conta guardada por 15 minutos)
define('LIC_USO', LIC_DADOS . DIRECTORY_SEPARATOR . 'uso-paineis.php');
define('LIC_CABECALHO', "<?php exit; ?>\n");

// Letras do código: sem 0/O, 1/I/L, para ninguém confundir ao digitar no robô.
// O mesmo alfabeto está no teclado do app (lib/licenca.dart).
define('LIC_ALFABETO', '23456789ABCDEFGHJKMNPQRSTUVWXYZ');

// Cópia do código do painel de cada cliente: a mesma regra da atualização
// automática (painel/atualizar.php, do painel principal)
if (is_file(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'painel' . DIRECTORY_SEPARATOR . 'atualizar.php')) {
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'painel' . DIRECTORY_SEPARATOR . 'atualizar.php';
}

function h($valor) {
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function licAleatorio($bytes) {
    if (function_exists('random_bytes')) {
        return random_bytes($bytes);
    }
    return openssl_random_pseudo_bytes($bytes);
}

// Corta um texto UTF-8 sem precisar da extensão mbstring
function licCortar($texto, $maximo) {
    return preg_match('/^.{0,' . (int) $maximo . '}/us', (string) $texto, $m) ? $m[0] : '';
}

function licBase64Url($dados) {
    return rtrim(strtr(base64_encode($dados), '+/', '-_'), '=');
}

// ---------- Configuração (config/licencas.local.php) ----------

function licConfig() {
    static $config = false;
    if ($config !== false) {
        return $config;
    }
    $caminho = LIC_RAIZ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'licencas.local.php';
    $dados = is_file($caminho) ? require $caminho : null;
    if (!is_array($dados) || empty($dados['usuario']) || empty($dados['senha']) || empty($dados['chave_privada'])) {
        $config = null;
        return $config;
    }
    $config = array(
        'usuario' => strtolower(trim($dados['usuario'])),
        'senha' => (string) $dados['senha'],
        'chave_privada' => (string) $dados['chave_privada'],
        // Dias que o robô funciona sem falar com a central (sem internet)
        'dias_offline' => isset($dados['dias_offline']) ? max(1, (int) $dados['dias_offline']) : 7,
        // Dias de aviso depois do vencimento, antes de travar
        'dias_carencia' => isset($dados['dias_carencia']) ? max(0, (int) $dados['dias_carencia']) : 7,
        // Login único dos clientes (pasta login/, ao lado desta)
        'url_login' => !empty($dados['url_login']) ? (string) $dados['url_login'] : 'https://suafoto.futuremedia.com.br/clienteFotos/buddyApp/login/',
        // Onde ficam os painéis dos clientes (cada um numa pasta)
        'base_paineis' => !empty($dados['base_paineis']) ? (string) $dados['base_paineis'] : ''
    );
    return $config;
}

// ---------- Leitura e gravação ----------

function licLerArquivo($caminho) {
    if (!is_file($caminho)) {
        return array();
    }
    $conteudo = (string) file_get_contents($caminho);
    if (strpos($conteudo, LIC_CABECALHO) === 0) {
        $conteudo = substr($conteudo, strlen(LIC_CABECALHO));
    }
    $dados = json_decode($conteudo, true);
    return is_array($dados) ? $dados : array();
}

function licGravarArquivo($caminho, $dados) {
    $pasta = dirname($caminho);
    if (!is_dir($pasta) && !@mkdir($pasta, 0755, true)) {
        return false;
    }
    $json = json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    $temporario = $caminho . '.tmp-' . bin2hex(licAleatorio(4));
    $conteudo = LIC_CABECALHO . $json;
    // Disco cheio grava só um pedaço: aí não troca o arquivo bom pelo cortado
    if (file_put_contents($temporario, $conteudo) !== strlen($conteudo)) {
        @unlink($temporario);
        return false;
    }
    if (!@rename($temporario, $caminho)) {
        @unlink($caminho); // PHP antigo no Windows não sobrescreve no rename
        if (!@rename($temporario, $caminho)) {
            @unlink($temporario);
            return false;
        }
    }
    return true;
}

// Licenças gravadas em $caminho (o licencas.php ou uma cópia de segurança).
// null se o arquivo não dá para ler (estragado, cortado ou vazio).
function licLerLicencas($caminho) {
    $conteudo = @file_get_contents($caminho);
    if ($conteudo === false) {
        return null;
    }
    if (strpos($conteudo, LIC_CABECALHO) === 0) {
        $conteudo = substr($conteudo, strlen(LIC_CABECALHO));
    }
    $dados = json_decode($conteudo, true);
    return is_array($dados) && isset($dados['licencas']) && is_array($dados['licencas']) ? $dados['licencas'] : null;
}

// Como está o licencas.php nesta visita: 'ok', 'recuperado' (estragado ou
// apagado; os dados vieram da cópia de segurança e o próximo licAlterar()
// grava de volta) ou 'indisponivel' (estragado e sem cópia boa: não grava nada).
function licEstadoArquivo($novo = null) {
    static $estado = 'ok';
    if ($novo !== null) {
        $estado = $novo;
    }
    return $estado;
}

// Cópia de segurança usada na recuperação (para o registro)
function licCopiaRecuperada($nova = null) {
    static $copia = null;
    if ($nova !== null) {
        $copia = $nova;
    }
    return $copia;
}

function licTodasNoArquivo() {
    if (is_file(LIC_ARQUIVO)) {
        $todas = licLerLicencas(LIC_ARQUIVO);
        if ($todas !== null) {
            licEstadoArquivo('ok');
            return $todas;
        }
    } elseif (!licBackups()) {
        licEstadoArquivo('ok');
        return array(); // central nova, ainda sem licenças
    }
    // Estragado ou apagado: nunca responder "código não existe" aos robôs
    // (isso trava o app). Usa a cópia de segurança boa mais nova.
    foreach (licBackups() as $copia) {
        if ($copia['tipo'] === 'estragado') {
            continue;
        }
        $todas = licLerLicencas($copia['caminho']);
        if ($todas !== null) {
            licEstadoArquivo('recuperado');
            licCopiaRecuperada($copia);
            return $todas;
        }
    }
    licEstadoArquivo('indisponivel');
    return array();
}

// Lista das licenças. Licença de antes dos códigos por robô é completada (e
// gravada) na primeira leitura: ver licCompletarCodigos(). Se o arquivo veio
// da cópia de segurança, a leitura já grava de volta o arquivo principal.
function licTodas() {
    $todas = licTodasNoArquivo();
    $completas = licCompletarCodigos($todas);
    if ($completas === $todas && licEstadoArquivo() !== 'recuperado') {
        return $todas;
    }
    if (licEstadoArquivo() === 'indisponivel') {
        return $completas;
    }
    $salvas = licAlterar(function ($lista) {
        return $lista;
    });
    return $salvas !== null ? $salvas : $completas;
}

function licBuscar($id) {
    $todas = licTodas();
    return is_string($id) && isset($todas[$id]) ? $todas[$id] : null;
}

// Altera as licenças com o arquivo travado, para dois robôs ativando ao mesmo
// tempo não apagarem um ao outro. $alterar recebe a lista e devolve a nova,
// ou false para não gravar nada. Devolve a lista gravada ou null.
function licAlterar($alterar) {
    if (!is_dir(LIC_DADOS)) {
        @mkdir(LIC_DADOS, 0755, true);
    }
    $trava = @fopen(LIC_DADOS . DIRECTORY_SEPARATOR . 'trava', 'c');
    if (!$trava || !flock($trava, LOCK_EX)) {
        return null;
    }
    try {
        $noArquivo = licTodasNoArquivo();
        $estado = licEstadoArquivo();
        if ($estado === 'indisponivel') {
            return null; // nunca grava por cima de um arquivo estragado sem cópia boa
        }
        if ($estado === 'recuperado') {
            licGuardarEstragado();
        } else {
            licFazerBackupDoDia();
        }
        $todas = licCompletarCodigos($noArquivo);
        $novas = call_user_func($alterar, $todas);
        if ($novas === false) {
            if ($todas !== $noArquivo || $estado === 'recuperado') {
                licGravarRecuperando($todas, $estado);
            }
            return $todas;
        }
        $novas = licCompletarCodigos($novas);
        return licGravarRecuperando($novas, $estado) ? $novas : null;
    } finally {
        flock($trava, LOCK_UN);
        fclose($trava);
    }
}

// Grava as licenças (dentro do licAlterar). Depois de uma recuperação, avisa
// no registro; na primeira gravação do dia sem cópia ainda (central nova),
// já faz a cópia do dia.
function licGravarRecuperando($todas, $estado) {
    if (!licGravarArquivo(LIC_ARQUIVO, array('licencas' => $todas))) {
        return false;
    }
    licEstadoArquivo('ok');
    if ($estado === 'recuperado') {
        $copia = licCopiaRecuperada();
        licRegistrar('sistema', 'Arquivo das licenças recuperado', null,
            'O licencas.php estava estragado ou foi apagado. Voltou a cópia de segurança de ' . ($copia ? licDataBr($copia['quando'], true) : '?') . '.');
    }
    licFazerBackupDoDia();
    return true;
}

// ---------- Cópias de segurança (dados/backup/) ----------
// Uma cópia por dia, feita na primeira gravação do dia (guarda as 30 mais
// novas), e uma cópia na hora antes de excluir licença ou de restaurar
// (guarda as 20 mais novas). A pasta dados/ é fechada para o navegador e cada
// cópia começa com o mesmo LIC_CABECALHO (exit) do licencas.php.

// Cópias, da mais nova para a mais antiga. Cada uma: arquivo, caminho,
// tipo ('diaria', 'antes-de-excluir', 'antes-de-restaurar', 'estragado'),
// quando (timestamp) e tamanho.
function licBackups() {
    $lista = array();
    foreach (glob(LIC_BACKUPS . DIRECTORY_SEPARATOR . '*.php') ?: array() as $caminho) {
        $nome = basename($caminho);
        if (preg_match('/^licencas-\d{4}-\d{2}-\d{2}\.php$/', $nome)) {
            $tipo = 'diaria';
        } elseif (preg_match('/^licencas-\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}-(antes-de-excluir|antes-de-restaurar)\.php$/', $nome, $m)) {
            $tipo = $m[1];
        } elseif (preg_match('/^estragado-\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.php$/', $nome)) {
            $tipo = 'estragado';
        } else {
            continue;
        }
        $lista[] = array(
            'arquivo' => $nome,
            'caminho' => $caminho,
            'tipo' => $tipo,
            'quando' => (int) @filemtime($caminho),
            'tamanho' => (int) @filesize($caminho)
        );
    }
    usort($lista, function ($a, $b) {
        return $b['quando'] - $a['quando'] ?: strcmp($b['arquivo'], $a['arquivo']);
    });
    return $lista;
}

function licBackupPorNome($arquivo) {
    foreach (licBackups() as $copia) {
        if ($copia['arquivo'] === $arquivo) {
            return $copia;
        }
    }
    return null;
}

function licTipoBackupTexto($tipo) {
    $textos = array(
        'diaria' => 'Cópia do dia',
        'antes-de-excluir' => 'Antes de excluir uma licença',
        'antes-de-restaurar' => 'Antes de restaurar uma cópia',
        'estragado' => 'Arquivo estragado (guardado para análise)'
    );
    return isset($textos[$tipo]) ? $textos[$tipo] : $tipo;
}

// Copia o licencas.php (que precisa estar bom) para dados/backup/<nome>
function licCopiarParaBackup($nome) {
    if (!is_file(LIC_ARQUIVO) || licLerLicencas(LIC_ARQUIVO) === null) {
        return false;
    }
    if (!is_dir(LIC_BACKUPS) && !@mkdir(LIC_BACKUPS, 0755, true)) {
        return false;
    }
    if (!is_file(LIC_BACKUPS . DIRECTORY_SEPARATOR . 'index.html')) {
        @file_put_contents(LIC_BACKUPS . DIRECTORY_SEPARATOR . 'index.html', '');
    }
    $destino = LIC_BACKUPS . DIRECTORY_SEPARATOR . $nome;
    $temporario = $destino . '.tmp-' . bin2hex(licAleatorio(4));
    if (!@copy(LIC_ARQUIVO, $temporario) || !@rename($temporario, $destino)) {
        @unlink($temporario);
        return false;
    }
    licLimparBackups();
    return true;
}

function licNomeBackupDoDia() {
    return 'licencas-' . date('Y-m-d') . '.php';
}

function licFazerBackupDoDia() {
    if (is_file(LIC_BACKUPS . DIRECTORY_SEPARATOR . licNomeBackupDoDia())) {
        return true;
    }
    return licCopiarParaBackup(licNomeBackupDoDia());
}

// Cópia na hora, antes de uma ação que apaga dados ($motivo: 'antes-de-excluir'
// ou 'antes-de-restaurar')
function licBackupAgora($motivo) {
    return licCopiarParaBackup('licencas-' . date('Y-m-d_H-i-s') . '-' . $motivo . '.php');
}

// Arquivo estragado: vai para dados/backup/estragado-<data>.php (nunca é
// apagado sozinho), e o licencas.php volta a ser gravado a partir da cópia
function licGuardarEstragado() {
    if (!is_file(LIC_ARQUIVO)) {
        return;
    }
    if (!is_dir(LIC_BACKUPS)) {
        @mkdir(LIC_BACKUPS, 0755, true);
    }
    @rename(LIC_ARQUIVO, LIC_BACKUPS . DIRECTORY_SEPARATOR . 'estragado-' . date('Y-m-d_H-i-s') . '.php');
}

function licLimparBackups() {
    $diarias = 0;
    $outras = 0;
    foreach (licBackups() as $copia) {
        if ($copia['tipo'] === 'diaria') {
            if (++$diarias > 30) {
                @unlink($copia['caminho']);
            }
        } elseif ($copia['tipo'] !== 'estragado' && ++$outras > 20) {
            @unlink($copia['caminho']);
        }
    }
}

// Volta as licenças para uma cópia. Antes, guarda o estado atual numa cópia
// "antes de restaurar". Devolve null se deu certo ou o texto do erro.
function licRestaurarBackup($arquivo) {
    $copia = licBackupPorNome($arquivo);
    if (!$copia || $copia['tipo'] === 'estragado') {
        return 'Cópia não encontrada.';
    }
    $todas = licLerLicencas($copia['caminho']);
    if ($todas === null) {
        return 'Esta cópia está estragada e não pode ser usada.';
    }
    licBackupAgora('antes-de-restaurar');
    $salvas = licAlterar(function () use ($todas) {
        return $todas;
    });
    return $salvas === null ? 'Não foi possível gravar. Confira se a pasta licencas/dados/ pode ser gravada.' : null;
}

// Impressão digital da chave pública (SHA-256 da chave, 16 primeiros
// caracteres). Serve para conferir se a cópia guardada da chave é a mesma
// que está no servidor, sem mostrar a chave.
function licImpressaoDigitalChave() {
    $config = licConfig();
    $privada = $config ? openssl_pkey_get_private($config['chave_privada']) : false;
    $detalhes = $privada ? openssl_pkey_get_details($privada) : null;
    if (!$detalhes || empty($detalhes['key'])) {
        return '';
    }
    return implode(' ', str_split(strtoupper(substr(hash('sha256', $detalhes['key']), 0, 16)), 4));
}

// ---------- Registro de ações ----------
// dados/registro.php: uma linha JSON por ação (quem, o quê, licença, detalhe,
// IP). Passando de 1 MB, vira registro-anterior.php e começa outro.

// $quem: 'equipe', 'robo', 'cliente' ou 'sistema'. $licenca: a licença (array) ou null.
function licRegistrar($quem, $acao, $licenca = null, $detalhe = '') {
    $linha = array(
        't' => date('c'),
        'quem' => $quem,
        'ip' => isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '',
        'acao' => $acao,
        'lic' => is_array($licenca) && isset($licenca['id']) ? (string) $licenca['id'] : '',
        'cliente' => is_array($licenca) && isset($licenca['cliente']) ? (string) $licenca['cliente'] : '',
        'det' => licCortar($detalhe, 400)
    );
    if (!is_dir(LIC_DADOS)) {
        @mkdir(LIC_DADOS, 0755, true);
    }
    if (is_file(LIC_REGISTRO) && @filesize(LIC_REGISTRO) > 1048576) {
        @unlink(LIC_REGISTRO_ANTERIOR);
        @rename(LIC_REGISTRO, LIC_REGISTRO_ANTERIOR);
        clearstatcache();
    }
    $json = json_encode($linha, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json !== false) {
        @file_put_contents(LIC_REGISTRO, (is_file(LIC_REGISTRO) ? '' : LIC_CABECALHO) . $json . "\n", FILE_APPEND | LOCK_EX);
    }
}

// Ações mais novas primeiro. $id = só as desta licença.
function licLerRegistro($limite = 300, $id = '') {
    $itens = array();
    foreach (array(LIC_REGISTRO, LIC_REGISTRO_ANTERIOR) as $arquivo) {
        $linhas = is_file($arquivo) ? @file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
        if (!$linhas) {
            continue;
        }
        for ($i = count($linhas) - 1; $i >= 0; $i--) {
            $item = json_decode($linhas[$i], true);
            if (!is_array($item) || empty($item['t']) || empty($item['acao'])) {
                continue;
            }
            if ($id !== '' && (!isset($item['lic']) || $item['lic'] !== $id)) {
                continue;
            }
            $itens[] = $item;
            if (count($itens) >= $limite) {
                return $itens;
            }
        }
    }
    return $itens;
}

function licQuemTexto($quem) {
    $textos = array('equipe' => 'Equipe', 'robo' => 'Robô', 'cliente' => 'Cliente', 'sistema' => 'Sistema');
    return isset($textos[$quem]) ? $textos[$quem] : (string) $quem;
}

// ---------- Código da licença ----------

// "fm-7k2p 9qxa" -> "7K2P9QXA"; null se não for um código possível
function licNormalizarCodigo($texto) {
    $limpo = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', (string) $texto));
    if (strlen($limpo) === 10 && substr($limpo, 0, 2) === 'FM') {
        $limpo = substr($limpo, 2);
    }
    if (strlen($limpo) !== 8 || strspn($limpo, LIC_ALFABETO) !== 8) {
        return null;
    }
    return $limpo;
}

function licCodigoTexto($id) {
    return 'FM-' . substr($id, 0, 4) . '-' . substr($id, 4, 4);
}

function licNovoId($existentes) {
    do {
        $id = '';
        foreach (str_split(licAleatorio(8)) as $byte) {
            $id .= substr(LIC_ALFABETO, ord($byte) % strlen(LIC_ALFABETO), 1);
        }
    } while (isset($existentes[$id]));
    return $id;
}

// ---------- Um código por robô ----------
// Cada licença tem um código para cada robô ('codigos'). O primeiro é o
// próprio id da licença (o código de antes, que os robôs já ativados usam).
// Cada robô ativado fica em 'dispositivos' (chave = ID do app no robô) com
// o código que ocupa ('codigo') e o serial de fábrica do robô ('serial').
// Um código vale para um robô só.

// Todos os códigos em uso na central (ids e códigos dos robôs), para um
// código novo não repetir nenhum
function licTodosCodigos($todas) {
    $usados = array();
    foreach ($todas as $id => $licenca) {
        $usados[$id] = true;
        if (isset($licenca['codigos']) && is_array($licenca['codigos'])) {
            foreach ($licenca['codigos'] as $codigo) {
                $usados[$codigo] = true;
            }
        }
    }
    return $usados;
}

// Deixa cada licença com um código por robô ('robos') e cada robô ativado
// preso a um código. Serve para as licenças antigas (sem 'codigos') e para
// as que ganharam robôs. Nunca tira códigos (diminuir é no licenca.php).
function licCompletarCodigos($todas) {
    foreach ($todas as $id => $licenca) {
        $codigos = isset($licenca['codigos']) && is_array($licenca['codigos']) ? array_values($licenca['codigos']) : array();
        if (!$codigos) {
            $codigos = array((string) $id);
        }
        $robos = max(1, isset($licenca['robos']) ? (int) $licenca['robos'] : 1);
        while (count($codigos) < $robos) {
            $todas[$id]['codigos'] = $codigos;
            $codigos[] = licNovoId(licTodosCodigos($todas));
        }

        // Robôs ativados antes dos códigos por robô: cada um fica com o
        // primeiro código livre, na ordem em que foram ativados
        $dispositivos = isset($licenca['dispositivos']) && is_array($licenca['dispositivos']) ? $licenca['dispositivos'] : array();
        $ocupados = array();
        foreach ($dispositivos as $dispositivo => $robo) {
            if (!empty($robo['codigo']) && in_array($robo['codigo'], $codigos, true)) {
                $ocupados[$robo['codigo']] = true;
            }
        }
        $semCodigo = array();
        foreach ($dispositivos as $dispositivo => $robo) {
            if (empty($robo['codigo']) || !in_array($robo['codigo'], $codigos, true)) {
                $semCodigo[$dispositivo] = isset($robo['ativado_em']) ? (string) $robo['ativado_em'] : '';
            }
        }
        asort($semCodigo);
        foreach (array_keys($semCodigo) as $dispositivo) {
            foreach ($codigos as $codigo) {
                if (!isset($ocupados[$codigo])) {
                    $dispositivos[$dispositivo]['codigo'] = $codigo;
                    $ocupados[$codigo] = true;
                    break;
                }
            }
        }

        $todas[$id]['codigos'] = $codigos;
        $todas[$id]['robos'] = count($codigos);
        $todas[$id]['dispositivos'] = $dispositivos;
    }
    return $todas;
}

// Licença que tem este código (id normalizado, ex.: 7K2P9QXA), ou null
function licBuscarCodigo($codigo) {
    if (!is_string($codigo) || $codigo === '') {
        return null;
    }
    foreach (licTodas() as $licenca) {
        if (in_array($codigo, $licenca['codigos'], true)) {
            return $licenca;
        }
    }
    return null;
}

// ID do robô que ocupa o código, ou null se o código está livre
function licDonoDoCodigo($licenca, $codigo) {
    foreach ($licenca['dispositivos'] as $dispositivo => $robo) {
        if (isset($robo['codigo']) && $robo['codigo'] === $codigo) {
            return (string) $dispositivo;
        }
    }
    return null;
}

// Um item por robô da licença: numero (1, 2...), codigo, dispositivo (ou
// null se livre) e robo (dados do robô, ou null)
function licVagas($licenca) {
    $vagas = array();
    foreach ($licenca['codigos'] as $i => $codigo) {
        $dono = licDonoDoCodigo($licenca, $codigo);
        $vagas[] = array(
            'numero' => $i + 1,
            'codigo' => $codigo,
            'dispositivo' => $dono,
            'robo' => $dono === null ? null : $licenca['dispositivos'][$dono]
        );
    }
    return $vagas;
}

// Nome do robô para as telas: serial de fábrica, senão o modelo
function licNomeRobo($robo) {
    if (!empty($robo['serial'])) {
        return 'Serial ' . $robo['serial'];
    }
    return !empty($robo['modelo']) ? $robo['modelo'] . ' (sem serial)' : 'Robô sem serial (app antigo)';
}

// ---------- Situação e token ----------

// Fim do dia do vencimento (o último dia ainda vale inteiro)
function licFimDoDia($data) {
    $tempo = strtotime($data . ' 23:59:59');
    return $tempo ? $tempo : 0;
}

// 'ativa', 'vencida' (ainda no aviso), 'expirada' (travou) ou 'suspensa'
function licSituacao($licenca, $agora = null) {
    $agora = $agora === null ? time() : $agora;
    if ($licenca['situacao'] === 'suspensa') {
        return 'suspensa';
    }
    if ($licenca['vence'] === '') {
        return 'ativa';
    }
    $fim = licFimDoDia($licenca['vence']);
    if ($agora <= $fim) {
        return 'ativa';
    }
    $config = licConfig();
    return $agora <= $fim + $config['dias_carencia'] * 86400 ? 'vencida' : 'expirada';
}

function licSituacaoTexto($situacao) {
    $textos = array(
        'ativa' => 'Ativa',
        'vencida' => 'Vencida (em aviso)',
        'expirada' => 'Expirada',
        'suspensa' => 'Suspensa'
    );
    return isset($textos[$situacao]) ? $textos[$situacao] : $situacao;
}

// Assina um JSON: base64url(json).base64url(assinatura RSA SHA-256)
function licAssinar($dados) {
    $config = licConfig();
    $corpo = licBase64Url(json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $chave = openssl_pkey_get_private($config['chave_privada']);
    $assinatura = '';
    if (!$chave || !openssl_sign($corpo, $assinatura, $chave, OPENSSL_ALGO_SHA256)) {
        return null;
    }
    return $corpo . '.' . licBase64Url($assinatura);
}

// Confere um token assinado por esta central (mesmo vencido) e devolve o JSON, ou null
function licLerAssinado($token) {
    $config = licConfig();
    $partes = explode('.', trim((string) $token));
    if (!$config || count($partes) !== 2 || strlen($token) > 4000) {
        return null;
    }
    $privada = openssl_pkey_get_private($config['chave_privada']);
    $detalhes = $privada ? openssl_pkey_get_details($privada) : null;
    $publica = $detalhes ? openssl_pkey_get_public($detalhes['key']) : null;
    $resto = strlen($partes[1]) % 4;
    $assinatura = base64_decode(strtr($partes[1], '-_', '+/') . ($resto ? str_repeat('=', 4 - $resto) : ''), true);
    if (!$publica || $assinatura === false || openssl_verify($partes[0], $assinatura, $publica, OPENSSL_ALGO_SHA256) !== 1) {
        return null;
    }
    $resto = strlen($partes[0]) % 4;
    $dados = json_decode((string) base64_decode(strtr($partes[0], '-_', '+/') . ($resto ? str_repeat('=', 4 - $resto) : ''), true), true);
    return is_array($dados) ? $dados : null;
}

// Passe de entrada no painel do cliente (login único, login/index.php ao
// lado da central). Vale 2 minutos e uma vez só (o painel guarda o "nonce").
function licEmitirPasseLogin($licenca) {
    return licAssinar(array(
        'v' => 1,
        'tipo' => 'login',
        'licenca' => licCodigoTexto($licenca['id']),
        'painel' => $licenca['painel'],
        'emitido' => time(),
        'expira' => time() + 120,
        'nonce' => bin2hex(licAleatorio(16))
    ));
}

// Endereço de entrada do painel do cliente com o passe
function licUrlEntrarPainel($licenca) {
    $passe = licEmitirPasseLogin($licenca);
    return $passe === null ? null : $licenca['painel'] . 'painel/entrar.php?t=' . rawurlencode($passe);
}

// ---------- Acesso do cliente ao painel (login único) ----------

// Usuário do painel: letras minúsculas, números, ponto, traço e "_"
function licNormalizarUsuario($texto) {
    $usuario = strtolower(trim((string) $texto));
    return preg_match('/^[a-z0-9._-]{3,40}$/', $usuario) ? $usuario : null;
}

function licBuscarPorUsuario($usuario) {
    foreach (licTodas() as $licenca) {
        if (isset($licenca['usuario']) && $licenca['usuario'] === $usuario) {
            return $licenca;
        }
    }
    return null;
}

// Senha nova, fácil de ditar (sem 0/O, 1/l/I)
function licGerarSenha() {
    $letras = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $senha = '';
    foreach (str_split(licAleatorio(10)) as $byte) {
        $senha .= substr($letras, ord($byte) % strlen($letras), 1);
    }
    return $senha;
}

// Token que o robô guarda e manda ao painel: base64url(json).base64url(assinatura)
function licEmitirToken($licenca, $dispositivo) {
    $config = licConfig();
    $agora = time();
    $situacao = licSituacao($licenca, $agora);
    $valido = $agora + $config['dias_offline'] * 86400;
    if ($licenca['vence'] !== '') {
        // Nunca passa do último dia do aviso, mesmo sem internet
        $valido = min($valido, licFimDoDia($licenca['vence']) + $config['dias_carencia'] * 86400);
    }

    $robo = isset($licenca['dispositivos'][$dispositivo]) ? $licenca['dispositivos'][$dispositivo] : array();
    return licAssinar(array(
        'v' => 1,
        'licenca' => licCodigoTexto($licenca['id']),
        // código que este robô ocupa (cada robô tem o seu)
        'codigo' => licCodigoTexto(!empty($robo['codigo']) ? $robo['codigo'] : $licenca['id']),
        'serial' => isset($robo['serial']) ? (string) $robo['serial'] : '',
        'cliente' => $licenca['cliente'],
        'painel' => $licenca['painel'],
        'dispositivo' => $dispositivo,
        'situacao' => $situacao,
        'vence' => $licenca['vence'],
        'emitido' => $agora,
        'valido_ate' => $valido
    ));
}

// ---------- Painel: endereço ----------

// Os painéis dos clientes ficam dentro de buddyApp/: o principal na própria
// buddyApp/ (pasta em branco) e os outros em buddyApp/<pasta>/
function licBasePaineis() {
    $config = licConfig();
    return $config && !empty($config['base_paineis']) ? rtrim($config['base_paineis'], '/') . '/' : 'https://suafoto.futuremedia.com.br/clienteFotos/buddyApp/';
}

// Pastas que já são do sistema (não podem ser de um cliente)
function licPastasReservadas() {
    return array('painel', 'login', 'licencas', 'config', 'eventos', 'fotos', 'galeria', 'leads', 'backup', 'cache', 'tools', 'fotos-arquivadas', 'cgi-bin', 'midia');
}

// Nome digitado -> pasta ("Cliente Teste 01" -> "cliente-teste-01"); '' = painel principal.
// Devolve null se não der para usar.
function licNormalizarPasta($texto) {
    $pasta = strtolower(trim((string) $texto));
    $pasta = preg_replace('/\s+/', '-', $pasta);
    $pasta = trim($pasta, '/');
    if ($pasta === '') {
        return '';
    }
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $pasta) || in_array($pasta, licPastasReservadas(), true)) {
        return null;
    }
    return $pasta;
}

// Cada painel é de UMA licença só: se duas licenças apontassem para o mesmo
// painel, dois clientes entrariam no mesmo painel pelo login único.
// Devolve a outra licença que já usa esse painel (ou null). $ignorar = a
// própria licença, ao editar.
function licLicencaDoPainel($painel, $ignorar = '') {
    $chave = licChavePainel($painel);
    foreach (licTodas() as $id => $licenca) {
        if ($id !== $ignorar && licChavePainel($licenca['painel']) === $chave) {
            return $licenca;
        }
    }
    return null;
}

// Mesma comparação dos painéis (painel/licenca.php, licencaChaveUrl):
// sem http/https, sem www., sem porta e sem diferença de maiúsculas
function licChavePainel($url) {
    $partes = parse_url((string) $url);
    if (!is_array($partes) || empty($partes['host'])) {
        return strtolower(trim((string) $url, '/'));
    }
    return preg_replace('/^www\./', '', strtolower($partes['host'])) . '/' . (isset($partes['path']) ? trim(strtolower($partes['path']), '/') : '');
}

function licPainelDaPasta($pasta) {
    return licBasePaineis() . ($pasta === '' ? '' : $pasta . '/');
}

// Pasta de um endereço salvo; null se o painel estiver fora de buddyApp/
function licPastaDoPainel($painel) {
    $base = licBasePaineis();
    if (strpos($painel, $base) !== 0) {
        return null;
    }
    return trim(substr($painel, strlen($base)), '/');
}

// Existe painel instalado nessa pasta? (a central fica em buddyApp/licencas/)
function licPainelInstalado($pasta) {
    $raiz = dirname(LIC_RAIZ) . ($pasta === '' ? '' : DIRECTORY_SEPARATOR . $pasta);
    return is_file($raiz . DIRECTORY_SEPARATOR . 'painel' . DIRECTORY_SEPARATOR . 'lib.php');
}

// ---------- Painéis: espaço das fotos e licença ----------

// config/painel.local.php de um painel deste servidor ('' = principal), ou null
function licConfigDoPainel($pasta) {
    $raiz = dirname(LIC_RAIZ) . ($pasta === '' ? '' : DIRECTORY_SEPARATOR . $pasta);
    $arquivo = $raiz . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'painel.local.php';
    $config = is_file($arquivo) ? include $arquivo : null;
    return is_array($config) ? $config : null;
}

// O painel só aceita robôs com licença? ('exigir_licenca' no config dele)
function licPainelExigeLicenca($pasta) {
    $config = licConfigDoPainel($pasta);
    return $config !== null && !empty($config['exigir_licenca']);
}

// Evento padrão e eventos individuais dos robôs ativados nesta licença.
function licEventoDoPainel($pasta, $licenca = null) {
    $raiz = dirname(LIC_RAIZ) . ($pasta === '' ? '' : DIRECTORY_SEPARATOR . $pasta);
    $dados = licLerArquivo($raiz . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'painel' . DIRECTORY_SEPARATOR . 'evento_atual.php');
    $nome = function ($item) {
        $evento = isset($item['pasta']) ? (string) $item['pasta'] : '';
        if (!preg_match('#^eventos/\d{4}-\d{2}-\d{2}(-[a-z0-9]+)*$#', $evento)) {
            return '';
        }
        // Arquivo gravado antes de ter o nome: mostra o id ("2026-11-09-casamento-ana")
        return isset($item['nome']) && trim((string) $item['nome']) !== '' ? (string) $item['nome'] : substr($evento, 8);
    };
    $atual = $nome($dados);
    $encerrado = $atual === '' && isset($dados['encerrado']) && is_array($dados['encerrado']) ? $nome($dados['encerrado']) : '';
    $individuais = array();
    $dispositivos = isset($licenca['dispositivos']) ? $licenca['dispositivos'] : array();
    foreach ($dispositivos as $robo) {
        $codigo = !empty($robo['codigo']) ? $robo['codigo'] : $licenca['id'];
        if (isset($dados['eventos_robos'][$codigo])) {
            $individuais[] = $dados['eventos_robos'][$codigo];
        }
    }
    return array(
        'atual' => $atual,
        'individuais' => array_unique($individuais),
        'encerrado' => $encerrado,
        'encerrado_em' => $encerrado !== '' && isset($dados['encerrado']['em']) ? (int) $dados['encerrado']['em'] : 0
    );
}

// Espaço das fotos de um painel deste servidor, como a barra do painel conta
// (painelUsoFotos): fotos soltas na pasta das fotos + pastas de evento.
// array('bytes', 'fotos', 'limite') ou null se o painel não está aqui.
// A conta fica guardada 15 minutos (dados/uso-paineis.php).
function licUsoPainel($pasta, $forcar = false) {
    static $guardado = null;
    if ($guardado === null) {
        $guardado = licLerArquivo(LIC_USO);
    }
    if (!$forcar && isset($guardado[$pasta]['calculado']) && time() - (int) $guardado[$pasta]['calculado'] < 900) {
        return $guardado[$pasta];
    }
    $config = licConfigDoPainel($pasta);
    if ($config === null) {
        return null;
    }
    $raiz = dirname(LIC_RAIZ) . ($pasta === '' ? '' : DIRECTORY_SEPARATOR . $pasta);
    $pastaFotos = isset($config['pasta_fotos']) ? trim(str_replace('\\', '/', (string) $config['pasta_fotos']), '/ ') : '';
    if ($pastaFotos !== '' && !preg_match('#^[a-zA-Z0-9_-]{1,60}(/[a-zA-Z0-9_-]{1,60}){0,4}$#', $pastaFotos)) {
        return null;
    }
    $fotos = $pastaFotos === '' ? $raiz : $raiz . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pastaFotos);
    $pastas = array($fotos);
    $eventos = $fotos . DIRECTORY_SEPARATOR . 'eventos';
    foreach ((is_dir($eventos) ? scandir($eventos) : false) ?: array() as $nome) {
        if ($nome[0] !== '.' && is_dir($eventos . DIRECTORY_SEPARATOR . $nome)) {
            $pastas[] = $eventos . DIRECTORY_SEPARATOR . $nome;
        }
    }
    // Lixeira do cliente (config/painel/lixeira/<cliente>/<dia>/<evento>/):
    // ainda ocupa espaço, mas não conta como foto
    $lixeira = glob($raiz . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'painel' . DIRECTORY_SEPARATOR . 'lixeira'
        . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: array();
    $bytes = 0;
    $quantidade = 0;
    foreach (array_merge($pastas, $lixeira) as $i => $pastaFoto) {
        foreach ((is_dir($pastaFoto) ? scandir($pastaFoto) : false) ?: array() as $nome) {
            $caminho = $pastaFoto . DIRECTORY_SEPARATOR . $nome;
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,150}\.(jpe?g|png|webp)$/i', $nome) && is_file($caminho)) {
                $bytes += (float) sprintf('%u', filesize($caminho));
                $quantidade += $i < count($pastas) ? 1 : 0;
            }
        }
    }
    $gb = isset($config['limite_gb']) && (float) $config['limite_gb'] > 0 ? (float) $config['limite_gb'] : 5;
    $guardado[$pasta] = array('bytes' => $bytes, 'fotos' => $quantidade, 'limite' => $gb * 1073741824, 'calculado' => time());
    licGravarArquivo(LIC_USO, $guardado);
    return $guardado[$pasta];
}

function licPorcentoUso($uso) {
    return $uso && $uso['limite'] > 0 ? $uso['bytes'] / $uso['limite'] * 100 : 0;
}

function licTamanhoTexto($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 1, ',', '.') . ' GB';
    }
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
    }
    return ($bytes > 0 ? max(1, round($bytes / 1024)) : 0) . ' KB';
}

// "1.234 fotos · 1,2 GB de 5,0 GB (24%)"
function licUsoTexto($uso) {
    $porcento = licPorcentoUso($uso);
    return number_format($uso['fotos'], 0, ',', '.') . ($uso['fotos'] === 1 ? ' foto' : ' fotos') . ' · '
        . licTamanhoTexto($uso['bytes']) . ' de ' . licTamanhoTexto($uso['limite'])
        . ' (' . ($porcento > 0 && $porcento < 1 ? 'menos de 1' : number_format(min($porcento, 999), 0, ',', '.')) . '%)';
}

// ---------- Último contato dos robôs ----------

// Robô que falou com a central há menos de 10 minutos (com o app aberto,
// ele confere a licença a cada minuto)
function licRoboOnline($robo) {
    $tempo = isset($robo['ultimo_contato']) ? strtotime($robo['ultimo_contato']) : 0;
    return $tempo && time() - $tempo < 600;
}

// Dias sem contato a partir dos quais a central avisa (dias_sem_contato no
// config/licencas.local.php; padrão 7, a validade da licença sem internet)
function licDiasSemContato() {
    $config = licConfig();
    return $config && isset($config['dias_sem_contato']) ? max(1, (int) $config['dias_sem_contato']) : ($config ? $config['dias_offline'] : 7);
}

// "agora", "há 5 min", "há 3 h", "há 2 dias"
function licHaQuantoTempo($data) {
    $tempo = is_int($data) ? $data : strtotime((string) $data);
    if (!$tempo) {
        return '';
    }
    $segundos = max(0, time() - $tempo);
    if ($segundos < 120) {
        return 'agora';
    }
    if ($segundos < 3600) {
        return 'há ' . floor($segundos / 60) . ' min';
    }
    if ($segundos < 172800) {
        return 'há ' . floor($segundos / 3600) . ' h';
    }
    return 'há ' . floor($segundos / 86400) . ' dias';
}

// Sem contato há mais de licDiasSemContato() dias?
function licContatoAtrasado($data) {
    $tempo = is_int($data) ? $data : strtotime((string) $data);
    return $tempo && time() - $tempo > licDiasSemContato() * 86400;
}

// ---------- Avisos da central ----------

// O que a equipe precisa olhar, para o topo da lista de licenças. Cada aviso:
// tipo ('erro' ou 'alerta'), texto e link (ou '').
function licAlertas($todas) {
    $alertas = array();
    $agora = time();
    $config = licConfig();

    // Arquivo das licenças recuperado da cópia (últimos 30 dias)
    foreach (licBackups() as $copia) {
        if ($copia['tipo'] === 'estragado' && $copia['quando'] > $agora - 30 * 86400) {
            $alertas[] = array('erro', 'Em ' . licDataBr($copia['quando'], true) . ' o arquivo das licenças estava estragado e voltou a cópia de segurança. Confira as licenças criadas ou alteradas pouco antes disso.', 'backup.php');
            break;
        }
    }
    // Gravou hoje e não fez a cópia do dia: a pasta backup/ não está gravando
    if (is_file(LIC_ARQUIVO) && date('Y-m-d', (int) @filemtime(LIC_ARQUIVO)) === date('Y-m-d')
        && !is_file(LIC_BACKUPS . DIRECTORY_SEPARATOR . licNomeBackupDoDia())) {
        $alertas[] = array('erro', 'A cópia de segurança de hoje não foi feita. Confira se o PHP pode gravar na pasta licencas/dados/backup/.', 'backup.php');
    }

    $paineisVistos = array();
    $paineisSemEvento = array();
    $paineisAntigos = array();
    foreach ($todas as $licenca) {
        $link = 'licenca.php?id=' . rawurlencode($licenca['id']);
        $nome = $licenca['cliente'];
        $situacao = licSituacao($licenca, $agora);
        if ($situacao === 'vencida') {
            $alertas[] = array('erro', $nome . ': a licença venceu em ' . licDataBr($licenca['vence']) . '. Os robôs travam em '
                . licDataBr(licFimDoDia($licenca['vence']) + $config['dias_carencia'] * 86400) . ' se a data não for estendida.', $link);
        } elseif ($situacao === 'ativa' && $licenca['vence'] !== '') {
            // Dias de calendário até o vencimento (o último dia ainda vale inteiro)
            $dias = (int) round((strtotime($licenca['vence']) - strtotime(date('Y-m-d', $agora))) / 86400);
            if ($dias <= 15) {
                $alertas[] = array('alerta', $nome . ': a licença vence ' . ($dias <= 0 ? 'hoje' : ($dias === 1 ? 'amanhã' : 'em ' . $dias . ' dias'))
                    . ' (' . licDataBr($licenca['vence']) . '). Depois disso, os robôs ainda funcionam por ' . $config['dias_carencia'] . ' dias de aviso.', $link);
            }
        }
        if ($situacao !== 'suspensa' && $situacao !== 'expirada') {
            foreach ($licenca['dispositivos'] as $robo) {
                if (licContatoAtrasado($robo['ultimo_contato'])) {
                    $alertas[] = array('alerta', $nome . ': o robô ' . licNomeRobo($robo) . ' não fala com a central ' . licHaQuantoTempo($robo['ultimo_contato'])
                        . '. Pode estar desligado ou sem internet.', $link . '#robos');
                }
            }
        }

        $pasta = licPastaDoPainel($licenca['painel']);
        if ($pasta === null || !licPainelInstalado($pasta)) {
            continue;
        }
        // Robô ligado a esta licença e painel sem evento: o robô fica parado
        // em "Nenhum evento ativo" (avisa uma vez por painel). Evento encerrado
        // pelo cliente é de propósito: não avisa.
        $eventoPainel = $licenca['dispositivos'] && $situacao !== 'suspensa' && $situacao !== 'expirada' && !isset($paineisSemEvento[$pasta])
            ? licEventoDoPainel($pasta, $licenca) : null;
        if ($eventoPainel && $eventoPainel['atual'] === '' && $eventoPainel['encerrado'] === '' && !$eventoPainel['individuais']) {
            $paineisSemEvento[$pasta] = true;
            $alertas[] = array('alerta', $nome . ': o painel não tem evento, então os robôs não tiram fotos (ficam na tela "Nenhum evento ativo"). O cliente cria o evento em "Configurar o app".', $link);
        }
        if (isset($paineisVistos[$pasta])) {
            continue;
        }
        $paineisVistos[$pasta] = true;
        $alertas = array_merge($alertas, licAlertasDoPainel($pasta, $nome, $link));
        // Código do painel: de antes da atualização automática, ou com erro ao se atualizar
        $codigo = licCodigoDoPainel($pasta);
        if ($codigo && $codigo['situacao'] === 'antigo') {
            $paineisAntigos[] = $nome;
        } elseif ($codigo && $codigo['situacao'] === 'erro') {
            $alertas[] = array('erro', $nome . ': o painel não conseguiu se atualizar sozinho (' . $codigo['erro'] . '). Use "Atualizar os arquivos do painel".', $link);
        }
    }
    if ($paineisAntigos) {
        $alertas[] = array('alerta', (count($paineisAntigos) === 1 ? '1 painel ainda está' : count($paineisAntigos) . ' painéis ainda estão')
            . ' com o código de antes da atualização automática (' . implode(', ', $paineisAntigos) . '). Clique em "Atualizar todos os painéis", no fim desta página, uma vez só: depois disso, eles se atualizam sozinhos.', '#atualizar-todos');
    }
    // Painel principal, mesmo sem licença apontando para ele
    if (!isset($paineisVistos['']) && licPainelInstalado('')) {
        $alertas = array_merge($alertas, licAlertasDoPainel('', 'Painel principal (buddyApp/)', ''));
    }

    // Erros primeiro
    usort($alertas, function ($a, $b) {
        return ($a[0] === 'erro' ? 0 : 1) - ($b[0] === 'erro' ? 0 : 1);
    });
    return $alertas;
}

function licAlertasDoPainel($pasta, $nome, $link) {
    $alertas = array();
    $uso = licUsoPainel($pasta);
    $porcento = licPorcentoUso($uso);
    if ($uso && $porcento >= 100) {
        $alertas[] = array('erro', $nome . ': o espaço das fotos está cheio (' . licUsoTexto($uso) . '). Aumente o limite_gb do painel ou peça ao cliente para baixar e apagar fotos antigas.', $link);
    } elseif ($uso && $porcento >= 80) {
        $alertas[] = array('alerta', $nome . ': o espaço das fotos está quase cheio (' . licUsoTexto($uso) . ').', $link);
    }
    if (!licPainelExigeLicenca($pasta)) {
        $alertas[] = array('alerta', $nome . ': o painel não exige licença, então qualquer robô (ou pessoa) que descubra o endereço consegue enviar fotos para ele. Depois de ativar a licença em todos os robôs desse painel, mude \'exigir_licenca\' para true no config/painel.local.php dele.', $link);
    }
    return $alertas;
}

// ---------- Painel de cada cliente: criar e atualizar ----------
//
// O painel principal (buddyApp/painel/) é o modelo. Um cliente novo ganha
// buddyApp/<pasta>/ com uma cópia só do código: as mídias (painel/midia/<id>/),
// os dados (config/painel/clientes/, miniaturas, eventos) e as fotos do
// principal nunca são copiados. Desde 24/11/2026 cada painel de cliente se
// atualiza sozinho quando o código do principal muda (painel/atualizar.php);
// os botões abaixo ficam para criar o painel e para forçar a atualização.

// Situação do código do painel de um cliente: 'ok' (igual ao principal),
// 'esperando' (o principal mudou; ele se atualiza no próximo acesso), 'antigo'
// (de antes da atualização automática: precisa do botão uma vez) ou 'erro'.
// Devolve array('situacao', 'atualizado_em', 'erro') ou null (sem painel aqui).
function licCodigoDoPainel($pasta) {
    static $principal = null;
    if ($pasta === '' || !function_exists('atualizarMarca') || !licPainelInstalado($pasta)) {
        return null;
    }
    if ($principal === null) {
        $principal = atualizarMarca(dirname(LIC_RAIZ));
    }
    $estado = atualizarEstado(dirname(LIC_RAIZ) . DIRECTORY_SEPARATOR . $pasta);
    if ($estado['marca'] === '') {
        $situacao = 'antigo';
    } elseif ($estado['marca'] === $principal['marca']) {
        $situacao = 'ok';
    } elseif ($estado['erro'] !== '') {
        $situacao = 'erro';
    } else {
        $situacao = 'esperando';
    }
    return array('situacao' => $situacao, 'atualizado_em' => $estado['atualizado_em'], 'erro' => $estado['erro'], 'erro_em' => $estado['erro_em']);
}

// Cria (ou atualiza o código de) buddyApp/<pasta>/. Na criação, gera o
// config/painel.local.php do cliente a partir do principal. Devolve null se
// deu certo ou o texto do erro.
function licInstalarPainel($pasta, $licenca, $soAtualizar = false) {
    $raiz = dirname(LIC_RAIZ);
    if ($pasta === '' || licNormalizarPasta($pasta) !== $pasta) {
        return 'Pasta inválida.';
    }
    if (!is_file($raiz . '/painel/lib.php') || !is_file($raiz . '/config/painel.local.php')) {
        return 'O painel principal não foi encontrado em ' . licBasePaineis() . '.';
    }
    $destino = $raiz . DIRECTORY_SEPARATOR . $pasta;
    $configDestino = $destino . '/config/painel.local.php';
    if ($soAtualizar && !is_file($configDestino)) {
        return 'Este cliente ainda não tem painel. Use "Criar o painel agora".';
    }

    foreach (array($destino, $destino . '/painel', $destino . '/config/painel/clientes') as $pastaNova) {
        if (!is_dir($pastaNova) && !@mkdir($pastaNova, 0755, true)) {
            return 'Não foi possível criar a pasta ' . $pasta . '/. Confira se o PHP pode gravar em ' . licBasePaineis() . '.';
        }
    }
    if (!function_exists('atualizarAgora')) {
        return 'Falta o arquivo painel/atualizar.php no painel principal. Suba os arquivos do painel de novo.';
    }
    // Copia o código (só o que mudou) e anota a versão no painel do cliente
    $erroCopia = atualizarAgora($raiz, $destino);
    if ($erroCopia !== null) {
        return $erroCopia . ' Confira se o PHP pode gravar em ' . licBasePaineis() . $pasta . '/.';
    }
    if ($soAtualizar || is_file($configDestino)) {
        // Atualização: o config do cliente fica como está, só as fotos
        // passam para a pasta padrão fotos/ (painéis de antes do padrão)
        return licMigrarFotos($destino, $licenca);
    }

    // Config do cliente: o mesmo login da equipe do principal, senha própria
    // para o login local (a equipe entra pelo botão "Abrir o painel")
    $principal = include $raiz . '/config/painel.local.php';
    $principal = is_array($principal) ? $principal : array();
    $config = licConfig();
    $novo = array(
        'usuario' => isset($principal['usuario']) ? $principal['usuario'] : 'futuremedia',
        'senha' => licGerarSenha() . licGerarSenha(),
        'usuario_equipe' => isset($principal['usuario_equipe']) ? $principal['usuario_equipe'] : '',
        'senha_equipe' => isset($principal['senha_equipe']) ? $principal['senha_equipe'] : '',
        'nome' => $licenca['cliente'],
        'pasta_fotos' => LIC_PASTA_FOTOS,
        'limite_gb' => isset($principal['limite_gb']) ? $principal['limite_gb'] : 5,
        'exigir_licenca' => true,
        'login_unico' => $config['url_login']
    );
    if (!licGravarConfigPainel($configDestino, $novo, $licenca['cliente'])) {
        return 'Não foi possível gravar o config/painel.local.php do cliente.';
    }
    licCriarPastaFotos($destino);
    return null;
}

// Atualiza o código de todos os painéis de cliente de uma vez (botão da lista).
// Devolve array(quantos deram certo, array de erros "Cliente: erro").
function licAtualizarTodosPaineis() {
    $feitos = 0;
    $erros = array();
    $vistos = array();
    foreach (licTodas() as $licenca) {
        $pasta = licPastaDoPainel($licenca['painel']);
        if ($pasta === null || $pasta === '' || isset($vistos[$pasta]) || !licPainelInstalado($pasta)) {
            continue;
        }
        $vistos[$pasta] = true;
        $erro = licInstalarPainel($pasta, $licenca, true);
        if ($erro === null) {
            $feitos++;
        } else {
            $erros[] = $licenca['cliente'] . ': ' . $erro;
        }
    }
    return array($feitos, $erros);
}

// Pasta padrão das fotos de cada cliente: buddyApp/<cliente>/fotos/
define('LIC_PASTA_FOTOS', 'fotos');

function licGravarConfigPainel($arquivo, $dados, $cliente) {
    $php = "<?php\n"
        . "// Painel do cliente \"" . str_replace(array("\r", "\n", '?>'), ' ', $cliente) . "\", da Central de Licenças (" . date('d/m/Y H:i') . ").\n"
        . "// O cliente entra pelo login único (login_unico). O login abaixo é só da\n"
        . "// equipe (painel/?local=1); na central, use \"Abrir o painel do cliente\".\n"
        . "// pasta_fotos: as fotos dos robôs ficam em <esta pasta>/fotos/ (padrão).\n"
        . "// exigir_licenca: só robôs com licença deste painel sincronizam e enviam fotos.\n"
        . "return " . var_export($dados, true) . ";\n";
    return @file_put_contents($arquivo, $php) !== false;
}

// .htaccess da pasta fotos/: as fotos abrem normalmente, mas nenhum script
// roda ali dentro (nem em eventos/). O mesmo texto está no uploadImage.php.
function licHtaccessFotos() {
    return "# Fotos dos robôs: só imagens. Nenhum script roda nesta pasta (nem nas de dentro).\n"
        . "<FilesMatch \"\\.(php[0-9]?|pht|phtml|phar|phps|shtml|cgi|pl|py|sh|asp|aspx|jsp)(\\.|$)\">\n"
        . "    <IfModule mod_authz_core.c>\n"
        . "        Require all denied\n"
        . "    </IfModule>\n"
        . "    <IfModule !mod_authz_core.c>\n"
        . "        Order deny,allow\n"
        . "        Deny from all\n"
        . "    </IfModule>\n"
        . "</FilesMatch>\n";
}

// fotos/ com index.html em branco (o navegador não lista as fotos) e o
// .htaccess que impede rodar scripts
function licCriarPastaFotos($destino) {
    $fotos = $destino . DIRECTORY_SEPARATOR . LIC_PASTA_FOTOS;
    if (!is_dir($fotos) && !@mkdir($fotos, 0755, true)) {
        return false;
    }
    if (!is_file($fotos . DIRECTORY_SEPARATOR . 'index.html')) {
        @file_put_contents($fotos . DIRECTORY_SEPARATOR . 'index.html', '');
    }
    if (!is_file($fotos . DIRECTORY_SEPARATOR . '.htaccess')) {
        @file_put_contents($fotos . DIRECTORY_SEPARATOR . '.htaccess', licHtaccessFotos());
    }
    return true;
}

// Painel de antes do padrão (pasta_fotos vazia, fotos soltas na pasta do
// cliente): move as fotos e a pasta eventos/ para fotos/ e muda o config.
// Painel que já usa uma pasta (fotos ou outra) fica como está.
// Devolve null se deu certo ou o texto do erro.
function licMigrarFotos($destino, $licenca) {
    $arquivo = $destino . '/config/painel.local.php';
    $config = is_file($arquivo) ? include $arquivo : null;
    if (!is_array($config)) {
        return 'O config/painel.local.php do cliente não foi encontrado.';
    }
    if (isset($config['pasta_fotos']) && trim((string) $config['pasta_fotos'], '/ ') !== '') {
        licCriarPastaFotos($destino);
        return null;
    }
    if (!licCriarPastaFotos($destino)) {
        return 'Não foi possível criar a pasta fotos/ do cliente.';
    }
    $fotos = $destino . DIRECTORY_SEPARATOR . LIC_PASTA_FOTOS;

    // Fotos soltas na pasta do cliente
    foreach ((array) glob($destino . DIRECTORY_SEPARATOR . '*') as $item) {
        if (is_file($item) && preg_match('/\.(jpe?g|png|webp)$/i', $item)) {
            $alvo = $fotos . DIRECTORY_SEPARATOR . basename($item);
            if (!is_file($alvo) && !@rename($item, $alvo)) {
                return 'Não foi possível mover a foto ' . basename($item) . ' para fotos/.';
            }
        }
    }
    // Pasta dos eventos
    $eventos = $destino . DIRECTORY_SEPARATOR . 'eventos';
    $eventosNovos = $fotos . DIRECTORY_SEPARATOR . 'eventos';
    if (is_dir($eventos)) {
        if (!is_dir($eventosNovos)) {
            if (!@rename($eventos, $eventosNovos)) {
                return 'Não foi possível mover a pasta eventos/ para fotos/.';
            }
        } else {
            foreach ((array) glob($eventos . DIRECTORY_SEPARATOR . '*') as $item) {
                $alvo = $eventosNovos . DIRECTORY_SEPARATOR . basename($item);
                if (!file_exists($alvo) && !@rename($item, $alvo)) {
                    return 'Não foi possível mover ' . basename($item) . ' para fotos/eventos/.';
                }
            }
            @rmdir($eventos);
        }
    }

    // Por último o config: daqui em diante as fotos novas vão para fotos/
    $config['pasta_fotos'] = LIC_PASTA_FOTOS;
    if (!licGravarConfigPainel($arquivo, $config, $licenca['cliente'])) {
        return 'As fotos foram para fotos/, mas não foi possível gravar o config do cliente. Tente de novo.';
    }
    return null;
}

// Endereço do painel do cliente, sempre terminando em "/"
function licNormalizarPainel($url) {
    $url = trim((string) $url);
    if (!preg_match('#^https?://[a-zA-Z0-9.-]+(:\d+)?(/[a-zA-Z0-9._~%/-]*)?$#', $url)) {
        return null;
    }
    return rtrim($url, '/') . '/';
}

// ---------- Tentativas erradas (api) ----------

// Até 10 erros por IP a cada 15 minutos. O $ip pode levar um prefixo
// ("login:1.2.3.4") para contar à parte os erros do login do painel.
function licBloqueadoPorTentativas($ip) {
    $tentativas = licLerArquivo(LIC_TENTATIVAS);
    $chave = sha1($ip);
    if (empty($tentativas[$chave])) {
        return false;
    }
    $recentes = array_filter($tentativas[$chave], function ($t) {
        return $t > time() - 900;
    });
    return count($recentes) >= 10;
}

function licAnotarTentativaErrada($ip) {
    $tentativas = licLerArquivo(LIC_TENTATIVAS);
    $limite = time() - 900;
    foreach ($tentativas as $chave => $lista) {
        $tentativas[$chave] = array_values(array_filter($lista, function ($t) use ($limite) {
            return $t > $limite;
        }));
        if (!$tentativas[$chave]) {
            unset($tentativas[$chave]);
        }
    }
    $tentativas[sha1($ip)][] = time();
    licGravarArquivo(LIC_TENTATIVAS, $tentativas);
}

// ---------- Sessão e páginas ----------

function licIniciarSessao() {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('centrallicencas');
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        session_set_cookie_params(0, '/', '', $https, true);
        session_start();
    }
}

// Sai sozinho depois de 1 hora sem uso
function licLogado() {
    if (empty($_SESSION['lic_logado']) || time() - (int) $_SESSION['lic_logado'] > 3600) {
        return false;
    }
    $_SESSION['lic_logado'] = time();
    return true;
}

function licExigirLogin() {
    licIniciarSessao();
    if (!licLogado()) {
        licIr('./');
    }
}

function licTokenCsrf() {
    if (empty($_SESSION['lic_csrf'])) {
        $_SESSION['lic_csrf'] = bin2hex(licAleatorio(16));
    }
    return $_SESSION['lic_csrf'];
}

function licCampoCsrf() {
    return '<input type="hidden" name="csrf" value="' . h(licTokenCsrf()) . '">';
}

function licConferirCsrf() {
    return isset($_POST['csrf']) && hash_equals(licTokenCsrf(), (string) $_POST['csrf']);
}

function licAviso($tipo, $mensagem) {
    $_SESSION['lic_avisos'][] = array('tipo' => $tipo, 'mensagem' => $mensagem);
}

function licIr($url) {
    header('Location: ' . $url, true, 303);
    exit;
}

function licDataBr($data, $comHora = false) {
    $tempo = is_int($data) ? $data : strtotime((string) $data);
    if (!$tempo) {
        return '-';
    }
    return date($comHora ? 'd/m/Y H:i' : 'd/m/Y', $tempo);
}

function licTopo($titulo, $logado = false) {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Frame-Options: DENY');
    header('X-Robots-Tag: noindex, nofollow');
    $avisos = isset($_SESSION['lic_avisos']) ? $_SESSION['lic_avisos'] : array();
    unset($_SESSION['lic_avisos']);
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo h($titulo); ?> · Central de Licenças</title>
<link rel="icon" type="image/png" sizes="32x32" href="marca/icone-32.png">
<link rel="icon" type="image/png" sizes="192x192" href="marca/icone-192.png">
<link rel="apple-touch-icon" sizes="180x180" href="marca/icone-180.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700;900&display=swap">
<link rel="stylesheet" href="estilo.css?v=1">
<link rel="stylesheet" href="central.css?v=8">
</head>
<body>
<header class="topo">
    <div class="topo-marca">
        <img class="topo-logo" src="marca/futuremedia.png" alt="Futuremedia" width="2501" height="347">
        <span class="topo-produto">Central de Licenças</span>
    </div>
    <?php if ($logado) {
        $paginaAtual = basename($_SERVER['SCRIPT_NAME']);
        ?>
    <nav class="menu" aria-label="Menu">
        <a href="./" class="menu-item"<?php echo $paginaAtual === 'index.php' ? ' aria-current="page"' : ''; ?>>Licenças</a>
        <a href="registro.php" class="menu-item"<?php echo $paginaAtual === 'registro.php' ? ' aria-current="page"' : ''; ?>>Registro</a>
        <a href="backup.php" class="menu-item"<?php echo $paginaAtual === 'backup.php' ? ' aria-current="page"' : ''; ?>>Backup</a>
        <a href="./?sair=1" class="menu-sair">Sair</a>
    </nav>
    <?php } ?>
</header>
<main class="conteudo">
<?php foreach ($avisos as $aviso) { ?>
    <div class="aviso aviso-<?php echo h($aviso['tipo']); ?>"><?php echo h($aviso['mensagem']); ?></div>
<?php } ?>
<?php
}

function licRodape() {
    ?>
</main>
<dialog class="confirmacao" id="confirmacao" aria-labelledby="confirmacao-titulo" aria-describedby="confirmacao-texto">
    <div class="confirmacao-icone" aria-hidden="true"></div>
    <h2 id="confirmacao-titulo"></h2>
    <p id="confirmacao-texto"></p>
    <div class="confirmacao-botoes">
        <button type="button" class="botao" data-resposta="nao">Cancelar</button>
        <button type="button" class="botao botao-primario" data-resposta="sim"></button>
    </div>
</dialog>
<script>
// Confirmação bonita no lugar do confirm(): formulários com data-confirmar
(function () {
    var caixa = document.getElementById('confirmacao');
    var aoConfirmar = null;
    if (caixa.showModal) {
        caixa.addEventListener('click', function (e) {
            var botao = e.target.closest('[data-resposta]');
            if (botao) { caixa.close(botao.getAttribute('data-resposta')); }
            else if (e.target === caixa) { caixa.close('nao'); }
        });
        caixa.addEventListener('close', function () {
            var sim = aoConfirmar;
            aoConfirmar = null;
            if (sim && caixa.returnValue === 'sim') { sim(); }
        });
    }
    function confirmar(form, sim) {
        if (!caixa.showModal) { if (window.confirm(form.getAttribute('data-confirmar'))) { sim(); } return; }
        document.getElementById('confirmacao-titulo').textContent = form.getAttribute('data-confirmar-titulo') || 'Tem certeza?';
        document.getElementById('confirmacao-texto').textContent = form.getAttribute('data-confirmar');
        caixa.querySelector('[data-resposta=sim]').textContent = form.getAttribute('data-confirmar-botao') || 'Confirmar';
        caixa.classList.toggle('confirmacao-perigo', form.hasAttribute('data-confirmar-perigo'));
        aoConfirmar = sim;
        caixa.returnValue = '';
        caixa.showModal();
        caixa.querySelector('[data-resposta=nao]').focus();
    }
    document.querySelectorAll('form[data-confirmar]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (form.confirmado) { return; }
            e.preventDefault();
            confirmar(form, function () { form.confirmado = true; form.submit(); });
        });
    });
    // Copiar o código da licença
    document.querySelectorAll('[data-copiar]').forEach(function (botao) {
        botao.addEventListener('click', function () {
            var texto = botao.getAttribute('data-copiar');
            var feito = function () { var antes = botao.textContent; botao.textContent = 'Copiado!'; setTimeout(function () { botao.textContent = antes; }, 1500); };
            if (navigator.clipboard) { navigator.clipboard.writeText(texto).then(feito); }
        });
    });
})();
</script>
</body>
</html>
<?php
}
