<?php
// Central de Licenças: login e lista das licenças, com o formulário de licença nova.
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib.php';
licIniciarSessao();

if (isset($_GET['sair'])) {
    $_SESSION = array();
    session_destroy();
    licIr('./');
}

$config = licConfig();

// ---------- Login ----------
if (!licLogado()) {
    $erro = '';
    $usuarioDigitado = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $usuarioDigitado = strtolower(trim(isset($_POST['usuario']) ? (string) $_POST['usuario'] : ''));
        $senha = isset($_POST['senha']) ? (string) $_POST['senha'] : '';
        $ipLogin = 'central:' . (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '');
        if (!licConferirCsrf()) {
            $erro = 'A página expirou. Tente de novo.';
        } elseif (!$config) {
            $erro = 'A central ainda não foi configurada. Confira o config/licencas.local.php.';
        } elseif (licBloqueadoPorTentativas($ipLogin)) {
            $erro = 'Muitas tentativas erradas. Espere 15 minutos e tente de novo.';
        } elseif ($usuarioDigitado === $config['usuario'] && hash_equals($config['senha'], $senha)) {
            session_regenerate_id(true);
            $_SESSION['lic_logado'] = time();
            licRegistrar('equipe', 'Entrou na central');
            licIr('./');
        } else {
            licAnotarTentativaErrada($ipLogin);
            licRegistrar('sistema', 'Senha errada no login da central');
            sleep(1); // atrasa tentativas de adivinhar a senha
            $erro = 'Usuário ou senha incorretos.';
        }
    }
    licTopo('Entrar');
    ?>
<section class="login">
    <h1>Central de Licenças</h1>
    <p class="suave">Área da equipe Futuremedia para controlar as licenças do App de Fotos.</p>
    <?php if ($erro !== '') { ?><div class="aviso aviso-erro"><?php echo h($erro); ?></div><?php } ?>
    <form method="post" class="formulario">
        <?php echo licCampoCsrf(); ?>
        <label>Usuário
            <input type="text" name="usuario" value="<?php echo h($usuarioDigitado); ?>" autocomplete="username" autocapitalize="none" required autofocus>
        </label>
        <label>Senha
            <input type="password" name="senha" autocomplete="current-password" required>
        </label>
        <button type="submit" class="botao botao-primario botao-largo">Entrar</button>
    </form>
</section>
    <?php
    licRodape();
    exit;
}

// ---------- Atualizar o código de todos os painéis de cliente ----------
// Depois disso eles se atualizam sozinhos (painel/atualizar.php); o botão
// fica para os painéis de antes da atualização automática e para forçar.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'atualizar_todos') {
    if (!licConferirCsrf()) {
        licAviso('erro', 'A página expirou. Tente de novo.');
        licIr('./#atualizar-todos');
    }
    @set_time_limit(300);
    list($feitos, $errosPaineis) = licAtualizarTodosPaineis();
    licRegistrar('equipe', 'Arquivos de todos os painéis atualizados', null, $feitos . ' painel(is)' . ($errosPaineis ? ', ' . count($errosPaineis) . ' com erro' : ''));
    if ($feitos > 0 || !$errosPaineis) {
        licAviso('ok', $feitos === 0 ? 'Nenhum painel de cliente para atualizar.' : ($feitos === 1 ? '1 painel atualizado' : $feitos . ' painéis atualizados')
            . ' com a versão do painel principal. Daqui para frente, eles se atualizam sozinhos.');
    }
    foreach ($errosPaineis as $erroPainel) {
        licAviso('erro', $erroPainel);
    }
    licIr('./#atualizar-todos');
}

// ---------- Licença nova ----------
$form = array('cliente' => '', 'usuario' => '', 'painel' => '', 'vence' => '', 'robos' => '1', 'observacao' => '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'criar') {
    foreach ($form as $campo => $valor) {
        $form[$campo] = isset($_POST[$campo]) ? trim((string) $_POST[$campo]) : '';
    }
    $pasta = licNormalizarPasta($form['painel']);
    $painel = $pasta === null ? null : licPainelDaPasta($pasta);
    $usuario = licNormalizarUsuario($form['usuario']);
    $robos = (int) $form['robos'];
    $erro = '';
    if (!licConferirCsrf()) {
        $erro = 'A página expirou. Tente de novo.';
    } elseif (!preg_match('/^.{1,80}$/u', $form['cliente'])) {
        $erro = 'Digite o nome do cliente (até 80 letras).';
    } elseif (!$usuario) {
        $erro = 'Usuário do painel: de 3 a 40 letras minúsculas, números, ponto, traço ou _.';
    } elseif (licBuscarPorUsuario($usuario)) {
        $erro = 'Já existe um cliente com o usuário "' . $usuario . '". Escolha outro.';
    } elseif (!$painel) {
        $erro = 'Pasta do painel inválida: use letras minúsculas, números, traço ou _ (ex.: clienteteste01). Os nomes ' . implode(', ', licPastasReservadas()) . ' são do sistema.';
    } elseif ($outraLicenca = licLicencaDoPainel($painel)) {
        $erro = 'Esse painel já é do cliente "' . $outraLicenca['cliente'] . '" (' . licCodigoTexto($outraLicenca['id']) . '). Cada cliente precisa de uma pasta própria, senão os dois entrariam no mesmo painel.';
    } elseif ($form['vence'] !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['vence']) || !strtotime($form['vence']))) {
        $erro = 'Data de vencimento inválida.';
    } elseif ($robos < 1 || $robos > 100) {
        $erro = 'O número de robôs vai de 1 a 100.';
    }

    if ($erro === '') {
        $novoId = null;
        $senha = licGerarSenha();
        $salvo = licAlterar(function ($todas) use ($form, $painel, $robos, $usuario, $senha, &$novoId) {
            $novoId = licNovoId(licTodosCodigos($todas));
            $todas[$novoId] = array(
                'id' => $novoId,
                'cliente' => $form['cliente'],
                'usuario' => $usuario,
                'senha_hash' => password_hash($senha, PASSWORD_DEFAULT),
                'painel' => $painel,
                'vence' => $form['vence'],
                'robos' => $robos,
                'situacao' => 'ativa',
                'observacao' => licCortar($form['observacao'], 500),
                'criada_em' => date('c'),
                // um código por robô: o 1º é o id, os outros o licAlterar() cria
                'codigos' => array($novoId),
                'dispositivos' => array()
            );
            return $todas;
        });
        if ($salvo === null) {
            $erro = 'Não foi possível gravar. Confira se a pasta dados/ pode ser gravada.';
        } else {
            licRegistrar('equipe', 'Licença criada', $salvo[$novoId], licCodigoTexto($novoId) . ' · '
                . ($robos === 1 ? '1 robô' : $robos . ' robôs') . ' · ' . ($form['vence'] === '' ? 'vitalícia' : 'vence ' . licDataBr($form['vence']))
                . ' · painel ' . $painel . ' · usuário ' . $usuario);
            licAviso('ok', $robos === 1
                ? 'Licença criada. Código para ativar o robô: ' . licCodigoTexto($novoId) . '.'
                : 'Licença criada com ' . $robos . ' códigos, um para cada robô (lista "Robôs" abaixo).');
            // Pasta nova: o painel do cliente é criado agora
            if ($pasta !== '' && !licPainelInstalado($pasta)) {
                $erroPainel = licInstalarPainel($pasta, $salvo[$novoId]);
                if ($erroPainel === null) {
                    licRegistrar('equipe', 'Painel do cliente criado', $salvo[$novoId], $painel);
                    licAviso('ok', 'Painel do cliente criado em ' . $painel . '.');
                } else {
                    licAviso('erro', 'A licença foi criada, mas o painel não: ' . $erroPainel . ' Tente de novo em "Criar o painel agora".');
                }
            }
            $_SESSION['lic_senha_nova'] = array('id' => $novoId, 'usuario' => $usuario, 'senha' => $senha);
            licIr('licenca.php?id=' . $novoId);
        }
    }
    licAviso('erro', $erro);
}

// ---------- Lista ----------
$licencas = licTodas();
$total = count($licencas);
$alertas = licAlertas($licencas);
uasort($licencas, function ($a, $b) {
    return strcmp($b['criada_em'], $a['criada_em']);
});
$busca = isset($_GET['busca']) ? trim((string) $_GET['busca']) : '';
if ($busca !== '') {
    $codigoBusca = licNormalizarCodigo($busca);
    $licencas = array_filter($licencas, function ($l) use ($busca, $codigoBusca) {
        return ($codigoBusca !== null && in_array($codigoBusca, $l['codigos'], true))
            || stripos($l['cliente'] . ' ' . $l['painel'] . ' ' . licCodigoTexto($l['id']), $busca) !== false;
    });
}

licTopo('Licenças', true);
?>
<div class="central-titulo">
    <div>
        <h1>Licenças</h1>
        <p class="suave"><?php echo $total === 1 ? '1 licença cadastrada' : $total . ' licenças cadastradas'; ?></p>
    </div>
    <form method="get" class="central-busca" role="search">
        <input type="search" name="busca" value="<?php echo h($busca); ?>" placeholder="Buscar cliente, código ou painel" aria-label="Buscar">
        <button type="submit" class="botao">Buscar</button>
    </form>
</div>

<?php if (!$config) { ?>
    <div class="aviso aviso-erro">A chave de assinatura não está configurada (config/licencas.local.php). Os robôs não conseguem ativar.</div>
<?php } ?>
<?php if (licEstadoArquivo() === 'indisponivel') { ?>
    <div class="aviso aviso-erro">O arquivo das licenças (licencas/dados/licencas.php) está estragado e não há cópia de segurança boa. Nada é gravado até ele ser consertado; os robôs continuam funcionando com a licença que já têm. Fale com o desenvolvedor.</div>
<?php } ?>

<?php if ($alertas) {
    $erros = count(array_filter($alertas, function ($a) { return $a[0] === 'erro'; }));
    ?>
<section class="cartao alertas<?php echo $erros ? ' alertas-erro' : ''; ?>" aria-labelledby="alertas-titulo">
    <h2 id="alertas-titulo">Atenção <span class="suave">(<?php echo count($alertas) === 1 ? '1 aviso' : count($alertas) . ' avisos'; ?>)</span></h2>
    <ul class="alertas-lista">
    <?php foreach ($alertas as $i => $alerta) { ?>
        <li class="alerta-<?php echo h($alerta[0]); ?>"<?php echo $i >= 6 ? ' hidden data-alerta-extra' : ''; ?>>
            <?php if ($alerta[2] !== '') { ?><a href="<?php echo h($alerta[2]); ?>"><?php echo h($alerta[1]); ?></a><?php } else { echo h($alerta[1]); } ?>
        </li>
    <?php } ?>
    </ul>
    <?php if (count($alertas) > 6) { ?>
        <button type="button" class="botao botao-pequeno" id="alertas-mais">Ver todos os <?php echo count($alertas); ?> avisos</button>
        <script>
        document.getElementById('alertas-mais').addEventListener('click', function () {
            document.querySelectorAll('[data-alerta-extra]').forEach(function (li) { li.hidden = false; });
            this.remove();
        });
        </script>
    <?php } ?>
</section>
<?php } ?>

<section class="cartao">
<?php if (!$licencas) { ?>
    <p class="suave"><?php echo $busca !== '' ? 'Nenhuma licença encontrada.' : 'Nenhuma licença ainda. Crie a primeira abaixo.'; ?></p>
<?php } else { ?>
    <div class="tabela-rolagem">
    <table class="tabela">
        <thead>
            <tr><th>Cliente</th><th>Código</th><th>Situação</th><th>Vence</th><th>Robôs</th><th>Último contato</th><th>Fotos</th></tr>
        </thead>
        <tbody>
        <?php foreach ($licencas as $l) {
            $situacao = licSituacao($l);
            $ultimo = 0;
            $online = 0;
            foreach ($l['dispositivos'] as $d) {
                $ultimo = max($ultimo, (int) strtotime($d['ultimo_contato']));
                $online += licRoboOnline($d) ? 1 : 0;
            }
            $pastaLista = licPastaDoPainel($l['painel']);
            $uso = $pastaLista !== null && licPainelInstalado($pastaLista) ? licUsoPainel($pastaLista) : null;
            $porcentoUso = licPorcentoUso($uso);
            ?>
            <tr>
                <td><a href="licenca.php?id=<?php echo h($l['id']); ?>" class="tabela-link"><?php echo h($l['cliente']); ?></a>
                    <span class="tabela-sub"><?php echo h(preg_replace('#^https?://#', '', $l['painel'])); ?></span></td>
                <td><code class="codigo"><?php echo h(licCodigoTexto($l['id'])); ?></code><?php if (count($l['codigos']) > 1) { ?><span class="tabela-sub">e mais <?php echo count($l['codigos']) - 1; ?> (um por robô)</span><?php } ?></td>
                <td><span class="selo selo-<?php echo h($situacao); ?>"><?php echo h(licSituacaoTexto($situacao)); ?></span></td>
                <td><?php echo $l['vence'] === '' ? 'Vitalícia' : h(licDataBr($l['vence'])); ?></td>
                <td><?php echo count($l['dispositivos']) . ' de ' . (int) $l['robos']; ?>
                    <?php if ($online) { ?><span class="tabela-sub"><span class="ponto-online" aria-hidden="true"></span><?php echo $online === 1 ? '1 ligado agora' : $online . ' ligados agora'; ?></span><?php } ?></td>
                <td<?php echo $ultimo && licContatoAtrasado($ultimo) && $situacao !== 'suspensa' ? ' class="contato-atrasado"' : ''; ?>><?php if ($ultimo) { ?><?php echo h(licDataBr($ultimo, true)); ?><span class="tabela-sub"><?php echo h(licHaQuantoTempo($ultimo)); ?></span><?php } else { ?><span class="suave">Nunca</span><?php } ?></td>
                <td><?php if ($uso) { ?>
                    <span class="uso-mini uso-<?php echo $porcentoUso >= 100 ? 'cheio' : ($porcentoUso >= 80 ? 'quase' : 'ok'); ?>" title="<?php echo h(licUsoTexto($uso)); ?>"><span style="width:<?php echo number_format(min(100, $porcentoUso), 1, '.', ''); ?>%"></span></span>
                    <span class="tabela-sub"><?php echo h(licTamanhoTexto($uso['bytes']) . ' de ' . licTamanhoTexto($uso['limite'])); ?></span>
                <?php } else { ?><span class="suave">-</span><?php } ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
    </div>
<?php } ?>
</section>

<section class="cartao" id="nova">
    <h2>Nova licença</h2>
    <form method="post" class="formulario central-form">
        <?php echo licCampoCsrf(); ?>
        <input type="hidden" name="acao" value="criar">
        <label>Cliente
            <input type="text" name="cliente" value="<?php echo h($form['cliente']); ?>" maxlength="80" required placeholder="Ex.: Buffet Alegria">
        </label>
        <label>Usuário do painel
            <input type="text" name="usuario" value="<?php echo h($form['usuario']); ?>" required pattern="[a-zA-Z0-9._\-]{3,40}" autocapitalize="none" placeholder="Ex.: buffetalegria">
            <span class="suave">O cliente entra em <?php echo h($config ? $config['url_login'] : ''); ?> com este usuário. A senha é gerada ao criar.</span>
        </label>
        <label>Pasta do painel do cliente
            <span class="campo-prefixo">
                <span class="campo-prefixo-texto"><?php echo h(preg_replace('#^https?://#', '', licBasePaineis())); ?></span>
                <input type="text" name="painel" value="<?php echo h($form['painel']); ?>" pattern="[a-zA-Z0-9][a-zA-Z0-9_\- ]{0,39}" autocapitalize="none" placeholder="clienteteste01">
            </span>
            <span class="suave">Só o nome da pasta. Em branco = o painel principal (buddyApp/).</span>
        </label>
        <div class="formulario-linha">
            <label>Vence em
                <input type="date" name="vence" value="<?php echo h($form['vence']); ?>">
                <span class="suave">Em branco = vitalícia.</span>
            </label>
            <label>Robôs
                <input type="number" name="robos" value="<?php echo h($form['robos']); ?>" min="1" max="100" required>
                <span class="suave">Quantos robôs podem usar. Cada robô ganha um código próprio.</span>
            </label>
        </div>
        <label>Observação <span class="suave">(só a equipe vê)</span>
            <textarea name="observacao" rows="2" maxlength="500"><?php echo h($form['observacao']); ?></textarea>
        </label>
        <div><button type="submit" class="botao botao-primario">Criar licença</button></div>
    </form>
</section>

<section class="cartao" id="atualizar-todos">
    <h2>Código dos painéis dos clientes</h2>
    <p class="suave">Cada painel de cliente se atualiza sozinho: depois que os arquivos do painel principal sobem pelo FTP, ele copia a versão nova na próxima vez que alguém abre o painel ou que o robô liga (o robô confere a cada 30 segundos). Ele espera 2 minutos sem mudança nos arquivos, para não copiar no meio do envio.</p>
    <p class="suave">Este botão atualiza todos agora. Use uma vez para os painéis de antes da atualização automática, ou se algum painel avisar erro.</p>
    <form method="post" data-confirmar-titulo="Atualizar todos os painéis?" data-confirmar-botao="Atualizar todos" data-confirmar="Copia o código do painel principal para o painel de cada cliente. Dados, molduras, eventos e fotos dos clientes não mudam.">
        <?php echo licCampoCsrf(); ?>
        <input type="hidden" name="acao" value="atualizar_todos">
        <button type="submit" class="botao">Atualizar todos os painéis</button>
    </form>
</section>
<?php
licRodape();
