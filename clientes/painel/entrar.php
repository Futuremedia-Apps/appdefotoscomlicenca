<?php
// Entrada pelo login único (https://suafoto.futuremedia.com.br/clienteFotos/buddyApp/login/).
// GET ?t=<passe assinado pela Central de Licenças>. O passe vale 2 minutos,
// só para este painel e uma vez só: os já usados ficam em
// config/painel/passes_usados.php até vencerem.
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'licenca.php';
painelIniciarSessao();

$loginUnico = painelLoginUnico();
$resultado = licencaConferirPasse(isset($_GET['t']) ? $_GET['t'] : '');

if (is_array($resultado)) {
    // Uso único: guarda o nonce até o passe vencer
    $arquivo = PAINEL_DADOS . DIRECTORY_SEPARATOR . 'passes_usados.php';
    $trava = @fopen(PAINEL_DADOS . DIRECTORY_SEPARATOR . 'passes.lock', 'c');
    if ($trava) {
        flock($trava, LOCK_EX);
    }
    $usados = painelLerArquivo($arquivo);
    $usados = is_array($usados) ? $usados : array();
    foreach ($usados as $nonce => $expira) {
        if ($expira < time()) {
            unset($usados[$nonce]);
        }
    }
    $nonce = (string) $resultado['nonce'];
    $repetido = isset($usados[$nonce]);
    if (!$repetido) {
        $usados[$nonce] = (int) $resultado['expira'];
        $gravou = painelGravarArquivo($arquivo, $usados);
    }
    if ($trava) {
        flock($trava, LOCK_UN);
        fclose($trava);
    }

    if ($repetido) {
        $resultado = 'expirou';
    } elseif (empty($gravou)) {
        $resultado = 'Não foi possível entrar agora. Tente de novo.';
    } else {
        session_regenerate_id(true);
        $_SESSION['painel_logado'] = true;
        $_SESSION['painel_via_login_unico'] = true;
        painelIr('cliente.php');
    }
}

// Passe vencido ou já usado (voltar no navegador, por exemplo): volta ao login
if ($resultado === 'expirou') {
    if (painelLogado()) {
        painelIr('cliente.php');
    }
    painelIr($loginUnico !== '' ? $loginUnico . '?m=expirou' : './');
}

http_response_code(403);
painelTopo('Entrar');
?>
<section class="login">
    <h1>Não foi possível entrar</h1>
    <div class="aviso aviso-erro"><?php echo h($resultado); ?></div>
    <?php if ($loginUnico !== '') { ?>
        <p><a class="botao botao-primario botao-largo" href="<?php echo h($loginUnico); ?>">Voltar ao login</a></p>
    <?php } ?>
</section>
<?php
painelRodape();
