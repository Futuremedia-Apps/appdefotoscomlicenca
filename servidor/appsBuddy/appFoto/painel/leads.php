<?php
// Leads: os cadastros feitos na página do QR Code (qr.php), por evento, com a
// foto de cada pessoa. Busca, excluir, baixar em Excel ou CSV
// (leads_baixar.php) e o atalho para o formulário (leads_formulario.php).
//   ?evento=<id>   evento (sem nada: o atual, ou o encerrado por último)
//   &foto=<nome>   só os cadastros de uma foto (link "Ver cadastro" da galeria)
//   &q=<texto>     busca em todas as respostas
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'leads_lib.php';
painelIniciarSessao();

$cliente = painelClienteDaPagina();
painelGravarEventoAtual($cliente);
$eventos = leadsEventos($cliente);

function leadsEventoValido($eventos, $id) {
    foreach ($eventos as $evento) {
        if ($evento['id'] === $id) {
            return $evento;
        }
    }
    return null;
}

// ---------- Excluir ----------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $evento = isset($_POST['evento']) ? (string) $_POST['evento'] : '';
    $voltar = 'leads.php?evento=' . rawurlencode($evento);
    if (!painelConferirCsrf()) {
        painelAviso('erro', 'A página expirou. Tente de novo.');
        painelIr($voltar);
    }
    if (!leadsEventoValido($eventos, $evento)) {
        painelAviso('erro', 'Evento não encontrado.');
        painelIr('leads.php');
    }
    $ids = array();
    foreach (isset($_POST['leads']) && is_array($_POST['leads']) ? $_POST['leads'] : array() as $id) {
        if (is_string($id) && preg_match('/^[a-f0-9]{16}$/', $id)) {
            $ids[] = $id;
        }
    }
    if (isset($_POST['acao']) && $_POST['acao'] === 'excluir' && $ids) {
        $sairam = leadsExcluir($cliente, $evento, $ids);
        painelAnotarExclusao('cliente', 'excluir_leads', $sairam, count($ids) - $sairam);
        if ($sairam > 0) {
            painelAviso('ok', ($sairam === 1 ? '1 lead excluído' : number_format($sairam, 0, ',', '.') . ' leads excluídos') . ' PERMANENTEMENTE.');
        } else {
            painelAviso('erro', 'Nenhum lead foi excluído. Tente de novo.');
        }
    } else {
        painelAviso('erro', 'Nenhum lead foi marcado.');
    }
    painelIr($voltar);
}

// ---------- Página ----------

// Evento pedido; sem pedido (ou desconhecido), o mesmo da galeria (o atual, ou o
// encerrado por último), e se ele não está aqui, o primeiro da lista
$pedido = isset($_GET['evento']) ? (string) $_GET['evento'] : '';
$dadosEvento = leadsEventoValido($eventos, $pedido);
if (!$dadosEvento) {
    $dadosEvento = leadsEventoValido($eventos, painelEventoDaPagina($cliente, null));
}
if (!$dadosEvento && $eventos) {
    $dadosEvento = $eventos[0];
}
$evento = $dadosEvento ? $dadosEvento['id'] : '';

$registroEvento = painelEvento($cliente, $evento, true);
$config = $registroEvento ? leadsConfig($cliente, $registroEvento) : null;
$modos = leadsModos();
$todos = $evento !== '' ? leadsDoEvento($cliente, $evento) : array();
$colunas = leadsColunas($config, $todos);

// Só de uma foto (vindo da galeria)
$soFoto = isset($_GET['foto']) && painelNomeFotoValido((string) $_GET['foto']) ? (string) $_GET['foto'] : '';
// Busca sem acento e sem maiúscula, em todas as respostas
function leadsSemAcento($texto) {
    $texto = strtr($texto, array(
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'Á' => 'a', 'À' => 'a', 'Â' => 'a', 'Ã' => 'a', 'Ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'É' => 'e', 'È' => 'e', 'Ê' => 'e', 'Ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'Í' => 'i', 'Ì' => 'i', 'Î' => 'i', 'Ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'Ó' => 'o', 'Ò' => 'o', 'Ô' => 'o', 'Õ' => 'o', 'Ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'Ú' => 'u', 'Ù' => 'u', 'Û' => 'u', 'Ü' => 'u',
        'ç' => 'c', 'Ç' => 'c', 'ñ' => 'n', 'Ñ' => 'n'
    ));
    return strtolower($texto);
}
$busca = isset($_GET['q']) ? leadsLimparTexto($_GET['q'], 80) : '';
$leads = array();
foreach ($todos as $lead) {
    if ($soFoto !== '' && !in_array($soFoto, $lead['fotos'], true)) {
        continue;
    }
    if ($busca !== '') {
        $texto = '';
        foreach ($colunas as $coluna) {
            $texto .= ' ' . leadsValorTexto($lead, $coluna);
        }
        if (strpos(leadsSemAcento($texto), leadsSemAcento($busca)) === false) {
            continue;
        }
    }
    $leads[] = $lead;
}

$porPagina = 100;
$paginas = max(1, (int) ceil(count($leads) / $porPagina));
$pagina = isset($_GET['pagina']) ? min($paginas, max(1, (int) $_GET['pagina'])) : 1;
$daPagina = array_slice($leads, ($pagina - 1) * $porPagina, $porPagina);
$paramEvento = 'evento=' . rawurlencode($evento);
$filtros = $paramEvento . ($soFoto !== '' ? '&foto=' . rawurlencode($soFoto) : '') . ($busca !== '' ? '&q=' . rawurlencode($busca) : '');
$pastaFotos = $evento !== '' ? painelPastaFotos($cliente, $evento) : '';
$comLeadsEmOutros = 0;
foreach ($eventos as $item) {
    $comLeadsEmOutros += $item['leads'] > 0 ? 1 : 0;
}

function leadsQuantidade($n) {
    return $n === 1 ? '1 lead' : number_format($n, 0, ',', '.') . ' leads';
}

// Resumo do formulário: "Nome, E-mail e Telefone (opcional)"
$nomesCampos = array();
foreach ($config ? $config['campos'] : array() as $campo) {
    $nomesCampos[] = $campo['rotulo'] . ($campo['obrigatorio'] ? '' : ' (opcional)');
}
$ultimoCampo = array_pop($nomesCampos);
$resumoCampos = $nomesCampos ? implode(', ', $nomesCampos) . ' e ' . $ultimoCampo : (string) $ultimoCampo;

painelTopo('Leads', painelMenu());
?>
<div class="cabecalho-pagina cabecalho-fotos">
    <div>
        <h1>Leads</h1>
        <p class="suave"><?php if ($dadosEvento) { ?>Evento: <strong><?php echo h($dadosEvento['nome']); ?></strong> · <?php echo leadsQuantidade(count($todos)); ?><?php } else { ?>Cadastros feitos na página do QR Code.<?php } ?></p>
    </div>
    <?php if ($todos) { ?>
    <div class="acoes">
        <a class="botao botao-primario botao-baixar" href="leads_baixar.php?<?php echo h($paramEvento . '&formato=xlsx'); ?>" download>⬇ Baixar em Excel (.xlsx)</a>
        <a class="botao botao-baixar" href="leads_baixar.php?<?php echo h($paramEvento . '&formato=csv'); ?>" download>⬇ CSV</a>
    </div>
    <?php } ?>
</div>

<?php if ($eventos && (count($eventos) > 1 || $comLeadsEmOutros > 1)) { ?>
<nav class="eventos-filtro" aria-label="Eventos">
    <?php foreach ($eventos as $item) { ?>
    <a href="leads.php?evento=<?php echo h(rawurlencode($item['id'])); ?>" class="evento-chip"<?php echo $item['id'] === $evento ? ' aria-current="page"' : ''; ?>>
        <?php echo h($item['nome']); ?> <span class="evento-chip-total"><?php echo number_format($item['leads'], 0, ',', '.'); ?></span>
        <?php echo $item['atual'] ? '<span class="evento-chip-atual">atual</span>' : ''; ?>
        <?php echo $item['apagado'] ? '<span class="evento-chip-atual">apagado</span>' : ''; ?>
    </a>
    <?php } ?>
</nav>
<?php } ?>

<?php if ($config && !$dadosEvento['apagado']) { ?>
<section class="cartao leads-resumo<?php echo $config['modo'] === 'desligado' ? ' leads-resumo-desligado' : ''; ?>">
    <div>
        <h2>Cadastro no QR Code: <?php echo h($modos[$config['modo']]['nome']); ?></h2>
        <p class="suave"><?php echo h($modos[$config['modo']]['explica']); ?><?php if ($config['modo'] !== 'desligado') { ?> Campos: <?php echo h($resumoCampos); ?>.<?php } ?></p>
    </div>
    <a class="botao" href="leads_formulario.php?<?php echo h($paramEvento); ?>">✎ Editar o formulário</a>
</section>
<?php } ?>

<?php if (!$eventos) { ?>
<section class="cartao vazio-fotos">
    <div class="vazio-icone" aria-hidden="true">📋</div>
    <h2>Nenhum evento ainda</h2>
    <p class="suave">Os leads ficam separados por evento. Crie o primeiro em <a href="cliente.php#evento">Configurar o app</a>, na seção "Eventos".</p>
</section>
<?php } elseif (!$todos) { ?>
<section class="cartao vazio-fotos">
    <div class="vazio-icone" aria-hidden="true">📋</div>
    <h2>Nenhum lead neste evento ainda</h2>
    <p class="suave"><?php echo $config && $config['modo'] === 'desligado'
        ? 'O cadastro está desligado: o QR Code abre a foto direto. Para receber leads, ligue o cadastro em "Editar o formulário".'
        : 'Quando alguém escanear o QR Code de uma foto e preencher o cadastro, ele aparece aqui, junto com a foto.'; ?></p>
</section>
<?php } else { ?>
<section class="cartao leads-lista">
    <form class="leads-busca" method="get" action="leads.php" role="search">
        <input type="hidden" name="evento" value="<?php echo h($evento); ?>">
        <?php if ($soFoto !== '') { ?><input type="hidden" name="foto" value="<?php echo h($soFoto); ?>"><?php } ?>
        <input type="search" name="q" value="<?php echo h($busca); ?>" placeholder="Buscar por nome, e-mail, telefone…" aria-label="Buscar nos leads">
        <button type="submit" class="botao">Buscar</button>
        <?php if ($busca !== '' || $soFoto !== '') { ?><a class="botao" href="leads.php?<?php echo h($paramEvento); ?>">Ver todos</a><?php } ?>
    </form>
    <?php if ($soFoto !== '') { ?>
    <p class="suave pequeno">Mostrando só os cadastros de uma foto.</p>
    <?php } ?>
    <?php if (!$leads) { ?>
    <p class="suave">Nenhum lead encontrado<?php echo $busca !== '' ? ' para "' . h($busca) . '"' : ''; ?>.</p>
    <?php } else { ?>
    <div class="tabela-rolagem">
        <table class="tabela leads-tabela">
            <thead>
                <tr>
                    <th class="leads-marcar"><input type="checkbox" id="marcar-todos" aria-label="Marcar todos desta página"></th>
                    <th>Foto</th>
                    <th>Quando</th>
                    <?php foreach ($colunas as $coluna) { ?><th><?php echo h($coluna['rotulo']); ?></th><?php } ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($daPagina as $lead) {
                    $tempo = strtotime($lead['em']); ?>
                <tr>
                    <td class="leads-marcar"><input type="checkbox" class="lead-marcar" name="leads[]" value="<?php echo h($lead['id']); ?>" form="form-selecao" aria-label="Marcar <?php echo h(leadsNomeExibido($lead)); ?>"></td>
                    <td class="leads-fotos">
                        <?php foreach (array_slice($lead['fotos'], 0, 2) as $foto) {
                            if (painelNomeFotoValido($foto) && is_file($pastaFotos . DIRECTORY_SEPARATOR . $foto)) {
                                $url = 'foto.php?f=' . rawurlencode($foto) . '&ev=' . rawurlencode($evento); ?>
                        <a href="<?php echo h($url); ?>" target="_blank" rel="noopener" title="Abrir a foto"><img src="<?php echo h($url . '&mini=1'); ?>" alt="Foto de <?php echo h(leadsNomeExibido($lead)); ?>" loading="lazy"></a>
                        <?php } else { ?>
                        <span class="leads-sem-foto" title="A foto foi excluída">Foto excluída</span>
                        <?php }
                        } ?>
                        <?php if (count($lead['fotos']) > 2) { ?><span class="leads-mais">+<?php echo count($lead['fotos']) - 2; ?></span><?php } ?>
                    </td>
                    <td class="leads-quando"><?php echo $tempo ? h(date('d/m/Y', $tempo)) . '<br><span class="suave">' . h(date('H:i', $tempo)) . '</span>' : '-'; ?></td>
                    <?php foreach ($colunas as $coluna) {
                        $texto = leadsValorTexto($lead, $coluna); ?>
                    <td><?php if ($texto === '') { ?><span class="suave">—</span><?php } elseif ($coluna['tipo'] === 'email') { ?><a href="mailto:<?php echo h($texto); ?>"><?php echo h($texto); ?></a><?php } else { echo h($texto); } ?></td>
                    <?php } ?>
                </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
    <?php } ?>
</section>

<?php if ($paginas > 1) { ?>
<nav class="paginacao" aria-label="Páginas">
    <?php if ($pagina > 1) { ?><a class="botao" href="leads.php?<?php echo h($filtros . '&pagina=' . ($pagina - 1)); ?>">← Mais novos</a><?php } else { ?><span></span><?php } ?>
    <span class="suave">Página <?php echo $pagina; ?> de <?php echo $paginas; ?></span>
    <?php if ($pagina < $paginas) { ?><a class="botao" href="leads.php?<?php echo h($filtros . '&pagina=' . ($pagina + 1)); ?>">Mais antigos →</a><?php } else { ?><span></span><?php } ?>
</nav>
<?php } ?>

<?php if ($comLeadsEmOutros > 1) { ?>
<p class="suave pequeno leads-todos">Precisa de tudo junto? <a href="leads_baixar.php?evento=todos&amp;formato=xlsx" download>Baixar os leads de todos os eventos (.xlsx)</a></p>
<?php } ?>

<form method="post" action="leads.php" id="form-selecao" class="selecao-barra" hidden data-confirmar-titulo="Excluir os leads marcados?" data-confirmar="Os leads marcados são apagados PERMANENTEMENTE. Não dá para desfazer. As fotos continuam na galeria." data-confirmar-botao="Excluir" data-confirmar-perigo>
    <?php echo painelCampoCsrf(); ?>
    <input type="hidden" name="acao" value="excluir">
    <input type="hidden" name="evento" value="<?php echo h($evento); ?>">
    <strong class="selecao-total" aria-live="polite"></strong>
    <div class="acoes">
        <button type="button" class="botao" data-limpar-selecao>Desmarcar</button>
        <button type="submit" class="botao botao-perigo">Excluir marcados</button>
    </div>
</form>
<script>
// Barra da seleção: aparece com algum lead marcado
(function () {
    var form = document.getElementById('form-selecao');
    var todos = document.getElementById('marcar-todos');
    var marcas = Array.prototype.slice.call(document.querySelectorAll('.lead-marcar'));
    if (!form || !marcas.length) return;

    function atualizar() {
        var n = marcas.filter(function (m) { return m.checked; }).length;
        var texto = n === 1 ? '1 lead' : n + ' leads';
        form.hidden = n === 0;
        form.querySelector('.selecao-total').textContent = texto + (n === 1 ? ' marcado' : ' marcados');
        form.setAttribute('data-confirmar-titulo', n === 1 ? 'Excluir o lead marcado?' : 'Excluir os ' + n + ' leads marcados?');
        form.setAttribute('data-confirmar', (n === 1 ? 'O lead marcado é apagado' : 'Os ' + n + ' leads marcados são apagados') + ' PERMANENTEMENTE. Não dá para desfazer. As fotos continuam na galeria.');
        form.querySelector('[type=submit]').textContent = 'Excluir ' + texto;
        if (todos) {
            todos.checked = n === marcas.length;
            todos.indeterminate = n > 0 && n < marcas.length;
        }
    }

    marcas.forEach(function (m) { m.addEventListener('change', atualizar); });
    if (todos) {
        todos.addEventListener('change', function () {
            marcas.forEach(function (m) { m.checked = todos.checked; });
            atualizar();
        });
    }
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
