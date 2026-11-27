<?php
// Formulário do cadastro na página do QR Code (qr.php): se pede cadastro
// (obrigatório, opcional ou desligado), os campos e os textos. Ao lado, a
// prévia de como o visitante vê, atualizada enquanto o cliente mexe (o próprio
// qr.php desenha, em modo de teste, sem salvar nada).
// Grava o formulário no evento, sem subir a versão (o robô não usa).
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'leads_lib.php';
painelIniciarSessao();

$cliente = painelClienteDaPagina();
$eventos = painelEventos($cliente);

// Links antigos abrem o evento em uso. Um salvamento deve trazer o evento
// explicitamente, para não gravar em outro caso o evento padrão tenha mudado.
if (!isset($_GET['evento']) && $_SERVER['REQUEST_METHOD'] === 'GET' && $eventos) {
    painelIr('leads_formulario.php?evento=' . rawurlencode(painelEventoDaPagina($cliente, null)));
}
$pedido = isset($_GET['evento']) && is_string($_GET['evento']) ? $_GET['evento'] : '';
$evento = painelEvento($cliente, $pedido);
if (!$evento) {
    painelAviso('erro', $eventos ? 'Escolha um evento na aba Leads para editar o formulário.' : 'Crie um evento para configurar o formulário.');
    painelIr('leads.php');
}
$idEvento = $evento['id'];
$paramEvento = 'evento=' . rawurlencode($idEvento);
$urlFormulario = 'leads_formulario.php?' . $paramEvento;
$urlLeads = 'leads.php?' . $paramEvento;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!painelConferirCsrf()) {
        painelAviso('erro', 'A página expirou. Tente de novo.');
        painelIr($urlFormulario);
    }
    $campos = json_decode(isset($_POST['campos_json']) && is_string($_POST['campos_json']) ? $_POST['campos_json'] : '', true);
    $dados = array('campos' => is_array($campos) ? $campos : array());
    foreach (array('modo', 'titulo', 'texto', 'botao', 'aviso') as $chave) {
        $dados[$chave] = isset($_POST[$chave]) && is_string($_POST[$chave]) ? $_POST[$chave] : '';
    }
    $erro = '';
    $config = leadsValidarConfig($dados, $erro);
    if (!$config) {
        painelAviso('erro', $erro);
        painelIr($urlFormulario);
    }
    $salvo = painelAtualizarCliente($cliente['id'], function ($c) use ($config, $idEvento) {
        foreach ($c['config']['eventos'] as $i => $item) {
            if ($item['id'] === $idEvento && empty($item['apagado_em'])) {
                $c['config']['eventos'][$i]['formulario_leads'] = $config;
                return $c;
            }
        }
        return null;
    });
    if ($salvo) {
        painelAviso('ok', 'Formulário salvo para ' . $evento['nome'] . '. A configuração vale para os QR Codes deste evento.');
        painelIr($urlLeads);
    }
    painelAviso('erro', 'Não foi possível salvar. Tente de novo.');
    painelIr($urlFormulario);
}

$config = leadsConfig($cliente, $evento);
$modos = leadsModos();
$tipos = leadsTipos();
$padrao = leadsTextosPadrao();
$json = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;

painelTopo('Formulário do QR Code', painelMenu());
?>
<div class="cabecalho-pagina">
    <a class="botao botao-pequeno" href="<?php echo h($urlLeads); ?>">← Voltar para os leads do evento</a>
    <h1 class="leads-titulo">Formulário do QR Code</h1>
    <p class="suave">Evento <strong><?php echo h($evento['nome']); ?></strong></p>
    <p class="suave pequeno">As alterações valem para os QR Codes das fotos deste evento, incluindo as que já foram tiradas.</p>
</div>
<?php if (count($eventos) > 1) { ?>
    <nav class="eventos-filtro" aria-label="Formulários por evento">
        <?php foreach ($eventos as $item) { ?>
            <a class="evento-chip" href="leads_formulario.php?evento=<?php echo h(rawurlencode($item['id'])); ?>"<?php echo $item['id'] === $idEvento ? ' aria-current="page"' : ''; ?>><?php echo h($item['nome']); ?></a>
        <?php } ?>
    </nav>
<?php } ?>
<noscript><div class="aviso aviso-erro">Para editar o formulário, ative o JavaScript do navegador.</div></noscript>

<form method="post" action="<?php echo h($urlFormulario); ?>" id="form-leads" class="leads-editor-grade">
    <?php echo painelCampoCsrf(); ?>
    <input type="hidden" name="campos_json" id="campos-json">
    <div class="leads-editor">
        <section class="cartao">
            <h2>1. Pedir cadastro?</h2>
            <div class="leads-modos" role="radiogroup" aria-label="Pedir cadastro">
                <?php foreach ($modos as $id => $modo) { ?>
                <label class="leads-modo">
                    <input type="radio" name="modo" value="<?php echo h($id); ?>"<?php echo $config['modo'] === $id ? ' checked' : ''; ?>>
                    <span><strong><?php echo h($modo['nome']); ?></strong><span class="suave"><?php echo h($modo['explica']); ?></span></span>
                </label>
                <?php } ?>
            </div>
        </section>

        <section class="cartao leads-depende" id="cartao-campos">
            <h2>2. Campos</h2>
            <p class="suave">Escolha o que perguntar e a ordem. Nome, e-mail e telefone já vêm conferidos (o e-mail precisa ter @, o telefone precisa ter DDD).</p>
            <p class="leads-desligado-aviso suave pequeno">O cadastro está desligado. Os campos ficam guardados para quando você ligar.</p>
            <ol class="leads-campos" id="lista-campos"></ol>
            <div class="leads-adicionar">
                <span class="leads-adicionar-rotulo">Adicionar:</span>
                <?php foreach ($tipos as $id => $tipo) { ?>
                <button type="button" class="botao botao-pequeno" data-adicionar="<?php echo h($id); ?>">+ <?php echo h($tipo['nome']); ?></button>
                <?php } ?>
            </div>
            <p class="suave pequeno" id="limite-campos" hidden>O formulário pode ter no máximo <?php echo LEADS_MAX_CAMPOS; ?> campos.</p>
        </section>

        <section class="cartao leads-depende">
            <h2>3. Textos da página</h2>
            <div class="formulario">
                <label>Título
                    <input type="text" name="titulo" maxlength="80" value="<?php echo h($config['titulo']); ?>" placeholder="<?php echo h($padrao['titulo']); ?>">
                </label>
                <label><span>Texto embaixo do título <span class="suave">(pode deixar vazio)</span></span>
                    <textarea name="texto" rows="2" maxlength="240" placeholder="<?php echo h($padrao['texto']); ?>"><?php echo h($config['texto']); ?></textarea>
                </label>
                <label>Botão
                    <input type="text" name="botao" maxlength="40" value="<?php echo h($config['botao']); ?>" placeholder="<?php echo h($padrao['botao']); ?>">
                </label>
                <label><span>Aviso sobre os dados (LGPD) <span class="suave">(aparece embaixo do botão; pode deixar vazio)</span></span>
                    <textarea name="aviso" rows="3" maxlength="400" placeholder="<?php echo h($padrao['aviso']); ?>"><?php echo h($config['aviso']); ?></textarea>
                </label>
            </div>
        </section>
    </div>

    <aside class="leads-previa" aria-label="Prévia">
        <div class="leads-celular">
            <iframe name="previa" id="previa" title="Prévia da página do QR Code" sandbox="allow-same-origin"></iframe>
        </div>
        <p class="suave pequeno">Prévia: é assim que fica no celular de quem escaneia o QR Code. A foto de verdade aparece borrada atrás do cadastro.</p>
    </aside>

    <div class="leads-salvar">
        <span class="suave pequeno" id="estado-salvar">Nenhuma mudança.</span>
        <button type="submit" class="botao botao-primario">Salvar formulário</button>
    </div>
</form>

<form method="post" action="<?php echo h('qr.php?teste=1&e=' . rawurlencode($idEvento)); ?>" target="previa" id="form-previa" hidden>
    <?php echo painelCampoCsrf(); ?>
    <input type="hidden" name="config">
</form>

<script>
(function () {
    var tipos = <?php echo json_encode($tipos, $json); ?>;
    var campos = <?php echo json_encode($config['campos'], $json); ?>;
    var maximo = <?php echo (int) LEADS_MAX_CAMPOS; ?>;
    var form = document.getElementById('form-leads');
    var lista = document.getElementById('lista-campos');
    var formPrevia = document.getElementById('form-previa');
    var previa = document.getElementById('previa');
    var mudou = false;

    // As opções de múltipla escolha ficam como o texto digitado (uma por linha)
    // enquanto o cliente escreve; viram lista só ao enviar
    campos.forEach(function (c) { if (c.tipo === 'escolha') { c.texto = (c.opcoes || []).join('\n'); } });

    function esc(t) {
        return String(t == null ? '' : t).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    }
    function linhas(texto) {
        var vistas = [];
        String(texto || '').split('\n').forEach(function (l) {
            l = l.trim();
            if (l !== '' && vistas.indexOf(l) === -1) { vistas.push(l); }
        });
        return vistas;
    }
    function novoId() {
        var id;
        do { id = 'c' + Math.random().toString(16).slice(2, 8).padEnd(6, '0'); }
        while (campos.some(function (c) { return c.id === id; }));
        return id;
    }

    function desenhar() {
        lista.innerHTML = campos.map(function (c, i) {
            var tipo = tipos[c.tipo];
            var html = '<li class="leads-campo" data-i="' + i + '">'
                + '<div class="leads-campo-topo"><span class="leads-campo-tipo">' + esc(tipo.nome) + '</span>'
                + '<div class="acoes">'
                + '<button type="button" class="botao botao-pequeno" data-mover="-1" aria-label="Subir"' + (i === 0 ? ' disabled' : '') + '>↑</button>'
                + '<button type="button" class="botao botao-pequeno" data-mover="1" aria-label="Descer"' + (i === campos.length - 1 ? ' disabled' : '') + '>↓</button>'
                + '<button type="button" class="botao botao-pequeno botao-perigo" data-remover>Remover</button>'
                + '</div></div>'
                + '<div class="leads-campo-linha">'
                + '<label class="leads-campo-rotulo">Pergunta<input type="text" maxlength="80" data-prop="rotulo" value="' + esc(c.rotulo) + '" placeholder="' + esc(tipo.rotulo || 'Ex.: Qual a sua empresa?') + '"></label>'
                + '<label class="leads-check"><input type="checkbox" data-prop="obrigatorio"' + (c.obrigatorio ? ' checked' : '') + '> Obrigatório</label>'
                + '</div>';
            if (c.tipo === 'escolha') {
                html += '<label class="leads-campo-opcoes"><span>Opções <span class="suave">(uma por linha)</span></span>'
                    + '<textarea rows="4" data-prop="texto" placeholder="Opção 1&#10;Opção 2&#10;Opção 3">' + esc(c.texto) + '</textarea></label>'
                    + '<label class="leads-check"><input type="checkbox" data-prop="varias"' + (c.varias ? ' checked' : '') + '> Pode marcar mais de uma</label>';
            }
            return html + '<p class="leads-campo-erro" hidden></p></li>';
        }).join('');
        if (!campos.length) {
            lista.innerHTML = '<li class="leads-campos-vazio suave">Nenhum campo. Adicione pelo menos um abaixo.</li>';
        }
        document.querySelectorAll('[data-adicionar]').forEach(function (b) {
            var tipo = b.getAttribute('data-adicionar');
            b.disabled = campos.length >= maximo || (tipos[tipo].unico && campos.some(function (c) { return c.tipo === tipo; }));
        });
        document.getElementById('limite-campos').hidden = campos.length < maximo;
    }

    function dados() {
        var modo = form.querySelector('input[name=modo]:checked');
        return {
            modo: modo ? modo.value : 'obrigatorio',
            titulo: form.titulo.value,
            texto: form.texto.value,
            botao: form.botao.value,
            aviso: form.aviso.value,
            campos: campos.map(function (c) {
                var saida = { id: c.id, tipo: c.tipo, rotulo: c.rotulo, obrigatorio: !!c.obrigatorio };
                if (c.tipo === 'escolha') { saida.opcoes = linhas(c.texto); saida.varias = !!c.varias; }
                return saida;
            })
        };
    }

    // Prévia: o qr.php desenha com o formulário que está na tela (sem salvar)
    var espera = null;
    var rolagem = 0;
    function atualizarPrevia() {
        clearTimeout(espera);
        espera = setTimeout(function () {
            try { rolagem = previa.contentWindow.scrollY || 0; } catch (e) { rolagem = 0; }
            formPrevia.config.value = JSON.stringify(dados());
            formPrevia.submit();
        }, 300);
    }
    previa.addEventListener('load', function () {
        try { previa.contentWindow.scrollTo(0, rolagem); } catch (e) {}
    });

    function mexeu() {
        mudou = true;
        document.getElementById('estado-salvar').textContent = 'Há mudanças não salvas.';
        var modo = form.querySelector('input[name=modo]:checked');
        form.classList.toggle('leads-desligado', !!modo && modo.value === 'desligado');
        atualizarPrevia();
    }

    lista.addEventListener('input', function (e) {
        var item = e.target.closest('[data-i]');
        var prop = e.target.getAttribute('data-prop');
        if (!item || !prop) return;
        var campo = campos[+item.getAttribute('data-i')];
        campo[prop] = e.target.type === 'checkbox' ? e.target.checked : e.target.value;
        e.target.removeAttribute('aria-invalid');
        item.querySelector('.leads-campo-erro').hidden = true;
        mexeu();
    });
    lista.addEventListener('click', function (e) {
        var item = e.target.closest('[data-i]');
        if (!item) return;
        var i = +item.getAttribute('data-i');
        var mover = e.target.closest('[data-mover]');
        if (mover) {
            var j = i + +mover.getAttribute('data-mover');
            var campo = campos.splice(i, 1)[0];
            campos.splice(j, 0, campo);
            desenhar();
            var botao = lista.querySelector('[data-i="' + j + '"] [data-mover="' + mover.getAttribute('data-mover') + '"]');
            (botao && !botao.disabled ? botao : lista.querySelector('[data-i="' + j + '"] input')).focus();
            mexeu();
        } else if (e.target.closest('[data-remover]')) {
            campos.splice(i, 1);
            desenhar();
            mexeu();
        }
    });
    document.querySelectorAll('[data-adicionar]').forEach(function (b) {
        b.addEventListener('click', function () {
            var tipo = b.getAttribute('data-adicionar');
            var campo = { id: tipos[tipo].unico ? tipo : novoId(), tipo: tipo, rotulo: tipos[tipo].rotulo, obrigatorio: false };
            if (tipo === 'escolha') { campo.texto = ''; campo.varias = true; }
            campos.push(campo);
            desenhar();
            var novo = lista.querySelector('[data-i="' + (campos.length - 1) + '"] input[data-prop=rotulo]');
            if (novo) { novo.focus(); if (!campo.rotulo) { novo.select(); } }
            mexeu();
        });
    });
    form.querySelectorAll('input[name=modo], input[name=titulo], textarea[name=texto], input[name=botao], textarea[name=aviso]').forEach(function (campo) {
        campo.addEventListener('input', mexeu);
        campo.addEventListener('change', mexeu);
    });

    // Antes de salvar: as mesmas regras do servidor, com o aviso no próprio campo
    function erroNoCampo(i, mensagem, seletor) {
        var item = lista.querySelector('[data-i="' + i + '"]');
        var aviso = item.querySelector('.leads-campo-erro');
        aviso.textContent = mensagem;
        aviso.hidden = false;
        var alvo = item.querySelector(seletor);
        alvo.setAttribute('aria-invalid', 'true');
        alvo.focus();
        item.scrollIntoView({ block: 'center' });
    }
    form.addEventListener('submit', function (e) {
        var d = dados();
        if (d.modo !== 'desligado' && !d.campos.length) {
            e.preventDefault();
            painelConfirmar({ titulo: 'Falta algum campo', texto: 'Coloque pelo menos um campo no formulário, ou escolha "Desligado".', botao: 'Entendi' }, function () {});
            return;
        }
        for (var i = 0; i < d.campos.length; i++) {
            if (!d.campos[i].rotulo.trim() && !tipos[d.campos[i].tipo].rotulo) {
                e.preventDefault();
                erroNoCampo(i, 'Escreva a pergunta deste campo.', 'input[data-prop=rotulo]');
                return;
            }
            if (d.campos[i].tipo === 'escolha' && !d.campos[i].opcoes.length) {
                e.preventDefault();
                erroNoCampo(i, 'Escreva as opções, uma por linha.', 'textarea[data-prop=texto]');
                return;
            }
        }
        document.getElementById('campos-json').value = JSON.stringify(d.campos);
        mudou = false;
    });
    window.addEventListener('beforeunload', function (e) {
        if (mudou) { e.preventDefault(); e.returnValue = ''; }
    });

    desenhar();
    form.classList.toggle('leads-desligado', form.querySelector('input[name=modo]:checked').value === 'desligado');
    formPrevia.config.value = JSON.stringify(dados());
    formPrevia.submit();
})();
</script>
<?php
painelRodape();
