<?php
// Área da equipe Futuremedia: excluir fotos do servidor. O cliente não tem
// acesso: login próprio (usuario_equipe e senha_equipe em config/painel.local.php),
// o login do cliente não vale aqui e nenhuma página do painel traz link para cá.
// Sem esse login configurado, a página responde 404.
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib.php';
painelIniciarSessao();

if (!painelEquipeAtiva()) {
    http_response_code(404);
    exit;
}
header('X-Robots-Tag: noindex');

if (isset($_GET['sair'])) {
    unset($_SESSION['painel_equipe']);
    painelIr('equipe.php');
}

// ---------- Login ----------

if (!painelEquipeLogada()) {
    unset($_SESSION['painel_equipe']);
    $erro = '';
    $usuarioDigitado = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['senha'])) {
        $usuarioDigitado = strtolower(trim(isset($_POST['usuario']) ? (string) $_POST['usuario'] : ''));
        $local = painelConfigLocal();
        if (!painelConferirCsrf()) {
            $erro = 'A página expirou. Tente de novo.';
        } elseif ($usuarioDigitado === $local['usuario_equipe'] && hash_equals($local['senha_equipe'], (string) $_POST['senha'])) {
            session_regenerate_id(true);
            $_SESSION['painel_equipe'] = time();
            painelIr('equipe.php');
        } else {
            sleep(1); // atrasa tentativas de adivinhar a senha
            $erro = 'Usuário ou senha incorretos.';
        }
    }

    painelTopo('Área da equipe');
    ?>
<section class="login">
    <h1>Área da equipe</h1>
    <p class="suave">Acesso só para a equipe Futuremedia.</p>
    <?php if ($erro !== '') { ?>
        <div class="aviso aviso-erro"><?php echo h($erro); ?></div>
    <?php } ?>
    <form method="post" class="formulario">
        <?php echo painelCampoCsrf(); ?>
        <label>Usuário
            <input type="text" name="usuario" value="<?php echo h($usuarioDigitado); ?>" autocomplete="off" autocapitalize="none" required autofocus>
        </label>
        <label>Senha
            <input type="password" name="senha" autocomplete="off" required>
        </label>
        <button type="submit" class="botao botao-primario botao-largo">Entrar</button>
    </form>
</section>
    <?php
    painelRodape();
    exit;
}

$_SESSION['painel_equipe'] = time();

$cliente = painelClienteUnico();
if (!$cliente) {
    painelTopo('Erro');
    echo '<div class="aviso aviso-erro">Não foi possível abrir o painel. Confira o config/painel.local.php e se a pasta config/painel/ pode ser gravada.</div>';
    painelRodape();
    exit;
}

painelLimparLixeira($cliente);
painelGravarEventoAtual($cliente);

// ---------- Excluir ----------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pagina = isset($_POST['pagina']) ? max(1, (int) $_POST['pagina']) : 1;
    // Evento da página ("-" = sem evento). Tem que estar na lista do painel.
    $valorEvento = isset($_POST['evento']) ? (string) $_POST['evento'] : '';
    $evento = $valorEvento === '-' ? '' : $valorEvento;
    $voltar = 'equipe.php?evento=' . rawurlencode($valorEvento) . ($pagina > 1 ? '&pagina=' . $pagina : '');
    if (!painelConferirCsrf()) {
        painelAviso('erro', 'A página expirou. Tente de novo.');
        painelIr($voltar);
    }
    if ($evento !== '' && !painelEvento($cliente, $evento)) {
        painelAviso('erro', 'Evento não encontrado.');
        painelIr('equipe.php');
    }

    $acao = isset($_POST['acao']) ? (string) $_POST['acao'] : '';
    // Só o que já existia quando a página abriu: durante um evento, uma foto que
    // chegou depois não entra em "excluir este dia" nem em "excluir todas"
    $ate = isset($_POST['ate']) ? (int) $_POST['ate'] : 0;
    $nomes = array();

    if ($acao === 'excluir_selecionadas') {
        $marcadas = isset($_POST['fotos']) && is_array($_POST['fotos']) ? $_POST['fotos'] : array();
        foreach ($marcadas as $nome) {
            if (is_string($nome)) {
                $nomes[] = $nome;
            }
        }
    } elseif ($acao === 'excluir_dia') {
        $nomes = painelNomesAte($cliente, $evento, $ate, isset($_POST['dia']) ? (string) $_POST['dia'] : '-');
    } elseif ($acao === 'excluir_todas') {
        $nomes = painelNomesAte($cliente, $evento, $ate);
    }

    @set_time_limit(0);

    // Apagar o evento (de vez): fotos, pasta, o que dele estiver na lixeira do
    // cliente e o evento da lista. O atual não pode ser apagado.
    if ($acao === 'apagar_evento') {
        list($apagadas, $erro) = painelApagarEventoDeVez($cliente, $evento, $ate);
        painelAnotarExclusao('equipe', $acao, $apagadas, $erro === '' ? 0 : 1);
        if ($erro !== '') {
            painelAviso('erro', $erro);
            painelIr($voltar);
        }
        $novo = painelCliente($cliente['id']);
        if ($novo) {
            painelGravarEventoAtual($novo);
        }
        painelAviso('ok', 'Evento apagado' . ($apagadas ? ' (' . ($apagadas === 1 ? '1 foto excluída' : painelQuantidadeFotos($apagadas) . ' excluídas') . ')' : '') . '.');
        painelIr('equipe.php');
    }

    list($apagadas, $falhas) = painelApagarFotos($cliente, $nomes, $evento);
    // Registro simples, para saber depois quando as fotos saíram
    painelAnotarExclusao('equipe', $acao, $apagadas, $falhas);

    if ($falhas > 0) {
        painelAviso('erro', painelQuantidadeFotos($falhas) . ' não puderam ser excluídas. Confira se o PHP pode gravar na pasta das fotos.');
    }
    if ($apagadas > 0) {
        painelAviso('ok', $apagadas === 1 ? '1 foto excluída.' : number_format($apagadas, 0, ',', '.') . ' fotos excluídas.');
    } elseif ($falhas === 0) {
        painelAviso('erro', 'Nenhuma foto foi excluída.');
    }
    painelIr($voltar);
}

// ---------- Página ----------

$evento = painelEventoDaPagina($cliente, isset($_GET['evento']) ? (string) $_GET['evento'] : null);
$dadosEvento = painelEvento($cliente, $evento);
$eventoAberto = painelEventoAberto($cliente, $dadosEvento);
$fotos = painelFotos($cliente, $evento);
$agora = time();
$paramEvento = 'evento=' . rawurlencode($evento === '' ? '-' : $evento);
$paramFoto = $evento === '' ? '' : '&ev=' . rawurlencode($evento);

$porPagina = 60;
$paginas = max(1, (int) ceil(count($fotos) / $porPagina));
$pagina = isset($_GET['pagina']) ? min($paginas, max(1, (int) $_GET['pagina'])) : 1;
$daPagina = array_slice($fotos, ($pagina - 1) * $porPagina, $porPagina);

$tamanhoTotal = 0;
$dias = array();
foreach ($fotos as $foto) {
    $tamanhoTotal += $foto['tamanho'];
    if (!isset($dias[$foto['dia']])) {
        $dias[$foto['dia']] = array('quantidade' => 0, 'tamanho' => 0);
    }
    $dias[$foto['dia']]['quantidade']++;
    $dias[$foto['dia']]['tamanho'] += $foto['tamanho'];
}
$grupos = array();
foreach ($daPagina as $foto) {
    $grupos[$foto['dia']][] = $foto;
}

function equipeCampos($acao, $pagina, $agora, $extras = array()) {
    global $evento;
    $html = painelCampoCsrf()
        . '<input type="hidden" name="acao" value="' . h($acao) . '">'
        . '<input type="hidden" name="evento" value="' . h($evento === '' ? '-' : $evento) . '">'
        . '<input type="hidden" name="pagina" value="' . (int) $pagina . '">'
        . '<input type="hidden" name="ate" value="' . (int) $agora . '">';
    foreach ($extras as $nome => $valor) {
        $html .= '<input type="hidden" name="' . h($nome) . '" value="' . h($valor) . '">';
    }
    return $html;
}

function equipeLinkPagina($n) {
    global $paramEvento;
    return 'equipe.php?' . $paramEvento . ($n > 1 ? '&pagina=' . $n : '');
}

painelTopo('Área da equipe', '<nav class="menu" aria-label="Menu"><a href="equipe.php?sair=1" class="menu-sair">Sair</a></nav>');
?>
<div class="cabecalho-pagina cabecalho-fotos">
    <div>
        <h1>Área da equipe: excluir fotos</h1>
        <p class="suave">Evento: <strong><?php echo h($dadosEvento ? $dadosEvento['nome'] : 'Sem evento'); ?></strong><?php echo $eventoAberto ? ' (aberto)' : ''; ?><?php echo $fotos ? ' · ' . painelQuantidadeFotos(count($fotos)) . ' · ' . h(painelTamanhoTexto($tamanhoTotal)) : ''; ?><br>O cliente não tem acesso a esta página.</p>
    </div>
    <div class="acoes">
        <?php if ($fotos) { ?>
        <a class="botao" href="fotos_zip.php?<?php echo h($paramEvento); ?>" download>⬇ Baixar todas (.zip)</a>
        <form method="post" action="equipe.php" data-confirmar-titulo="Excluir todas as fotos deste evento?" data-confirmar="<?php echo h(painelQuantidadeFotos(count($fotos))); ?> (<?php echo h(painelTamanhoTexto($tamanhoTotal)); ?>) de &quot;<?php echo h($dadosEvento ? $dadosEvento['nome'] : 'Sem evento'); ?>&quot; saem do servidor e da galeria do cliente, e os QR Codes delas param de funcionar. Não dá para desfazer." data-confirmar-botao="Excluir todas" data-confirmar-perigo>
            <?php echo equipeCampos('excluir_todas', $pagina, $agora); ?>
            <button type="submit" class="botao botao-perigo">Excluir todas</button>
        </form>
        <?php } ?>
        <?php if ($dadosEvento && !$eventoAberto) { ?>
        <form method="post" action="equipe.php" data-confirmar-titulo="Apagar o evento &quot;<?php echo h($dadosEvento['nome']); ?>&quot;?" data-confirmar="<?php echo h($fotos ? painelQuantidadeFotos(count($fotos)) . ' saem do servidor, ' : ''); ?>a pasta é apagada e o evento sai da lista do cliente. Não dá para desfazer." data-confirmar-botao="Apagar evento" data-confirmar-perigo>
            <?php echo equipeCampos('apagar_evento', $pagina, $agora); ?>
            <button type="submit" class="botao botao-perigo">Apagar o evento</button>
        </form>
        <?php } ?>
    </div>
</div>

<?php echo painelBarraArmazenamento($cliente, 'Exclua eventos antigos para liberar espaço.'); ?>
<?php echo painelFiltroEventos($cliente, $evento, 'equipe.php'); ?>

<?php if (!$fotos) { ?>
<section class="cartao vazio-fotos">
    <div class="vazio-icone" aria-hidden="true">📷</div>
    <h2>Nenhuma foto no servidor</h2>
    <p class="suave">Quando o robô enviar fotos, elas aparecem aqui.</p>
</section>
<?php } else { ?>
<div class="aviso aviso-erro">Excluir é definitivo: a foto sai do servidor e da galeria do cliente, e o QR Code dela para de funcionar. Se precisar guardar, baixe antes.</div>
<?php } ?>

<?php foreach ($grupos as $dia => $doDia) { ?>
<section class="cartao dia-fotos">
    <div class="dia-topo">
        <h2><?php echo h(painelDiaTexto($dia)); ?> <span class="suave dia-total"><?php echo painelQuantidadeFotos($dias[$dia]['quantidade']); ?> · <?php echo h(painelTamanhoTexto($dias[$dia]['tamanho'])); ?></span></h2>
        <div class="acoes">
            <button type="button" class="botao botao-pequeno" data-marcar-dia="<?php echo h($dia); ?>">Marcar as desta página</button>
            <a class="botao botao-pequeno" href="fotos_zip.php?<?php echo h($paramEvento); ?>&dia=<?php echo h($dia); ?>" download>⬇ Baixar este dia (.zip)</a>
            <form method="post" action="equipe.php" data-confirmar-titulo="Excluir as fotos de <?php echo h(strtolower(painelDiaTexto($dia))); ?>?" data-confirmar="<?php echo h(painelQuantidadeFotos($dias[$dia]['quantidade'])); ?> saem do servidor e da galeria do cliente, e os QR Codes delas param de funcionar. Não dá para desfazer." data-confirmar-botao="Excluir este dia" data-confirmar-perigo>
                <?php echo equipeCampos('excluir_dia', $pagina, $agora, array('dia' => $dia)); ?>
                <button type="submit" class="botao botao-pequeno botao-perigo">Excluir este dia</button>
            </form>
        </div>
    </div>
    <div class="grade-fotos">
        <?php foreach ($doDia as $foto) {
            $url = 'foto.php?f=' . rawurlencode($foto['nome']) . $paramFoto; ?>
        <label class="foto foto-marcavel">
            <input type="checkbox" class="foto-marcar" name="fotos[]" value="<?php echo h($foto['nome']); ?>" form="form-selecao" data-dia="<?php echo h($dia); ?>" aria-label="Marcar a foto das <?php echo h(date('H:i', $foto['tempo'])); ?>">
            <span class="foto-imagem"><img src="<?php echo h($url . '&mini=1'); ?>" alt="" loading="lazy"></span>
            <span class="foto-legenda">
                <span class="foto-hora"><?php echo h(date('H:i', $foto['tempo'])); ?></span>
                <a href="<?php echo h($url); ?>" target="_blank" rel="noopener">Ver foto</a>
            </span>
        </label>
        <?php } ?>
    </div>
</section>
<?php } ?>

<?php if ($paginas > 1) { ?>
<nav class="paginacao" aria-label="Páginas">
    <?php if ($pagina > 1) { ?><a class="botao" href="<?php echo h(equipeLinkPagina($pagina - 1)); ?>">← Mais novas</a><?php } else { ?><span></span><?php } ?>
    <span class="suave">Página <?php echo $pagina; ?> de <?php echo $paginas; ?></span>
    <?php if ($pagina < $paginas) { ?><a class="botao" href="<?php echo h(equipeLinkPagina($pagina + 1)); ?>">Mais antigas →</a><?php } else { ?><span></span><?php } ?>
</nav>
<?php } ?>

<?php if ($fotos) { ?>
<form method="post" action="equipe.php" id="form-selecao" class="selecao-barra" hidden data-confirmar-titulo="Excluir as fotos marcadas?" data-confirmar="As fotos marcadas saem do servidor e da galeria do cliente, e os QR Codes delas param de funcionar. Não dá para desfazer." data-confirmar-botao="Excluir" data-confirmar-perigo>
    <?php echo equipeCampos('excluir_selecionadas', $pagina, $agora); ?>
    <strong class="selecao-total" aria-live="polite"></strong>
    <div class="acoes">
        <button type="button" class="botao" data-limpar-selecao>Desmarcar</button>
        <button type="submit" class="botao botao-perigo">Excluir marcadas</button>
    </div>
</form>
<script>
// Barra da seleção: aparece com alguma foto marcada e leva a quantidade para a confirmação
(function () {
    var form = document.getElementById('form-selecao');
    var marcas = Array.prototype.slice.call(document.querySelectorAll('.foto-marcar'));

    function atualizar() {
        var n = marcas.filter(function (m) { return m.checked; }).length;
        var texto = n === 1 ? '1 foto' : n + ' fotos';
        form.hidden = n === 0;
        form.querySelector('.selecao-total').textContent = texto + (n === 1 ? ' marcada' : ' marcadas');
        form.setAttribute('data-confirmar-titulo', n === 1 ? 'Excluir a foto marcada?' : 'Excluir as ' + n + ' fotos marcadas?');
        form.querySelector('[type=submit]').textContent = 'Excluir ' + texto;
    }

    marcas.forEach(function (m) { m.addEventListener('change', atualizar); });
    document.querySelectorAll('[data-marcar-dia]').forEach(function (botao) {
        botao.addEventListener('click', function () {
            var doDia = marcas.filter(function (m) { return m.getAttribute('data-dia') === botao.getAttribute('data-marcar-dia'); });
            var todas = doDia.every(function (m) { return m.checked; });
            doDia.forEach(function (m) { m.checked = !todas; });
            atualizar();
        });
    });
    form.querySelector('[data-limpar-selecao]').addEventListener('click', function () {
        marcas.forEach(function (m) { m.checked = false; });
        atualizar();
    });
    atualizar();
})();
</script>
<?php } ?>
<?php
painelRodape();
