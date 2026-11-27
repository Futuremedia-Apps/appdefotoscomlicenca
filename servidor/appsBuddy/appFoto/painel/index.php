<?php
// Login do painel. Com 'login_unico' em config/painel.local.php, o cliente
// entra pelo login único da Futuremedia (pasta login/) e este
// endereço só redireciona para lá. O login próprio (usuário e senha de
// config/painel.local.php) continua em ./?local=1, para a equipe.
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib.php';
painelIniciarSessao();

$loginUnico = painelLoginUnico();

if (isset($_GET['sair'])) {
    $viaLoginUnico = !empty($_SESSION['painel_via_login_unico']);
    $_SESSION = array();
    session_destroy();
    if ($loginUnico !== '') {
        painelIr($viaLoginUnico ? $loginUnico . '?m=saiu' : './?local=1');
    }
    painelIr('./');
}

if (painelLogado()) {
    painelIr('cliente.php');
}

if ($loginUnico !== '' && !isset($_GET['local']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    painelIr($loginUnico);
}

$erro = '';
$usuarioDigitado = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuarioDigitado = strtolower(trim(isset($_POST['usuario']) ? (string) $_POST['usuario'] : ''));
    $senha = isset($_POST['senha']) ? (string) $_POST['senha'] : '';
    $local = painelConfigLocal();

    if (!painelConferirCsrf()) {
        $erro = 'A página expirou. Tente de novo.';
    } elseif (!$local) {
        $erro = 'O painel ainda não foi configurado. Confira o config/painel.local.php.';
    } elseif ($usuarioDigitado === $local['usuario'] && hash_equals($local['senha'], $senha)) {
        session_regenerate_id(true);
        $_SESSION['painel_logado'] = true;
        painelIr('cliente.php');
    } else {
        sleep(1); // atrasa tentativas de adivinhar a senha
        $erro = 'Usuário ou senha incorretos.';
    }
}

painelTopo('Entrar');
?>
<section class="login">
    <h1>Entrar no painel</h1>
    <p class="suave">Troque as molduras, fundos e o vídeo do app de fotos sem precisar instalar nada no robô.</p>
    <?php if ($erro !== '') { ?>
        <div class="aviso aviso-erro"><?php echo h($erro); ?></div>
    <?php } ?>
    <form method="post" class="formulario">
        <?php echo painelCampoCsrf(); ?>
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
painelRodape();
