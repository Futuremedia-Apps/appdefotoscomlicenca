<?php
// Conferência da licença do robô, usada pelo api/sync.php e pelo uploadImage.php.
// O robô manda no cabeçalho "X-Licenca" o token que recebeu da Central de
// Licenças da Futuremedia: base64url(json).base64url(assinatura RSA SHA-256).
// Aqui só se confere a assinatura com a chave pública abaixo, sem falar com a
// central: o token vale para este painel, para este robô e até "valido_ate".
//
// Liga com 'exigir_licenca' => true em config/painel.local.php. Sem a linha,
// robôs sem licença continuam funcionando (para atualizar os robôs aos poucos).
// Compatível com PHP 5.6 e sem depender do lib.php.

define('LICENCA_CHAVE_PUBLICA', "-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA8yp6YzvqAGcw4SpRMvYF
XwJAgPIYbHKJ37TEdinT1u4s0bjmNSFA5oiGDSTllOxOcgStV6C7FEiApZzZZ8JJ
K/iowL7O2W3d3bgi5aRzVwF1WvXn6y7MlxUavG4FuMFI+zpxesCsGz8ARp4nPDp1
iWC3t6K8DjtTMNVLrT/h4QM5pHDPMtQHtt528VtG4in+1dhBHlQoOzo8u2jXAemh
uuQBH0wPGO0WqFD1aiwVZRWCAM7GdWQRXVjNN0kMB6HPajPcDuaJva+gTpNI1EJr
H0GPocLHRGKI1AogyZiRTi1Hu+sjnvicO0qXUJykPM0fGYuPglJlesz2uMyTfUnP
YQIDAQAB
-----END PUBLIC KEY-----");

function licencaConfigPainel() {
    static $config = false;
    if ($config === false) {
        $caminho = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'painel.local.php';
        $dados = is_file($caminho) ? include $caminho : null;
        $config = is_array($dados) ? $dados : array();
    }
    return $config;
}

function licencaExigida() {
    $config = licencaConfigPainel();
    return !empty($config['exigir_licenca']);
}

function licencaBase64UrlDecode($texto) {
    $resto = strlen($texto) % 4;
    if ($resto) {
        $texto .= str_repeat('=', 4 - $resto);
    }
    return base64_decode(strtr($texto, '-_', '+/'), true);
}

// "https://www.Site.com:443/clienteFotos/buddyApp/" -> "site.com/clientefotos/buddyapp"
function licencaChaveUrl($url) {
    $partes = parse_url((string) $url);
    if (!is_array($partes) || empty($partes['host'])) {
        return '';
    }
    $host = preg_replace('/^www\./', '', strtolower($partes['host']));
    $caminho = isset($partes['path']) ? trim(strtolower($partes['path']), '/') : '';
    return $host . '/' . $caminho;
}

// Endereço deste painel (a pasta do uploadImage.php), a partir do endereço do
// script que está rodando. $niveisAcima: 0 para o uploadImage.php, 2 para o
// painel/api/sync.php. Dá para fixar com 'painel_url' no painel.local.php, se
// o servidor estiver atrás de um proxy que muda o endereço.
function licencaUrlDestePainel($niveisAcima) {
    $config = licencaConfigPainel();
    if (!empty($config['painel_url'])) {
        return (string) $config['painel_url'];
    }
    $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
    $caminho = str_replace('\\', '/', dirname(isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '/x'));
    for ($i = 0; $i < $niveisAcima; $i++) {
        $caminho = str_replace('\\', '/', dirname($caminho));
    }
    return 'https://' . $host . '/' . trim($caminho, '/') . '/';
}

// Confere a assinatura da central e devolve o JSON assinado, ou null
function licencaLerAssinado($token) {
    $token = trim((string) $token);
    $partes = explode('.', $token);
    if (count($partes) !== 2 || strlen($token) > 4000) {
        return null;
    }
    $assinatura = licencaBase64UrlDecode($partes[1]);
    $chave = openssl_pkey_get_public(LICENCA_CHAVE_PUBLICA);
    if ($assinatura === false || !$chave || openssl_verify($partes[0], $assinatura, $chave, OPENSSL_ALGO_SHA256) !== 1) {
        return null;
    }
    $dados = json_decode((string) licencaBase64UrlDecode($partes[0]), true);
    return is_array($dados) ? $dados : null;
}

// Passe do login único (painel/entrar.php). Devolve os dados do passe ou o
// texto do erro (string). O "nonce" (uso único) é conferido pelo entrar.php.
function licencaConferirPasse($token) {
    $dados = licencaLerAssinado($token);
    if (!$dados || !isset($dados['tipo'], $dados['painel'], $dados['expira'], $dados['nonce']) || $dados['tipo'] !== 'login') {
        return 'Acesso inválido.';
    }
    if ((int) $dados['expira'] < time()) {
        return 'expirou';
    }
    // painel/entrar.php fica um nível abaixo da raiz do painel
    if (licencaChaveUrl($dados['painel']) !== licencaChaveUrl(licencaUrlDestePainel(1))) {
        return 'Este acesso é de outro painel.';
    }
    return $dados;
}

// Licenças da Central (buddyApp/licencas/dados/licencas.php), procurando a
// partir do painel principal (buddyApp/) ou de um cliente (buddyApp/<pasta>/).
// null se a central não está neste servidor ou o arquivo está ilegível.
function licencaLerCentral() {
    $raiz = dirname(__DIR__);
    foreach (array($raiz, dirname($raiz)) as $pasta) {
        $arquivo = $pasta . DIRECTORY_SEPARATOR . 'licencas' . DIRECTORY_SEPARATOR . 'dados' . DIRECTORY_SEPARATOR . 'licencas.php';
        if (!is_file($arquivo)) {
            continue;
        }
        $conteudo = (string) @file_get_contents($arquivo);
        $cabecalho = "<?php exit; ?>\n";
        if (strpos($conteudo, $cabecalho) === 0) {
            $conteudo = substr($conteudo, strlen($cabecalho));
        }
        $central = json_decode($conteudo, true);
        return is_array($central) && isset($central['licencas']) && is_array($central['licencas']) ? $central['licencas'] : null;
    }
    return null;
}

// Licenças que apontam para este painel, para o cliente ver o código e
// ativar os robôs sozinho. $niveisAcima como em licencaUrlDestePainel()
// (1 para as páginas de painel/). Cada item: codigo, situacao (ativa,
// vencida, suspensa), vence, robos, usados.
function licencasDestePainel($niveisAcima) {
    $licencas = licencaLerCentral();
    if (!$licencas) {
        return array();
    }
    $este = licencaChaveUrl(licencaUrlDestePainel($niveisAcima));
    $lista = array();
    foreach ($licencas as $id => $licenca) {
        if (!isset($licenca['painel']) || licencaChaveUrl($licenca['painel']) !== $este) {
            continue;
        }
        $vence = isset($licenca['vence']) ? (string) $licenca['vence'] : '';
        $situacao = 'ativa';
        if (isset($licenca['situacao']) && $licenca['situacao'] === 'suspensa') {
            $situacao = 'suspensa';
        } elseif ($vence !== '' && time() > strtotime($vence . ' 23:59:59')) {
            $situacao = 'vencida';
        }
        // Um código por robô (central nova); central antiga: só o id
        $codigos = isset($licenca['codigos']) && is_array($licenca['codigos']) && $licenca['codigos'] ? $licenca['codigos'] : array($id);
        $dispositivos = isset($licenca['dispositivos']) && is_array($licenca['dispositivos']) ? $licenca['dispositivos'] : array();
        $robos = array();
        foreach ($codigos as $i => $codigo) {
            $robo = null;
            foreach ($dispositivos as $d) {
                if (isset($d['codigo']) ? $d['codigo'] === $codigo : $i === 0) {
                    $robo = $d;
                    break;
                }
            }
            $robos[] = array(
                'numero' => $i + 1,
                'codigo' => licencaCodigoTexto($codigo),
                'em_uso' => $robo !== null,
                'serial' => $robo !== null && !empty($robo['serial']) ? (string) $robo['serial'] : '',
                'ultimo_contato' => $robo !== null && !empty($robo['ultimo_contato']) ? (string) $robo['ultimo_contato'] : ''
            );
        }
        $lista[] = array(
            'codigo' => licencaCodigoTexto($id),
            'situacao' => $situacao,
            'vence' => $vence,
            'robos' => count($codigos),
            'usados' => count($dispositivos),
            'codigos' => $robos
        );
    }
    return $lista;
}

// Situação da licença agora, lida direto da Central de Licenças, que fica no
// mesmo servidor (buddyApp/licencas/). Assim, licença suspensa ou excluída e
// robô desvinculado são recusados na hora, sem esperar o token vencer.
// Devolve null se está tudo certo (ou se a central não está neste servidor).
function licencaSituacaoNaCentral($dados, &$codigoCentral) {
    $licencas = licencaLerCentral();
    if ($licencas === null) {
        return null; // central em outro servidor ou ilegível: não derruba o robô
    }
    $id = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', isset($dados['licenca']) ? (string) $dados['licenca'] : ''));
    if (strlen($id) === 10 && substr($id, 0, 2) === 'FM') {
        $id = substr($id, 2);
    }
    if (!isset($licencas[$id])) {
        return 'A licença deste robô não existe mais. Fale com a Futuremedia.';
    }
    $licenca = $licencas[$id];
    if (isset($licenca['situacao']) && $licenca['situacao'] === 'suspensa') {
        return 'A licença deste robô está suspensa. Fale com a Futuremedia.';
    }
    if (!isset($licenca['dispositivos'][(string) $dados['dispositivo']])) {
        return 'Este robô foi desvinculado da licença.';
    }
    // Cada robô tem o seu código: token de um código que o robô já largou
    $robo = $licenca['dispositivos'][(string) $dados['dispositivo']];
    if (isset($dados['codigo'], $robo['codigo']) && licencaNormalizarCodigo($dados['codigo']) !== $robo['codigo']) {
        return 'Este robô foi desvinculado da licença.';
    }
    $codigoCentral = !empty($robo['codigo']) ? $robo['codigo'] : $id;
    return null;
}

// "fm-7k2p 9qxa" -> "7K2P9QXA" (mesma regra da central)
function licencaNormalizarCodigo($texto) {
    $id = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', (string) $texto));
    return strlen($id) === 10 && substr($id, 0, 2) === 'FM' ? substr($id, 2) : $id;
}

function licencaCodigoTexto($id) {
    return 'FM-' . substr($id, 0, 4) . '-' . substr($id, 4, 4);
}

// Devolve null se a licença vale, ou o motivo da recusa (texto para o robô)
function licencaConferir($niveisAcima, $dispositivo, &$codigoRobo = null) {
    $codigoRobo = '';
    $token = isset($_SERVER['HTTP_X_LICENCA']) ? trim((string) $_SERVER['HTTP_X_LICENCA']) : '';
    if ($token === '') {
        return 'Este robô não tem licença. Atualize o app e ative a licença.';
    }
    $dados = licencaLerAssinado($token);
    if (!$dados || isset($dados['tipo']) || !isset($dados['painel'], $dados['dispositivo'], $dados['valido_ate'])) {
        return 'Licença inválida.';
    }
    if ((int) $dados['valido_ate'] < time()) {
        return 'A licença deste robô venceu. Abra o app com internet para renovar.';
    }
    if ($dispositivo !== null && (string) $dados['dispositivo'] !== (string) $dispositivo) {
        return 'Esta licença é de outro robô.';
    }
    if (licencaChaveUrl($dados['painel']) !== licencaChaveUrl(licencaUrlDestePainel($niveisAcima))) {
        return 'Este robô está licenciado para outro painel.';
    }
    $motivo = licencaSituacaoNaCentral($dados, $codigoCentral);
    if ($motivo === null) {
        $codigoRobo = $codigoCentral !== null ? $codigoCentral
            : licencaNormalizarCodigo(isset($dados['codigo']) ? $dados['codigo'] : (isset($dados['licenca']) ? $dados['licenca'] : ''));
    }
    return $motivo;
}

// A escolha do evento usa o código assinado pela central. O ID informado
// na consulta só serve para conferir se a licença pertence àquele robô.
function licencaConferirEvento($niveisAcima, $dispositivo, $haVinculos, &$codigo) {
    $codigo = '';
    if (!licencaExigida() && (!$haVinculos || empty($_SERVER['HTTP_X_LICENCA']))) {
        return null;
    }
    return licencaConferir($niveisAcima, $dispositivo, $codigo);
}
