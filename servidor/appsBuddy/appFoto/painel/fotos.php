<?php
// Galeria: as fotos que os robôs enviaram, por evento (?evento=id, "-" = sem
// evento; sem nada, o evento atual), da mais nova para a mais antiga, separadas
// por dia. Baixa uma, um dia inteiro ou todas do evento (.zip). No topo, a barra
// do espaço usado por todas as fotos.
//
// Desde 23/11/2026 o cliente também exclui fotos (marcadas, um dia, todas do
// evento) e apaga eventos encerrados. Em cada exclusão ele escolhe: "Mover para
// a lixeira" (lixeira.php, volta em até 30 dias) ou "Excluir de vez".
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'leads_lib.php';
painelIniciarSessao();

$cliente = painelClienteDaPagina();
painelLimparLixeira($cliente);
painelGravarEventoAtual($cliente);

// ---------- Excluir ----------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pagina = isset($_POST['pagina']) ? max(1, (int) $_POST['pagina']) : 1;
    // Evento da página ("-" = sem evento). Tem que estar na lista do painel.
    $valorEvento = isset($_POST['evento']) ? (string) $_POST['evento'] : '';
    $evento = $valorEvento === '-' ? '' : $valorEvento;
    $voltar = 'fotos.php?evento=' . rawurlencode($valorEvento) . ($pagina > 1 ? '&pagina=' . $pagina : '');
    if (!painelConferirCsrf()) {
        painelAviso('erro', 'A página expirou. Tente de novo.');
        painelIr($voltar);
    }
    if ($evento !== '' && !painelEvento($cliente, $evento)) {
        painelAviso('erro', 'Evento não encontrado.');
        painelIr('fotos.php');
    }

    $acao = isset($_POST['acao']) ? (string) $_POST['acao'] : '';
    // Sem escolha (ou sem JavaScript), vai para a lixeira: é o que dá para desfazer
    $lixeira = !isset($_POST['modo']) || $_POST['modo'] !== 'definitivo';
    // Só o que já existia quando a página abriu: durante um evento, uma foto que
    // chegou depois não entra em "este dia", "todas" nem "apagar o evento"
    $ate = isset($_POST['ate']) ? (int) $_POST['ate'] : 0;
    @set_time_limit(0);

    if ($acao === 'apagar_evento') {
        $dadosEvento = painelEvento($cliente, $evento);
        list($quantas, $erro) = $lixeira ? painelEventoParaLixeira($cliente, $evento, $ate) : painelApagarEventoDeVez($cliente, $evento, $ate);
        painelAnotarExclusao('cliente', $lixeira ? 'apagar_evento_lixeira' : 'apagar_evento', $quantas, $erro === '' ? 0 : 1);
        if ($erro !== '') {
            painelAviso('erro', $erro);
            painelIr($voltar);
        }
        $novo = painelCliente($cliente['id']);
        if ($novo) {
            painelGravarEventoAtual($novo);
        }
        $nome = $dadosEvento ? $dadosEvento['nome'] : '';
        if (!$lixeira) {
            painelAviso('ok', 'Evento "' . $nome . '" apagado de vez' . ($quantas ? ' (' . painelQuantidadeFotos($quantas) . ')' : '') . '.');
        } elseif ($quantas) {
            painelAviso('ok', 'Evento "' . $nome . '" apagado: ' . ($quantas === 1 ? 'a foto foi' : 'as ' . painelQuantidadeFotos($quantas) . ' foram')
                . ' para a lixeira. Dá para recuperar tudo (o evento volta junto) em Lixeira, por ' . PAINEL_LIXEIRA_DIAS . ' dias.');
        } else {
            painelAviso('ok', 'Evento "' . $nome . '" apagado.');
        }
        painelIr('fotos.php');
    }

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
    } else {
        painelIr($voltar);
    }

    list($feitas, $falhas) = $lixeira ? painelMoverParaLixeira($cliente, $nomes, $evento) : painelApagarFotos($cliente, $nomes, $evento);
    painelAnotarExclusao('cliente', $acao . ($lixeira ? '_lixeira' : ''), $feitas, $falhas);
    if ($falhas > 0) {
        painelAviso('erro', painelQuantidadeFotos($falhas) . ' não puderam ser excluídas. Tente de novo.');
    }
    if ($feitas > 0 && $lixeira) {
        painelAviso('ok', ($feitas === 1 ? '1 foto foi' : painelQuantidadeFotos($feitas) . ' foram') . ' para a lixeira. Dá para recuperar em Lixeira, por ' . PAINEL_LIXEIRA_DIAS . ' dias.');
    } elseif ($feitas > 0) {
        painelAviso('ok', ($feitas === 1 ? '1 foto excluída' : painelQuantidadeFotos($feitas) . ' excluídas') . ' de vez.');
    } elseif ($falhas === 0) {
        painelAviso('erro', 'Nenhuma foto foi excluída.');
    }
    painelIr($voltar);
}

// ---------- Página ----------

$evento = painelEventoDaPagina($cliente, isset($_GET['evento']) ? (string) $_GET['evento'] : null);
$dadosEvento = painelEvento($cliente, $evento);
$fotos = painelFotos($cliente, $evento);
$agora = time();
// Cliente que ainda não criou nenhum evento: o robô não tira fotos
$nenhumEvento = !painelEventos($cliente);
// Eventos abertos continuam recebendo fotos e só podem ser apagados após encerrar.
$eventoAberto = painelEventoAberto($cliente, $dadosEvento);
$nomeEvento = $dadosEvento ? $dadosEvento['nome'] : ($nenhumEvento ? 'nenhum ainda' : 'Sem evento');
$naLixeira = count(painelItensLixeira($cliente));
// Parâmetro do evento nos links desta página (paginação, .zip, fotos)
$paramEvento = 'evento=' . rawurlencode($evento === '' ? '-' : $evento);
$paramFoto = $evento === '' ? '' : '&ev=' . rawurlencode($evento);
// Quem se cadastrou na página do QR Code de cada foto (leads.php)
$cadastrosPorFoto = $evento === '' ? array() : leadsPorFoto($cliente, $evento);

$porPagina = 60;
$paginas = max(1, (int) ceil(count($fotos) / $porPagina));
$pagina = isset($_GET['pagina']) ? min($paginas, max(1, (int) $_GET['pagina'])) : 1;
$daPagina = array_slice($fotos, ($pagina - 1) * $porPagina, $porPagina);

// Total de fotos e de bytes de cada dia (de todas as páginas)
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

// As fotos desta página, agrupadas por dia
$grupos = array();
foreach ($daPagina as $foto) {
    $grupos[$foto['dia']][] = $foto;
}

function linkPagina($n, $paramEvento) {
    return 'fotos.php?' . $paramEvento . ($n > 1 ? '&pagina=' . $n : '');
}

// Campos de todo formulário de exclusão. "modo" começa em lixeira; o botão
// "Excluir de vez" da confirmação troca para definitivo.
function fotosCampos($acao, $extras = array()) {
    global $evento, $pagina, $agora;
    $html = painelCampoCsrf()
        . '<input type="hidden" name="acao" value="' . h($acao) . '">'
        . '<input type="hidden" name="modo" value="lixeira">'
        . '<input type="hidden" name="evento" value="' . h($evento === '' ? '-' : $evento) . '">'
        . '<input type="hidden" name="pagina" value="' . (int) $pagina . '">'
        . '<input type="hidden" name="ate" value="' . (int) $agora . '">';
    foreach ($extras as $nome => $valor) {
        $html .= '<input type="hidden" name="' . h($nome) . '" value="' . h($valor) . '">';
    }
    return $html;
}

// Atributos da confirmação com as duas saídas (lixeira ou de vez)
function fotosConfirmar($titulo, $texto, $alternativa = 'Excluir de vez') {
    return ' data-confirmar-titulo="' . h($titulo) . '" data-confirmar="' . h($texto) . '"'
        . ' data-confirmar-botao="Mover para a lixeira" data-confirmar-alternativa="' . h($alternativa) . '"';
}

$textoExcluir = ' saem da galeria e os QR Codes param de abrir. Na lixeira, dá para recuperar por ' . PAINEL_LIXEIRA_DIAS
    . ' dias. Excluir de vez não dá para desfazer.';

painelTopo('Fotos', painelMenu());
?>
<div class="cabecalho-pagina cabecalho-fotos">
    <div>
        <h1>Fotos tiradas</h1>
        <p class="suave">Evento: <strong><?php echo h($nomeEvento); ?></strong><?php echo $dadosEvento && !$eventoAberto ? ' (encerrado)' : ''; ?><?php if ($fotos) { ?> · <?php echo painelQuantidadeFotos(count($fotos)); ?> · <?php echo h(painelTamanhoTexto($tamanhoTotal)); ?><?php } ?></p>
    </div>
    <div class="acoes">
        <a class="botao" href="lixeira.php">🗑 Lixeira<?php echo $naLixeira ? ' (' . number_format($naLixeira, 0, ',', '.') . ')' : ''; ?></a>
        <?php if ($fotos) { ?>
        <a class="botao botao-primario botao-baixar" href="fotos_zip.php?<?php echo h($paramEvento); ?>" download>⬇ Baixar todas do evento (.zip)</a>
        <?php } ?>
    </div>
</div>

<?php echo painelBarraArmazenamento($cliente, 'Fale com a Futuremedia para liberar espaço.'); ?>
<?php echo painelFiltroEventos($cliente, $evento, 'fotos.php'); ?>

<?php if ($fotos || ($dadosEvento && !$eventoAberto)) { ?>
<div class="galeria-excluir">
    <p class="suave pequeno"><?php echo $fotos ? 'Para excluir só algumas, marque as fotos.' : 'Este evento não tem fotos.'; ?></p>
    <div class="acoes">
        <?php if ($fotos) { ?>
        <form method="post" action="fotos.php"<?php echo fotosConfirmar('Excluir todas as fotos deste evento?', painelQuantidadeFotos(count($fotos)) . ' (' . painelTamanhoTexto($tamanhoTotal) . ') de "' . $nomeEvento . '"' . $textoExcluir); ?>>
            <?php echo fotosCampos('excluir_todas'); ?>
            <button type="submit" class="botao botao-pequeno botao-perigo">Excluir todas do evento</button>
        </form>
        <?php } ?>
        <?php if ($dadosEvento && !$eventoAberto) { ?>
        <form method="post" action="fotos.php"<?php echo fotosConfirmar('Apagar o evento "' . $dadosEvento['nome'] . '"?', ($fotos ? 'As ' . painelQuantidadeFotos(count($fotos)) . ' saem da galeria (os QR Codes param de abrir) e o' : 'O')
            . ' evento sai da lista. Na lixeira, dá para recuperar tudo, e o evento volta junto, por ' . PAINEL_LIXEIRA_DIAS . ' dias. Apagar de vez não dá para desfazer.', 'Apagar de vez'); ?>>
            <?php echo fotosCampos('apagar_evento'); ?>
            <button type="submit" class="botao botao-pequeno botao-perigo">Apagar o evento</button>
        </form>
        <?php } ?>
    </div>
</div>
<?php } ?>

<?php if (!$fotos && $nenhumEvento) { ?>
<section class="cartao vazio-fotos">
    <div class="vazio-icone" aria-hidden="true">📷</div>
    <h2>Nenhum evento ainda</h2>
    <p class="suave">O robô só tira fotos com um evento. Crie o primeiro em <a href="cliente.php#evento">Configurar o app</a>, no cartão "Evento atual".</p>
</section>
<?php } elseif (!$fotos) { ?>
<section class="cartao vazio-fotos">
    <div class="vazio-icone" aria-hidden="true">📷</div>
    <h2>Nenhuma foto ainda</h2>
    <p class="suave">Quando alguém tirar uma foto no robô e ela for enviada, ela aparece aqui.</p>
</section>
<?php } ?>

<?php foreach ($grupos as $dia => $doDia) { ?>
<section class="cartao dia-fotos">
    <div class="dia-topo">
        <h2><?php echo h(painelDiaTexto($dia)); ?> <span class="suave dia-total"><?php echo painelQuantidadeFotos($dias[$dia]['quantidade']); ?> · <?php echo h(painelTamanhoTexto($dias[$dia]['tamanho'])); ?></span></h2>
        <div class="acoes">
            <button type="button" class="botao botao-pequeno" data-marcar-dia="<?php echo h($dia); ?>">Marcar o dia</button>
            <a class="botao botao-pequeno" href="fotos_zip.php?<?php echo h($paramEvento); ?>&dia=<?php echo h($dia); ?>" download>⬇ Baixar este dia (.zip)</a>
            <form method="post" action="fotos.php"<?php echo fotosConfirmar('Excluir as fotos de ' . strtolower(painelDiaTexto($dia)) . '?', painelQuantidadeFotos($dias[$dia]['quantidade']) . ' (' . painelTamanhoTexto($dias[$dia]['tamanho']) . ')' . $textoExcluir); ?>>
                <?php echo fotosCampos('excluir_dia', array('dia' => $dia)); ?>
                <button type="submit" class="botao botao-pequeno botao-perigo">Excluir este dia</button>
            </form>
        </div>
    </div>
    <div class="grade-fotos">
        <?php foreach ($doDia as $foto) {
            $url = 'foto.php?f=' . rawurlencode($foto['nome']) . $paramFoto;
            $cadastros = isset($cadastrosPorFoto[$foto['nome']]) ? $cadastrosPorFoto[$foto['nome']] : array(); ?>
        <div class="foto-item">
            <input type="checkbox" class="foto-marcar" name="fotos[]" value="<?php echo h($foto['nome']); ?>" form="form-selecao" data-dia="<?php echo h($dia); ?>" aria-label="Marcar a foto das <?php echo h(date('H:i', $foto['tempo'])); ?>">
            <a class="foto" href="<?php echo h($url); ?>" data-baixar="<?php echo h($url . '&baixar=1'); ?>" data-legenda="<?php echo h(date('d/m/Y', $foto['tempo']) . ' às ' . date('H:i', $foto['tempo'])); ?>"<?php if ($cadastros) { ?> data-leads="<?php echo h(implode(', ', $cadastros)); ?>" data-leads-link="<?php echo h('leads.php?evento=' . rawurlencode($evento) . '&foto=' . rawurlencode($foto['nome'])); ?>"<?php } ?> target="_blank">
                <span class="foto-imagem"><img src="<?php echo h($url . '&mini=1'); ?>" alt="Foto das <?php echo h(date('H:i', $foto['tempo'])); ?>" loading="lazy"><?php if ($cadastros) { ?><span class="foto-lead" title="Cadastro: <?php echo h(implode(', ', $cadastros)); ?>"><span aria-hidden="true">👤 <?php echo count($cadastros); ?></span><span class="oculto"><?php echo count($cadastros) === 1 ? '1 cadastro' : count($cadastros) . ' cadastros'; ?></span></span><?php } ?></span>
                <span class="foto-hora"><?php echo h(date('H:i', $foto['tempo'])); ?></span>
            </a>
        </div>
        <?php } ?>
    </div>
</section>
<?php } ?>

<?php if ($paginas > 1) { ?>
<nav class="paginacao" aria-label="Páginas">
    <?php if ($pagina > 1) { ?><a class="botao" href="<?php echo h(linkPagina($pagina - 1, $paramEvento)); ?>">← Mais novas</a><?php } else { ?><span></span><?php } ?>
    <span class="suave">Página <?php echo $pagina; ?> de <?php echo $paginas; ?></span>
    <?php if ($pagina < $paginas) { ?><a class="botao" href="<?php echo h(linkPagina($pagina + 1, $paramEvento)); ?>">Mais antigas →</a><?php } else { ?><span></span><?php } ?>
</nav>
<?php } ?>

<?php if ($fotos) { ?>
<form method="post" action="fotos.php" id="form-selecao" class="selecao-barra" hidden<?php echo fotosConfirmar('Excluir as fotos marcadas?', 'As fotos marcadas' . $textoExcluir); ?>>
    <?php echo fotosCampos('excluir_selecionadas'); ?>
    <strong class="selecao-total" aria-live="polite"></strong>
    <div class="acoes">
        <button type="button" class="botao" data-limpar-selecao>Desmarcar</button>
        <button type="submit" class="botao botao-perigo">Excluir marcadas</button>
    </div>
</form>
<?php } ?>

<dialog class="visor" id="visor" aria-label="Foto">
    <img class="visor-imagem" alt="">
    <div class="visor-barra">
        <span class="visor-legenda"></span>
        <div class="visor-botoes">
            <button type="button" class="botao" data-visor="anterior" aria-label="Foto anterior">←</button>
            <button type="button" class="botao" data-visor="proxima" aria-label="Próxima foto">→</button>
            <a class="botao visor-leads" href="#" hidden>👤 Ver cadastro</a>
            <a class="botao botao-primario visor-baixar" href="#" download>⬇ Baixar</a>
            <button type="button" class="botao" data-visor="fechar">Fechar</button>
        </div>
    </div>
</dialog>

<script>
// Visor: abre a foto grande na própria página, com anterior/próxima e baixar
(function () {
    var visor = document.getElementById('visor');
    var fotos = Array.prototype.slice.call(document.querySelectorAll('.grade-fotos .foto'));
    if (!visor.showModal || !fotos.length) return;
    var imagem = visor.querySelector('.visor-imagem');
    var atual = 0;

    function mostrar(i) {
        atual = (i + fotos.length) % fotos.length;
        var foto = fotos[atual];
        imagem.src = foto.getAttribute('href');
        imagem.alt = foto.querySelector('img').alt;
        var cadastro = foto.getAttribute('data-leads');
        visor.querySelector('.visor-legenda').textContent = foto.getAttribute('data-legenda') + '  ·  ' + (atual + 1) + ' de ' + fotos.length + (cadastro ? '  ·  Cadastro: ' + cadastro : '');
        visor.querySelector('.visor-baixar').setAttribute('href', foto.getAttribute('data-baixar'));
        var leads = visor.querySelector('.visor-leads');
        leads.hidden = !cadastro;
        leads.setAttribute('href', foto.getAttribute('data-leads-link') || '#');
    }

    fotos.forEach(function (foto, i) {
        foto.addEventListener('click', function (e) {
            e.preventDefault();
            mostrar(i);
            visor.showModal();
        });
    });
    visor.addEventListener('click', function (e) {
        var botao = e.target.closest('[data-visor]');
        var acao = botao ? botao.getAttribute('data-visor') : (e.target === visor ? 'fechar' : '');
        if (acao === 'anterior') mostrar(atual - 1);
        if (acao === 'proxima') mostrar(atual + 1);
        if (acao === 'fechar') visor.close();
    });
    document.addEventListener('keydown', function (e) {
        if (!visor.open) return;
        if (e.key === 'ArrowLeft') mostrar(atual - 1);
        if (e.key === 'ArrowRight') mostrar(atual + 1);
    });
    visor.addEventListener('close', function () { imagem.removeAttribute('src'); });
})();

// Barra da seleção: aparece com alguma foto marcada e leva a quantidade para a confirmação
(function () {
    var form = document.getElementById('form-selecao');
    if (!form) return;
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
<?php
painelRodape();
