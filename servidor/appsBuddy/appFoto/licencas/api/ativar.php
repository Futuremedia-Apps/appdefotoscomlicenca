<?php
// Endpoint dos robôs. POST (formulário):
//   acao        "ativar" (código digitado no robô, ocupa uma vaga),
//               "renovar" (robô já ativado; não ocupa vaga nova) ou
//               "desvincular" (só com "token": o robô libera a vaga dele)
//   codigo      código do robô (FM-XXXX-XXXX). Cada robô da licença tem o seu;
//               um código vale para um robô só
//   dispositivo ID do robô (o mesmo que vai no sync.php)
//   serial      serial de fábrica do robô (identifica o robô na central)
//   modelo, versao  só para mostrar na central
// Resposta: {"ok":true,"token":"...","codigo":"FM-...","cliente":"...","painel":"...","situacao":"...","vence":"..."}
// ou {"ok":false,"erro":"<motivo>","message":"<texto para a tela do robô>"}.
// Os motivos codigo, suspensa, expirada e desvinculado são definitivos: o app
// trava. em_uso (código de outro robô) só recusa a ativação. Qualquer outro
// erro (servidor, internet) não trava.
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib.php';

function responder($status, $dados) {
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function recusar($status, $erro, $mensagem) {
    responder($status, array('ok' => false, 'erro' => $erro, 'message' => $mensagem));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    recusar(405, 'metodo', 'Método não permitido.');
}
if (!licConfig()) {
    recusar(500, 'servidor', 'A central de licenças não está configurada. Fale com a Futuremedia.');
}

$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
if (licBloqueadoPorTentativas($ip)) {
    recusar(429, 'tentativas', 'Muitos códigos errados. Espere 15 minutos e tente de novo.');
}

// Desvincular pelo próprio robô (tela de admin): só com o token dele, que
// prova que o pedido vem do robô vinculado. Libera a vaga na licença.
if (isset($_POST['acao']) && $_POST['acao'] === 'desvincular') {
    $dados = licLerAssinado(isset($_POST['token']) ? $_POST['token'] : '');
    if (!$dados || isset($dados['tipo']) || empty($dados['licenca']) || empty($dados['dispositivo'])) {
        recusar(403, 'token', 'Não foi possível confirmar a licença deste robô.');
    }
    $idDesvincular = licNormalizarCodigo($dados['licenca']);
    $dispositivoDesvincular = (string) $dados['dispositivo'];
    $roboSaiu = null;
    $salvasDesvincular = licAlterar(function ($todas) use ($idDesvincular, $dispositivoDesvincular, &$roboSaiu) {
        if (!isset($todas[$idDesvincular]['dispositivos'][$dispositivoDesvincular])) {
            return false; // já estava livre (ou a licença não existe mais)
        }
        $roboSaiu = $todas[$idDesvincular]['dispositivos'][$dispositivoDesvincular];
        unset($todas[$idDesvincular]['dispositivos'][$dispositivoDesvincular]);
        return $todas;
    });
    if ($roboSaiu && $salvasDesvincular !== null) {
        licRegistrar('robo', 'Robô desvinculado pela tela de admin do app', $salvasDesvincular[$idDesvincular],
            licNomeRobo($roboSaiu) . ' · código ' . licCodigoTexto($roboSaiu['codigo']) . ' ficou livre');
    }
    responder(200, array('ok' => true));
}

$acao = isset($_POST['acao']) && $_POST['acao'] === 'renovar' ? 'renovar' : 'ativar';
$codigo = licNormalizarCodigo(isset($_POST['codigo']) ? $_POST['codigo'] : '');
$dispositivo = isset($_POST['dispositivo']) ? (string) $_POST['dispositivo'] : '';
$modelo = licCortar(preg_replace('/[^\p{L}\p{N} ._()-]/u', '', isset($_POST['modelo']) ? (string) $_POST['modelo'] : ''), 60);
$versao = licCortar(preg_replace('/[^0-9a-zA-Z .+()-]/', '', isset($_POST['versao']) ? (string) $_POST['versao'] : ''), 30);
// Serial de fábrica do robô (app novo): identifica o robô na central e no painel
$serial = licCortar(preg_replace('/[^0-9a-zA-Z._-]/', '', isset($_POST['serial']) ? (string) $_POST['serial'] : ''), 40);

if (!preg_match('/^[a-zA-Z0-9]{8,40}$/', $dispositivo)) {
    recusar(400, 'dispositivo', 'Identificação do robô inválida. Reinstale o app.');
}
$encontrada = $codigo === null ? null : licBuscarCodigo($codigo);
if (!$encontrada && licEstadoArquivo() === 'indisponivel') {
    // Arquivo das licenças estragado e sem cópia: não é culpa do código, e
    // "codigo" travaria o robô. Responde como falha do servidor (não trava).
    recusar(503, 'servidor', 'A central de licenças está em manutenção. Tente de novo mais tarde.');
}
if (!$encontrada) {
    licAnotarTentativaErrada($ip);
    recusar(404, 'codigo', $acao === 'ativar'
        ? 'Código não encontrado. Confira as letras e os números.'
        : 'A licença deste robô não existe mais. Fale com a Futuremedia.');
}
$id = $encontrada['id'];

// Confere e anota o robô com o arquivo travado
$recusa = null;
$evento = null; // para o registro: array(ação, detalhe)
$salvas = licAlterar(function ($todas) use ($id, $codigo, $acao, $dispositivo, $modelo, $versao, $serial, &$recusa, &$evento) {
    if (!isset($todas[$id]) || !in_array($codigo, $todas[$id]['codigos'], true)) {
        $recusa = array(404, 'codigo', 'A licença deste robô não existe mais. Fale com a Futuremedia.');
        return false;
    }
    $licenca = $todas[$id];
    $situacao = licSituacao($licenca);
    if ($situacao === 'suspensa') {
        $recusa = array(403, 'suspensa', 'A licença deste robô está suspensa. Fale com a Futuremedia.');
        return false;
    }
    if ($situacao === 'expirada') {
        $recusa = array(403, 'expirada', 'A licença deste robô venceu em ' . licDataBr($licenca['vence']) . '. Fale com a Futuremedia para renovar.');
        return false;
    }

    $agora = date('c');
    $jaNestaLicenca = isset($licenca['dispositivos'][$dispositivo]);
    $comEsteCodigo = $jaNestaLicenca && $licenca['dispositivos'][$dispositivo]['codigo'] === $codigo;

    if ($acao === 'renovar' && !$jaNestaLicenca) {
        $recusa = array(403, 'desvinculado', 'Este robô foi desvinculado da licença. Digite o código de novo ou fale com a Futuremedia.');
        return false;
    }

    // Ativar com um código que este robô ainda não ocupa
    if ($acao === 'ativar' && !$comEsteCodigo) {
        $dono = licDonoDoCodigo($licenca, $codigo);
        if ($dono !== null) {
            $serialDono = isset($licenca['dispositivos'][$dono]['serial']) ? (string) $licenca['dispositivos'][$dono]['serial'] : '';
            if ($serial === '' || $serialDono !== $serial) {
                $recusa = array(403, 'em_uso', 'Este código já está em uso '
                    . ($serialDono !== '' ? 'no robô de serial ' . $serialDono : 'em outro robô')
                    . '. Cada robô tem o seu código: use outro código da licença ou desvincule aquele robô.');
                $evento = array('Tentou ativar um código que já está em uso', 'código ' . licCodigoTexto($codigo)
                    . ' · pedido do robô ' . ($serial !== '' ? 'serial ' . $serial : 'sem serial') . ' · em uso no ' . licNomeRobo($licenca['dispositivos'][$dono]));
                return false;
            }
            // Mesmo robô (mesmo serial) com o app reinstalado: troca o ID antigo pelo novo
            unset($todas[$id]['dispositivos'][$dono]);
            $evento = array('Robô reativado (app reinstalado)', 'serial ' . $serial . ' · código ' . licCodigoTexto($codigo) . ' · ID ' . $dono . ' → ' . $dispositivo);
        }
        // Um robô ocupa um código só: larga o que tinha (nesta ou em outra licença)
        $codigoAnterior = '';
        foreach ($todas as $outroId => $outra) {
            if (isset($outra['dispositivos'][$dispositivo]['codigo'])) {
                $codigoAnterior = licCodigoTexto($outra['dispositivos'][$dispositivo]['codigo']);
            }
            unset($todas[$outroId]['dispositivos'][$dispositivo]);
        }
        if ($evento === null) {
            $evento = array('Robô ativado', ($serial !== '' ? 'serial ' . $serial : ($modelo !== '' ? $modelo . ' (sem serial)' : 'robô sem serial'))
                . ' · código ' . licCodigoTexto($codigo) . ($codigoAnterior !== '' ? ' · largou o código ' . $codigoAnterior : '') . ' · ID ' . $dispositivo);
        }
        $licenca = $todas[$id];
        $licenca['dispositivos'][$dispositivo] = array('codigo' => $codigo, 'serial' => '', 'ativado_em' => $agora, 'modelo' => '', 'versao_app' => '');
    }

    $licenca['dispositivos'][$dispositivo]['ultimo_contato'] = $agora;
    if ($serial !== '') {
        $licenca['dispositivos'][$dispositivo]['serial'] = $serial;
    }
    if ($modelo !== '') {
        $licenca['dispositivos'][$dispositivo]['modelo'] = $modelo;
    }
    if ($versao !== '') {
        $licenca['dispositivos'][$dispositivo]['versao_app'] = $versao;
    }
    $todas[$id] = $licenca;
    return $todas;
});

if ($recusa) {
    if ($evento) {
        licRegistrar('robo', $evento[0], $encontrada, $evento[1]);
    }
    recusar($recusa[0], $recusa[1], $recusa[2]);
}
if ($salvas === null) {
    recusar(500, 'servidor', 'A central de licenças não conseguiu gravar. Tente de novo em instantes.');
}

$licenca = $salvas[$id];
if ($evento) {
    licRegistrar('robo', $evento[0], $licenca, $evento[1]);
}
$token = licEmitirToken($licenca, $dispositivo);
if ($token === null) {
    recusar(500, 'servidor', 'A central de licenças não conseguiu assinar a licença. Fale com a Futuremedia.');
}

responder(200, array(
    'ok' => true,
    'token' => $token,
    'codigo' => licCodigoTexto($licenca['dispositivos'][$dispositivo]['codigo']),
    'cliente' => $licenca['cliente'],
    'painel' => $licenca['painel'],
    'situacao' => licSituacao($licenca),
    'vence' => $licenca['vence']
));
