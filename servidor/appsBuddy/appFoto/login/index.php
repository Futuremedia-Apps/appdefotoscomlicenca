<?php
// Login único dos painéis dos clientes: https://suafoto.futuremedia.com.br/clienteFotos/buddyApp/login/
// O cliente digita usuário e senha (cadastrados na Central de Licenças) e vai
// direto para o painel dele, com um passe assinado que vale 2 minutos e uma
// vez só (o painel confere em painel/entrar.php). Lê os dados da central, que
// fica na pasta licencas/ ao lado desta.
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'licencas' . DIRECTORY_SEPARATOR . 'lib.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name('loginpainel');
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params(0, '/', '', $https, true);
    session_start();
}
if (empty($_SESSION['login_csrf'])) {
    $_SESSION['login_csrf'] = bin2hex(licAleatorio(16));
}

$erro = '';
$usuarioDigitado = '';
// Mensagem vinda do painel (passe vencido, saiu do painel)
$avisos = array(
    'saiu' => array('ok', 'Você saiu do painel.'),
    'expirou' => array('erro', 'O acesso expirou. Entre de novo.')
);
$aviso = isset($_GET['m'], $avisos[$_GET['m']]) ? $avisos[$_GET['m']] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuarioDigitado = strtolower(trim(isset($_POST['usuario']) ? (string) $_POST['usuario'] : ''));
    $senha = isset($_POST['senha']) ? (string) $_POST['senha'] : '';
    $ip = 'login:' . (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '');

    if (!isset($_POST['csrf']) || !hash_equals($_SESSION['login_csrf'], (string) $_POST['csrf'])) {
        $erro = 'A página expirou. Tente de novo.';
    } elseif (!licConfig()) {
        $erro = 'O login está em manutenção. Tente de novo em alguns minutos.';
    } elseif (licBloqueadoPorTentativas($ip)) {
        $erro = 'Muitas tentativas erradas. Espere 15 minutos e tente de novo.';
    } else {
        $usuario = licNormalizarUsuario($usuarioDigitado);
        $licenca = $usuario ? licBuscarPorUsuario($usuario) : null;
        if (!$licenca || empty($licenca['senha_hash']) || !password_verify($senha, $licenca['senha_hash'])) {
            licAnotarTentativaErrada($ip);
            if ($licenca) {
                // Só registra quando o usuário existe (senha errada de um cliente)
                licRegistrar('cliente', 'Senha errada no login do cliente', $licenca, 'usuário ' . $usuario);
            }
            sleep(1); // atrasa tentativas de adivinhar a senha
            $erro = 'Usuário ou senha incorretos.';
        } else {
            $situacao = licSituacao($licenca);
            if ($situacao === 'suspensa') {
                $erro = 'O acesso a este painel está suspenso. Fale com a Futuremedia.';
                licRegistrar('cliente', 'Login recusado: licença suspensa', $licenca, 'usuário ' . $usuario);
            } elseif ($situacao === 'expirada') {
                $erro = 'A licença deste painel venceu. Fale com a Futuremedia para renovar.';
                licRegistrar('cliente', 'Login recusado: licença vencida', $licenca, 'usuário ' . $usuario);
            } else {
                $url = licUrlEntrarPainel($licenca);
                if ($url === null) {
                    $erro = 'O login está em manutenção. Tente de novo em alguns minutos.';
                } else {
                    licRegistrar('cliente', 'Cliente entrou no painel', $licenca, 'usuário ' . $usuario);
                    header('Location: ' . $url, true, 303);
                    exit;
                }
            }
        }
    }
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Entrar · Painel do App de Fotos</title>
<link rel="icon" type="image/png" sizes="32x32" href="marca/icone-32.png">
<link rel="icon" type="image/png" sizes="192x192" href="marca/icone-192.png">
<link rel="apple-touch-icon" sizes="180x180" href="marca/icone-180.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700;900&display=swap">
<link rel="stylesheet" href="estilo.css?v=2">
</head>
<body>
<header class="topo">
    <div class="topo-marca">
        <img class="topo-logo" src="marca/futuremedia.png" alt="Futuremedia" width="2501" height="347">
        <span class="topo-produto">Painel do App de Fotos</span>
    </div>
</header>
<main class="conteudo">
<section class="login">
    <h1>Entrar no painel</h1>
    <p class="suave">Troque as molduras, fundos e o vídeo do app de fotos e veja as fotos dos seus eventos.</p>
    <?php if ($erro !== '') { ?>
        <div class="aviso aviso-erro"><?php echo h($erro); ?></div>
    <?php } elseif ($aviso) { ?>
        <div class="aviso aviso-<?php echo h($aviso[0]); ?>"><?php echo h($aviso[1]); ?></div>
    <?php } ?>
    <form method="post" class="formulario">
        <input type="hidden" name="csrf" value="<?php echo h($_SESSION['login_csrf']); ?>">
        <label>Usuário
            <input type="text" name="usuario" value="<?php echo h($usuarioDigitado); ?>" autocomplete="username" autocapitalize="none" required autofocus>
        </label>
        <label>Senha
            <input type="password" name="senha" autocomplete="current-password" required>
        </label>
        <button type="submit" class="botao botao-primario botao-largo">Entrar</button>
    </form>
    <p class="suave login-ajuda">Esqueceu a senha? Fale com a Futuremedia.</p>
</section>
</main>
</body>
</html>
