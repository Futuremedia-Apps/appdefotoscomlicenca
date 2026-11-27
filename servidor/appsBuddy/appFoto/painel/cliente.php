<?php
// Página do painel: o trajeto do app e, para cada tela, a imagem, os botões
// (imagem, posição e tamanho), as molduras, o vídeo, a cor e o PIN.
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'licenca.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'leads_lib.php';
painelIniciarSessao();

$cliente = painelClienteDaPagina();
$id = $cliente['id'];
$urlPagina = 'cliente.php';
$slots = painelSlots();
$telas = painelTelas();
$licencasPainel = licencasDestePainel(1);
$robosDoPainel = array();
$alvosVisuais = array();
foreach ($licencasPainel as $licenca) {
    foreach ($licenca['codigos'] as $robo) {
        if ($robo['em_uso']) {
            $codigo = licencaNormalizarCodigo($robo['codigo']);
            $robo['nome_painel'] = 'Robô ' . (int) $robo['numero'];
            $robo['identificador'] = $robo['serial'] !== '' ? 'Serial ' . $robo['serial'] : 'Código ' . $robo['codigo'];
            $robosDoPainel[$codigo] = $robo;
            $alvosVisuais[substr(hash('sha256', $id . ':' . $codigo), 0, 20)] = $codigo;
        }
    }
}

$alvoVisual = isset($_GET['visual']) ? $_GET['visual'] : '';
if ($alvoVisual !== '' && (!is_string($alvoVisual) || !isset($alvosVisuais[$alvoVisual]))) {
    painelAviso('erro', 'Este robô não está ativado neste painel.');
    painelIr($urlPagina . '#visual');
}
$codigoVisual = $alvoVisual === '' ? '' : $alvosVisuais[$alvoVisual];
$visualProprio = painelVisualProprio($cliente, $codigoVisual);
$editorVisivel = $codigoVisual === '' || $visualProprio;
if ($codigoVisual !== '') {
    $urlPagina .= '?visual=' . rawurlencode($alvoVisual);
}

// $alterar recebe a configuração selecionada no editor.
function salvarConfig($id, $codigoVisual, $alterar, $mensagemOk, &$erro) {
    $salvo = painelAtualizarVisual($id, $codigoVisual, $alterar);
    if ($salvo) {
        painelAviso('ok', $mensagemOk);
    } else {
        painelAviso('erro', $erro !== '' ? $erro : 'Não foi possível salvar. Abra a configuração novamente.');
    }
    return $salvo;
}

if (painelPostGrandeDemais()) {
    painelAviso('erro', 'O arquivo passa do limite do servidor (' . painelLimiteUploadTexto() . ').');
    painelIr($urlPagina);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!painelConferirCsrf()) {
        painelAviso('erro', 'A página expirou. Tente de novo.');
        painelIr($urlPagina);
    }

    $acao = isset($_POST['acao']) ? (string) $_POST['acao'] : '';
    $slot = isset($_POST['slot']) ? (string) $_POST['slot'] : '';
    $indice = isset($_POST['indice']) ? (int) $_POST['indice'] : -1;
    $tela = isset($_POST['tela']) ? (string) $_POST['tela'] : '';
    $erro = '';

    // Todo formulário de uma tela leva junto as posições do editor, para não
    // perder o que foi arrastado e ainda não salvo. Só grava se mudou algo.
    $layoutSalvo = null;
    if (isset($telas[$tela]['elementos'], $_POST['layout'])) {
        $layoutPost = (string) $_POST['layout'];
        $layoutSalvo = painelAtualizarVisual($id, $codigoVisual, function ($config) use ($tela, $layoutPost) {
            $novo = painelAplicarLayout($config, $tela, $layoutPost);
            return painelLayout($novo) == painelLayout($config) ? $config : $novo;
        }, false);
    }

    if ($acao === 'salvar_layout') {
        if ($layoutSalvo) {
            painelAviso('ok', 'Posições salvas. O visual será aplicado na próxima sincronização.');
        } else {
            painelAviso('erro', 'Não foi possível salvar as posições. Abra a configuração novamente.');
        }
    } elseif ($acao === 'enviar_midia' && isset($slots[$slot])) {
        $uploads = painelListaUploads('arquivo');
        $tipo = $slots[$slot]['tipo'] === 'video' ? 'video' : 'imagem';
        $elemento = isset($slots[$slot]['elemento']) ? $slots[$slot]['elemento'] : '';
        salvarConfig($id, $codigoVisual, function ($config) use ($id, $uploads, $tipo, $slot, $elemento, &$erro) {
            $info = painelGuardarUpload($id, $uploads ? $uploads[0] : array(), $tipo, $erro);
            if (!$info) {
                return null;
            }
            $config['midia'][$slot] = $info;
            // Botão ou balão novo: a caixa ganha o formato da imagem.
            return $elemento !== '' ? painelAjustarAoFormato($config, $elemento, $info['largura'], $info['altura']) : $config;
        }, $slots[$slot]['titulo'] . ': arquivo atualizado.', $erro);
    } elseif ($acao === 'remover_midia' && isset($slots[$slot])) {
        salvarConfig($id, $codigoVisual, function ($config) use ($slot) {
            unset($config['midia'][$slot]);
            return $config;
        }, $slots[$slot]['titulo'] . ': voltou ao padrão do app.', $erro);
    } elseif ($acao === 'enviar_molduras') {
        $uploads = painelListaUploads('molduras');
        $novas = 0;
        $salvo = painelAtualizarVisual($id, $codigoVisual, function ($config) use ($id, $uploads, &$novas, &$erro) {
            foreach ($uploads as $upload) {
                $info = painelGuardarUpload($id, $upload, 'moldura', $erro);
                if ($info) {
                    $config['molduras'][] = $info;
                    $novas++;
                } elseif ($erro !== 'Escolha um arquivo.') {
                    painelAviso('erro', $erro);
                }
            }
            return $novas > 0 ? $config : null;
        });
        if ($salvo) {
            painelAviso('ok', $novas === 1 ? 'Moldura adicionada.' : $novas . ' molduras adicionadas.');
        } elseif ($novas || $erro === '') {
            painelAviso('erro', 'Não foi possível salvar as molduras. Abra a configuração novamente.');
        }
    } elseif ($acao === 'remover_moldura') {
        salvarConfig($id, $codigoVisual, function ($config) use ($indice) {
            if (!isset($config['molduras'][$indice])) {
                return null;
            }
            array_splice($config['molduras'], $indice, 1);
            return $config;
        }, 'Moldura removida.', $erro);
    } elseif ($acao === 'mover_moldura') {
        $destino = $indice + (isset($_POST['direcao']) && $_POST['direcao'] === 'antes' ? -1 : 1);
        salvarConfig($id, $codigoVisual, function ($config) use ($indice, $destino) {
            $molduras = $config['molduras'];
            if (!isset($molduras[$indice]) || !isset($molduras[$destino])) {
                return null;
            }
            $temporaria = $molduras[$indice];
            $molduras[$indice] = $molduras[$destino];
            $molduras[$destino] = $temporaria;
            $config['molduras'] = $molduras;
            return $config;
        }, 'Ordem das molduras atualizada.', $erro);
    } elseif ($acao === 'salvar_aparencia' || $acao === 'salvar_acesso') {
        $salvarAcesso = $acao === 'salvar_acesso';
        $cor = isset($_POST['cor_primaria']) && is_string($_POST['cor_primaria']) ? strtolower(trim($_POST['cor_primaria'])) : '';
        $pin = null;
        if ($salvarAcesso || ($codigoVisual === '' && isset($_POST['pin_admin']))) {
            $pin = isset($_POST['pin_admin']) && is_string($_POST['pin_admin']) ? trim($_POST['pin_admin']) : '';
        }
        $tempo = ($salvarAcesso || $codigoVisual === '') && isset($_POST['tempo_inatividade'])
            ? painelValidarTempoInatividade($_POST['tempo_inatividade']) : null;
        if (!$salvarAcesso && !preg_match('/^#[0-9a-f]{6}$/', $cor)) {
            painelAviso('erro', 'Cor inválida.');
        } elseif ($pin !== null && !preg_match('/^[0-9]{4,8}$/', $pin)) {
            painelAviso('erro', 'O PIN precisa ter de 4 a 8 números.');
        } elseif ($tempo === false || ($salvarAcesso && $tempo === null)) {
            painelAviso('erro', 'Informe um tempo de inatividade em segundos inteiros, maior que zero.');
        } else {
            $mensagem = 'Configurações salvas. Toque em Sincronizar no admin do robô para aplicar.';
            if ($salvarAcesso) {
                $salvo = painelAtualizarCliente($id, function ($c) use ($pin, $tempo) {
                    if ($c['config']['pin_admin'] === $pin && painelTempoInatividade($c['config']) === $tempo) {
                        return $c;
                    }
                    $c['config']['pin_admin'] = $pin;
                    $c['config']['tempo_inatividade'] = $tempo;
                    return painelMarcarAlteracao($c);
                });
                painelAviso($salvo ? 'ok' : 'erro', $salvo ? $mensagem : 'Não foi possível salvar as configurações.');
            } else {
                salvarConfig($id, $codigoVisual, function ($config) use ($cor, $pin, $tempo) {
                    $config['cor_primaria'] = $cor;
                    if ($pin !== null) {
                        $config['pin_admin'] = $pin;
                    }
                    if ($tempo !== null) {
                        $config['tempo_inatividade'] = $tempo;
                    }
                    return $config;
                }, $mensagem, $erro);
            }
        }
        painelIr($urlPagina . ($salvarAcesso ? '#acesso' : '#cor'));
    } elseif ($acao === 'modo_visual') {
        $modo = isset($_POST['modo']) ? $_POST['modo'] : '';
        $salvo = $codigoVisual !== '' && in_array($modo, array('proprio', 'compartilhado'), true)
            ? painelAtualizarCliente($id, function ($c) use ($codigoVisual, $modo) {
                $proprio = $modo === 'proprio';
                if ($proprio === painelVisualProprio($c, $codigoVisual)) {
                    return $c;
                }
                if (!isset($c['config']['visuais_robos'][$codigoVisual])) {
                    $c['config']['visuais_robos'][$codigoVisual]['config'] = painelSomenteVisual($c['config']);
                }
                $c['config']['visuais_robos'][$codigoVisual]['proprio'] = $proprio;
                return painelMarcarAlteracao($c);
            }) : null;
        if ($salvo) {
            painelAviso('ok', 'Modo visual salvo. Toque em Sincronizar no admin do robô para aplicar.');
        } else {
            painelAviso('erro', 'Não foi possível alterar o modo visual deste robô.');
        }
        painelIr($urlPagina . '#visual');
    } elseif ($acao === 'evento_do_robo') {
        $codigo = isset($_POST['robo']) && is_string($_POST['robo']) ? licencaNormalizarCodigo($_POST['robo']) : '';
        $escolha = isset($_POST['evento']) && is_string($_POST['evento']) ? $_POST['evento'] : null;
        $reabrir = $escolha !== null && strpos($escolha, 'reabrir:') === 0;
        $idEvento = $reabrir ? substr($escolha, 8) : $escolha;
        $salvo = isset($robosDoPainel[$codigo]) && $idEvento !== null ? painelAtualizarCliente($id, function ($c) use ($codigo, $idEvento, $reabrir) {
            if ($idEvento === '') {
                unset($c['config']['eventos_dos_robos'][$codigo]);
                return $c;
            }
            $evento = painelEvento($c, $idEvento);
            if (!$evento || (!$reabrir && !painelEventoAberto($c, $evento))) {
                return null;
            }
            $c['config']['eventos_dos_robos'][$codigo] = $idEvento;
            return painelAbrirEvento($c, $idEvento, true);
        }) : null;
        if ($salvo) {
            painelGravarEventoAtual($salvo);
            painelAviso('ok', 'Evento do robô atualizado. Os próximos envios seguem esse vínculo.');
        } else {
            painelAviso('erro', 'Não foi possível vincular o robô. Confira se ele está ativado e se o evento está disponível.');
        }
        painelIr($urlPagina . '#robos-eventos');
    } elseif ($acao === 'criar_evento' || $acao === 'usar_evento') {
        // O evento vem do servidor, sem mexer na versão da configuração do app.
        $nome = trim(preg_replace('/\s+/u', ' ', isset($_POST['nome_evento']) ? (string) $_POST['nome_evento'] : ''));
        $idEvento = isset($_POST['evento']) ? (string) $_POST['evento'] : '';
        $apenasCriar = $acao === 'criar_evento' && isset($_POST['apenas_criar']);
        $repetido = false;
        foreach (painelEventos($cliente) as $existente) {
            $repetido = $repetido || strtolower($existente['nome']) === strtolower($nome);
        }
        if ($acao === 'criar_evento' && !preg_match('/^.{1,60}$/u', $nome)) {
            painelAviso('erro', 'Dê um nome ao evento, com até 60 letras.');
        } elseif ($acao === 'criar_evento' && $repetido) {
            painelAviso('erro', 'Já existe um evento com esse nome. Escolha o evento que já existe.');
        } else {
            $salvo = painelAtualizarCliente($id, function ($c) use ($acao, $nome, $idEvento, $apenasCriar) {
                if ($acao === 'criar_evento') {
                    foreach (painelEventos($c) as $existente) {
                        if (strtolower($existente['nome']) === strtolower($nome)) {
                            return null;
                        }
                    }
                    $idEvento = painelNovoIdEvento($c, $nome, leadsEventosComArquivo($c));
                    $c['config']['eventos'][] = array('id' => $idEvento, 'nome' => $nome, 'criado_em' => date('c'));
                } elseif (!painelEvento($c, $idEvento)) {
                    return null;
                }
                return $apenasCriar ? painelAbrirEvento($c, $idEvento, true) : painelMudarEventoAtual($c, $idEvento);
            });
            if ($salvo) {
                painelGravarEventoAtual($salvo);
                if ($apenasCriar) {
                    painelAviso('ok', 'Evento "' . $nome . '" criado. Você já pode escolher os robôs que vão usá-lo.');
                } else {
                    $evento = painelEventoAtual($salvo);
                    painelAviso('ok', '"' . $evento['nome'] . '" é o evento padrão para os robôs que seguem o painel.');
                }
            } else {
                painelAviso('erro', 'Não foi possível trocar o evento. Tente de novo.');
            }
        }
        painelIr($urlPagina . '#evento');
    } elseif ($acao === 'encerrar_evento') {
        $atual = painelEventoAtual($cliente);
        $idEvento = isset($_POST['evento']) ? (string) $_POST['evento'] : ($atual ? $atual['id'] : '');
        $salvo = painelAtualizarCliente($id, function ($c) use ($idEvento) {
            return painelEventoAberto($c, painelEvento($c, $idEvento)) ? painelEncerrarEvento($c, $idEvento) : null;
        });
        if ($salvo) {
            painelGravarEventoAtual($salvo);
            $evento = painelEvento($salvo, $idEvento);
            painelAviso('ok', 'Evento "' . $evento['nome'] . '" encerrado. Os robôs vinculados a ele param em até 30 segundos. Quem já estava tirando foto ainda tem '
                . (PAINEL_TOLERANCIA_ENCERRAR / 60) . ' minutos para terminar.');
        } else {
            painelAviso('erro', painelEventoAberto($cliente, painelEvento($cliente, $idEvento)) ? 'Não foi possível encerrar o evento. Tente de novo.' : 'Não há evento aberto para encerrar.');
        }
        painelIr($urlPagina . '#evento');
    }

    painelIr($urlPagina . ($tela !== '' && isset($telas[$tela]) ? '#tela-' . $tela : ''));
}

// Atualiza os eventos exibidos na Central de Licenças.
painelGravarEventoAtual($cliente);

$config = painelConfigDoRobo($cliente, $codigoVisual);
$midia = $config['midia'];
$molduras = $config['molduras'];
$layout = painelLayout($config);

// Campos comuns de todo formulário desta página
function camposOcultos($acao, $extras = array()) {
    $html = painelCampoCsrf() . '<input type="hidden" name="acao" value="' . h($acao) . '">';
    foreach ($extras as $nome => $valor) {
        $html .= '<input type="hidden" name="' . h($nome) . '" value="' . h($valor) . '">';
    }
    return $html;
}

// Endereço da imagem de um slot: a enviada ou a padrão (ou null se não tem)
function urlSlot($id, $midia, $slots, $slot) {
    if (isset($midia[$slot])) {
        return painelUrlMidia($id, $midia[$slot]['arquivo']);
    }
    return $slots[$slot]['padrao'] !== '' ? 'padrao/' . $slots[$slot]['padrao'] : null;
}

$urlMoldura = $molduras ? painelUrlMidia($id, $molduras[0]['arquivo']) : 'padrao/moldura1.png';

// Miniatura de uma tela, usada no trajeto
function estiloMiniatura($tela, $id, $midia, $slots, $urlMoldura) {
    if (!empty($tela['fundo'])) {
        return "background-image:url('" . h(urlSlot($id, $midia, $slots, $tela['fundo'])) . "')";
    }
    if (!empty($tela['camera'])) {
        return "background-image:url('" . h($urlMoldura) . "'),linear-gradient(135deg,#59647a,#2b3240)";
    }
    if (!empty($tela['secao'])) {
        // Escolha da moldura: tela branca com as molduras lado a lado
        return "background-image:url('" . h($urlMoldura) . "')";
    }
    return '';
}

// Botão de enviar arquivo (envia ao escolher)
function campoArquivo($rotulo, $nome, $aceita, $classe = 'botao botao-pequeno botao-primario') {
    return '<label class="' . h($classe) . '">' . h($rotulo)
        . '<input type="file" name="' . h($nome) . '" accept="' . h($aceita) . '" data-auto class="oculto"></label>'
        . '<noscript><button type="submit" class="botao botao-pequeno">Enviar</button></noscript>';
}

list(, $totalFotos) = painelUsoFotos($cliente);
$eventoAtual = painelEventoAtual($cliente);
$eventoEncerrado = painelEventoEncerrado($cliente);
$eventosPainel = painelEventos($cliente);
$outrosEventos = array();
$outrosAbertos = array();
foreach ($eventosPainel as $evento) {
    if (!$eventoAtual || $evento['id'] !== $eventoAtual['id']) {
        $outrosEventos[] = $evento;
        if (painelEventoAberto($cliente, $evento)) {
            $outrosAbertos[] = $evento;
        }
    }
}
$eventosDosRobos = painelEventosDosRobos($cliente);
$avisoEncerrar = 'Os robôs que usam este evento param de tirar fotos em até 30 segundos. Quem já estava tirando foto tem '
    . (PAINEL_TOLERANCIA_ENCERRAR / 60) . ' minutos para terminar.';

painelTopo($cliente['nome'], painelMenu());
?>
<div class="cabecalho-pagina cabecalho-fotos">
    <div>
        <h1><?php echo h($cliente['nome']); ?></h1>
        <p class="suave">Para aplicar mudanças nas telas e molduras, toque em <strong>Sincronizar</strong> no admin do robô.</p>
    </div>
    <a class="botao" href="fotos.php">📷 Ver fotos tiradas<?php echo $totalFotos ? ' (' . number_format($totalFotos, 0, ',', '.') . ')' : ''; ?></a>
</div>

<section class="cartao evento" id="evento" aria-labelledby="titulo-eventos">
    <header class="evento-cabecalho">
        <h2 id="titulo-eventos">Eventos</h2>
        <p class="suave">Os robôs sem escolha individual seguem o evento padrão.</p>
    </header>
    <div class="evento-controles">
        <div class="evento-atual<?php echo $eventoAtual ? '' : ' evento-vazio'; ?>">
            <h3 class="evento-rotulo">Evento padrão</h3>
            <?php if ($eventoAtual) { ?>
                <p class="evento-nome"><?php echo h($eventoAtual['nome']); ?></p>
                <p class="suave pequeno">Criado em <?php echo h(painelDataBr($eventoAtual['criado_em'])); ?> · <a href="fotos.php?evento=<?php echo h(rawurlencode($eventoAtual['id'])); ?>"><?php echo painelQuantidadeFotos(painelContarFotos($cliente, $eventoAtual['id'])); ?></a></p>
                <form method="post" action="<?php echo h($urlPagina); ?>" class="evento-acoes" data-confirmar-titulo="Encerrar o evento &quot;<?php echo h($eventoAtual['nome']); ?>&quot;?" data-confirmar="<?php echo h($avisoEncerrar); ?>" data-confirmar-botao="Encerrar evento">
                    <?php echo camposOcultos('encerrar_evento', array('evento' => $eventoAtual['id'])); ?>
                    <button type="submit" class="botao botao-pequeno">Encerrar evento</button>
                </form>
            <?php } else { ?>
                <p class="evento-nome">Sem evento padrão</p>
                <?php if ($eventoEncerrado) { ?>
                    <p class="suave pequeno">"<?php echo h($eventoEncerrado['evento']['nome']); ?>" foi encerrado em <?php echo h(date('d/m/Y', $eventoEncerrado['em']) . ' às ' . date('H:i', $eventoEncerrado['em'])); ?>.</p>
                    <form method="post" action="<?php echo h($urlPagina); ?>" class="evento-acoes">
                        <?php echo camposOcultos('usar_evento', array('evento' => $eventoEncerrado['evento']['id'])); ?>
                        <button type="submit" class="botao botao-pequeno">Reabrir "<?php echo h($eventoEncerrado['evento']['nome']); ?>"</button>
                    </form>
                <?php } elseif (!$eventosPainel) { ?>
                    <p class="suave pequeno">Crie o primeiro evento para começar a tirar fotos.</p>
                <?php } ?>
            <?php } ?>
        </div>
        <div class="evento-formularios">
            <form method="post" action="<?php echo h($urlPagina); ?>" class="evento-form">
                <?php echo camposOcultos('criar_evento'); ?>
                <label for="nome-evento">Novo evento</label>
                <input type="text" id="nome-evento" name="nome_evento" maxlength="60" required placeholder="Ex.: Casamento Ana e Pedro" autocomplete="off">
                <div class="evento-criar-acoes">
                    <button type="submit" class="botao botao-primario">Criar como padrão</button>
                    <button type="submit" name="apenas_criar" value="1" class="botao">Criar sem mudar o padrão</button>
                </div>
            </form>
            <?php if ($outrosEventos) { ?>
                <form method="post" action="<?php echo h($urlPagina); ?>" class="evento-form">
                    <?php echo camposOcultos('usar_evento'); ?>
                    <label for="trocar-evento">Trocar evento padrão</label>
                    <div class="evento-linha">
                        <select id="trocar-evento" name="evento">
                            <?php foreach ($outrosEventos as $evento) { ?>
                                <option value="<?php echo h($evento['id']); ?>"><?php echo h($evento['nome'] . ' (' . (!empty($evento['encerrado_em'])
                                    ? 'encerrado em ' . painelDataBr($evento['encerrado_em'])
                                    : 'criado em ' . painelDataBr($evento['criado_em'])) . ')'); ?></option>
                            <?php } ?>
                        </select>
                        <button type="submit" class="botao">Usar como padrão</button>
                    </div>
                </form>
            <?php } ?>
        </div>
    </div>
    <section class="evento-bloco" id="robos-eventos" aria-labelledby="titulo-robos-eventos">
        <h3 id="titulo-robos-eventos">Evento por robô</h3>
        <?php if (!$robosDoPainel) { ?>
            <p class="suave">Os robôs aparecem aqui depois de ativar a licença deste painel.</p>
        <?php } else { ?>
            <p class="suave pequeno">O vínculo vale para os próximos envios. Vários robôs podem usar o mesmo evento.</p>
            <ul class="evento-lista">
            <?php foreach ($robosDoPainel as $codigo => $robo) {
                $vinculo = isset($eventosDosRobos[$codigo]) ? $eventosDosRobos[$codigo] : '';
                $eventoDoRobo = painelEventoDoRobo($cliente, $codigo);
                $nomeRobo = $robo['nome_painel'];
                $identificador = $robo['identificador'];
                ?>
                <li>
                    <form method="post" action="<?php echo h($urlPagina); ?>" class="evento-robo-form">
                        <?php echo camposOcultos('evento_do_robo', array('robo' => $robo['codigo'])); ?>
                        <div class="evento-robo-dado">
                            <strong><?php echo h($nomeRobo); ?></strong>
                            <span class="suave pequeno"><?php echo h($identificador); ?></span>
                        </div>
                        <div class="evento-robo-dado">
                            <span class="suave pequeno">Em uso</span>
                            <strong><?php echo $eventoDoRobo ? h($eventoDoRobo['nome']) : 'Sem evento ativo'; ?></strong>
                        </div>
                        <label class="evento-robo-campo">
                            <span>Vincular a</span>
                            <select name="evento" aria-label="<?php echo h('Evento de ' . $nomeRobo . ' · ' . $identificador); ?>">
                                <option value=""<?php echo $vinculo === '' ? ' selected' : ''; ?>>Usar evento padrão</option>
                                <?php if ($vinculo !== '' && !painelEvento($cliente, $vinculo)) { ?>
                                    <option selected disabled>Evento excluído</option>
                                <?php } ?>
                                <?php foreach ($eventosPainel as $evento) {
                                    $aberto = painelEventoAberto($cliente, $evento); ?>
                                    <option value="<?php echo h(($aberto ? '' : 'reabrir:') . $evento['id']); ?>"<?php echo $vinculo === $evento['id'] ? ' selected' : ''; ?>>
                                        <?php echo h(($aberto ? '' : 'Reabrir e usar: ') . $evento['nome']); ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </label>
                        <button type="submit" class="botao" aria-label="<?php echo h('Salvar evento de ' . $nomeRobo); ?>">Salvar</button>
                    </form>
                </li>
            <?php } ?>
            </ul>
        <?php } ?>
    </section>
    <?php if ($outrosAbertos) { ?>
    <section class="evento-bloco" aria-labelledby="titulo-outros-eventos">
        <h3 id="titulo-outros-eventos">Outros eventos abertos</h3>
        <ul class="evento-lista">
        <?php foreach ($outrosAbertos as $evento) { ?>
            <li>
                <form method="post" action="<?php echo h($urlPagina); ?>" class="evento-encerrar-form" data-confirmar-titulo="Encerrar o evento &quot;<?php echo h($evento['nome']); ?>&quot;?" data-confirmar="<?php echo h($avisoEncerrar); ?>" data-confirmar-botao="Encerrar evento">
                    <?php echo camposOcultos('encerrar_evento', array('evento' => $evento['id'])); ?>
                    <a href="fotos.php?evento=<?php echo h(rawurlencode($evento['id'])); ?>"><?php echo h($evento['nome']); ?></a>
                    <button type="submit" class="botao botao-pequeno">Encerrar evento</button>
                </form>
            </li>
        <?php } ?>
        </ul>
    </section>
    <?php } ?>
</section>

<aside class="dica">
    <div class="dica-rotulo">💡 Dica</div>
    <h2>Como atualizar o robô</h2>
    <ol>
        <li>Na tela inicial do app, toque <strong>5 vezes seguidas</strong> no canto superior direito.</li>
        <li>Digite o PIN de acesso: <strong><?php echo h($config['pin_admin']); ?></strong>.</li>
        <li>Toque em <strong>Sincronizar</strong>.</li>
    </ol>
</aside>

<!-- Licença: o código para ativar o robô (lido da Central de Licenças) -->
<section class="cartao licenca" id="licenca">
    <h2>Licença do robô</h2>
    <?php if (!$licencasPainel) { ?>
        <p class="suave">Este painel ainda não tem licença. Fale com a Futuremedia para receber o código de ativação do robô.</p>
    <?php } else { ?>
        <?php foreach ($licencasPainel as $licenca) {
            $umRobo = count($licenca['codigos']) === 1;
            ?>
        <div class="licenca-linha">
            <?php if ($umRobo) {
                $robo = $licenca['codigos'][0];
                ?>
            <div class="licenca-codigo">
                <span class="evento-rotulo">Código de ativação</span>
                <code class="licenca-numero"><?php echo h($robo['codigo']); ?></code>
                <button type="button" class="botao botao-pequeno" data-copiar="<?php echo h($robo['codigo']); ?>">Copiar</button>
                <span class="licenca-uso"><?php echo $robo['em_uso']
                    ? 'Em uso no robô ' . ($robo['serial'] !== '' ? 'de serial <strong>' . h($robo['serial']) . '</strong>' : '(serial aparece quando o app do robô for atualizado)')
                    : 'Livre: ainda não está em nenhum robô'; ?></span>
            </div>
            <?php } ?>
            <dl class="licenca-dados">
                <dt>Situação</dt>
                <dd><span class="licenca-selo licenca-<?php echo h($licenca['situacao']); ?>"><?php echo h(array('ativa' => 'Ativa', 'vencida' => 'Vencida', 'suspensa' => 'Suspensa')[$licenca['situacao']]); ?></span></dd>
                <dt>Validade</dt>
                <dd><?php echo $licenca['vence'] === '' ? 'Sem vencimento' : 'Até ' . h(date('d/m/Y', strtotime($licenca['vence']))); ?></dd>
                <dt>Robôs</dt>
                <dd><?php echo (int) $licenca['usados'] . ' de ' . (int) $licenca['robos'] . ' em uso'; ?></dd>
            </dl>
        </div>
        <?php if (!$umRobo) { ?>
        <p class="evento-rotulo">Códigos de ativação: um para cada robô</p>
        <ul class="licenca-robos">
            <?php foreach ($licenca['codigos'] as $robo) { ?>
            <li>
                <span class="licenca-robo-num">Robô <?php echo (int) $robo['numero']; ?></span>
                <code class="licenca-numero licenca-numero-menor"><?php echo h($robo['codigo']); ?></code>
                <button type="button" class="botao botao-pequeno" data-copiar="<?php echo h($robo['codigo']); ?>">Copiar</button>
                <span class="licenca-uso"><?php echo $robo['em_uso']
                    ? 'Em uso' . ($robo['serial'] !== '' ? ' · serial <strong>' . h($robo['serial']) . '</strong>' : ' (serial aparece quando o app do robô for atualizado)')
                    : 'Livre'; ?></span>
            </li>
            <?php } ?>
        </ul>
        <?php } ?>
        <?php if ($licenca['situacao'] !== 'ativa') { ?>
            <div class="aviso aviso-erro"><?php echo $licenca['situacao'] === 'suspensa'
                ? 'A licença está suspensa e os robôs ficam travados. Fale com a Futuremedia.'
                : 'A licença venceu. Fale com a Futuremedia para renovar, antes que os robôs travem.'; ?></div>
        <?php } ?>
        <?php } ?>
        <ol class="licenca-passos">
            <li>Abra o app no robô. Sem licença, ele mostra a tela <strong>Ativar licença</strong>.</li>
            <li>Digite o código acima no teclado da tela e toque em <strong>Ativar</strong>. Com mais de um robô, use um código <strong>Livre</strong> em cada robô: um código vale para um robô só.</li>
            <li>Pronto: o robô baixa as telas e molduras deste painel sozinho.</li>
        </ol>
        <p class="suave">O serial mostrado é o número de fábrica do robô; ele também aparece na tela de admin do app. Robô que já estava ativado e foi desvinculado: é só digitar o mesmo código de novo.</p>
    <?php } ?>
</section>

<section class="cartao" id="acesso" aria-labelledby="titulo-acesso">
    <h2 id="titulo-acesso">Acesso e inatividade</h2>
    <p class="suave pequeno">Estes ajustes valem para todos os robôs deste painel.</p>
    <form method="post" action="<?php echo h($urlPagina); ?>" class="formulario formulario-linha">
        <?php echo camposOcultos('salvar_acesso'); ?>
        <label>PIN da tela de admin do robô
            <span class="suave pequeno">De 4 a 8 números.</span>
            <input type="text" name="pin_admin" value="<?php echo h($cliente['config']['pin_admin']); ?>" inputmode="numeric" pattern="[0-9]{4,8}" minlength="4" maxlength="8" required autocomplete="off" data-so-numeros="O PIN precisa ter de 4 a 8 números (só números, sem letras ou espaços).">
        </label>
        <label>Fechar após inatividade
            <span class="suave pequeno">Tempo em segundos na tela inicial. O padrão é 60.</span>
            <input type="number" name="tempo_inatividade" value="<?php echo painelTempoInatividade($cliente['config']); ?>" min="1" max="<?php echo PAINEL_TEMPO_INATIVIDADE_MAXIMO; ?>" step="1" required>
        </label>
        <button type="submit" class="botao botao-primario">Salvar ajustes</button>
    </form>
</section>

<section class="cartao" id="visual" aria-labelledby="titulo-visual">
    <header class="visual-cabecalho">
        <h2 id="titulo-visual">Visual do app</h2>
        <a class="pequeno" href="#acesso">PIN e inatividade</a>
    </header>
    <?php if ($alvosVisuais) { ?>
        <nav aria-label="Qual visual editar">
            <ul class="visual-perfis">
                <li>
                    <a class="visual-perfil" href="cliente.php#visual"<?php echo $codigoVisual === '' ? ' aria-current="page"' : ''; ?>>
                        <span class="visual-perfil-titulo">
                            <strong>Visual compartilhado</strong>
                            <?php if ($codigoVisual === '') { ?><span class="visual-atual">Selecionado</span><?php } ?>
                        </span>
                        <span class="visual-perfil-detalhe">Configuração comum do painel</span>
                    </a>
                </li>
                <?php foreach ($alvosVisuais as $alvo => $codigo) {
                    $robo = $robosDoPainel[$codigo];
                    $selecionado = $alvo === $alvoVisual;
                    $proprio = painelVisualProprio($cliente, $codigo); ?>
                    <li>
                        <a class="visual-perfil" href="cliente.php?visual=<?php echo h($alvo); ?>#visual"<?php echo $selecionado ? ' aria-current="page"' : ''; ?>>
                            <span class="visual-perfil-titulo">
                                <strong><?php echo h($robo['nome_painel']); ?></strong>
                                <?php if ($selecionado) { ?><span class="visual-atual">Selecionado</span><?php } ?>
                            </span>
                            <span class="visual-perfil-detalhe"><?php echo h($robo['identificador']); ?></span>
                            <span class="visual-modo<?php echo $proprio ? ' visual-modo-proprio' : ''; ?>"><?php echo $proprio ? 'Visual próprio' : 'Usa o compartilhado'; ?></span>
                        </a>
                    </li>
                <?php } ?>
            </ul>
        </nav>
    <?php } ?>
    <?php if ($codigoVisual === '') { ?>
        <p class="suave visual-escopo">Você está editando o <strong>visual compartilhado</strong>, usado por todos os robôs que seguem essa configuração.</p>
    <?php } else { ?>
        <div class="visual-escopo">
            <p class="suave">
                <?php echo $visualProprio ? 'As edições abaixo valem para este robô.' : 'Ative um visual próprio para editar as telas e molduras deste robô.'; ?>
            </p>
            <form method="post" action="<?php echo h($urlPagina); ?>">
                <?php echo camposOcultos('modo_visual', array('modo' => $visualProprio ? 'compartilhado' : 'proprio')); ?>
                <button type="submit" class="botao<?php echo $visualProprio ? '' : ' botao-primario'; ?>"><?php echo $visualProprio ? 'Usar visual compartilhado' : 'Usar visual próprio'; ?></button>
            </form>
        </div>
    <?php } ?>
</section>

<?php if ($editorVisivel) { ?>
<!-- Trajeto: as telas na ordem em que a pessoa passa por elas -->
<section class="cartao" id="trajeto">
    <h2>Trajeto do app</h2>
    <p class="suave">É por estas telas que a pessoa passa, nesta ordem. Toque numa tela para editar. Cada tela tem <strong>uma imagem inteira</strong> (o fundo já com os textos) e <strong>botões</strong> que você troca, move e muda de tamanho.</p>
    <p class="suave pequeno">Botão sem imagem usa o desenho padrão do app, na <a href="#cor">cor principal</a>. Para usar uma imagem no botão, envie um PNG com fundo transparente; o tamanho na tela você ajusta no editor.</p>
    <ol class="trajeto">
    <?php
    // Telas "ramo" (aviso e escolha da moldura) ficam embaixo da tela de onde saem
    $principais = array();
    foreach ($telas as $idTela => $tela) {
        if (!empty($tela['ramo']) && $principais) {
            $principais[count($principais) - 1]['ramos'][$idTela] = $tela;
        } else {
            $principais[] = array('id' => $idTela, 'tela' => $tela, 'ramos' => array());
        }
    }
    foreach ($principais as $p) {
        $todas = array($p['id'] => $p['tela']) + $p['ramos']; ?>
        <li class="passo">
        <?php foreach ($todas as $idTela => $tela) {
            $ancora = 'tela-' . (isset($tela['secao']) ? $tela['secao'] : $idTela); ?>
            <a class="passo-cartao<?php echo !empty($tela['ramo']) ? ' passo-ramo' : ''; ?>" href="#<?php echo h($ancora); ?>">
                <span class="passo-mini<?php echo !empty($tela['video']) ? ' passo-mini-video' : (!empty($tela['secao']) ? ' passo-mini-escolha' : ''); ?>" style="<?php echo estiloMiniatura($tela, $id, $midia, $slots, $urlMoldura); ?>"></span>
                <span class="passo-titulo"><span class="passo-num"><?php echo h($tela['numero']); ?></span> <?php echo h($tela['titulo']); ?></span>
                <?php if (!empty($tela['elementos'])) { ?>
                <span class="passo-botoes">
                    <?php foreach ($tela['elementos'] as $el) {
                        if ($el['tipo'] !== 'botao') continue; ?>
                        <span><?php echo h($el['texto']); ?> → <?php echo h(isset($telas[$el['destino']]) ? $telas[$el['destino']]['numero'] : $el['destino']); ?></span>
                    <?php } ?>
                </span>
                <?php } elseif (!empty($tela['video'])) { ?>
                    <span class="passo-botoes"><span>Vai sozinha para 6</span></span>
                <?php } ?>
            </a>
        <?php } ?>
        </li>
    <?php } ?>
        <li class="passo passo-fim"><span>↺ Volta para<br><strong>1. Início</strong></span></li>
    </ol>
</section>

<?php foreach ($telas as $idTela => $tela) {
    if (!empty($tela['secao'])) {
        continue;
    }
    $temEditor = !empty($tela['elementos']); ?>
<section class="cartao tela" id="tela-<?php echo h($idTela); ?>" data-tela-secao="<?php echo h($idTela); ?>">
    <div class="tela-cabecalho">
        <span class="tela-num"><?php echo h($tela['numero']); ?></span>
        <div>
            <h2><?php echo h($tela['titulo']); ?></h2>
            <p class="suave"><?php echo h($tela['descricao']); ?></p>
        </div>
    </div>

    <?php if ($temEditor) {
        // Dados do editor: imagem da tela e os elementos com a posição atual
        $elementosEditor = array();
        foreach ($tela['elementos'] as $idEl => $el) {
            $slotEl = $el['tipo'] === 'botao' ? 'botao_' . $idEl : ($el['tipo'] === 'imagem' ? $idEl : '');
            $arquivo = $slotEl !== '' && isset($midia[$slotEl]) ? $midia[$slotEl] : null;
            $aspecto = isset($el['aspecto']) ? $el['aspecto'] : null;
            if ($arquivo && !empty($arquivo['largura']) && !empty($arquivo['altura'])) {
                $aspecto = $arquivo['largura'] / $arquivo['altura'];
            }
            $elementosEditor[] = array(
                'id' => $idEl,
                'titulo' => $el['titulo'],
                'tipo' => $el['tipo'],
                'texto' => isset($el['texto']) ? $el['texto'] : '',
                'estilo' => isset($el['estilo']) ? $el['estilo'] : '',
                'icone' => isset($el['icone']) ? $el['icone'] : '',
                'aspecto' => $aspecto,
                'imagem' => $slotEl !== '' ? urlSlot($id, $midia, $slots, $slotEl) : null,
                'condicional' => isset($el['aviso']),
                'padrao' => painelRetangulo($el['padrao'][0], $el['padrao'][1], $el['padrao'][2], $el['padrao'][3]),
                'atual' => $layout[$idEl]
            );
        }
        $fixos = array();
        foreach (isset($tela['fixos']) ? $tela['fixos'] : array() as $fixo) {
            $fixos[] = array('titulo' => $fixo['titulo'], 'x' => $fixo['retangulo'][0], 'y' => $fixo['retangulo'][1], 'w' => $fixo['retangulo'][2], 'h' => $fixo['retangulo'][3]);
        }
        $dadosEditor = array(
            'tela' => $idTela,
            'cor' => $config['cor_primaria'],
            'fundo' => !empty($tela['fundo']) ? urlSlot($id, $midia, $slots, $tela['fundo']) : null,
            'camera' => !empty($tela['camera']) ? $urlMoldura : null,
            'elementos' => $elementosEditor,
            'fixos' => $fixos
        ); ?>
    <div class="editor" data-editor="<?php echo h(json_encode($dadosEditor)); ?>">
        <div class="editor-palco">
            <div class="palco" aria-label="Prévia da tela <?php echo h($tela['titulo']); ?>"></div>
            <?php if ($idTela === 'camera') { ?>
            <!-- Sem o botão Moldura, o app fecha o espaço dele (AppConfig.retanguloCamera) -->
            <div class="previa-modo">
                <span class="previa-modo-rotulo">Prévia:</span>
                <div class="previa-modo-botoes" role="group" aria-label="Prévia da câmera">
                    <button type="button" class="ativo" data-comando="modo-com" aria-pressed="true">Com 2 ou mais molduras</button>
                    <button type="button" data-comando="modo-sem" aria-pressed="false">Com 1 moldura</button>
                </div>
            </div>
            <p class="suave pequeno previa-explica">
                Com só 1 moldura, o botão Moldura não aparece: o Início sobe para o lugar dele e os dois botões (com o balão) ficam centralizados no espaço.
                <?php if (count($molduras) < 2) { ?><strong>Hoje há <?php echo $molduras ? '1 moldura' : 'só a moldura padrão'; ?>, então o robô mostra a tela assim.</strong><?php } ?>
                Para arrastar os botões, use "Com 2 ou mais molduras".
            </p>
            <?php } ?>
            <p class="suave pequeno palco-dica">Arraste para mover. Puxe o canto <strong>◢</strong> para mudar o tamanho (o meio do elemento fica no lugar). As setas do teclado fazem ajustes finos.</p>
            <div class="medidas" hidden>
                <strong class="medidas-nome"></strong>
                <div class="medidas-campos">
                    <label>Horizontal <input type="number" data-medida="cx" step="0.1" min="0" max="100"><span>%</span></label>
                    <label>Vertical <input type="number" data-medida="cy" step="0.1" min="0" max="100"><span>%</span></label>
                    <label>Largura <input type="number" data-medida="w" step="0.1" min="2" max="100"><span>%</span></label>
                    <label>Altura <input type="number" data-medida="h" step="0.1" min="2" max="100"><span>%</span></label>
                </div>
                <p class="suave pequeno medidas-ajuda">Horizontal e vertical marcam o <strong>meio</strong> do elemento: 50% e 50% é o centro da tela.</p>
                <div class="acoes">
                    <button type="button" class="botao botao-pequeno" data-comando="centralizar">Centralizar na horizontal</button>
                    <button type="button" class="botao botao-pequeno" data-comando="padrao-um">Voltar este ao lugar padrão</button>
                </div>
            </div>
            <form method="post" action="<?php echo h($urlPagina); ?>" class="salvar-layout">
                <?php echo camposOcultos('salvar_layout', array('tela' => $idTela)); ?>
                <button type="submit" class="botao botao-primario" disabled>Salvar posições</button>
                <button type="button" class="botao" data-comando="padrao-todos">Tudo no lugar padrão</button>
                <span class="estado-layout suave pequeno"></span>
            </form>
        </div>

        <div class="editor-lista">
            <?php if (!empty($tela['fundo'])) {
                $slotFundo = $tela['fundo'];
                $atual = isset($midia[$slotFundo]) ? $midia[$slotFundo] : null; ?>
            <div class="peca">
                <div class="peca-topo">
                    <span class="peca-mini" style="background-image:url('<?php echo h(urlSlot($id, $midia, $slots, $slotFundo)); ?>')"></span>
                    <div>
                        <strong>Imagem da tela</strong>
                        <span class="suave pequeno"><?php echo $atual ? 'Atual: ' . h($atual['nome_original']) . ' (' . (int) $atual['largura'] . 'x' . (int) $atual['altura'] . ')' : 'Usando a imagem padrão do app'; ?></span>
                    </div>
                </div>
                <p class="suave pequeno">Uma imagem só, 1920x1080 (JPG ou PNG), com o fundo e os textos desta tela. Deixe livre o espaço dos botões.</p>
                <div class="acoes">
                    <form method="post" enctype="multipart/form-data" action="<?php echo h($urlPagina); ?>">
                        <?php echo camposOcultos('enviar_midia', array('slot' => $slotFundo, 'tela' => $idTela)); ?>
                        <?php echo campoArquivo('Trocar imagem da tela', 'arquivo', 'image/png,image/jpeg'); ?>
                    </form>
                    <?php if ($atual) { ?>
                    <form method="post" action="<?php echo h($urlPagina); ?>" data-confirmar-titulo="Voltar à imagem padrão?" data-confirmar="A tela <?php echo h($tela['titulo']); ?> deixa de usar a imagem enviada e volta a usar a imagem padrão do app." data-confirmar-botao="Voltar ao padrão">
                        <?php echo camposOcultos('remover_midia', array('slot' => $slotFundo, 'tela' => $idTela)); ?>
                        <button type="submit" class="botao botao-pequeno">Voltar ao padrão</button>
                    </form>
                    <?php } ?>
                </div>
            </div>
            <?php } ?>

            <?php foreach ($tela['elementos'] as $idEl => $el) {
                $slotEl = $el['tipo'] === 'botao' ? 'botao_' . $idEl : ($el['tipo'] === 'imagem' ? $idEl : '');
                $atual = $slotEl !== '' && isset($midia[$slotEl]) ? $midia[$slotEl] : null; ?>
            <div class="peca peca-elemento" data-elemento="<?php echo h($idEl); ?>" tabindex="0">
                <div class="peca-topo">
                    <?php if ($slotEl !== '' && urlSlot($id, $midia, $slots, $slotEl)) { ?>
                        <span class="peca-mini peca-mini-botao xadrez" style="background-image:url('<?php echo h(urlSlot($id, $midia, $slots, $slotEl)); ?>')"></span>
                    <?php } else { ?>
                        <span class="peca-mini peca-mini-icone"><?php echo $el['tipo'] === 'foto' ? '▣' : ($el['tipo'] === 'qrcode' ? '▦' : '▭'); ?></span>
                    <?php } ?>
                    <div>
                        <strong><?php echo h($el['titulo']); ?></strong>
                        <?php if ($el['tipo'] === 'botao') { ?>
                            <span class="destino">→ <?php echo h(painelDestinoTexto($el['destino'])); ?></span>
                            <span class="suave pequeno"><?php echo $atual ? 'Imagem: ' . h($atual['nome_original']) : 'Usando o botão padrão do app'; ?></span>
                        <?php } elseif ($el['tipo'] === 'imagem') { ?>
                            <span class="suave pequeno"><?php echo $atual ? 'Imagem: ' . h($atual['nome_original']) : 'Usando a imagem padrão do app'; ?></span>
                        <?php } elseif ($el['tipo'] === 'foto') { ?>
                            <span class="suave pequeno">Onde aparece a foto que a pessoa tirou.</span>
                        <?php } else { ?>
                            <span class="suave pequeno">Onde aparece o QR Code para baixar a foto. É sempre quadrado.</span>
                        <?php } ?>
                        <?php if (isset($el['aviso'])) { ?><span class="suave pequeno"><?php echo h($el['aviso']); ?></span><?php } ?>
                    </div>
                </div>
                <?php if ($slotEl !== '') { ?>
                <div class="acoes">
                    <form method="post" enctype="multipart/form-data" action="<?php echo h($urlPagina); ?>">
                        <?php echo camposOcultos('enviar_midia', array('slot' => $slotEl, 'tela' => $idTela)); ?>
                        <?php echo campoArquivo($el['tipo'] === 'botao' ? ($atual ? 'Trocar imagem do botão' : 'Usar uma imagem no botão') : 'Trocar imagem', 'arquivo', 'image/png,image/jpeg'); ?>
                    </form>
                    <?php if ($atual) { ?>
                    <form method="post" action="<?php echo h($urlPagina); ?>" data-confirmar-titulo="<?php echo $el['tipo'] === 'botao' ? 'Voltar ao botão padrão?' : 'Voltar à imagem padrão?'; ?>" data-confirmar="<?php echo h($el['titulo']); ?> deixa de usar a imagem enviada e volta <?php echo $el['tipo'] === 'botao' ? 'a ser o botão padrão do app' : 'a usar a imagem padrão do app'; ?>." data-confirmar-botao="Voltar ao padrão">
                        <?php echo camposOcultos('remover_midia', array('slot' => $slotEl, 'tela' => $idTela)); ?>
                        <button type="submit" class="botao botao-pequeno"><?php echo $el['tipo'] === 'botao' ? 'Voltar ao botão padrão' : 'Voltar ao padrão'; ?></button>
                    </form>
                    <?php } ?>
                </div>
                <?php } ?>
            </div>
            <?php } ?>
        </div>
    </div>
    <?php } ?>

    <?php if ($idTela === 'camera') { ?>
    <div class="subsecao" id="tela-escolha_moldura">
        <div class="cartao-topo">
            <div>
                <h3>Molduras</h3>
                <p class="suave">PNG com fundo transparente, 1920x1080 (paisagem). Com 2 ou mais molduras, a tela <strong>3a. Escolha da moldura</strong> abre antes da foto, nesta ordem, e aparece o botão Moldura.</p>
            </div>
            <form method="post" enctype="multipart/form-data" action="<?php echo h($urlPagina); ?>">
                <?php echo camposOcultos('enviar_molduras', array('tela' => $idTela)); ?>
                <label class="botao botao-primario">+ Adicionar molduras
                    <input type="file" name="molduras[]" accept="image/png" multiple data-auto class="oculto">
                </label>
                <noscript><button type="submit" class="botao">Enviar</button></noscript>
            </form>
        </div>

        <div class="grade grade-molduras">
        <?php if (!$molduras) { ?>
            <figure class="item">
                <div class="previa xadrez"><img src="padrao/moldura1.png" alt="Moldura padrão"></div>
                <figcaption><span class="selo">Padrão do app</span> Nenhuma moldura enviada: o app usa a moldura que veio instalada.</figcaption>
            </figure>
        <?php } ?>
        <?php foreach ($molduras as $i => $moldura) { ?>
            <figure class="item">
                <div class="previa xadrez"><img src="<?php echo h(painelUrlMidia($id, $moldura['arquivo'])); ?>" alt="" loading="lazy"></div>
                <figcaption>
                    <strong><?php echo ($i + 1) . '. ' . h($moldura['nome_original']); ?></strong>
                    <span class="suave pequeno"><?php echo (int) $moldura['largura'] . 'x' . (int) $moldura['altura']; ?> · <?php echo h(painelTamanhoTexto($moldura['tamanho'])); ?></span>
                </figcaption>
                <div class="acoes">
                    <form method="post" action="<?php echo h($urlPagina); ?>">
                        <?php echo camposOcultos('mover_moldura', array('indice' => $i, 'direcao' => 'antes', 'tela' => $idTela)); ?>
                        <button type="submit" class="botao botao-pequeno" title="Mover para antes" <?php echo $i === 0 ? 'disabled' : ''; ?>>←</button>
                    </form>
                    <form method="post" action="<?php echo h($urlPagina); ?>">
                        <?php echo camposOcultos('mover_moldura', array('indice' => $i, 'direcao' => 'depois', 'tela' => $idTela)); ?>
                        <button type="submit" class="botao botao-pequeno" title="Mover para depois" <?php echo $i === count($molduras) - 1 ? 'disabled' : ''; ?>>→</button>
                    </form>
                    <form method="post" action="<?php echo h($urlPagina); ?>" data-confirmar-titulo="Remover a moldura?" data-confirmar="A moldura <?php echo h($moldura['nome_original']); ?> sai da lista. Para usá-la de novo, é preciso enviar o arquivo outra vez." data-confirmar-botao="Remover" data-confirmar-perigo>
                        <?php echo camposOcultos('remover_moldura', array('indice' => $i, 'tela' => $idTela)); ?>
                        <button type="submit" class="botao botao-pequeno botao-perigo">Remover</button>
                    </form>
                </div>
            </figure>
        <?php } ?>
        </div>
    </div>
    <?php } ?>

    <?php if (!empty($tela['video'])) {
        $slotVideo = $slots['video_carregamento'];
        $video = isset($midia['video_carregamento']) ? $midia['video_carregamento'] : null; ?>
    <p class="suave"><?php echo h($slotVideo['descricao']); ?> Limite do servidor: <?php echo h(painelLimiteUploadTexto()); ?>.</p>
    <div class="video-linha">
        <div class="previa previa-video">
            <?php if ($video) { ?>
                <video src="<?php echo h(painelUrlMidia($id, $video['arquivo'])); ?>" controls muted loop playsinline preload="metadata"></video>
            <?php } else { ?>
                <div class="previa-vazia"><span class="selo">Padrão do app</span><br>O app usa o vídeo que veio instalado.</div>
            <?php } ?>
        </div>
        <div>
            <?php if ($video) { ?>
                <p><strong><?php echo h($video['nome_original']); ?></strong><br><span class="suave pequeno"><?php echo h(painelTamanhoTexto($video['tamanho'])); ?> · enviado em <?php echo h(painelDataBr($video['enviado_em'])); ?></span></p>
            <?php } ?>
            <div class="acoes">
                <form method="post" enctype="multipart/form-data" action="<?php echo h($urlPagina); ?>">
                    <?php echo camposOcultos('enviar_midia', array('slot' => 'video_carregamento', 'tela' => $idTela)); ?>
                    <?php echo campoArquivo($video ? 'Trocar vídeo' : 'Enviar vídeo', 'arquivo', 'video/mp4', 'botao botao-primario'); ?>
                </form>
                <?php if ($video) { ?>
                <form method="post" action="<?php echo h($urlPagina); ?>" data-confirmar-titulo="Voltar ao vídeo padrão?" data-confirmar="O app deixa de usar o vídeo enviado e volta a mostrar o vídeo de carregamento que veio instalado." data-confirmar-botao="Voltar ao padrão">
                    <?php echo camposOcultos('remover_midia', array('slot' => 'video_carregamento', 'tela' => $idTela)); ?>
                    <button type="submit" class="botao">Voltar ao padrão</button>
                </form>
                <?php } ?>
            </div>
        </div>
    </div>
    <?php } ?>
</section>
<?php } ?>

<section class="cartao" id="cor">
    <h2><?php echo $codigoVisual === '' ? 'Cor do visual compartilhado' : 'Cor do robô'; ?></h2>
    <form method="post" action="<?php echo h($urlPagina); ?>" class="formulario formulario-linha">
        <?php echo camposOcultos('salvar_aparencia'); ?>
        <label>Cor principal
            <span class="suave pequeno">Botões sem imagem e textos do app.</span>
            <input type="color" name="cor_primaria" value="<?php echo h($config['cor_primaria']); ?>">
        </label>
        <button type="submit" class="botao botao-primario">Salvar cor</button>
    </form>
</section>
<?php } ?>

<!-- Mapa: as etapas na lateral, marcando a que está na tela (só em telas largas) -->
<nav class="mapa" aria-label="Mapa do painel">
    <p class="mapa-titulo">Mapa do app</p>
    <ol>
        <li><a href="#evento" title="Evento atual"><span class="mapa-num mapa-num-extra" aria-hidden="true">★</span><span class="mapa-nome">Evento</span></a></li>
        <li><a href="#licenca" title="Licença do robô"><span class="mapa-num mapa-num-extra" aria-hidden="true">⚿</span><span class="mapa-nome">Licença</span></a></li>
        <li><a href="#acesso" title="Acesso e inatividade"><span class="mapa-num mapa-num-extra" aria-hidden="true">⚙</span><span class="mapa-nome">Acesso</span></a></li>
        <li><a href="#visual" title="Visual do app"><span class="mapa-num mapa-num-extra" aria-hidden="true">◉</span><span class="mapa-nome">Visual</span></a></li>
        <?php if ($editorVisivel) { ?>
        <li><a href="#trajeto" title="Trajeto do app"><span class="mapa-num mapa-num-extra" aria-hidden="true">↦</span><span class="mapa-nome">Trajeto</span></a></li>
        <?php foreach ($telas as $idTela => $tela) {
            $ancora = 'tela-' . (isset($tela['secao']) ? $tela['secao'] : $idTela); ?>
        <li<?php echo !empty($tela['ramo']) ? ' class="mapa-ramo"' : ''; ?>>
            <a href="#<?php echo h($ancora); ?>" title="<?php echo h($tela['numero'] . '. ' . $tela['titulo']); ?>">
                <span class="mapa-num"><?php echo h($tela['numero']); ?></span>
                <span class="mapa-mini<?php echo !empty($tela['video']) ? ' passo-mini-video' : (!empty($tela['secao']) ? ' passo-mini-escolha' : ''); ?>" style="<?php echo estiloMiniatura($tela, $id, $midia, $slots, $urlMoldura); ?>"></span>
                <span class="mapa-nome"><?php echo h($tela['titulo']); ?></span>
            </a>
        </li>
        <?php } ?>
        <li><a href="#cor" title="Cor principal"><span class="mapa-num mapa-num-extra" aria-hidden="true">◐</span><span class="mapa-nome">Cor</span></a></li>
        <?php } ?>
    </ol>
</nav>
<script>
// Marca no mapa a etapa que está na tela: a última seção cujo topo já passou
// de 30% da altura da janela. As anteriores ficam "percorridas" (linha cheia).
(function () {
    var links = Array.prototype.slice.call(document.querySelectorAll('.mapa a'));
    var alvos = links.map(function (a) { return document.getElementById(a.getAttribute('href').slice(1)); });
    var agendado = false;

    function marcar() {
        agendado = false;
        var linha = window.innerHeight * 0.3;
        var atual = 0;
        alvos.forEach(function (alvo, i) {
            if (alvo && alvo.getBoundingClientRect().top <= linha) atual = i;
        });
        // No fim da página, a última seção pode nunca chegar à linha
        if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 2) atual = links.length - 1;
        links.forEach(function (a, i) {
            a.parentNode.classList.toggle('mapa-ativo', i === atual);
            a.parentNode.classList.toggle('mapa-passou', i < atual);
            if (i === atual) { a.setAttribute('aria-current', 'step'); } else { a.removeAttribute('aria-current'); }
        });
    }
    function agendar() {
        if (!agendado) { agendado = true; window.requestAnimationFrame(marcar); }
    }
    window.addEventListener('scroll', agendar, { passive: true });
    window.addEventListener('resize', agendar);
    marcar();
})();
</script>
<script src="editor.js?v=4"></script>
<?php
painelRodape();
