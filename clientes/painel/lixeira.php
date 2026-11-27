<?php
// Lixeira do cliente: fotos excluídas com "Mover para a lixeira" nos últimos
// PAINEL_LIXEIRA_DIAS dias, por dia da exclusão e por evento. Restaurar volta a
// foto para o evento dela (e para a galeria e o QR Code; um evento apagado volta
// junto). Excluir de vez e esvaziar apagam permanentemente.
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib.php';
painelIniciarSessao();

$cliente = painelClienteDaPagina();
painelLimparLixeira($cliente);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pagina = isset($_POST['pagina']) ? max(1, (int) $_POST['pagina']) : 1;
    $voltar = 'lixeira.php' . ($pagina > 1 ? '?pagina=' . $pagina : '');
    if (!painelConferirCsrf()) {
        painelAviso('erro', 'A página expirou. Tente de novo.');
        painelIr($voltar);
    }
    $acao = isset($_POST['acao']) ? (string) $_POST['acao'] : '';
    if ($acao === 'restaurar_tudo' || $acao === 'esvaziar') {
        $chaves = array();
        foreach (painelItensLixeira($cliente) as $item) {
            $chaves[] = $item['chave'];
        }
    } else {
        $chaves = isset($_POST['itens']) && is_array($_POST['itens']) ? $_POST['itens'] : array();
    }
    @set_time_limit(0);

    if ($acao === 'restaurar_marcadas' || $acao === 'restaurar_tudo') {
        list($feitas, $falhas) = painelRestaurarDaLixeira($cliente, $chaves);
        painelAnotarExclusao('cliente', $acao, $feitas, $falhas);
        if ($feitas > 0) {
            painelAviso('ok', ($feitas === 1 ? '1 foto voltou' : painelQuantidadeFotos($feitas) . ' voltaram') . ' para a galeria. O QR Code volta a abrir.');
        }
        if ($falhas > 0) {
            painelAviso('erro', painelQuantidadeFotos($falhas) . ' não puderam voltar (o evento delas não existe mais, ou já há uma foto com o mesmo nome).');
        }
    } elseif ($acao === 'excluir_marcadas' || $acao === 'esvaziar') {
        list($feitas, $falhas) = painelExcluirDaLixeira($cliente, $chaves);
        painelAnotarExclusao('cliente', 'lixeira_' . $acao, $feitas, $falhas);
        if ($feitas > 0) {
            painelAviso('ok', ($feitas === 1 ? '1 foto excluída' : painelQuantidadeFotos($feitas) . ' excluídas') . ' de vez.');
        }
        if ($falhas > 0) {
            painelAviso('erro', painelQuantidadeFotos($falhas) . ' não puderam ser excluídas. Tente de novo.');
        }
    }
    if (empty($feitas) && empty($falhas)) {
        painelAviso('erro', 'Nenhuma foto foi marcada.');
    }
    // Evento apagado que voltou (ou saiu de vez) muda o "encerrado por último"
    $novo = painelCliente($cliente['id']);
    if ($novo) {
        painelRemoverEventosApagados($novo);
        painelGravarEventoAtual(painelCliente($cliente['id']) ?: $novo);
    }
    painelIr($voltar);
}

$itens = painelItensLixeira($cliente);
$tamanhoTotal = 0;
foreach ($itens as $item) {
    $tamanhoTotal += $item['tamanho'];
}
$porPagina = 120;
$paginas = max(1, (int) ceil(count($itens) / $porPagina));
$pagina = isset($_GET['pagina']) ? min($paginas, max(1, (int) $_GET['pagina'])) : 1;

// Desta página: por dia da exclusão e, dentro dele, por evento
$grupos = array();
foreach (array_slice($itens, ($pagina - 1) * $porPagina, $porPagina) as $item) {
    $grupos[$item['dia']][$item['evento']][] = $item;
}

function lixeiraNomeEvento($cliente, $evento) {
    if ($evento === '') {
        return 'Sem evento';
    }
    $dados = painelEvento($cliente, $evento, true);
    if (!$dados) {
        return $evento . ' (evento apagado de vez)';
    }
    return $dados['nome'] . (!empty($dados['apagado_em']) ? ' (evento apagado, volta se restaurar)' : '');
}

function lixeiraDiaTexto($dia) {
    $texto = painelDiaTexto($dia);
    return $texto === 'Hoje' || $texto === 'Ontem' ? 'Excluídas ' . strtolower($texto) : 'Excluídas em ' . $texto;
}

function lixeiraCampos($acao) {
    global $pagina;
    return painelCampoCsrf() . '<input type="hidden" name="acao" value="' . h($acao) . '"><input type="hidden" name="pagina" value="' . (int) $pagina . '">';
}

painelTopo('Lixeira', painelMenu());
?>
<div class="cabecalho-pagina cabecalho-fotos">
    <div>
        <h1>Lixeira</h1>
        <p class="suave"><?php echo $itens ? painelQuantidadeFotos(count($itens)) . ' · ' . h(painelTamanhoTexto($tamanhoTotal)) . '. ' : ''; ?>As fotos ficam aqui por <?php echo PAINEL_LIXEIRA_DIAS; ?> dias depois de excluídas e depois somem sozinhas. Enquanto estão aqui, não aparecem na galeria e o QR Code delas não abre.</p>
    </div>
    <div class="acoes">
        <a class="botao" href="fotos.php">← Voltar para as fotos</a>
        <?php if ($itens) { ?>
        <form method="post" action="lixeira.php" data-confirmar-titulo="Restaurar todas as fotos?" data-confirmar="<?php echo h(painelQuantidadeFotos(count($itens))); ?> voltam para a galeria, cada uma no seu evento, e os QR Codes voltam a abrir." data-confirmar-botao="Restaurar todas">
            <?php echo lixeiraCampos('restaurar_tudo'); ?>
            <button type="submit" class="botao">Restaurar todas</button>
        </form>
        <form method="post" action="lixeira.php" data-confirmar-titulo="Esvaziar a lixeira?" data-confirmar="<?php echo h(painelQuantidadeFotos(count($itens)) . ' (' . painelTamanhoTexto($tamanhoTotal) . ')' . (count($itens) === 1 ? ' é apagada' : ' são apagadas')); ?> PERMANENTEMENTE. Não dá para desfazer." data-confirmar-botao="Esvaziar a lixeira" data-confirmar-perigo>
            <?php echo lixeiraCampos('esvaziar'); ?>
            <button type="submit" class="botao botao-perigo">Esvaziar a lixeira</button>
        </form>
        <?php } ?>
    </div>
</div>

<?php if (!$itens) { ?>
<section class="cartao vazio-fotos">
    <div class="vazio-icone" aria-hidden="true">🗑</div>
    <h2>A lixeira está vazia</h2>
    <p class="suave">Quando você excluir fotos e escolher "Mover para a lixeira", elas ficam aqui por <?php echo PAINEL_LIXEIRA_DIAS; ?> dias.</p>
</section>
<?php } ?>

<?php foreach ($grupos as $dia => $porEvento) {
    $somem = strtotime($dia . ' +' . PAINEL_LIXEIRA_DIAS . ' days');
    ?>
<section class="cartao dia-fotos">
    <div class="dia-topo">
        <h2><?php echo h(lixeiraDiaTexto($dia)); ?> <span class="suave dia-total">somem em <?php echo h(date('d/m/Y', $somem)); ?></span></h2>
        <button type="button" class="botao botao-pequeno" data-marcar-dia="<?php echo h($dia); ?>">Marcar o dia</button>
    </div>
    <?php foreach ($porEvento as $eventoItem => $doEvento) { ?>
    <h3 class="lixeira-evento"><?php echo h(lixeiraNomeEvento($cliente, $eventoItem)); ?> <span class="suave dia-total"><?php echo painelQuantidadeFotos(count($doEvento)); ?></span></h3>
    <div class="grade-fotos">
        <?php foreach ($doEvento as $item) {
            $url = 'foto.php?f=' . rawurlencode($item['nome']) . '&ev=' . rawurlencode($item['evento'] === '' ? PAINEL_LIXEIRA_SEM_EVENTO : $item['evento']) . '&lixeira=' . rawurlencode($dia); ?>
        <label class="foto foto-marcavel">
            <input type="checkbox" class="foto-marcar" name="itens[]" value="<?php echo h($item['chave']); ?>" form="form-selecao" data-dia="<?php echo h($dia); ?>" aria-label="Marcar a foto de <?php echo h(date('d/m/Y H:i', $item['tempo'])); ?>">
            <span class="foto-imagem"><img src="<?php echo h($url . '&mini=1'); ?>" alt="" loading="lazy"></span>
            <span class="foto-legenda">
                <span class="foto-hora"><?php echo h(date('d/m H:i', $item['tempo'])); ?></span>
                <a href="<?php echo h($url); ?>" target="_blank" rel="noopener">Ver foto</a>
            </span>
        </label>
        <?php } ?>
    </div>
    <?php } ?>
</section>
<?php } ?>

<?php if ($paginas > 1) { ?>
<nav class="paginacao" aria-label="Páginas">
    <?php if ($pagina > 1) { ?><a class="botao" href="lixeira.php?pagina=<?php echo $pagina - 1; ?>">← Mais novas</a><?php } else { ?><span></span><?php } ?>
    <span class="suave">Página <?php echo $pagina; ?> de <?php echo $paginas; ?></span>
    <?php if ($pagina < $paginas) { ?><a class="botao" href="lixeira.php?pagina=<?php echo $pagina + 1; ?>">Mais antigas →</a><?php } else { ?><span></span><?php } ?>
</nav>
<?php } ?>

<?php if ($itens) { ?>
<form method="post" action="lixeira.php" id="form-selecao" class="selecao-barra selecao-barra-lixeira" hidden>
    <?php echo lixeiraCampos('restaurar_marcadas'); ?>
    <strong class="selecao-total" aria-live="polite"></strong>
    <div class="acoes">
        <button type="button" class="botao" data-limpar-selecao>Desmarcar</button>
        <button type="button" class="botao botao-perigo" data-excluir-marcadas>Excluir de vez</button>
        <button type="submit" class="botao botao-primario">Restaurar</button>
    </div>
</form>
<script>
// Seleção: "Restaurar" envia direto; "Excluir de vez" pergunta antes
(function () {
    var form = document.getElementById('form-selecao');
    var acao = form.querySelector('input[name=acao]');
    var marcas = Array.prototype.slice.call(document.querySelectorAll('.foto-marcar'));
    var quantas = function () { return marcas.filter(function (m) { return m.checked; }).length; };

    function atualizar() {
        var n = quantas();
        form.hidden = n === 0;
        form.querySelector('.selecao-total').textContent = (n === 1 ? '1 foto marcada' : n + ' fotos marcadas');
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
    form.querySelector('[data-excluir-marcadas]').addEventListener('click', function () {
        var n = quantas();
        painelConfirmar({
            titulo: n === 1 ? 'Excluir de vez a foto marcada?' : 'Excluir de vez as ' + n + ' fotos marcadas?',
            texto: n === 1 ? 'A foto é apagada PERMANENTEMENTE. Não dá para desfazer.' : 'As ' + n + ' fotos são apagadas PERMANENTEMENTE. Não dá para desfazer.',
            botao: 'Excluir de vez',
            perigo: true
        }, function () {
            acao.value = 'excluir_marcadas';
            form.submit();
        });
    });
    atualizar();
})();
</script>
<?php } ?>
<?php
painelRodape();
