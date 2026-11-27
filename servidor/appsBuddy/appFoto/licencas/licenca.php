<?php
// Central de Licenças: uma licença (dados, robôs vinculados, suspender, excluir).
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib.php';
licExigirLogin();

$id = isset($_GET['id']) ? (string) $_GET['id'] : '';
$licenca = licBuscar($id);
if (!$licenca) {
    licAviso('erro', 'Licença não encontrada.');
    licIr('./');
}
$voltar = 'licenca.php?id=' . $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!licConferirCsrf()) {
        licAviso('erro', 'A página expirou. Tente de novo.');
        licIr($voltar);
    }
    $acao = isset($_POST['acao']) ? (string) $_POST['acao'] : '';

    if ($acao === 'salvar') {
        $cliente = trim(isset($_POST['cliente']) ? (string) $_POST['cliente'] : '');
        $pasta = licNormalizarPasta(isset($_POST['painel']) ? $_POST['painel'] : '');
        $painel = $pasta === null ? null : licPainelDaPasta($pasta);
        $vence = trim(isset($_POST['vence']) ? (string) $_POST['vence'] : '');
        $robos = (int) (isset($_POST['robos']) ? $_POST['robos'] : 0);
        $observacao = licCortar(trim(isset($_POST['observacao']) ? (string) $_POST['observacao'] : ''), 500);
        if (!preg_match('/^.{1,80}$/u', $cliente)) {
            licAviso('erro', 'Digite o nome do cliente (até 80 letras).');
        } elseif (!$painel) {
            licAviso('erro', 'Pasta do painel inválida: use letras minúsculas, números, traço ou _ (ex.: clienteteste01). Os nomes ' . implode(', ', licPastasReservadas()) . ' são do sistema.');
        } elseif ($outraLicenca = licLicencaDoPainel($painel, $id)) {
            licAviso('erro', 'Esse painel já é do cliente "' . $outraLicenca['cliente'] . '" (' . licCodigoTexto($outraLicenca['id']) . '). Cada cliente precisa de uma pasta própria, senão os dois entrariam no mesmo painel.');
        } elseif ($vence !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $vence) || !strtotime($vence))) {
            licAviso('erro', 'Data de vencimento inválida.');
        } elseif ($robos < 1 || $robos > 100) {
            licAviso('erro', 'O número de robôs vai de 1 a 100.');
        } else {
            $recusaRobos = null;
            $salvo = licAlterar(function ($todas) use ($id, $cliente, $painel, $vence, $robos, $observacao, &$recusaRobos) {
                if (!isset($todas[$id])) {
                    return false;
                }
                // Menos robôs: apaga códigos livres, do último para o primeiro
                // (o 1º nunca sai). Mais robôs: licAlterar() cria os códigos.
                $codigos = $todas[$id]['codigos'];
                $tirar = count($codigos) - $robos;
                for ($i = count($codigos) - 1; $i >= 1 && $tirar > 0; $i--) {
                    if (licDonoDoCodigo($todas[$id], $codigos[$i]) === null) {
                        array_splice($codigos, $i, 1);
                        $tirar--;
                    }
                }
                if ($tirar > 0) {
                    $recusaRobos = 'Para ficar com ' . ($robos === 1 ? '1 robô' : $robos . ' robôs') . ', desvincule antes ' . ($tirar === 1 ? 'um robô' : $tirar . ' robôs') . ' na lista "Robôs": só dá para apagar código livre.';
                    return false;
                }
                $todas[$id]['codigos'] = $codigos;
                $todas[$id]['cliente'] = $cliente;
                $todas[$id]['painel'] = $painel;
                $todas[$id]['vence'] = $vence;
                $todas[$id]['robos'] = $robos;
                $todas[$id]['observacao'] = $observacao;
                return $todas;
            });
            if ($recusaRobos !== null) {
                licAviso('erro', $recusaRobos);
            } elseif ($salvo === null) {
                licAviso('erro', 'Não foi possível gravar.');
            } else {
                $mudouPainel = $painel !== $licenca['painel'];
                $mudouRobos = count($salvo[$id]['codigos']) - count($licenca['codigos']);
                // Registro: só o que mudou
                $mudancas = array();
                if ($cliente !== $licenca['cliente']) {
                    $mudancas[] = 'cliente "' . $licenca['cliente'] . '" → "' . $cliente . '"';
                }
                if ($mudouPainel) {
                    $mudancas[] = 'painel ' . $licenca['painel'] . ' → ' . $painel;
                }
                if ($vence !== $licenca['vence']) {
                    $mudancas[] = 'vencimento ' . ($licenca['vence'] === '' ? 'vitalícia' : licDataBr($licenca['vence'])) . ' → ' . ($vence === '' ? 'vitalícia' : licDataBr($vence));
                }
                if ($mudouRobos) {
                    $mudancas[] = 'robôs ' . count($licenca['codigos']) . ' → ' . count($salvo[$id]['codigos']);
                }
                if ($observacao !== $licenca['observacao']) {
                    $mudancas[] = 'observação';
                }
                if ($mudancas) {
                    licRegistrar('equipe', 'Dados da licença alterados', $salvo[$id], implode(' · ', $mudancas));
                }
                if ($mudouRobos > 0) {
                    licAviso('ok', $mudouRobos === 1 ? 'Criado 1 código novo, para o robô a mais.' : 'Criados ' . $mudouRobos . ' códigos novos, um para cada robô a mais.');
                } elseif ($mudouRobos < 0) {
                    licAviso('ok', $mudouRobos === -1 ? 'Apagado 1 código livre.' : 'Apagados ' . -$mudouRobos . ' códigos livres.');
                }
                licAviso('ok', $mudouPainel
                    ? 'Licença salva. Os robôs passam a usar o painel novo quando renovarem a licença (ao sincronizar ou em até 6 horas).'
                    : 'Licença salva.');
                // Pasta nova sem painel: cria o painel do cliente agora
                if ($mudouPainel && $pasta !== '' && !licPainelInstalado($pasta)) {
                    $erroPainel = licInstalarPainel($pasta, $salvo[$id]);
                    if ($erroPainel === null) {
                        licRegistrar('equipe', 'Painel do cliente criado', $salvo[$id], $painel);
                    }
                    licAviso($erroPainel === null ? 'ok' : 'erro', $erroPainel === null
                        ? 'Painel do cliente criado em ' . $painel . '.'
                        : 'O painel não foi criado: ' . $erroPainel);
                }
            }
        }
        licIr($voltar);
    }

    // Painel do cliente: criar (pasta sem painel) ou atualizar o código
    if ($acao === 'instalar_painel' || $acao === 'atualizar_painel') {
        $pasta = licPastaDoPainel($licenca['painel']);
        if ($pasta === null || $pasta === '') {
            licAviso('erro', $pasta === '' ? 'Esta licença usa o painel principal, que já existe.' : 'O painel desta licença fica fora de ' . licBasePaineis() . '.');
            licIr($voltar);
        }
        $atualizar = $acao === 'atualizar_painel';
        $erroPainel = licInstalarPainel($pasta, $licenca, $atualizar);
        if ($erroPainel !== null) {
            licAviso('erro', $erroPainel);
        } else {
            licRegistrar('equipe', $atualizar ? 'Arquivos do painel atualizados' : 'Painel do cliente criado', $licenca, $licenca['painel']);
            licUsoPainel($pasta, true); // as fotos podem ter mudado de pasta
            licAviso('ok', $atualizar
                ? 'Arquivos do painel atualizados com a versão do painel principal. As fotos do cliente ficam em fotos/; dados, molduras, eventos e fotos foram mantidos.'
                : 'Painel do cliente criado em ' . $licenca['painel'] . '.');
        }
        licIr($voltar);
    }

    // Usuário e senha do cliente no login único (pasta login/)
    if ($acao === 'acesso') {
        $usuario = licNormalizarUsuario(isset($_POST['usuario']) ? $_POST['usuario'] : '');
        $senhaDigitada = isset($_POST['senha']) ? (string) $_POST['senha'] : '';
        $outro = $usuario ? licBuscarPorUsuario($usuario) : null;
        if (!$usuario) {
            licAviso('erro', 'Usuário: de 3 a 40 letras minúsculas, números, ponto, traço ou _.');
        } elseif ($outro && $outro['id'] !== $id) {
            licAviso('erro', 'O usuário "' . $usuario . '" já é de outro cliente (' . $outro['cliente'] . ').');
        } elseif ($senhaDigitada !== '' && strlen($senhaDigitada) < 8) {
            licAviso('erro', 'A senha precisa ter pelo menos 8 caracteres (ou deixe em branco para gerar uma).');
        } else {
            $trocarSenha = !empty($_POST['nova_senha']) || $senhaDigitada !== '' || empty($licenca['senha_hash']);
            $senha = $senhaDigitada !== '' ? $senhaDigitada : licGerarSenha();
            licAlterar(function ($todas) use ($id, $usuario, $trocarSenha, $senha) {
                if (!isset($todas[$id])) {
                    return false;
                }
                $todas[$id]['usuario'] = $usuario;
                if ($trocarSenha) {
                    $todas[$id]['senha_hash'] = password_hash($senha, PASSWORD_DEFAULT);
                }
                return $todas;
            });
            $usuarioAntes = isset($licenca['usuario']) ? $licenca['usuario'] : '';
            $detalhes = array();
            if ($usuario !== $usuarioAntes) {
                $detalhes[] = 'usuário ' . ($usuarioAntes === '' ? '(nenhum)' : $usuarioAntes) . ' → ' . $usuario;
            }
            if ($trocarSenha) {
                $detalhes[] = $senhaDigitada !== '' ? 'senha definida pela equipe' : 'senha nova gerada';
            }
            if ($detalhes) {
                licRegistrar('equipe', 'Acesso do cliente alterado', $licenca, implode(' · ', $detalhes));
            }
            if ($trocarSenha) {
                $_SESSION['lic_senha_nova'] = array('id' => $id, 'usuario' => $usuario, 'senha' => $senha);
            } else {
                licAviso('ok', 'Usuário salvo. A senha continua a mesma.');
            }
        }
        licIr($voltar . '#acesso');
    }

    // Suporte: a equipe entra no painel do cliente sem a senha dele
    if ($acao === 'abrir_painel') {
        $url = licUrlEntrarPainel($licenca);
        if ($url === null) {
            licAviso('erro', 'Não foi possível assinar o acesso. Confira a chave em config/licencas.local.php.');
            licIr($voltar);
        }
        licRegistrar('equipe', 'Equipe abriu o painel do cliente', $licenca);
        licIr($url);
    }

    if ($acao === 'suspender' || $acao === 'reativar') {
        licAlterar(function ($todas) use ($id, $acao) {
            if (!isset($todas[$id])) {
                return false;
            }
            $todas[$id]['situacao'] = $acao === 'suspender' ? 'suspensa' : 'ativa';
            return $todas;
        });
        licRegistrar('equipe', $acao === 'suspender' ? 'Licença suspensa' : 'Licença reativada', $licenca);
        licAviso('ok', $acao === 'suspender'
            ? 'Licença suspensa. Os robôs travam na próxima vez que falarem com a central, e o painel recusa os robôs quando a licença guardada neles passar da validade.'
            : 'Licença reativada.');
        licIr($voltar);
    }

    if ($acao === 'desvincular') {
        $dispositivo = isset($_POST['dispositivo']) ? (string) $_POST['dispositivo'] : '';
        $roboSaindo = isset($licenca['dispositivos'][$dispositivo]) ? $licenca['dispositivos'][$dispositivo] : null;
        licAlterar(function ($todas) use ($id, $dispositivo) {
            if (!isset($todas[$id]['dispositivos'][$dispositivo])) {
                return false;
            }
            unset($todas[$id]['dispositivos'][$dispositivo]);
            return $todas;
        });
        if ($roboSaindo) {
            licRegistrar('equipe', 'Robô desvinculado pela central', $licenca,
                licNomeRobo($roboSaindo) . ' · código ' . licCodigoTexto($roboSaindo['codigo']) . ' ficou livre · ID ' . $dispositivo);
        }
        licAviso('ok', 'Robô desvinculado. O código dele ficou livre; o robô trava na próxima vez que falar com a central.');
        licIr($voltar);
    }

    if ($acao === 'excluir') {
        $confirmacao = licNormalizarCodigo(isset($_POST['confirmar_codigo']) ? $_POST['confirmar_codigo'] : '');
        if ($confirmacao !== $id) {
            licAviso('erro', 'Para excluir, digite o código da licença.');
            licIr($voltar);
        }
        // Cópia na hora: dá para desfazer em "Backup"
        licBackupAgora('antes-de-excluir');
        $salvoExcluir = licAlterar(function ($todas) use ($id) {
            unset($todas[$id]);
            return $todas;
        });
        if ($salvoExcluir === null) {
            licAviso('erro', 'Não foi possível gravar.');
            licIr($voltar);
        }
        licRegistrar('equipe', 'Licença excluída', $licenca, licCodigoTexto($id) . ' · ' . count($licenca['dispositivos']) . ' robô(s) vinculado(s) · painel ' . $licenca['painel']);
        licAviso('ok', 'Licença ' . licCodigoTexto($id) . ' excluída. Se foi engano, ela pode voltar pela cópia "Antes de excluir uma licença", em Backup.');
        licIr('./');
    }
    licIr($voltar);
}

$situacao = licSituacao($licenca);
$codigo = licCodigoTexto($licenca['id']);
$pastaAtual = licPastaDoPainel($licenca['painel']);
$painelInstalado = $pastaAtual === null || licPainelInstalado($pastaAtual);

// Senha recém-criada: aparece uma vez só
$senhaNova = null;
if (isset($_SESSION['lic_senha_nova']) && $_SESSION['lic_senha_nova']['id'] === $id) {
    $senhaNova = $_SESSION['lic_senha_nova'];
}
unset($_SESSION['lic_senha_nova']);
$config = licConfig();
$vagas = licVagas($licenca);
$usados = count($licenca['dispositivos']);
$usoPainel = $pastaAtual !== null && licPainelInstalado($pastaAtual) ? licUsoPainel($pastaAtual) : null;
$painelSemLicenca = $pastaAtual !== null && licPainelInstalado($pastaAtual) && !licPainelExigeLicenca($pastaAtual);
// Evento do painel (atual, encerrado por último; null = painel fora daqui).
// Sem evento atual, os robôs não tiram fotos.
$eventoPainel = $pastaAtual !== null && licPainelInstalado($pastaAtual) ? licEventoDoPainel($pastaAtual, $licenca) : null;
$ultimasAcoes = licLerRegistro(8, $id);

licTopo($licenca['cliente'], true);
?>
<p><a href="./" class="voltar">← Todas as licenças</a></p>

<section class="cartao licenca-cabeca">
    <div>
        <h1><?php echo h($licenca['cliente']); ?></h1>
        <p class="suave"><?php echo h($licenca['painel']); ?></p>
        <?php if ($usoPainel) {
            $porcentoPainel = licPorcentoUso($usoPainel);
            ?>
            <p class="licenca-uso-fotos<?php echo $porcentoPainel >= 100 ? ' uso-texto-cheio' : ($porcentoPainel >= 80 ? ' uso-texto-quase' : ''); ?>">
                <span class="uso-mini uso-<?php echo $porcentoPainel >= 100 ? 'cheio' : ($porcentoPainel >= 80 ? 'quase' : 'ok'); ?>" aria-hidden="true"><span style="width:<?php echo number_format(min(100, $porcentoPainel), 1, '.', ''); ?>%"></span></span>
                Fotos: <?php echo h(licUsoTexto($usoPainel)); ?>
            </p>
        <?php } ?>
        <?php if ($eventoPainel && $eventoPainel['atual'] !== '') { ?>
            <p class="licenca-evento">Evento padrão: <strong><?php echo h($eventoPainel['atual']); ?></strong></p>
        <?php } elseif ($eventoPainel && $eventoPainel['encerrado'] !== '') { ?>
            <p class="licenca-evento">Evento padrão encerrado: <strong><?php echo h($eventoPainel['encerrado']); ?></strong><?php echo $eventoPainel['encerrado_em'] ? ' em ' . h(licDataBr($eventoPainel['encerrado_em'], true)) : ''; ?>.</p>
        <?php } elseif ($eventoPainel && !$eventoPainel['individuais']) { ?>
            <p class="licenca-alerta licenca-sem-evento">Nenhum evento no painel: os robôs não tiram fotos até o cliente criar um evento em "Configurar o app".</p>
        <?php } ?>
        <?php if ($eventoPainel && $eventoPainel['individuais']) { ?>
            <p class="licenca-evento">Eventos atribuídos aos robôs: <strong><?php echo h(implode(', ', $eventoPainel['individuais'])); ?></strong></p>
        <?php } ?>
        <?php if ($painelSemLicenca) { ?>
            <p class="licenca-alerta">Este painel não exige licença: qualquer robô que descubra o endereço consegue enviar fotos. Depois de ativar todos os robôs, mude 'exigir_licenca' para true no config/painel.local.php do painel.</p>
        <?php } ?>
        <?php if (!$painelInstalado) { ?>
            <div class="painel-faltando">
                <p class="licenca-alerta">Ainda não existe painel nessa pasta.</p>
                <form method="post">
                    <?php echo licCampoCsrf(); ?>
                    <input type="hidden" name="acao" value="instalar_painel">
                    <button type="submit" class="botao botao-primario botao-pequeno">Criar o painel agora</button>
                </form>
            </div>
        <?php } ?>
        <span class="selo selo-<?php echo h($situacao); ?>"><?php echo h(licSituacaoTexto($situacao)); ?></span>
        <?php if ($situacao === 'vencida') { ?>
            <p class="licenca-alerta">Venceu em <?php echo h(licDataBr($licenca['vence'])); ?>. Os robôs travam em <?php echo h(licDataBr(licFimDoDia($licenca['vence']) + $config['dias_carencia'] * 86400)); ?>, se a data não for estendida.</p>
        <?php } elseif ($situacao === 'expirada') { ?>
            <p class="licenca-alerta">Venceu em <?php echo h(licDataBr($licenca['vence'])); ?> e os robôs estão travados. Mude a data de vencimento para liberar.</p>
        <?php } ?>
    </div>
    <?php if (count($vagas) === 1) { ?>
    <div class="licenca-codigo">
        <span class="suave">Código para ativar o robô</span>
        <code class="codigo codigo-grande"><?php echo h($codigo); ?></code>
        <button type="button" class="botao botao-pequeno" data-copiar="<?php echo h($codigo); ?>">Copiar</button>
    </div>
    <?php } else { ?>
    <div class="licenca-codigo">
        <span class="suave"><?php echo count($vagas); ?> robôs, um código para cada</span>
        <a href="#robos" class="botao botao-pequeno">Ver os códigos ↓</a>
    </div>
    <?php } ?>
</section>

<section class="cartao" id="acesso">
    <div class="acesso-topo">
        <h2>Acesso ao painel</h2>
        <div class="acesso-botoes">
            <?php if ($painelInstalado && $pastaAtual !== '' && $pastaAtual !== null) { ?>
            <form method="post" data-confirmar="Copia os arquivos do painel principal (versão mais nova) para o painel deste cliente. Molduras, telas, fotos, eventos e logins do cliente continuam como estão." data-confirmar-titulo="Atualizar o painel do cliente?" data-confirmar-botao="Atualizar">
                <?php echo licCampoCsrf(); ?>
                <input type="hidden" name="acao" value="atualizar_painel">
                <button type="submit" class="botao botao-pequeno">Atualizar os arquivos do painel</button>
            </form>
            <?php } ?>
            <form method="post">
                <?php echo licCampoCsrf(); ?>
                <input type="hidden" name="acao" value="abrir_painel">
                <button type="submit" class="botao botao-pequeno" formtarget="_blank"<?php echo $painelInstalado ? '' : ' disabled title="Crie o painel primeiro"'; ?>>Abrir o painel do cliente ↗</button>
            </form>
        </div>
    </div>
    <p class="suave">O cliente entra em <strong><?php echo h($config['url_login']); ?></strong> e cai direto no painel dele.</p>
    <?php $codigoPainel = $pastaAtual !== null && $pastaAtual !== '' ? licCodigoDoPainel($pastaAtual) : null;
    if ($codigoPainel) {
        $quandoCodigo = $codigoPainel['atualizado_em'] !== '' ? licDataBr($codigoPainel['atualizado_em'], true) : '';
        ?>
        <p class="licenca-codigo-painel codigo-<?php echo h($codigoPainel['situacao']); ?>">Código do painel: <?php
        if ($codigoPainel['situacao'] === 'ok') {
            echo 'igual ao do painel principal (copiado em ' . h($quandoCodigo) . ').';
        } elseif ($codigoPainel['situacao'] === 'esperando') {
            echo 'o painel principal mudou. Este painel pega a versão nova sozinho, na próxima vez que alguém abrir o painel ou que o robô ligar.';
        } elseif ($codigoPainel['situacao'] === 'antigo') {
            echo 'de antes da atualização automática. Clique em "Atualizar os arquivos do painel" uma vez; depois ele se atualiza sozinho.';
        } else {
            echo 'não conseguiu se atualizar sozinho (' . h($codigoPainel['erro']) . '). Use "Atualizar os arquivos do painel".';
        } ?></p>
    <?php } ?>
    <?php if ($senhaNova) { ?>
        <div class="senha-nova" role="status">
            <p><strong>Anote e passe para o cliente.</strong> A senha não aparece de novo.</p>
            <dl>
                <dt>Endereço</dt><dd><?php echo h($config['url_login']); ?></dd>
                <dt>Usuário</dt><dd><code class="codigo"><?php echo h($senhaNova['usuario']); ?></code></dd>
                <dt>Senha</dt><dd><code class="codigo"><?php echo h($senhaNova['senha']); ?></code></dd>
            </dl>
            <button type="button" class="botao botao-pequeno" data-copiar="<?php echo h('Painel: ' . $config['url_login'] . "\nUsuário: " . $senhaNova['usuario'] . "\nSenha: " . $senhaNova['senha']); ?>">Copiar os três</button>
        </div>
    <?php } elseif (empty($licenca['usuario']) || empty($licenca['senha_hash'])) { ?>
        <div class="aviso aviso-erro">Esta licença ainda não tem usuário. Defina abaixo para o cliente conseguir entrar.</div>
    <?php } ?>
    <form method="post" class="formulario central-form">
        <?php echo licCampoCsrf(); ?>
        <input type="hidden" name="acao" value="acesso">
        <div class="formulario-linha">
            <label>Usuário
                <input type="text" name="usuario" value="<?php echo h(isset($licenca['usuario']) ? $licenca['usuario'] : ''); ?>" required pattern="[a-zA-Z0-9._\-]{3,40}" autocapitalize="none">
            </label>
            <label>Nova senha <span class="suave">(opcional)</span>
                <input type="text" name="senha" minlength="8" autocomplete="off" placeholder="Em branco = gerar uma">
            </label>
        </div>
        <div class="acesso-botoes">
            <button type="submit" class="botao botao-primario">Salvar usuário</button>
            <?php if (!empty($licenca['senha_hash'])) { ?>
                <button type="submit" name="nova_senha" value="1" class="botao">Gerar senha nova</button>
            <?php } ?>
        </div>
    </form>
</section>

<section class="cartao" id="robos">
    <h2>Robôs <span class="suave">(<?php echo $usados . ' de ' . count($vagas) . ' em uso'; ?>)</span></h2>
    <p class="suave">Cada robô tem o seu código: no robô, abra o app e digite o código de uma linha livre. Um código vale para um robô só.</p>
    <div class="tabela-rolagem">
    <table class="tabela">
        <thead><tr><th>#</th><th>Código</th><th>Robô</th><th>Ativado em</th><th>Último contato</th><th>Versão do app</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($vagas as $vaga) {
            $codigoVaga = licCodigoTexto($vaga['codigo']);
            $d = $vaga['robo'];
            ?>
            <tr>
                <td><?php echo (int) $vaga['numero']; ?></td>
                <td><code class="codigo"><?php echo h($codigoVaga); ?></code>
                    <button type="button" class="botao botao-pequeno" data-copiar="<?php echo h($codigoVaga); ?>">Copiar</button></td>
                <?php if ($d === null) { ?>
                    <td><span class="selo selo-livre">Livre</span></td><td>-</td><td>-</td><td>-</td><td></td>
                <?php } else { ?>
                <td><strong><?php echo h(licNomeRobo($d)); ?></strong>
                    <span class="tabela-sub"><?php echo h(trim((!empty($d['serial']) && !empty($d['modelo']) ? $d['modelo'] . ' · ' : '') . 'ID ' . $vaga['dispositivo'])); ?></span></td>
                <td><?php echo h(licDataBr($d['ativado_em'], true)); ?></td>
                <td<?php echo licContatoAtrasado($d['ultimo_contato']) ? ' class="contato-atrasado"' : ''; ?>><?php echo h(licDataBr($d['ultimo_contato'], true)); ?>
                    <span class="tabela-sub"><?php if (licRoboOnline($d)) { ?><span class="ponto-online" aria-hidden="true"></span>Ligado agora<?php } else { echo h(licHaQuantoTempo($d['ultimo_contato'])); } ?></span></td>
                <td><?php echo h($d['versao_app'] !== '' ? $d['versao_app'] : '-'); ?></td>
                <td class="tabela-acao">
                    <form method="post" data-confirmar="O robô trava na próxima vez que falar com a central e o código <?php echo h($codigoVaga); ?> fica livre para outro robô." data-confirmar-titulo="Desvincular este robô?" data-confirmar-botao="Desvincular" data-confirmar-perigo>
                        <?php echo licCampoCsrf(); ?>
                        <input type="hidden" name="acao" value="desvincular">
                        <input type="hidden" name="dispositivo" value="<?php echo h($vaga['dispositivo']); ?>">
                        <button type="submit" class="botao botao-pequeno botao-perigo">Desvincular</button>
                    </form>
                </td>
                <?php } ?>
            </tr>
        <?php } ?>
        </tbody>
    </table>
    </div>
    <p class="suave">Para mais ou menos robôs, mude o número em "Dados da licença": cada robô a mais ganha um código novo. Para diminuir, o robô que sai precisa estar desvinculado.</p>
</section>

<section class="cartao">
    <h2>Dados da licença</h2>
    <form method="post" class="formulario central-form">
        <?php echo licCampoCsrf(); ?>
        <input type="hidden" name="acao" value="salvar">
        <label>Cliente
            <input type="text" name="cliente" value="<?php echo h($licenca['cliente']); ?>" maxlength="80" required>
        </label>
        <label>Pasta do painel do cliente
            <span class="campo-prefixo">
                <span class="campo-prefixo-texto"><?php echo h(preg_replace('#^https?://#', '', licBasePaineis())); ?></span>
                <input type="text" name="painel" value="<?php echo h((string) $pastaAtual); ?>" pattern="[a-zA-Z0-9][a-zA-Z0-9_\- ]{0,39}" autocapitalize="none" placeholder="(painel principal)">
            </span>
            <span class="suave">Só o nome da pasta. Em branco = o painel principal (buddyApp/).</span>
        </label>
        <div class="formulario-linha">
            <label>Vence em
                <input type="date" name="vence" value="<?php echo h($licenca['vence']); ?>">
                <span class="suave">Em branco = vitalícia.</span>
            </label>
            <label>Robôs
                <input type="number" name="robos" value="<?php echo (int) $licenca['robos']; ?>" min="1" max="100" required>
            </label>
        </div>
        <label>Observação <span class="suave">(só a equipe vê)</span>
            <textarea name="observacao" rows="2" maxlength="500"><?php echo h($licenca['observacao']); ?></textarea>
        </label>
        <p class="suave">Criada em <?php echo h(licDataBr($licenca['criada_em'], true)); ?>.</p>
        <div><button type="submit" class="botao botao-primario">Salvar</button></div>
    </form>
</section>

<section class="cartao" id="registro">
    <div class="acesso-topo">
        <h2>Últimas ações</h2>
        <a href="registro.php?id=<?php echo h(rawurlencode($id)); ?>" class="botao botao-pequeno">Ver o registro completo</a>
    </div>
    <?php if (!$ultimasAcoes) { ?>
        <p class="suave">Nada registrado ainda. O registro começa a contar a partir desta versão da central.</p>
    <?php } else { ?>
        <ul class="registro-curto">
        <?php foreach ($ultimasAcoes as $item) { ?>
            <li><span class="registro-quando"><?php echo h(licDataBr($item['t'], true)); ?></span>
                <span><strong><?php echo h($item['acao']); ?></strong> <span class="suave">· <?php echo h(licQuemTexto($item['quem'])); ?></span>
                <?php if (!empty($item['det'])) { ?><span class="tabela-sub"><?php echo h($item['det']); ?></span><?php } ?></span></li>
        <?php } ?>
        </ul>
    <?php } ?>
</section>

<section class="cartao licenca-perigo">
    <h2>Bloquear ou excluir</h2>
    <div class="licenca-perigo-linha">
        <?php if ($licenca['situacao'] === 'suspensa') { ?>
            <form method="post">
                <?php echo licCampoCsrf(); ?>
                <input type="hidden" name="acao" value="reativar">
                <button type="submit" class="botao botao-primario">Reativar licença</button>
            </form>
            <p class="suave">Os robôs voltam a funcionar na próxima vez que falarem com a central (ao abrir o app ou sincronizar).</p>
        <?php } else { ?>
            <form method="post" data-confirmar="Os robôs travam na próxima vez que falarem com a central. Dá para reativar depois." data-confirmar-titulo="Suspender a licença?" data-confirmar-botao="Suspender" data-confirmar-perigo>
                <?php echo licCampoCsrf(); ?>
                <input type="hidden" name="acao" value="suspender">
                <button type="submit" class="botao botao-perigo">Suspender licença</button>
            </form>
            <p class="suave">Use para falta de pagamento: dá para reativar depois sem ativar os robôs de novo.</p>
        <?php } ?>
    </div>
    <form method="post" class="licenca-excluir" data-confirmar="A licença some da central e os robôs travam. Antes de excluir, a central guarda uma cópia de segurança (em Backup), caso seja engano." data-confirmar-titulo="Excluir a licença?" data-confirmar-botao="Excluir" data-confirmar-perigo>
        <?php echo licCampoCsrf(); ?>
        <input type="hidden" name="acao" value="excluir">
        <label>Para excluir, digite o código <?php echo count($vagas) > 1 ? 'do robô 1, ' : ''; ?><strong><?php echo h($codigo); ?></strong>
            <input type="text" name="confirmar_codigo" autocomplete="off" autocapitalize="characters" required>
        </label>
        <button type="submit" class="botao botao-perigo">Excluir licença</button>
    </form>
</section>
<?php
licRodape();
