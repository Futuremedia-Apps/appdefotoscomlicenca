<?php
// Leads: o cadastro que o visitante faz na página do QR Code (qr.php).
//
// Formulário (leads_formulario.php), guardado no evento. Os eventos sem
// formulário próprio usam a configuração anterior do cliente (config.leads).
// Não sobe a versão da configuração, porque o robô não usa. O cliente escolhe:
//   - modo: "obrigatorio" (a foto só aparece depois do cadastro), "opcional"
//     (dá para tocar em "Agora não") ou "desligado" (a foto aparece direto);
//   - os textos da página;
//   - os campos: nome, e-mail e telefone (um de cada) e campos próprios de
//     texto, número e múltipla escolha.
//
// Cadastros em config/painel/leads/<cliente>/<evento>.php, protegidos como o
// resto de config/painel/. O arquivo começa com a linha PHP que encerra a
// execução e depois tem uma linha JSON por registro, sempre acrescentada no fim:
//   {"t":"lead","id":..,"em":..,"foto":..,"valores":{..},"rotulos":{..},"tipos":{..}}
//   {"t":"foto","id":<lead>,"em":..,"foto":..}
// A linha "foto" quer dizer que a mesma pessoa abriu outra foto do evento.
// Excluir (pelo painel) regrava o arquivo, com a mesma trava de quem acrescenta.
//
// O celular que fez o cadastro guarda o id do lead num cookie do evento. Nas
// outras fotos do mesmo evento ele não preenche de novo, e a foto entra no
// mesmo lead. O id é aleatório (16 hex) e nunca aparece na página, então serve
// de senha: sem ele, não há como "pular" o cadastro obrigatório.

define('LEADS_MAX_CAMPOS', 12);
define('LEADS_MAX_OPCOES', 20);
define('LEADS_COOKIE_DIAS', 30);
// Cadastros por IP a cada 10 minutos. É alto porque, num evento, todo mundo
// costuma estar na mesma rede (o mesmo IP).
define('LEADS_LIMITE_IP', 120);
// Tamanho máximo do arquivo de um evento (perto de 60 mil cadastros)
define('LEADS_LIMITE_ARQUIVO', 20971520);

// Tipos de campo. "unico": no máximo um no formulário, com id fixo (= tipo).
function leadsTipos() {
    return array(
        'nome' => array('nome' => 'Nome', 'rotulo' => 'Nome', 'unico' => true),
        'email' => array('nome' => 'E-mail', 'rotulo' => 'E-mail', 'unico' => true),
        'telefone' => array('nome' => 'Telefone', 'rotulo' => 'Telefone', 'unico' => true),
        'texto' => array('nome' => 'Texto', 'rotulo' => '', 'unico' => false),
        'numero' => array('nome' => 'Número', 'rotulo' => '', 'unico' => false),
        'escolha' => array('nome' => 'Múltipla escolha', 'rotulo' => '', 'unico' => false)
    );
}

function leadsModos() {
    return array(
        'obrigatorio' => array('nome' => 'Obrigatório', 'explica' => 'A foto só aparece depois do cadastro.'),
        'opcional' => array('nome' => 'Opcional', 'explica' => 'O visitante pode tocar em "Agora não" e ver a foto sem se cadastrar.'),
        'desligado' => array('nome' => 'Desligado', 'explica' => 'O QR Code abre a foto direto, sem cadastro.')
    );
}

function leadsTextosPadrao() {
    return array(
        'titulo' => 'Sua foto está pronta!',
        'texto' => 'Preencha rapidinho para ver e baixar a sua foto.',
        'botao' => 'Ver minha foto',
        'aviso' => 'Ao enviar, você concorda que os organizadores deste evento usem estes dados para entrar em contato com você.'
    );
}

// Enquanto o cliente não salva o formulário: obrigatório, com nome e e-mail
function leadsConfigPadrao() {
    return array_merge(array('modo' => 'obrigatorio'), leadsTextosPadrao(), array(
        'campos' => array(
            array('id' => 'nome', 'tipo' => 'nome', 'rotulo' => 'Nome', 'obrigatorio' => true),
            array('id' => 'email', 'tipo' => 'email', 'rotulo' => 'E-mail', 'obrigatorio' => true)
        )
    ));
}

// Formulário do evento, com compatibilidade para o modelo antigo do painel.
function leadsConfig($cliente, $evento) {
    $configuracoes = array(
        isset($evento['formulario_leads']) ? $evento['formulario_leads'] : null,
        isset($cliente['config']['leads']) ? $cliente['config']['leads'] : null
    );
    foreach ($configuracoes as $salva) {
        if (!is_array($salva)) {
            continue;
        }
        $erro = '';
        $config = leadsValidarConfig($salva, $erro);
        if ($config) {
            return $config;
        }
    }
    return leadsConfigPadrao();
}

function leadsValor($dados, $chave) {
    return is_array($dados) && isset($dados[$chave]) ? $dados[$chave] : '';
}

// Corta em $max letras (não bytes), sem quebrar acento
function leadsCortar($texto, $max) {
    if (function_exists('mb_substr')) {
        return mb_substr($texto, 0, $max, 'UTF-8');
    }
    return preg_match('/^.{0,' . (int) $max . '}/us', $texto, $m) ? $m[0] : '';
}

// Texto de uma linha (ou de várias, com $linhas): UTF-8 válido, sem
// caracteres de controle nem espaços sobrando, com no máximo $max letras
function leadsLimparTexto($valor, $max, $linhas = false) {
    $valor = is_scalar($valor) ? (string) $valor : '';
    if (preg_match('//u', $valor) !== 1) {
        return '';
    }
    $valor = str_replace("\r\n", "\n", $valor);
    $valor = preg_replace($linhas ? '/[\x00-\x09\x0B-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u', ' ', $valor);
    $valor = preg_replace('/[ ]{2,}/u', ' ', $valor);
    if ($linhas) {
        $valor = preg_replace('/ ?\n ?/u', "\n", $valor);
        $valor = preg_replace('/\n{3,}/u', "\n\n", $valor);
    }
    return trim(leadsCortar(trim($valor), $max));
}

// Confere e arruma o formulário. Devolve o formulário ou null (com $erro).
// $previa: para a prévia do painel enquanto o cliente digita, nunca recusa
// (campo sem nome ganha um nome provisório, opções vazias ficam vazias).
function leadsValidarConfig($dados, &$erro, $previa = false) {
    $erro = '';
    $modos = leadsModos();
    $tipos = leadsTipos();
    $padrao = leadsTextosPadrao();
    $modo = (string) leadsValor($dados, 'modo');
    $config = array(
        'modo' => isset($modos[$modo]) ? $modo : 'obrigatorio',
        'titulo' => leadsLimparTexto(leadsValor($dados, 'titulo'), 80),
        'texto' => leadsLimparTexto(leadsValor($dados, 'texto'), 240, true),
        'botao' => leadsLimparTexto(leadsValor($dados, 'botao'), 40),
        'aviso' => leadsLimparTexto(leadsValor($dados, 'aviso'), 400, true),
        'campos' => array()
    );
    if ($config['titulo'] === '') {
        $config['titulo'] = $padrao['titulo'];
    }
    if ($config['botao'] === '') {
        $config['botao'] = $padrao['botao'];
    }

    $lista = isset($dados['campos']) && is_array($dados['campos']) ? array_values($dados['campos']) : array();
    if (count($lista) > LEADS_MAX_CAMPOS) {
        if (!$previa) {
            $erro = 'O formulário pode ter no máximo ' . LEADS_MAX_CAMPOS . ' campos.';
            return null;
        }
        $lista = array_slice($lista, 0, LEADS_MAX_CAMPOS);
    }
    $vistos = array();
    foreach ($lista as $i => $campo) {
        $tipo = (string) leadsValor($campo, 'tipo');
        if (!isset($tipos[$tipo])) {
            continue;
        }
        if ($tipos[$tipo]['unico']) {
            $id = $tipo;
            if (isset($vistos[$id])) {
                if ($previa) {
                    continue;
                }
                $erro = 'O campo "' . $tipos[$tipo]['nome'] . '" aparece duas vezes.';
                return null;
            }
        } else {
            $id = (string) leadsValor($campo, 'id');
            if (!preg_match('/^c[a-f0-9]{6}$/', $id) || isset($vistos[$id])) {
                do {
                    $id = 'c' . painelAleatorio(3);
                } while (isset($vistos[$id]));
            }
        }
        $rotulo = leadsLimparTexto(leadsValor($campo, 'rotulo'), 80);
        if ($rotulo === '') {
            $rotulo = $tipos[$tipo]['rotulo'];
        }
        if ($rotulo === '') {
            if (!$previa) {
                $erro = 'Escreva a pergunta do campo ' . ($i + 1) . ' (' . $tipos[$tipo]['nome'] . ').';
                return null;
            }
            $rotulo = 'Pergunta sem texto';
        }
        $novo = array('id' => $id, 'tipo' => $tipo, 'rotulo' => $rotulo, 'obrigatorio' => !empty($campo['obrigatorio']));
        if ($tipo === 'escolha') {
            $opcoes = array();
            $origem = isset($campo['opcoes']) && is_array($campo['opcoes']) ? $campo['opcoes'] : array();
            foreach ($origem as $opcao) {
                $opcao = leadsLimparTexto($opcao, 60);
                if ($opcao !== '' && !in_array($opcao, $opcoes, true)) {
                    $opcoes[] = $opcao;
                }
            }
            if (count($opcoes) > LEADS_MAX_OPCOES) {
                if (!$previa) {
                    $erro = '"' . $rotulo . '" pode ter no máximo ' . LEADS_MAX_OPCOES . ' opções.';
                    return null;
                }
                $opcoes = array_slice($opcoes, 0, LEADS_MAX_OPCOES);
            }
            if (!$opcoes && !$previa) {
                $erro = 'Escreva as opções de "' . $rotulo . '" (uma por linha).';
                return null;
            }
            $novo['opcoes'] = $opcoes;
            $novo['varias'] = !empty($campo['varias']);
        }
        $vistos[$id] = true;
        $config['campos'][] = $novo;
    }
    if ($config['modo'] !== 'desligado' && !$config['campos'] && !$previa) {
        $erro = 'Coloque pelo menos um campo no formulário (ou escolha "Desligado").';
        return null;
    }
    return $config;
}

// ---------- Respostas do visitante ----------

// Telefone do Brasil com DDD, "(11) 98765-4321" ou "(11) 3456-7890". Com "+"
// e outro país, "+<números>". '' se não parece telefone.
function leadsTelefone($texto) {
    $digitos = preg_replace('/\D+/', '', $texto);
    if (strpos(ltrim($texto), '+') === 0 && strpos($digitos, '55') !== 0) {
        return strlen($digitos) >= 8 && strlen($digitos) <= 15 ? '+' . $digitos : '';
    }
    if (strlen($digitos) >= 12 && strpos($digitos, '55') === 0) {
        $digitos = substr($digitos, 2);
    }
    if ((strlen($digitos) === 11 || strlen($digitos) === 12) && $digitos[0] === '0') {
        $digitos = substr($digitos, 1); // 0 da operadora (011...)
    }
    if (!preg_match('/^[1-9][0-9]/', $digitos)) {
        return '';
    }
    if (strlen($digitos) === 11) {
        return '(' . substr($digitos, 0, 2) . ') ' . substr($digitos, 2, 5) . '-' . substr($digitos, 7);
    }
    if (strlen($digitos) === 10) {
        return '(' . substr($digitos, 0, 2) . ') ' . substr($digitos, 2, 4) . '-' . substr($digitos, 6);
    }
    return '';
}

// Número como o brasileiro escreve ("1.234,5", "12,5", "-3") guardado com
// ponto decimal ("1234.5"). null se não é número.
function leadsNumero($texto) {
    $t = str_replace(' ', '', $texto);
    if (!preg_match('/^-?[0-9.,]+$/', $t)) {
        return null;
    }
    if (strpos($t, ',') !== false) {
        $t = str_replace(',', '.', str_replace('.', '', $t));
    } elseif (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $t)) {
        $t = str_replace('.', '', $t); // 1.234 = mil duzentos e trinta e quatro
    }
    return preg_match('/^-?\d{1,15}(\.\d{1,6})?$/', $t) ? $t : null;
}

// Uma resposta. Com erro, devolve o que a pessoa digitou (para mostrar de novo).
function leadsValidarValor($campo, $bruto, &$erro) {
    $erro = '';
    if ($campo['tipo'] === 'escolha') {
        $marcadas = is_array($bruto) ? $bruto : ((string) $bruto === '' ? array() : array($bruto));
        $escolhidas = array();
        foreach ($marcadas as $opcao) {
            $opcao = leadsLimparTexto($opcao, 60);
            if (in_array($opcao, $campo['opcoes'], true)) {
                $escolhidas[] = $opcao;
            }
        }
        // Na ordem das opções, sem repetir; uma só se não pode marcar várias
        $escolhidas = array_values(array_intersect($campo['opcoes'], $escolhidas));
        return empty($campo['varias']) ? array_slice($escolhidas, 0, 1) : $escolhidas;
    }
    $texto = leadsLimparTexto(is_array($bruto) ? '' : $bruto, $campo['tipo'] === 'texto' ? 300 : 160);
    if ($texto === '') {
        return '';
    }
    if ($campo['tipo'] === 'nome') {
        return leadsCortar($texto, 120);
    }
    if ($campo['tipo'] === 'email') {
        $email = strtolower(str_replace(' ', '', $texto));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erro = 'Confira o e-mail (exemplo: nome@email.com).';
            return $texto;
        }
        return $email;
    }
    if ($campo['tipo'] === 'telefone') {
        $telefone = leadsTelefone($texto);
        if ($telefone === '') {
            $erro = 'Confira o telefone, com o DDD (exemplo: 11 98765-4321).';
            return $texto;
        }
        return $telefone;
    }
    if ($campo['tipo'] === 'numero') {
        $numero = leadsNumero($texto);
        if ($numero === null) {
            $erro = 'Digite só números.';
            return $texto;
        }
        return $numero;
    }
    return $texto;
}

// Todas as respostas do formulário enviado ($post = $_POST, campos
// "campo_<id>"). Devolve array(valores, erros por campo).
function leadsValidarEnvio($config, $post) {
    $valores = array();
    $erros = array();
    foreach ($config['campos'] as $campo) {
        $id = $campo['id'];
        $erro = '';
        $valor = leadsValidarValor($campo, isset($post['campo_' . $id]) ? $post['campo_' . $id] : '', $erro);
        if ($erro === '' && $campo['obrigatorio'] && ($valor === '' || $valor === array())) {
            $erro = $campo['tipo'] === 'escolha' ? (!empty($campo['varias']) ? 'Marque pelo menos uma opção.' : 'Escolha uma opção.') : 'Preencha este campo.';
        }
        if ($erro !== '') {
            $erros[$id] = $erro;
        }
        $valores[$id] = $valor;
    }
    return array($valores, $erros);
}

// ---------- Arquivos dos cadastros ----------

function leadsPasta($cliente) {
    return PAINEL_DADOS . DIRECTORY_SEPARATOR . 'leads' . DIRECTORY_SEPARATOR . $cliente['id'];
}

function leadsArquivo($cliente, $evento) {
    return leadsPasta($cliente) . DIRECTORY_SEPARATOR . $evento . '.php';
}

// Uma trava por cliente, para quem acrescenta e quem regrava não se atropelarem
function leadsTravar($cliente) {
    $pasta = leadsPasta($cliente);
    if (!is_dir($pasta) && !@mkdir($pasta, 0755, true)) {
        return null;
    }
    $trava = @fopen($pasta . DIRECTORY_SEPARATOR . 'trava.lock', 'c');
    if (!$trava) {
        return null;
    }
    if (!flock($trava, LOCK_EX)) {
        fclose($trava);
        return null;
    }
    return $trava;
}

function leadsSoltar($trava) {
    flock($trava, LOCK_UN);
    fclose($trava);
}

// Acrescenta um registro (array) no fim do arquivo do evento
function leadsAcrescentar($cliente, $evento, $registro) {
    if (!painelIdEventoValido($evento)) {
        return false;
    }
    $linha = json_encode($registro, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($linha === false) {
        return false;
    }
    $arquivo = leadsArquivo($cliente, $evento);
    if (is_file($arquivo) && filesize($arquivo) > LEADS_LIMITE_ARQUIVO) {
        return false;
    }
    $trava = leadsTravar($cliente);
    if (!$trava) {
        return false;
    }
    clearstatcache(true, $arquivo);
    $novo = !is_file($arquivo) || filesize($arquivo) === 0;
    $ok = file_put_contents($arquivo, ($novo ? PAINEL_CABECALHO_ARQUIVO : '') . $linha . "\n", FILE_APPEND) !== false;
    leadsSoltar($trava);
    return $ok;
}

// Cadastros de um evento, do mais novo para o mais antigo. Cada um: id,
// evento, em (data ISO), fotos (a primeira é a do cadastro), valores, rotulos
// e tipos (como eram no formulário na hora do cadastro).
function leadsDoEvento($cliente, $evento) {
    $arquivo = leadsArquivo($cliente, $evento);
    if (!painelIdEventoValido($evento) || !is_file($arquivo)) {
        return array();
    }
    $alca = @fopen($arquivo, 'r');
    if (!$alca) {
        return array();
    }
    $leads = array();
    while (($linha = fgets($alca)) !== false) {
        $dados = json_decode($linha, true); // o cabeçalho PHP e uma linha pela metade não passam
        if (!is_array($dados) || !isset($dados['t'], $dados['id'], $dados['foto']) || !is_string($dados['id']) || !is_string($dados['foto'])) {
            continue;
        }
        $id = $dados['id'];
        if ($dados['t'] === 'lead') {
            $leads[$id] = array(
                'id' => $id,
                'evento' => $evento,
                'em' => isset($dados['em']) ? (string) $dados['em'] : '',
                'fotos' => array($dados['foto']),
                'valores' => isset($dados['valores']) && is_array($dados['valores']) ? $dados['valores'] : array(),
                'rotulos' => isset($dados['rotulos']) && is_array($dados['rotulos']) ? $dados['rotulos'] : array(),
                'tipos' => isset($dados['tipos']) && is_array($dados['tipos']) ? $dados['tipos'] : array()
            );
        } elseif ($dados['t'] === 'foto' && isset($leads[$id]) && !in_array($dados['foto'], $leads[$id]['fotos'], true)) {
            $leads[$id]['fotos'][] = $dados['foto'];
        }
    }
    fclose($alca);
    return array_reverse(array_values($leads));
}

// Quantos cadastros o evento tem (sem montar a lista)
function leadsContar($cliente, $evento) {
    $arquivo = leadsArquivo($cliente, $evento);
    if (!painelIdEventoValido($evento) || !is_file($arquivo)) {
        return 0;
    }
    return substr_count((string) @file_get_contents($arquivo), '{"t":"lead",');
}

// O lead do cookie existe neste evento? Se sim e $foto ainda não está nele,
// acrescenta a foto (a mesma pessoa abriu outra foto do evento).
function leadsVincularFoto($cliente, $evento, $id, $foto) {
    foreach (leadsDoEvento($cliente, $evento) as $lead) {
        if ($lead['id'] === $id) {
            if ($foto !== '' && !in_array($foto, $lead['fotos'], true)) {
                leadsAcrescentar($cliente, $evento, array('t' => 'foto', 'id' => $id, 'em' => date('c'), 'foto' => $foto));
            }
            return true;
        }
    }
    return false;
}

// Só confere se o lead existe (para entregar a foto, sem ler a lista toda)
function leadsExiste($cliente, $evento, $id) {
    $arquivo = leadsArquivo($cliente, $evento);
    if ($id === '' || !painelIdEventoValido($evento) || !is_file($arquivo)) {
        return false;
    }
    return strpos((string) @file_get_contents($arquivo), '{"t":"lead","id":"' . $id . '"') !== false;
}

// Exclui cadastros (e as fotos ligadas a eles). Devolve quantos saíram.
function leadsExcluir($cliente, $evento, $ids) {
    $arquivo = leadsArquivo($cliente, $evento);
    if (!$ids || !painelIdEventoValido($evento) || !is_file($arquivo)) {
        return 0;
    }
    $trava = leadsTravar($cliente);
    if (!$trava) {
        return 0;
    }
    $ficam = array();
    $sairam = 0;
    foreach (file($arquivo) ?: array() as $linha) {
        $dados = json_decode($linha, true);
        if (!is_array($dados) || !isset($dados['t'], $dados['id'])) {
            continue; // cabeçalho (volta abaixo) e linha estragada
        }
        if (in_array($dados['id'], $ids, true)) {
            $sairam += $dados['t'] === 'lead' ? 1 : 0;
            continue;
        }
        $ficam[] = rtrim($linha, "\r\n") . "\n";
    }
    if ($sairam > 0) {
        if (!$ficam) {
            $sairam = @unlink($arquivo) ? $sairam : 0;
        } else {
            $temporario = $arquivo . '.tmp-' . painelAleatorio(4);
            $ok = file_put_contents($temporario, PAINEL_CABECALHO_ARQUIVO . implode('', $ficam)) !== false;
            if ($ok && !@rename($temporario, $arquivo)) {
                @unlink($arquivo);
                $ok = @rename($temporario, $arquivo);
            }
            if (!$ok) {
                @unlink($temporario);
                $sairam = 0;
            }
        }
    }
    leadsSoltar($trava);
    return $sairam;
}

// Eventos que têm arquivo de cadastros (ids), até os que já saíram da lista
function leadsEventosComArquivo($cliente) {
    $ids = array();
    foreach (glob(leadsPasta($cliente) . DIRECTORY_SEPARATOR . '*.php') ?: array() as $arquivo) {
        $id = basename($arquivo, '.php');
        if (painelIdEventoValido($id) && filesize($arquivo) > strlen(PAINEL_CABECALHO_ARQUIVO)) {
            $ids[] = $id;
        }
    }
    return $ids;
}

// Eventos da página de leads, do mais novo para o mais antigo: os da lista do
// painel e os apagados que ainda têm cadastros (os cadastros nunca somem junto
// com o evento). Cada um: id, nome, atual, apagado e leads (quantidade).
function leadsEventos($cliente) {
    $comArquivo = leadsEventosComArquivo($cliente);
    $atual = painelEventoAtual($cliente);
    $eventos = array();
    foreach (painelEventos($cliente, true) as $evento) {
        $apagado = !empty($evento['apagado_em']);
        if ($apagado && !in_array($evento['id'], $comArquivo, true)) {
            continue;
        }
        $eventos[$evento['id']] = array(
            'id' => $evento['id'],
            'nome' => $evento['nome'],
            'atual' => $atual && $atual['id'] === $evento['id'],
            'apagado' => $apagado,
            'leads' => leadsContar($cliente, $evento['id'])
        );
    }
    foreach ($comArquivo as $id) {
        if (!isset($eventos[$id])) {
            $nome = leadsNomeGuardado($cliente, $id);
            $eventos[$id] = array('id' => $id, 'nome' => $nome !== '' ? $nome : 'Evento apagado (' . $id . ')', 'atual' => false, 'apagado' => true, 'leads' => leadsContar($cliente, $id));
        }
    }
    return array_values($eventos);
}

// Nome do evento guardado nos cadastros (o do mais recente), para o evento que
// já saiu da lista do painel. '' se nenhum cadastro tem o nome.
function leadsNomeGuardado($cliente, $evento) {
    $arquivo = leadsArquivo($cliente, $evento);
    $conteudo = is_file($arquivo) ? (string) @file_get_contents($arquivo) : '';
    if (!preg_match_all('/"evento_nome":("(?:[^"\\\\]|\\\\.)*")/', $conteudo, $m)) {
        return '';
    }
    $nome = json_decode(end($m[1]));
    return is_string($nome) ? $nome : '';
}

// ---------- Cookie do celular que já se cadastrou ----------

function leadsNomeCookie($evento) {
    return 'lead_' . substr(sha1(PAINEL_RAIZ . '|' . $evento), 0, 12);
}

function leadsIdDoCookie($evento) {
    $nome = leadsNomeCookie($evento);
    $valor = isset($_COOKIE[$nome]) ? (string) $_COOKIE[$nome] : '';
    return preg_match('/^[a-f0-9]{16}$/', $valor) ? $valor : '';
}

function leadsGravarCookie($evento, $id) {
    $caminho = rtrim(str_replace('\\', '/', dirname(isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '/x')), '/') . '/';
    $segundos = LEADS_COOKIE_DIAS * 86400;
    header('Set-Cookie: ' . leadsNomeCookie($evento) . '=' . $id
        . '; Expires=' . gmdate('D, d M Y H:i:s', time() + $segundos) . ' GMT; Max-Age=' . $segundos
        . '; Path=' . $caminho . '; HttpOnly; SameSite=Lax' . (leadsHttps() ? '; Secure' : ''), false);
}

function leadsHttps() {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
}

// Limite de cadastros por IP (LEADS_LIMITE_IP a cada 10 minutos). O IP não
// fica guardado: o arquivo tem o nome de um resumo (hash) do IP com a janela.
function leadsDentroDoLimite($cliente) {
    $pasta = leadsPasta($cliente) . DIRECTORY_SEPARATOR . 'limite';
    if (!is_dir($pasta) && !@mkdir($pasta, 0755, true)) {
        return true;
    }
    $janela = (int) floor(time() / 600);
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    $arquivo = $pasta . DIRECTORY_SEPARATOR . substr(sha1($ip . '|' . $janela . '|' . PAINEL_RAIZ), 0, 20);
    $quantos = is_file($arquivo) ? (int) @file_get_contents($arquivo) : 0;
    if ($quantos >= LEADS_LIMITE_IP) {
        return false;
    }
    @file_put_contents($arquivo, (string) ($quantos + 1), LOCK_EX);
    if (mt_rand(1, 40) === 1) {
        foreach (glob($pasta . DIRECTORY_SEPARATOR . '*') ?: array() as $velho) {
            if (is_file($velho) && filemtime($velho) < time() - 1800) {
                @unlink($velho);
            }
        }
    }
    return true;
}

// ---------- Para mostrar e baixar ----------

// Colunas da lista e da planilha: os campos de agora, na ordem do formulário,
// e depois os que saíram do formulário mas têm respostas guardadas.
function leadsColunas($config, $leads) {
    $colunas = array();
    foreach (isset($config['campos']) ? $config['campos'] : array() as $campo) {
        $colunas[$campo['id']] = array('id' => $campo['id'], 'rotulo' => $campo['rotulo'], 'tipo' => $campo['tipo']);
    }
    foreach ($leads as $lead) {
        foreach ($lead['valores'] as $id => $valor) {
            if (!isset($colunas[$id]) && is_string($id) && preg_match('/^[a-z0-9]{1,12}$/', $id)) {
                $colunas[$id] = array(
                    'id' => $id,
                    'rotulo' => isset($lead['rotulos'][$id]) && is_string($lead['rotulos'][$id]) ? $lead['rotulos'][$id] : $id,
                    'tipo' => isset($lead['tipos'][$id]) && is_string($lead['tipos'][$id]) ? $lead['tipos'][$id] : 'texto'
                );
            }
        }
    }
    return array_values($colunas);
}

// Resposta como texto: opções separadas por vírgula, número com vírgula decimal
function leadsValorTexto($lead, $coluna) {
    $valor = isset($lead['valores'][$coluna['id']]) ? $lead['valores'][$coluna['id']] : '';
    if (is_array($valor)) {
        return implode(', ', array_filter($valor, 'is_string'));
    }
    $valor = is_scalar($valor) ? (string) $valor : '';
    return $coluna['tipo'] === 'numero' ? str_replace('.', ',', $valor) : $valor;
}

// Nome para mostrar ao lado da foto: o nome, ou o e-mail, ou o telefone...
function leadsNomeExibido($lead) {
    foreach (array('nome', 'email', 'telefone') as $id) {
        if (isset($lead['valores'][$id]) && is_string($lead['valores'][$id]) && $lead['valores'][$id] !== '') {
            return $lead['valores'][$id];
        }
    }
    foreach ($lead['valores'] as $valor) {
        $texto = is_array($valor) ? implode(', ', array_filter($valor, 'is_string')) : (is_scalar($valor) ? (string) $valor : '');
        if ($texto !== '') {
            return $texto;
        }
    }
    return 'Cadastro sem nome';
}

// Fotos do evento que têm cadastro: nome da foto => nomes das pessoas
function leadsPorFoto($cliente, $evento) {
    $porFoto = array();
    foreach (leadsDoEvento($cliente, $evento) as $lead) {
        foreach ($lead['fotos'] as $foto) {
            $porFoto[$foto][] = leadsNomeExibido($lead);
        }
    }
    return $porFoto;
}

// Endereço do site com a pasta do painel do cliente (buddyApp/<cliente>),
// para os links das fotos na planilha
function leadsUrlRaiz() {
    $host = isset($_SERVER['HTTP_HOST']) && preg_match('/^[a-z0-9.\-]+(:\d+)?$/i', (string) $_SERVER['HTTP_HOST'])
        ? (string) $_SERVER['HTTP_HOST'] : (isset($_SERVER['SERVER_NAME']) ? (string) $_SERVER['SERVER_NAME'] : 'localhost');
    $caminho = str_replace('\\', '/', dirname(dirname(isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '/x/x')));
    return (leadsHttps() ? 'https' : 'http') . '://' . $host . rtrim($caminho, '/');
}

// Endereço público da foto (o mesmo arquivo que o QR Code antigo abria)
function leadsUrlFoto($cliente, $evento, $foto) {
    $pasta = isset($cliente['pasta_fotos']) ? trim((string) $cliente['pasta_fotos'], '/') : '';
    $partes = $pasta === '' ? array() : explode('/', $pasta);
    $partes[] = PAINEL_PASTA_EVENTOS;
    $partes[] = $evento;
    $partes[] = $foto;
    return leadsUrlRaiz() . '/' . implode('/', array_map('rawurlencode', $partes));
}
