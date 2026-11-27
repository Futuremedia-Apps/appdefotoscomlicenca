<?php
// Página que o QR Code abre no celular do visitante (pública, sem login).
// O app (1.0.3 em diante) recebe este endereço em "pagina", na resposta do
// uploadImage.php. Robô com app antigo continua abrindo a página antiga, sem
// cadastro.
//
//   qr.php?e=<evento>&f=<foto>   cadastro (se o cliente ligou) e depois a foto
//   ...&img=1 / &baixar=1        a foto; com cadastro obrigatório, só para o
//                                celular que se cadastrou neste evento
//   ...&previa=1                 a foto minúscula e borrada, atrás do cadastro
//   ...&pular=1                  cadastro opcional: "Agora não"
//   qr.php?teste=1 (POST)        prévia do formulário dentro do painel, só para
//                                quem está logado; nada é salvo
//
// O endereço nunca escolhe uma pasta: o evento tem que estar na lista do
// painel e o nome da foto tem o formato das fotos.
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'leads_lib.php';

$cliente = painelClienteUnico();
$teste = isset($_GET['teste']);

function qrEndereco($evento, $foto, $extra = '') {
    return 'qr.php?e=' . rawurlencode($evento) . '&f=' . rawurlencode($foto) . $extra;
}

// Cor do cliente (a mesma dos botões do app) e o texto que dá para ler em cima dela
function qrCores($cliente) {
    $cor = $cliente && isset($cliente['config']['cor_primaria']) ? (string) $cliente['config']['cor_primaria'] : '';
    $cor = preg_match('/^#[0-9a-fA-F]{6}$/', $cor) ? strtolower($cor) : '#0b3675';
    $canais = array();
    foreach (array(1, 3, 5) as $i) {
        $c = hexdec(substr($cor, $i, 2)) / 255;
        $canais[] = $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
    }
    $luz = 0.2126 * $canais[0] + 0.7152 * $canais[1] + 0.0722 * $canais[2];
    $clara = ($luz + 0.05) / 0.05 > 1.05 / ($luz + 0.05);
    return array(
        'cor' => $cor,
        'sobre' => $clara ? '#14161a' : '#ffffff',
        // Rótulo do evento em cima do branco: cor clara demais não dá para ler
        'forte' => $luz > 0.35 ? '#14161a' : $cor
    );
}

function qrEnviarArquivo($caminho, $tipo, $download, $cache) {
    header('Content-Type: ' . $tipo);
    header('Content-Length: ' . sprintf('%u', filesize($caminho)));
    header('Cache-Control: ' . $cache);
    header('X-Content-Type-Options: nosniff');
    if ($download !== '') {
        header('Content-Disposition: attachment; filename="' . $download . '"');
    }
    readfile($caminho);
    exit;
}

// A foto com 48 px de largura e borrada: só cores e formas, para mostrar que a
// foto está ali, atrás do cadastro. Sem GD, 404 (a página esconde a imagem).
function qrEnviarPrevia($caminho) {
    $info = function_exists('imagecreatetruecolor') ? @getimagesize($caminho) : false;
    $imagem = false;
    if ($info && $info[0] > 0 && $info[1] > 0 && $info[0] * $info[1] <= 30000000) {
        if ($info[2] === IMAGETYPE_JPEG) {
            $imagem = @imagecreatefromjpeg($caminho);
        } elseif ($info[2] === IMAGETYPE_PNG) {
            $imagem = @imagecreatefrompng($caminho);
        } elseif (defined('IMAGETYPE_WEBP') && $info[2] === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) {
            $imagem = @imagecreatefromwebp($caminho);
        }
    }
    if (!$imagem) {
        http_response_code(404);
        exit;
    }
    $largura = 48;
    $altura = max(1, (int) round($info[1] * $largura / $info[0]));
    $mini = imagecreatetruecolor($largura, $altura);
    imagecopyresampled($mini, $imagem, 0, 0, 0, 0, $largura, $altura, $info[0], $info[1]);
    imagedestroy($imagem);
    for ($i = 0; $i < 4; $i++) {
        imagefilter($mini, IMG_FILTER_GAUSSIAN_BLUR);
    }
    header('Content-Type: image/jpeg');
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    imagejpeg($mini, null, 55);
    imagedestroy($mini);
    exit;
}

// ---------- Prévia dentro do painel ----------

if ($teste) {
    painelIniciarSessao();
    $configPrevia = null;
    if ($cliente && painelLogado() && $_SERVER['REQUEST_METHOD'] === 'POST' && painelConferirCsrf()) {
        $erro = '';
        $dados = json_decode(isset($_POST['config']) ? (string) $_POST['config'] : '', true);
        $configPrevia = leadsValidarConfig(is_array($dados) ? $dados : array(), $erro, true);
    }
    session_write_close();
    if (!$configPrevia) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Prévia indisponível.';
        exit;
    }
    $eventoPrevia = isset($_GET['e']) ? painelEvento($cliente, $_GET['e']) : painelEventoAtual($cliente);
    if (isset($_GET['e']) && !$eventoPrevia) {
        http_response_code(404);
        exit;
    }
    qrPagina($configPrevia['modo'] === 'desligado' ? 'foto' : 'cadastro', array(
        'cliente' => $cliente,
        'config' => $configPrevia,
        'teste' => true,
        'eventoNome' => $eventoPrevia ? $eventoPrevia['nome'] : 'Nome do evento'
    ));
    exit;
}

// ---------- A foto pedida ----------

$evento = isset($_GET['e']) ? (string) $_GET['e'] : '';
$nome = isset($_GET['f']) ? (string) $_GET['f'] : '';
$dadosEvento = $cliente && painelIdEventoValido($evento) ? painelEvento($cliente, $evento) : null;
$caminho = $dadosEvento && painelNomeFotoValido($nome) ? painelPastaFotos($cliente, $evento) . DIRECTORY_SEPARATOR . $nome : '';
$arquivoPedido = !empty($_GET['img']) || !empty($_GET['baixar']) || !empty($_GET['previa']);

if ($caminho === '' || !is_file($caminho) || is_link($caminho)) {
    if ($arquivoPedido) {
        http_response_code(404);
        exit;
    }
    http_response_code(404);
    qrPagina('indisponivel', array('cliente' => $cliente, 'config' => leadsConfigPadrao()));
    exit;
}

$config = leadsConfig($cliente, $dadosEvento);
$leadId = leadsIdDoCookie($evento);
$cadastrado = $leadId !== '' && leadsExiste($cliente, $evento, $leadId);
// A foto pode ser entregue? (no obrigatório, só para quem se cadastrou)
$liberada = $config['modo'] !== 'obrigatorio' || $cadastrado;

if (!empty($_GET['previa'])) {
    qrEnviarPrevia($caminho);
}
if (!empty($_GET['img']) || !empty($_GET['baixar'])) {
    if (!$liberada) {
        http_response_code(403);
        exit;
    }
    $tipos = array('jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp');
    qrEnviarArquivo($caminho, $tipos[strtolower(pathinfo($nome, PATHINFO_EXTENSION))], !empty($_GET['baixar']) ? $nome : '', 'private, max-age=86400');
}

// ---------- Cadastro enviado ----------

$valores = array();
$erros = array();
$erroGeral = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $config['modo'] !== 'desligado') {
    // Campo escondido que só robô de spam preenche: finge que deu certo
    if (isset($_POST['site']) && trim((string) $_POST['site']) !== '') {
        painelIr(qrEndereco($evento, $nome));
    }
    list($valores, $erros) = leadsValidarEnvio($config, $_POST);
    $vazio = true;
    foreach ($valores as $valor) {
        if ($valor !== '' && $valor !== array()) {
            $vazio = false;
        }
    }
    if (!$erros && $vazio && $config['modo'] === 'opcional') {
        painelIr(qrEndereco($evento, $nome, '&pular=1'));
    }
    if (!$erros) {
        if (!leadsDentroDoLimite($cliente)) {
            $erroGeral = 'Muitos cadastros seguidos nesta rede. Espere alguns minutos e tente de novo.';
        } else {
            $rotulos = array();
            $tiposCampo = array();
            foreach ($config['campos'] as $campo) {
                $rotulos[$campo['id']] = $campo['rotulo'];
                $tiposCampo[$campo['id']] = $campo['tipo'];
            }
            $id = painelAleatorio(8);
            $salvo = leadsAcrescentar($cliente, $evento, array(
                't' => 'lead',
                'id' => $id,
                'em' => date('c'),
                'foto' => $nome,
                // O nome do evento vai junto: se o evento for apagado de vez, a aba
                // Leads continua mostrando o nome (e não só o id)
                'evento_nome' => $dadosEvento['nome'],
                'valores' => (object) $valores,
                'rotulos' => (object) $rotulos,
                'tipos' => (object) $tiposCampo
            ));
            if ($salvo) {
                leadsGravarCookie($evento, $id);
                painelIr(qrEndereco($evento, $nome, '&ok=1'));
            }
            $erroGeral = 'Não foi possível salvar agora. Tente de novo em instantes.';
        }
    }
}

// Já se cadastrou neste evento (em outra foto): a foto entra no mesmo cadastro
if ($cadastrado) {
    leadsVincularFoto($cliente, $evento, $leadId, $nome);
}

$mostrarFoto = $config['modo'] === 'desligado' || $cadastrado || ($config['modo'] === 'opcional' && isset($_GET['pular']) && $_SERVER['REQUEST_METHOD'] !== 'POST');
$medidas = @getimagesize($caminho);
qrPagina($mostrarFoto ? 'foto' : 'cadastro', array(
    'cliente' => $cliente,
    'config' => $config,
    'eventoNome' => $dadosEvento['nome'],
    'evento' => $evento,
    'nome' => $nome,
    'medidas' => $medidas ? array($medidas[0], $medidas[1]) : null,
    'valores' => $valores,
    'erros' => $erros,
    'erroGeral' => $erroGeral,
    'acabou' => !empty($_GET['ok']) && $cadastrado
));

// ---------- Página ----------

function qrIcone($nome) {
    $icones = array(
        'baixar' => '<path d="M12 3v12m0 0-5-5m5 5 5-5M5 21h14"/>',
        'compartilhar' => '<path d="M12 15V3m0 0L7 8m5-5 5 5M5 13v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6"/>',
        'foto' => '<path d="M4 8a2 2 0 0 1 2-2h2l1.5-2h5L16 6h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"/><circle cx="12" cy="12.5" r="3.5"/>',
        'cadeado' => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>'
    );
    return '<svg class="icone" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $icones[$nome] . '</svg>';
}

function qrCampo($campo, $valor, $erro, $teste) {
    $id = 'campo-' . $campo['id'];
    $nomeCampo = 'campo_' . $campo['id'];
    $opcional = $campo['obrigatorio'] ? '' : ' <span class="opcional">(opcional)</span>';
    $invalido = $erro !== '' ? ' aria-invalid="true" aria-describedby="' . h($id) . '-erro"' : '';
    $mensagem = $erro !== '' ? '<p class="erro-campo" id="' . h($id) . '-erro">' . h($erro) . '</p>' : '';

    if ($campo['tipo'] === 'escolha') {
        $marcadas = is_array($valor) ? $valor : array();
        $varias = !empty($campo['varias']);
        $html = '<fieldset class="campo"' . ($erro !== '' ? ' aria-describedby="' . h($id) . '-erro"' : '') . '>'
            . '<legend>' . h($campo['rotulo']) . $opcional . ($varias ? ' <span class="opcional">· pode marcar mais de uma</span>' : '') . '</legend><div class="opcoes">';
        foreach ($campo['opcoes'] as $i => $opcao) {
            $html .= '<label class="opcao"><input type="' . ($varias ? 'checkbox' : 'radio') . '" name="' . h($nomeCampo) . ($varias ? '[]' : '') . '" value="' . h($opcao) . '"'
                . (in_array($opcao, $marcadas, true) ? ' checked' : '') . '><span>' . h($opcao) . '</span></label>';
        }
        return $html . '</div>' . $mensagem . '</fieldset>';
    }

    $atributos = array(
        'nome' => 'type="text" autocomplete="name" autocapitalize="words" maxlength="120"',
        'email' => 'type="email" autocomplete="email" inputmode="email" autocapitalize="off" spellcheck="false" maxlength="160" placeholder="nome@email.com"',
        'telefone' => 'type="tel" autocomplete="tel" inputmode="tel" maxlength="20" placeholder="(11) 98765-4321" data-telefone',
        'numero' => 'type="text" inputmode="decimal" maxlength="20"',
        'texto' => 'type="text" maxlength="300"'
    );
    $texto = is_array($valor) ? '' : (string) $valor;
    if ($campo['tipo'] === 'numero' && $erro === '') {
        $texto = str_replace('.', ',', $texto);
    }
    return '<div class="campo"><label for="' . h($id) . '">' . h($campo['rotulo']) . $opcional . '</label>'
        . '<input id="' . h($id) . '" name="' . h($nomeCampo) . '" ' . $atributos[$campo['tipo']] . ' value="' . h($texto) . '"' . $invalido
        . ($teste ? ' tabindex="-1"' : '') . '>' . $mensagem . '</div>';
}

function qrPagina($tipo, $v) {
    $cores = qrCores($v['cliente']);
    $config = $v['config'];
    $teste = !empty($v['teste']);
    $eventoNome = isset($v['eventoNome']) ? $v['eventoNome'] : '';
    $titulos = array('foto' => 'Sua foto', 'cadastro' => $config['titulo'], 'indisponivel' => 'Foto indisponível');

    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
    // A prévia aparece dentro do painel (mesmo site); a página de verdade, em lugar nenhum
    header('X-Frame-Options: ' . ($teste ? 'SAMEORIGIN' : 'DENY'));
    $endereco = $teste ? '' : (isset($v['evento']) ? qrEndereco($v['evento'], $v['nome']) : '');
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="<?php echo h($cores['cor']); ?>">
<title><?php echo h($titulos[$tipo] . ($eventoNome !== '' ? ' · ' . $eventoNome : '')); ?></title>
<style>
:root {
    --cor: <?php echo h($cores['cor']); ?>;
    --sobre-cor: <?php echo h($cores['sobre']); ?>;
    --cor-forte: <?php echo h($cores['forte']); ?>;
    --fundo: #f2f3f5;
    --cartao: #ffffff;
    --texto: #1b1d22;
    --suave: #5d636e;
    --borda: #d9dde3;
    --erro: #b3261e;
    --erro-fundo: #fdecea;
}
* { box-sizing: border-box; }
html { -webkit-text-size-adjust: 100%; }
body {
    margin: 0;
    min-height: 100vh;
    min-height: 100svh;
    background: var(--fundo);
    color: var(--texto);
    font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    -webkit-font-smoothing: antialiased;
}
.faixa { height: 120px; background: var(--cor); }
.pagina { width: min(480px, calc(100% - 32px)); margin: -88px auto 0; padding-bottom: calc(28px + env(safe-area-inset-bottom, 0px)); }
.cartao { background: var(--cartao); border-radius: 20px; box-shadow: 0 14px 40px rgba(16, 24, 40, .13); overflow: hidden; }
.corpo { padding: 22px 20px 24px; }
.evento { margin: 0 0 4px; font-size: .75rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--cor-forte); }
h1 { margin: 0 0 6px; font-size: 1.55rem; line-height: 1.2; font-weight: 800; letter-spacing: -.01em; }
.texto { margin: 0 0 20px; color: var(--suave); white-space: pre-line; }
h1 + .campo, h1 + .erro-geral { margin-top: 18px; }
.previa { position: relative; aspect-ratio: 16 / 9; background: #cfd4db; overflow: hidden; }
.previa img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; filter: blur(10px) saturate(1.15); transform: scale(1.2); }
.previa-selo {
    position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%);
    display: inline-flex; align-items: center; gap: 8px; padding: 9px 16px;
    border-radius: 999px; background: rgba(255, 255, 255, .92); color: var(--texto);
    font-weight: 700; font-size: .95rem; white-space: nowrap; box-shadow: 0 4px 16px rgba(0, 0, 0, .15);
}
.campo { margin: 0 0 16px; padding: 0; border: 0; min-width: 0; }
.campo > label, .campo legend { display: block; margin: 0 0 6px; padding: 0; font-weight: 650; font-size: .95rem; }
.opcional { font-weight: 400; color: var(--suave); font-size: .85rem; }
input[type=text], input[type=email], input[type=tel] {
    display: block; width: 100%; min-height: 52px; padding: 12px 14px;
    border: 1.5px solid var(--borda); border-radius: 12px; background: #fff; color: inherit;
    font: inherit; font-size: 16px; /* 16px: o iPhone não dá zoom ao tocar */
    transition: border-color .12s, box-shadow .12s;
}
input[type=text]:focus, input[type=email]:focus, input[type=tel]:focus { outline: none; border-color: var(--cor); box-shadow: 0 0 0 3px rgba(0, 0, 0, .08); }
input[aria-invalid=true] { border-color: var(--erro); }
.opcoes { display: grid; gap: 8px; }
.opcao {
    display: flex; align-items: center; gap: 12px; min-height: 50px; padding: 10px 14px;
    border: 1.5px solid var(--borda); border-radius: 12px; cursor: pointer; transition: border-color .12s;
}
.opcao input { flex: none; width: 21px; height: 21px; margin: 0; accent-color: var(--cor); }
.opcao:has(input:checked) { border-color: var(--cor); box-shadow: inset 0 0 0 1px var(--cor); }
.opcao:focus-within { outline: 3px solid rgba(0, 0, 0, .14); outline-offset: 1px; }
.erro-campo { margin: 6px 0 0; color: var(--erro); font-size: .88rem; font-weight: 600; }
.erro-geral { margin: 0 0 16px; padding: 12px 14px; border-radius: 12px; background: var(--erro-fundo); color: var(--erro); font-weight: 600; }
.botao {
    display: flex; align-items: center; justify-content: center; gap: 10px;
    width: 100%; min-height: 54px; padding: 12px 18px; border: 0; border-radius: 14px;
    background: var(--cor); color: var(--sobre-cor); font: inherit; font-size: 1.05rem; font-weight: 700;
    text-decoration: none; cursor: pointer; -webkit-tap-highlight-color: transparent;
}
.botao:active { transform: translateY(1px); }
.botao:focus-visible, .pular:focus-visible { outline: 3px solid rgba(0, 0, 0, .3); outline-offset: 2px; }
.botao-claro { background: #fff; color: var(--texto); border: 1.5px solid var(--borda); }
.botao[disabled] { opacity: .6; }
.pular { display: block; margin: 10px auto 0; padding: 10px; text-align: center; color: var(--suave); font-weight: 600; text-decoration: underline; text-underline-offset: 3px; }
.aviso { margin: 16px 0 0; color: var(--suave); font-size: .8rem; line-height: 1.45; text-align: center; white-space: pre-line; }
.foto { display: block; width: 100%; height: auto; background: #e3e6ea; }
.foto-vazia { display: grid; place-items: center; aspect-ratio: 16 / 9; color: #8d949e; background: linear-gradient(135deg, #e7eaee, #d5dae1); }
.foto-vazia .icone { width: 48px; height: 48px; }
.acoes { display: grid; gap: 10px; margin-top: 18px; }
.dica { margin: 12px 0 0; color: var(--suave); font-size: .85rem; text-align: center; }
.indisponivel { text-align: center; }
.indisponivel .icone { width: 44px; height: 44px; color: #8d949e; margin-bottom: 6px; }
.armadilha { position: absolute; left: -10000px; width: 1px; height: 1px; overflow: hidden; }
[hidden] { display: none !important; }
@media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
</style>
</head>
<body>
<div class="faixa"></div>
<main class="pagina">
<?php if ($tipo === 'indisponivel') { ?>
    <div class="cartao"><div class="corpo indisponivel">
        <?php echo qrIcone('foto'); ?>
        <h1>Esta foto não está mais disponível</h1>
        <p class="texto">Ela pode ter sido apagada pelos organizadores do evento.</p>
    </div></div>
<?php } elseif ($tipo === 'foto') { ?>
    <div class="cartao">
        <?php if ($teste) { ?>
        <div class="foto-vazia"><?php echo qrIcone('foto'); ?></div>
        <?php } else { ?>
        <img class="foto" src="<?php echo h($endereco . '&img=1'); ?>" alt="Sua foto"<?php echo $v['medidas'] ? ' width="' . (int) $v['medidas'][0] . '" height="' . (int) $v['medidas'][1] . '"' : ''; ?>>
        <?php } ?>
        <div class="corpo">
            <?php if ($eventoNome !== '') { ?><p class="evento"><?php echo h($eventoNome); ?></p><?php } ?>
            <h1><?php echo !empty($v['acabou']) ? 'Pronto! Aqui está a sua foto' : 'Sua foto'; ?></h1>
            <div class="acoes">
                <a class="botao" href="<?php echo $teste ? '#' : h($endereco . '&baixar=1'); ?>" download><?php echo qrIcone('baixar'); ?> Baixar foto</a>
                <button type="button" class="botao botao-claro" id="compartilhar" hidden<?php echo $teste ? '' : ' data-foto="' . h($endereco . '&img=1') . '" data-nome="' . h($v['nome']) . '"'; ?>><?php echo qrIcone('compartilhar'); ?> Compartilhar</button>
            </div>
            <p class="dica" id="dica-iphone" hidden>No iPhone, toque em Compartilhar e depois em "Salvar imagem".</p>
        </div>
    </div>
<?php } else {
    $erros = isset($v['erros']) ? $v['erros'] : array();
    $valores = isset($v['valores']) ? $v['valores'] : array(); ?>
    <form class="cartao" method="post" action="<?php echo $teste ? '#' : h($endereco); ?>" novalidate>
        <div class="previa">
            <?php if (!$teste) { ?><img src="<?php echo h($endereco . '&previa=1'); ?>" alt="" onerror="this.remove()"><?php } ?>
            <span class="previa-selo"><?php echo qrIcone('cadeado'); ?> Sua foto está aqui</span>
        </div>
        <div class="corpo">
            <?php if ($eventoNome !== '') { ?><p class="evento"><?php echo h($eventoNome); ?></p><?php } ?>
            <h1><?php echo h($config['titulo']); ?></h1>
            <?php if ($config['texto'] !== '') { ?><p class="texto"><?php echo h($config['texto']); ?></p><?php } ?>
            <?php if (!empty($v['erroGeral'])) { ?><p class="erro-geral" role="alert"><?php echo h($v['erroGeral']); ?></p><?php } ?>
            <?php foreach ($config['campos'] as $campo) {
                echo qrCampo($campo, isset($valores[$campo['id']]) ? $valores[$campo['id']] : '', isset($erros[$campo['id']]) ? $erros[$campo['id']] : '', $teste);
            } ?>
            <div class="armadilha" aria-hidden="true"><label>Não preencha <input type="text" name="site" tabindex="-1" autocomplete="off"></label></div>
            <button type="<?php echo $teste ? 'button' : 'submit'; ?>" class="botao"><?php echo h($config['botao']); ?></button>
            <?php if ($config['modo'] === 'opcional') { ?>
            <a class="pular" href="<?php echo $teste ? '#' : h($endereco . '&pular=1'); ?>">Agora não, ver a foto</a>
            <?php } ?>
            <?php if ($config['aviso'] !== '') { ?><p class="aviso"><?php echo h($config['aviso']); ?></p><?php } ?>
        </div>
    </form>
<?php } ?>
</main>
<?php if (!$teste && $tipo !== 'indisponivel') { ?>
<script>
(function () {
    // Primeiro campo com erro ganha o foco
    var comErro = document.querySelector('[aria-invalid=true], fieldset[aria-describedby] input');
    if (comErro) { comErro.focus(); }

    // Telefone: (11) 98765-4321 enquanto digita (com "+", deixa como está)
    Array.prototype.forEach.call(document.querySelectorAll('[data-telefone]'), function (campo) {
        campo.addEventListener('input', function () {
            if (campo.value.trim().charAt(0) === '+') { return; }
            var d = campo.value.replace(/\D/g, '');
            if (d.length > 11 && d.indexOf('55') === 0) { d = d.slice(2); }
            d = d.slice(0, 11);
            var f = d;
            if (d.length > 2) { f = '(' + d.slice(0, 2) + ') ' + d.slice(2); }
            if (d.length > 6) { f = '(' + d.slice(0, 2) + ') ' + d.slice(2, d.length - 4) + '-' + d.slice(d.length - 4); }
            campo.value = f;
        });
    });

    // Enviando: evita dois toques
    var form = document.querySelector('form.cartao');
    if (form) {
        form.addEventListener('submit', function () {
            var botao = form.querySelector('button[type=submit]');
            setTimeout(function () { botao.disabled = true; }, 0);
        });
    }

    // Compartilhar a foto (o arquivo, não o link). A foto é baixada antes, para
    // o toque abrir o compartilhamento na hora (o iPhone exige).
    var botao = document.getElementById('compartilhar');
    if (botao && navigator.share && window.fetch && window.File) {
        var arquivo = null;
        fetch(botao.getAttribute('data-foto'), { credentials: 'same-origin' }).then(function (r) { return r.ok ? r.blob() : null; }).then(function (blob) {
            if (!blob) { return; }
            var f = new File([blob], botao.getAttribute('data-nome'), { type: blob.type || 'image/jpeg' });
            if (!navigator.canShare || navigator.canShare({ files: [f] })) {
                arquivo = f;
                botao.hidden = false;
            }
        }).catch(function () {});
        botao.addEventListener('click', function () {
            if (arquivo) { navigator.share({ files: [arquivo] }).catch(function () {}); }
        });
        if (/iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)) {
            document.getElementById('dica-iphone').hidden = false;
        }
    }
})();
</script>
<?php } ?>
</body>
</html>
<?php
}
