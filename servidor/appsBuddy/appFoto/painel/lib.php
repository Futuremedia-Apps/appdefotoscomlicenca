<?php
// Painel web do app de fotos (camerashot): o cliente troca molduras, fundos,
// vídeo de carregamento etc. sem precisar de um APK novo. O robô baixa tudo
// pelo api/sync.php.
//
// O painel atende um cliente só: quem entra cai direto na página de molduras,
// imagens e vídeo. O cadastro desse cliente é criado no primeiro acesso.
//
// Onde fica cada coisa:
//   config/painel.local.php        login do painel, nome do cliente e pasta das fotos
//   config/painel/clientes/<id>.php  configuração do cliente (molduras, imagens, robôs)
//   painel/midia/<id>/             arquivos enviados, com nome = hash do conteúdo,
//                                  assim o robô só baixa o que mudou
//   painel/padrao/                 cópia das imagens padrão do APK (só para exibir)
//
// Os arquivos de cliente começam com uma linha PHP que encerra a execução, então
// mesmo que a pasta config/ fique acessível pelo navegador nada é exibido.

date_default_timezone_set('America/Sao_Paulo');

define('PAINEL_RAIZ', dirname(__DIR__));
define('PAINEL_DADOS', PAINEL_RAIZ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'painel');
define('PAINEL_CLIENTES', PAINEL_DADOS . DIRECTORY_SEPARATOR . 'clientes');
define('PAINEL_MIDIA', __DIR__ . DIRECTORY_SEPARATOR . 'midia');
define('PAINEL_CABECALHO_ARQUIVO', "<?php exit; ?>\n");
define('PAINEL_TEMPO_INATIVIDADE_PADRAO', 60);
define('PAINEL_TEMPO_INATIVIDADE_MAXIMO', 2147483647);

// Painel de cliente: no fim de cada página (e de cada consulta do robô), confere
// se o código do painel principal mudou e se atualiza sozinho (atualizar.php).
// Nada pode sair na resposta (as consultas do robô são JSON).
require_once __DIR__ . DIRECTORY_SEPARATOR . 'atualizar.php';
register_shutdown_function(function () {
    $nivel = error_reporting(0);
    try {
        atualizarSeNecessario(PAINEL_RAIZ);
    } catch (Exception $e) {
        // fica para a próxima conferência
    } catch (Throwable $e) {
        // idem (PHP 7+)
    }
    error_reporting($nivel);
});

// Telas do app, na ordem em que a pessoa passa por elas (o "trajeto" que o
// painel mostra). Cada tela tem uma imagem inteira (fundo já com os textos) e
// elementos que o cliente move e redimensiona: botões, balão, foto e QR Code.
//
// Posições em frações da tela (0 a 1): x e y são o canto superior esquerdo,
// w e h a largura e a altura. Os padrões e os nomes dos elementos são os mesmos
// do app (lib/app_config.dart, AppConfig.layoutPadrao).
//
// Tipos de elemento:
//   botao   toque leva a algum lugar; o cliente pode trocar por uma imagem
//           (slot "botao_<id>"). Sem imagem, o app desenha o botão padrão.
//   imagem  enfeite sem toque (o balão), com imagem padrão no APK
//   foto    onde aparece a foto tirada (tela final)
//   qrcode  onde aparece o QR Code (tela final), sempre quadrado
function painelTelas() {
    return array(
        'inicio' => array(
            'numero' => '1',
            'titulo' => 'Início',
            'descricao' => 'Primeira tela. Fica esperando alguém tocar em Iniciar. O tempo para fechar por inatividade pode ser configurado no painel.',
            'fundo' => 'tela_inicio',
            'elementos' => array(
                'iniciar' => array('tipo' => 'botao', 'titulo' => 'Botão Iniciar', 'texto' => 'Iniciar', 'destino' => 'termos', 'padrao' => array(0.35, 0.81, 0.30, 0.16)),
                'sair' => array('tipo' => 'botao', 'titulo' => 'Botão Sair', 'texto' => 'Sair', 'destino' => 'Fecha o app', 'padrao' => array(0.01, 0.02, 0.12, 0.10))
            ),
            // Partes que não se movem, só aparecem no editor para não cobrir
            'fixos' => array(
                array('titulo' => 'Área secreta do admin (5 toques)', 'retangulo' => array(0.88, 0, 0.12, 0.15))
            )
        ),
        'termos' => array(
            'numero' => '2',
            'titulo' => 'Termos de uso',
            'descricao' => 'A pessoa lê os termos e escolhe. Sem uso por 120 segundos, volta para o Início.',
            'fundo' => 'tela_termos',
            'elementos' => array(
                'nao_aceito' => array('tipo' => 'botao', 'titulo' => 'Botão Não aceito', 'texto' => 'Não aceito', 'destino' => 'recusa', 'padrao' => array(0.18, 0.81, 0.30, 0.16)),
                'aceito' => array('tipo' => 'botao', 'titulo' => 'Botão Aceito', 'texto' => 'Aceito', 'destino' => 'camera', 'padrao' => array(0.52, 0.81, 0.30, 0.16))
            )
        ),
        'recusa' => array(
            'numero' => '2a',
            'titulo' => 'Aviso "Não aceito"',
            'descricao' => 'Aparece por cima dos termos quando a pessoa toca em Não aceito.',
            'fundo' => 'tela_recusa',
            'ramo' => true,
            'elementos' => array(
                'recusa_sair' => array('tipo' => 'botao', 'titulo' => 'Botão Não tirar foto', 'texto' => 'Não tirar foto', 'estilo' => 'cheio', 'destino' => 'inicio', 'padrao' => array(0.1317, 0.692, 0.30, 0.16)),
                'recusa_voltar' => array('tipo' => 'botao', 'titulo' => 'Botão Voltar para Termos', 'texto' => 'Voltar para Termos', 'estilo' => 'cheio', 'destino' => 'termos', 'padrao' => array(0.4683, 0.692, 0.40, 0.16))
            )
        ),
        'camera' => array(
            'numero' => '3',
            'titulo' => 'Câmera',
            'descricao' => 'Mostra a câmera com a moldura. Ao tocar em Tirar foto, conta 5 segundos e tira a foto. Sem uso por 120 segundos, volta para o Início.',
            'camera' => true,
            'elementos' => array(
                'tirar_foto' => array('tipo' => 'botao', 'titulo' => 'Botão Tirar foto', 'texto' => 'Tirar foto', 'icone' => 'camera', 'destino' => 'revisao', 'padrao' => array(0.8225, 0.12, 0.1575, 0.28)),
                'balao' => array('tipo' => 'imagem', 'titulo' => 'Balão', 'aspecto' => 501 / 139, 'padrao' => array(0.5625, 0.1983, 0.25, 0.1235)),
                'trocar_moldura' => array('tipo' => 'botao', 'titulo' => 'Botão Moldura', 'texto' => 'Moldura', 'icone' => 'molduras', 'destino' => 'escolha_moldura', 'aviso' => 'Só aparece com 2 ou mais molduras.', 'padrao' => array(0.85625, 0.42, 0.12375, 0.22)),
                'camera_inicio' => array('tipo' => 'botao', 'titulo' => 'Botão Início', 'texto' => 'Início', 'icone' => 'inicio', 'destino' => 'inicio', 'padrao' => array(0.85625, 0.66, 0.12375, 0.22))
            )
        ),
        'escolha_moldura' => array(
            'numero' => '3a',
            'titulo' => 'Escolha da moldura',
            'descricao' => 'Lista das molduras para a pessoa escolher. Abre sozinha ao entrar na câmera, só quando há 2 ou mais molduras.',
            'ramo' => true,
            'secao' => 'camera'
        ),
        'revisao' => array(
            'numero' => '4',
            'titulo' => 'Revisão da foto',
            'descricao' => 'Mostra a foto com a moldura. Sem uso por 120 segundos, a foto é salva sozinha.',
            'camera' => true,
            'elementos' => array(
                'salvar' => array('tipo' => 'botao', 'titulo' => 'Botão Salvar', 'texto' => 'Salvar', 'icone' => 'salvar', 'destino' => 'processando', 'padrao' => array(0.8225, 0.24, 0.1575, 0.28)),
                'refazer' => array('tipo' => 'botao', 'titulo' => 'Botão Tirar nova foto', 'texto' => 'Tirar nova foto', 'icone' => 'refazer', 'destino' => 'camera', 'padrao' => array(0.839375, 0.54, 0.12375, 0.22))
            )
        ),
        'processando' => array(
            'numero' => '5',
            'titulo' => 'Processando',
            'descricao' => 'Toca o vídeo de carregamento enquanto junta a foto com a moldura e envia para o servidor. Depois vai sozinha para a tela final.',
            'video' => true
        ),
        'final' => array(
            'numero' => '6',
            'titulo' => 'Final (QR Code)',
            'descricao' => 'Mostra a foto e o QR Code para baixar. Aos 90 segundos pergunta "Você ainda está aí?" e aos 120 volta para o Início.',
            'fundo' => 'tela_final',
            'elementos' => array(
                'foto' => array('tipo' => 'foto', 'titulo' => 'Foto tirada', 'aspecto' => 16 / 9, 'padrao' => array(0.05, 0.05, 0.636, 0.6484)),
                // Centralizado com "Obrigado por participar!" e "Escaneie e pegue sua foto!" da imagem padrão
                'qrcode' => array('tipo' => 'qrcode', 'titulo' => 'QR Code', 'aspecto' => 1, 'padrao' => array(0.75, 0.2444, 0.16, 0.2844)),
                'finalizar' => array('tipo' => 'botao', 'titulo' => 'Botão Finalizar', 'texto' => 'Finalizar', 'destino' => 'inicio', 'padrao' => array(0.35, 0.81, 0.30, 0.16))
            )
        )
    );
}

// Texto "leva para ..." de um botão
function painelDestinoTexto($destino) {
    $telas = painelTelas();
    return isset($telas[$destino]) ? 'Vai para ' . $telas[$destino]['numero'] . '. ' . $telas[$destino]['titulo'] : $destino;
}

// Arquivos que o cliente pode trocar. As chaves são as mesmas do app
// (lib/app_config.dart, AppConfig.imagensPadrao e AppConfig.imagemBotao).
function painelSlots() {
    $slots = array();
    foreach (painelTelas() as $idTela => $tela) {
        if (!empty($tela['fundo'])) {
            $slots[$tela['fundo']] = array(
                'titulo' => 'Imagem da tela ' . $tela['titulo'],
                'tipo' => 'imagem',
                'padrao' => $tela['fundo'] . '.jpg'
            );
        }
        if (empty($tela['elementos'])) {
            continue;
        }
        foreach ($tela['elementos'] as $id => $elemento) {
            if ($elemento['tipo'] === 'botao') {
                $slots['botao_' . $id] = array('titulo' => $elemento['titulo'], 'tipo' => 'imagem', 'padrao' => '', 'elemento' => $id);
            } elseif ($elemento['tipo'] === 'imagem') {
                $slots[$id] = array('titulo' => $elemento['titulo'], 'tipo' => 'imagem', 'padrao' => 'balao_img2.png', 'elemento' => $id);
            }
        }
    }
    $slots['video_carregamento'] = array(
        'titulo' => 'Vídeo de carregamento',
        'descricao' => 'Toca em loop enquanto a foto é processada e enviada. MP4 (H.264), 1920x1080, de preferência com menos de 20 MB.',
        'tipo' => 'video',
        'padrao' => ''
    );
    return $slots;
}

// ---------- Posição e tamanho dos elementos ----------

function painelElementos() {
    $elementos = array();
    foreach (painelTelas() as $idTela => $tela) {
        if (!empty($tela['elementos'])) {
            foreach ($tela['elementos'] as $id => $elemento) {
                $elemento['tela'] = $idTela;
                $elementos[$id] = $elemento;
            }
        }
    }
    return $elementos;
}

// Deixa o retângulo dentro da tela, com um tamanho mínimo para dar para tocar
function painelRetangulo($x, $y, $w, $h) {
    $w = min(1, max(0.02, (float) $w));
    $h = min(1, max(0.02, (float) $h));
    $x = min(1 - $w, max(0, (float) $x));
    $y = min(1 - $h, max(0, (float) $y));
    return array('x' => round($x, 4), 'y' => round($y, 4), 'w' => round($w, 4), 'h' => round($h, 4));
}

// Padrões que foram corrigidos: quem ainda tem o valor antigo salvo (salvou a
// tela sem mexer no elemento) passa a usar o padrão novo
function painelPadroesAntigos() {
    return array(
        'qrcode' => array(array('x' => 0.77, 'y' => 0.2422, 'w' => 0.16, 'h' => 0.2844))
    );
}

// Posição de todos os elementos: a salva no painel ou a padrão
function painelLayout($config) {
    $salvo = isset($config['layout']) && is_array($config['layout']) ? $config['layout'] : array();
    $antigos = painelPadroesAntigos();
    $layout = array();
    foreach (painelElementos() as $id => $elemento) {
        $r = isset($salvo[$id]) ? $salvo[$id] : null;
        if (is_array($r) && isset($antigos[$id])) {
            foreach ($antigos[$id] as $antigo) {
                if (isset($r['x'], $r['y'], $r['w'], $r['h']) && abs($r['x'] - $antigo['x']) < 0.00005 && abs($r['y'] - $antigo['y']) < 0.00005
                    && abs($r['w'] - $antigo['w']) < 0.00005 && abs($r['h'] - $antigo['h']) < 0.00005) {
                    $r = null;
                }
            }
        }
        $layout[$id] = is_array($r) && isset($r['x'], $r['y'], $r['w'], $r['h'])
            ? painelRetangulo($r['x'], $r['y'], $r['w'], $r['h'])
            : painelRetangulo($elemento['padrao'][0], $elemento['padrao'][1], $elemento['padrao'][2], $elemento['padrao'][3]);
    }
    return $layout;
}

// Aplica as posições enviadas pelo editor (JSON) aos elementos de uma tela
function painelAplicarLayout($config, $idTela, $json) {
    $telas = painelTelas();
    $dados = json_decode((string) $json, true);
    if (!isset($telas[$idTela]['elementos']) || !is_array($dados)) {
        return $config;
    }
    foreach ($telas[$idTela]['elementos'] as $id => $elemento) {
        if (isset($dados[$id]) && is_array($dados[$id]) && isset($dados[$id]['x'], $dados[$id]['y'], $dados[$id]['w'], $dados[$id]['h'])) {
            $r = $dados[$id];
            $config['layout'][$id] = painelRetangulo($r['x'], $r['y'], $r['w'], $r['h']);
        }
    }
    return $config;
}

// Com uma imagem nova, o elemento ganha o formato dela (mesma largura e centro)
function painelAjustarAoFormato($config, $idElemento, $largura, $altura) {
    if ($largura <= 0 || $altura <= 0) {
        return $config;
    }
    $layout = painelLayout($config);
    if (!isset($layout[$idElemento])) {
        return $config;
    }
    $r = $layout[$idElemento];
    $w = $r['w'];
    // Tela 16:9: altura em fração = largura * 16/9 / (largura/altura da imagem)
    $h = $w * (16 / 9) * $altura / $largura;
    if ($h > 1) {
        $w = $w / $h;
        $h = 1;
    }
    $cx = $r['x'] + $r['w'] / 2;
    $cy = $r['y'] + $r['h'] / 2;
    $config['layout'][$idElemento] = painelRetangulo($cx - $w / 2, $cy - $h / 2, $w, $h);
    return $config;
}

// Pastas da raiz do appFoto que não podem virar pasta das fotos
function painelPastasReservadas() {
    return array('config', 'painel', 'login', 'licencas', 'galeria', 'leads', 'eventos', 'backup', 'cache', 'tools', 'fotos-arquivadas', 'cgi-bin', 'midia');
}

function h($valor) {
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function painelAleatorio($bytes) {
    if (function_exists('random_bytes')) {
        return bin2hex(random_bytes($bytes));
    }
    if (function_exists('openssl_random_pseudo_bytes')) {
        return bin2hex(openssl_random_pseudo_bytes($bytes));
    }
    return substr(sha1(uniqid((string) mt_rand(), true)), 0, $bytes * 2);
}

function painelDataBr($iso) {
    $tempo = $iso ? strtotime($iso) : false;
    return $tempo ? date('d/m/Y H:i', $tempo) : '-';
}

function painelTamanhoTexto($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 1, ',', '.') . ' GB';
    }
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
    }
    // Sem nenhuma foto: "0 KB" (antes aparecia "1 KB")
    return ($bytes > 0 ? max(1, round($bytes / 1024)) : 0) . ' KB';
}

// ---------- Leitura e gravação dos arquivos de cliente ----------

function painelLerArquivo($caminho) {
    if (!is_file($caminho)) {
        return null;
    }
    $conteudo = file_get_contents($caminho);
    if (strpos($conteudo, PAINEL_CABECALHO_ARQUIVO) === 0) {
        $conteudo = substr($conteudo, strlen(PAINEL_CABECALHO_ARQUIVO));
    }
    $dados = json_decode($conteudo, true);
    return is_array($dados) ? $dados : null;
}

// Grava num temporário e renomeia, para nunca deixar um arquivo pela metade
function painelGravarArquivo($caminho, $dados) {
    $pasta = dirname($caminho);
    if (!is_dir($pasta) && !mkdir($pasta, 0755, true)) {
        return false;
    }

    $json = json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    $temporario = $caminho . '.tmp-' . painelAleatorio(4);
    if (file_put_contents($temporario, PAINEL_CABECALHO_ARQUIVO . $json) === false) {
        return false;
    }
    if (!@rename($temporario, $caminho)) {
        // PHP antigo no Windows não sobrescreve no rename
        @unlink($caminho);
        if (!@rename($temporario, $caminho)) {
            @unlink($temporario);
            return false;
        }
    }
    return true;
}

function painelIdValido($id) {
    return is_string($id) && preg_match('/^c[a-f0-9]{10}$/', $id) === 1;
}

function painelArquivoCliente($id) {
    return PAINEL_CLIENTES . DIRECTORY_SEPARATOR . $id . '.php';
}

function painelCliente($id) {
    return painelIdValido($id) ? painelLerArquivo(painelArquivoCliente($id)) : null;
}

function painelClientes() {
    $clientes = array();
    foreach (glob(PAINEL_CLIENTES . DIRECTORY_SEPARATOR . 'c*.php') ?: array() as $arquivo) {
        $cliente = painelLerArquivo($arquivo);
        if ($cliente && isset($cliente['id']) && painelIdValido($cliente['id'])) {
            $clientes[] = $cliente;
        }
    }
    usort($clientes, function ($a, $b) {
        return strcasecmp($a['nome'], $b['nome']);
    });
    return $clientes;
}

// O cliente do painel. É criado no primeiro acesso, e o nome e a pasta das
// fotos acompanham o config/painel.local.php (mudar lá sobe a versão, para o
// robô passar a enviar as fotos para a pasta nova).
function painelClienteUnico() {
    $local = painelConfigLocal();
    if (!$local) {
        return null;
    }
    if (!is_dir(PAINEL_CLIENTES) && !mkdir(PAINEL_CLIENTES, 0755, true)) {
        return null;
    }

    // Trava para dois acessos ao mesmo tempo não criarem dois cadastros
    $trava = @fopen(PAINEL_CLIENTES . DIRECTORY_SEPARATOR . 'criar.lock', 'c');
    if (!$trava) {
        return null;
    }
    if (!flock($trava, LOCK_EX)) {
        fclose($trava);
        return null;
    }
    $clientes = painelClientes();
    $cliente = $clientes ? $clientes[0] : painelCriarCliente($local['nome'], $local['pasta_fotos']);
    flock($trava, LOCK_UN);
    fclose($trava);

    if ($cliente && ($cliente['nome'] !== $local['nome'] || $cliente['pasta_fotos'] !== $local['pasta_fotos'])) {
        $atualizado = painelAtualizarCliente($cliente['id'], function ($c) use ($local) {
            $c['nome'] = $local['nome'];
            $c['pasta_fotos'] = $local['pasta_fotos'];
            return painelMarcarAlteracao($c);
        });
        if ($atualizado) {
            $cliente = $atualizado;
        }
    }
    return $cliente;
}

function painelConfigPadrao() {
    return array(
        'versao' => 1,
        'atualizado_em' => date('c'),
        'cor_primaria' => '#0b3675',
        'pin_admin' => '1234',
        'tempo_inatividade' => PAINEL_TEMPO_INATIVIDADE_PADRAO,
        'midia' => array(),
        'molduras' => array(),
        'layout' => array()
    );
}

function painelValidarTempoInatividade($valor) {
    if (!is_int($valor) && !is_string($valor)) {
        return false;
    }
    return filter_var($valor, FILTER_VALIDATE_INT, array('options' => array(
        'min_range' => 1,
        'max_range' => PAINEL_TEMPO_INATIVIDADE_MAXIMO
    )));
}

function painelTempoInatividade($config) {
    $tempo = isset($config['tempo_inatividade']) ? painelValidarTempoInatividade($config['tempo_inatividade']) : false;
    return $tempo === false ? PAINEL_TEMPO_INATIVIDADE_PADRAO : $tempo;
}

function painelCriarCliente($nome, $pastaFotos) {
    do {
        $id = 'c' . painelAleatorio(5);
    } while (is_file(painelArquivoCliente($id)));

    $cliente = array(
        'id' => $id,
        'nome' => $nome,
        'pasta_fotos' => $pastaFotos,
        'criado_em' => date('c'),
        'config' => painelConfigPadrao(),
        'dispositivos' => array()
    );

    return painelGravarArquivo(painelArquivoCliente($id), $cliente) ? $cliente : null;
}

function painelTravarCliente($id, $modo) {
    if (!painelIdValido($id)) {
        return null;
    }
    if (!is_dir(PAINEL_CLIENTES) && !mkdir(PAINEL_CLIENTES, 0755, true)) {
        return null;
    }

    $trava = @fopen(PAINEL_CLIENTES . DIRECTORY_SEPARATOR . $id . '.lock', 'c');
    if (!$trava) {
        return null;
    }
    if (!flock($trava, $modo)) {
        fclose($trava);
        return null;
    }

    return $trava;
}

// Lê, altera e grava o cliente sob a mesma trava usada nos envios.
// $alterar recebe o cliente e devolve os dados alterados ou null.
function painelAtualizarCliente($id, $alterar, $limparMidia = false) {
    $trava = painelTravarCliente($id, LOCK_EX);
    if (!$trava) {
        return null;
    }
    $resultado = null;
    $cliente = painelCliente($id);
    if ($cliente) {
        $novo = call_user_func($alterar, $cliente);
        if (is_array($novo) && ($novo === $cliente || painelGravarArquivo(painelArquivoCliente($id), $novo))) {
            $resultado = $novo;
            if ($limparMidia && $novo !== $cliente) {
                painelLimparMidia($novo);
            }
        }
    }

    flock($trava, LOCK_UN);
    fclose($trava);
    return $resultado;
}

// Toda mudança visível no app aumenta a versão; o robô compara com a dele.
function painelMarcarAlteracao($cliente) {
    $cliente['config']['versao'] = (int) $cliente['config']['versao'] + 1;
    $cliente['config']['atualizado_em'] = date('c');
    return $cliente;
}

// O PIN, a inatividade e os vínculos de evento pertencem ao painel. Só estes
// campos são copiados e editados como visual próprio de um robô.
function painelSomenteVisual($config) {
    return array_intersect_key($config, array('midia' => true, 'molduras' => true, 'layout' => true, 'cor_primaria' => true));
}

function painelVisualProprio($cliente, $codigo) {
    return !empty($cliente['config']['visuais_robos'][$codigo]['proprio']);
}

function painelConfigDoRobo($cliente, $codigo) {
    $config = $cliente['config'];
    if (painelVisualProprio($cliente, $codigo)) {
        $config = array_replace($config, $config['visuais_robos'][$codigo]['config']);
    }
    return $config;
}

function painelAtualizarVisual($id, $codigo, $alterar, $limparMidia = true) {
    return painelAtualizarCliente($id, function ($cliente) use ($codigo, $alterar) {
        if ($codigo !== '' && !painelVisualProprio($cliente, $codigo)) {
            return null;
        }
        $config = $codigo === '' ? $cliente['config'] : $cliente['config']['visuais_robos'][$codigo]['config'];
        $novo = call_user_func($alterar, $config);
        if (!is_array($novo)) {
            return null;
        }
        if ($novo === $config) {
            return $cliente;
        }
        // Remove slots que já saíram do app, como a antiga "capa".
        $novo['midia'] = array_intersect_key($novo['midia'], painelSlots());
        if ($codigo === '') {
            $cliente['config'] = $novo;
        } else {
            $cliente['config']['visuais_robos'][$codigo]['config'] = $novo;
        }
        return painelMarcarAlteracao($cliente);
    }, $limparMidia);
}

// ---------- Arquivos enviados ----------

function painelUrlMidia($id, $arquivo) {
    return 'midia/' . rawurlencode($id) . '/' . rawurlencode($arquivo);
}

function painelBytesIni($valor) {
    $valor = trim((string) $valor);
    $numero = (float) $valor;
    switch (strtolower(substr($valor, -1))) {
        case 'g':
            $numero *= 1024;
        case 'm':
            $numero *= 1024;
        case 'k':
            $numero *= 1024;
    }
    return (int) $numero;
}

// Maior arquivo que o PHP deste servidor aceita receber
function painelLimiteUpload() {
    $limites = array();
    foreach (array('upload_max_filesize', 'post_max_size') as $chave) {
        $bytes = painelBytesIni(ini_get($chave));
        if ($bytes > 0) {
            $limites[] = $bytes;
        }
    }
    return $limites ? min($limites) : 0;
}

function painelLimiteUploadTexto() {
    $limite = painelLimiteUpload();
    return $limite > 0 ? painelTamanhoTexto($limite) : 'sem limite';
}

// Quando o envio passa do post_max_size o PHP descarta tudo: $_POST e $_FILES vazios
function painelPostGrandeDemais() {
    return $_SERVER['REQUEST_METHOD'] === 'POST'
        && empty($_POST) && empty($_FILES)
        && isset($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > 0;
}

function painelPareceMp4($caminho) {
    $arquivo = @fopen($caminho, 'rb');
    if (!$arquivo) {
        return false;
    }
    $inicio = fread($arquivo, 12);
    fclose($arquivo);
    return strlen($inicio) === 12 && substr($inicio, 4, 4) === 'ftyp';
}

// $_FILES de um campo "nome[]" vira uma lista de arquivos
function painelListaUploads($campo) {
    if (!isset($_FILES[$campo]) || !is_array($_FILES[$campo]['name'])) {
        return isset($_FILES[$campo]) ? array($_FILES[$campo]) : array();
    }
    $lista = array();
    foreach ($_FILES[$campo]['name'] as $i => $nome) {
        $lista[] = array(
            'name' => $nome,
            'type' => $_FILES[$campo]['type'][$i],
            'tmp_name' => $_FILES[$campo]['tmp_name'][$i],
            'error' => $_FILES[$campo]['error'][$i],
            'size' => $_FILES[$campo]['size'][$i]
        );
    }
    return $lista;
}

// Valida e guarda um arquivo enviado. $tipo: 'imagem', 'moldura' ou 'video'.
// Retorna os dados do arquivo ou null, com a mensagem em $erro.
function painelGuardarUpload($idCliente, $upload, $tipo, &$erro) {
    $erro = '';
    $nomeOriginal = isset($upload['name']) ? basename((string) $upload['name']) : '';

    if (!isset($upload['error']) || $upload['error'] === UPLOAD_ERR_NO_FILE) {
        $erro = 'Escolha um arquivo.';
        return null;
    }
    if ($upload['error'] === UPLOAD_ERR_INI_SIZE || $upload['error'] === UPLOAD_ERR_FORM_SIZE) {
        $erro = '"' . $nomeOriginal . '" passa do limite do servidor (' . painelLimiteUploadTexto() . ').';
        return null;
    }
    if ($upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
        $erro = 'Falha ao enviar "' . $nomeOriginal . '". Tente de novo.';
        return null;
    }

    $info = array(
        'nome_original' => function_exists('mb_substr') ? mb_substr($nomeOriginal, 0, 120, 'UTF-8') : substr($nomeOriginal, 0, 120),
        'tamanho' => (int) filesize($upload['tmp_name']),
        'enviado_em' => date('c')
    );

    if ($tipo === 'video') {
        if (strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION)) !== 'mp4' || !painelPareceMp4($upload['tmp_name'])) {
            $erro = 'O vídeo precisa ser um arquivo MP4.';
            return null;
        }
        $extensao = 'mp4';
    } else {
        $imagem = @getimagesize($upload['tmp_name']);
        $permitidos = $tipo === 'moldura' ? array(IMAGETYPE_PNG) : array(IMAGETYPE_PNG, IMAGETYPE_JPEG);
        if (!is_array($imagem) || !in_array($imagem[2], $permitidos, true)) {
            $erro = $tipo === 'moldura'
                ? '"' . $nomeOriginal . '" não é PNG. A moldura precisa ser PNG com fundo transparente.'
                : '"' . $nomeOriginal . '" não é uma imagem PNG ou JPG.';
            return null;
        }
        $extensao = $imagem[2] === IMAGETYPE_PNG ? 'png' : 'jpg';
        $info['largura'] = (int) $imagem[0];
        $info['altura'] = (int) $imagem[1];
    }

    $pasta = PAINEL_MIDIA . DIRECTORY_SEPARATOR . $idCliente;
    if (!is_dir($pasta) && !mkdir($pasta, 0755, true)) {
        $erro = 'Não foi possível criar a pasta de arquivos no servidor.';
        return null;
    }
    if (!is_file($pasta . DIRECTORY_SEPARATOR . 'index.html')) {
        @file_put_contents($pasta . DIRECTORY_SEPARATOR . 'index.html', '');
    }

    $nome = substr(sha1_file($upload['tmp_name']), 0, 20) . '.' . $extensao;
    $destino = $pasta . DIRECTORY_SEPARATOR . $nome;
    if (!is_file($destino) && !move_uploaded_file($upload['tmp_name'], $destino)) {
        $erro = 'Não foi possível salvar "' . $nomeOriginal . '" no servidor.';
        return null;
    }
    @chmod($destino, 0644);

    $info['arquivo'] = $nome;
    return $info;
}

// Mantém as mídias referenciadas por todos os visuais, inclusive o próprio
// de um robô que voltou temporariamente ao visual compartilhado.
function painelLimparMidia($cliente) {
    $usados = array('index.html' => true);
    $config = $cliente['config'];
    $visuais = isset($config['visuais_robos']) ? $config['visuais_robos'] : array();
    $configuracoes = array($config);
    foreach ($visuais as $visual) {
        $configuracoes[] = $visual['config'];
    }
    foreach ($configuracoes as $visual) {
        foreach (array_merge(array_values($visual['midia']), $visual['molduras']) as $item) {
            $usados[$item['arquivo']] = true;
        }
    }

    foreach (glob(PAINEL_MIDIA . DIRECTORY_SEPARATOR . $cliente['id'] . DIRECTORY_SEPARATOR . '*') ?: array() as $arquivo) {
        if (is_file($arquivo) && !isset($usados[basename($arquivo)])) {
            @unlink($arquivo);
        }
    }
}

// ---------- Fotos tiradas pelos robôs ----------

// ---------- Eventos ----------
//
// Cada evento tem uma pasta de fotos: <pasta das fotos>/eventos/<id>, com id =
// data de criação + nome ("2026-11-09-casamento-ana"). Quem escolhe a pasta é o
// servidor: o uploadImage.php consulta os vínculos do cliente a cada foto.
// Trocar o evento no painel vale no próximo envio, sem sincronizar o robô.
//
// Desde 23/11/2026 as fotos só podem ser tiradas com um evento: sem evento
// atual, o uploadImage.php recusa a foto e o robô mostra "Nenhum evento ativo"
// no lugar da tela inicial (ele pergunta ao painel/api/evento.php). As fotos
// "Sem evento" que já estavam na pasta das fotos continuam na galeria.
//
// O cliente pode encerrar o evento (os robôs param) e reabrir depois. Quem já
// estava tirando foto ainda tem PAINEL_TOLERANCIA_ENCERRAR segundos: a foto que
// chegar nesse tempo entra no evento que acabou de ser encerrado.

define('PAINEL_PASTA_EVENTOS', 'eventos');
// Cinco minutos, usados no envio de fotos de um evento encerrado.
define('PAINEL_TOLERANCIA_ENCERRAR', 300);

// Eventos do cliente, do criado por último para o mais antigo. Evento apagado
// pelo cliente com as fotos na lixeira ("apagado_em") só entra com $comApagados:
// ele volta se alguma foto dele for restaurada.
function painelEventos($cliente, $comApagados = false) {
    $eventos = isset($cliente['config']['eventos']) && is_array($cliente['config']['eventos']) ? $cliente['config']['eventos'] : array();
    if (!$comApagados) {
        $eventos = array_values(array_filter($eventos, function ($evento) {
            return empty($evento['apagado_em']);
        }));
    }
    usort($eventos, function ($a, $b) {
        $ordem = strcmp($b['criado_em'], $a['criado_em']);
        return $ordem !== 0 ? $ordem : strcmp($b['id'], $a['id']);
    });
    return $eventos;
}

function painelEventoAtual($cliente) {
    $id = isset($cliente['config']['evento_atual']) ? (string) $cliente['config']['evento_atual'] : '';
    return painelEvento($cliente, $id);
}

function painelEventosDosRobos($cliente) {
    return isset($cliente['config']['eventos_dos_robos']) && is_array($cliente['config']['eventos_dos_robos'])
        ? $cliente['config']['eventos_dos_robos'] : array();
}

function painelEventoAberto($cliente, $evento) {
    return $evento && empty($evento['apagado_em']) && empty($evento['encerrado_em'])
        && (!empty($evento['aberto']) || (isset($cliente['config']['evento_atual']) && $cliente['config']['evento_atual'] === $evento['id']));
}

function painelEventoDoRobo($cliente, $codigo) {
    $vinculos = painelEventosDosRobos($cliente);
    if (!isset($vinculos[$codigo])) {
        return painelEventoAtual($cliente);
    }
    $evento = painelEvento($cliente, $vinculos[$codigo]);
    return painelEventoAberto($cliente, $evento) ? $evento : null;
}

function painelEventoDoEnvio($cliente, $codigo) {
    $evento = painelEventoDoRobo($cliente, $codigo);
    if ($evento) {
        return $evento;
    }
    $vinculos = painelEventosDosRobos($cliente);
    if (isset($vinculos[$codigo])) {
        $evento = painelEvento($cliente, $vinculos[$codigo]);
        $em = $evento && !empty($evento['encerrado_em']) ? strtotime($evento['encerrado_em']) : 0;
    } elseif (isset($cliente['config']['encerramento_padrao'])) {
        $encerrado = $cliente['config']['encerramento_padrao'];
        $evento = painelEvento($cliente, $encerrado['id']);
        $em = $encerrado['em'];
    } else {
        $encerrado = painelEventoEncerrado($cliente);
        $evento = $encerrado ? $encerrado['evento'] : null;
        $em = $encerrado ? $encerrado['em'] : 0;
    }
    return $evento && $em && time() - $em <= PAINEL_TOLERANCIA_ENCERRAR ? $evento : null;
}

// O evento com esse id, ou null ('' ou id desconhecido)
function painelEvento($cliente, $id, $comApagados = false) {
    foreach (painelEventos($cliente, $comApagados) as $evento) {
        if ($evento['id'] === $id) {
            return $evento;
        }
    }
    return null;
}

// Id novo a partir do nome: data + nome sem acentos, único entre os eventos
function painelNovoIdEvento($cliente, $nome, $idsReservados = array()) {
    $texto = strtolower(strtr($nome, array(
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'Á' => 'a', 'À' => 'a', 'Â' => 'a', 'Ã' => 'a', 'Ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'É' => 'e', 'È' => 'e', 'Ê' => 'e', 'Ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'Í' => 'i', 'Ì' => 'i', 'Î' => 'i', 'Ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'Ó' => 'o', 'Ò' => 'o', 'Ô' => 'o', 'Õ' => 'o', 'Ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'Ú' => 'u', 'Ù' => 'u', 'Û' => 'u', 'Ü' => 'u',
        'ç' => 'c', 'Ç' => 'c', 'ñ' => 'n', 'Ñ' => 'n'
    )));
    $texto = trim(substr(trim(preg_replace('/[^a-z0-9]+/', '-', $texto), '-'), 0, 50), '-');
    $base = date('Y-m-d') . ($texto !== '' ? '-' . $texto : '');
    $id = $base;
    for ($i = 2; painelEvento($cliente, $id, true) || in_array($id, $idsReservados, true) || is_dir(painelPastaFotos($cliente, '', true) . DIRECTORY_SEPARATOR . $id); $i++) {
        $id = $base . '-' . $i;
    }
    return $id;
}

function painelIdEventoValido($id) {
    return is_string($id) && preg_match('/^\d{4}-\d{2}-\d{2}(-[a-z0-9]+)*$/', $id) === 1 && strlen($id) <= 80;
}

// Evento encerrado por último (o de "encerrado_em" mais novo), enquanto não há
// evento aberto: array('evento' => evento, 'em' => timestamp) ou null. Se a
// equipe apagar esse evento, vale o encerrado antes dele.
function painelEventoEncerrado($cliente) {
    if (painelEventoAtual($cliente)) {
        return null;
    }
    $ultimo = null;
    foreach (painelEventos($cliente) as $evento) {
        if (!empty($evento['encerrado_individual'])) {
            continue;
        }
        $em = !empty($evento['encerrado_em']) ? strtotime($evento['encerrado_em']) : false;
        // "encerrado_ordem" (microtime) desempata dois encerrados no mesmo segundo
        $ordem = isset($evento['encerrado_ordem']) ? (float) $evento['encerrado_ordem'] : (float) $em;
        if ($em && (!$ultimo || $ordem > $ultimo['ordem'])) {
            $ultimo = array('evento' => $evento, 'em' => $em, 'ordem' => $ordem);
        }
    }
    return $ultimo;
}

// Abrir para um robô mantém o evento aberto mesmo se o padrão mudar.
function painelAbrirEvento($c, $id, $individual) {
    if ($individual && !isset($c['config']['encerramento_padrao'])) {
        $ultimo = painelEventoEncerrado($c);
        if ($ultimo) {
            $c['config']['encerramento_padrao'] = array('id' => $ultimo['evento']['id'], 'em' => $ultimo['em']);
        }
    }
    foreach ($c['config']['eventos'] as &$evento) {
        if ($evento['id'] === $id) {
            unset($evento['encerrado_em'], $evento['encerrado_ordem'], $evento['encerrado_individual']);
            if ($individual) {
                $evento['aberto'] = true;
            }
            break;
        }
    }
    unset($evento);
    return $c;
}

function painelEncerrarEvento($c, $id) {
    $padrao = isset($c['config']['evento_atual']) && $c['config']['evento_atual'] === $id;
    $agora = time();
    foreach ($c['config']['eventos'] as &$evento) {
        if ($evento['id'] === $id) {
            $evento['encerrado_em'] = date('c', $agora);
            $evento['encerrado_ordem'] = microtime(true);
            $evento['encerrado_individual'] = !$padrao;
            unset($evento['aberto']);
            break;
        }
    }
    unset($evento);
    if ($padrao) {
        $c['config']['evento_atual'] = '';
        $c['config']['encerramento_padrao'] = array('id' => $id, 'em' => $agora);
    }
    return $c;
}

// Um evento aberto só pelo padrão se encerra ao sair do padrão. Eventos
// abertos individualmente continuam recebendo as fotos dos robôs vinculados.
function painelMudarEventoAtual($c, $novo) {
    $antes = painelEventoAtual($c);
    if ($antes && $antes['id'] !== $novo && ($novo === '' || empty($antes['aberto']))) {
        $c = painelEncerrarEvento($c, $antes['id']);
    }
    $c['config']['evento_atual'] = $novo;
    return $novo !== '' ? painelAbrirEvento($c, $novo, false) : $c;
}

// Resumo para a central de licenças e para instalações antigas do upload.
function painelGravarEventoAtual($cliente) {
    $evento = painelEventoAtual($cliente);
    $dados = array(
        'pasta' => $evento ? PAINEL_PASTA_EVENTOS . '/' . $evento['id'] : '',
        'nome' => $evento ? $evento['nome'] : ''
    );
    $encerrado = painelEventoEncerrado($cliente);
    if ($encerrado) {
        $dados['encerrado'] = array(
            'pasta' => PAINEL_PASTA_EVENTOS . '/' . $encerrado['evento']['id'],
            'nome' => $encerrado['evento']['nome'],
            'em' => $encerrado['em']
        );
    }
    foreach (painelEventosDosRobos($cliente) as $codigo => $idEvento) {
        $eventoRobo = painelEvento($cliente, $idEvento);
        if (painelEventoAberto($cliente, $eventoRobo)) {
            $dados['eventos_robos'][$codigo] = $eventoRobo['nome'];
        }
    }
    $arquivo = PAINEL_DADOS . DIRECTORY_SEPARATOR . 'evento_atual.php';
    if (painelLerArquivo($arquivo) !== $dados) {
        painelGravarArquivo($arquivo, $dados);
    }
}

// ---------- Fotos tiradas pelos robôs ----------

// Pasta das fotos de um evento ('' = sem evento: a própria pasta do
// uploadImage.php + pasta_fotos). $raizEventos = a pasta que guarda os eventos.
function painelPastaFotos($cliente, $evento = '', $raizEventos = false) {
    $pasta = isset($cliente['pasta_fotos']) ? (string) $cliente['pasta_fotos'] : '';
    $caminho = $pasta === '' ? PAINEL_RAIZ : PAINEL_RAIZ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pasta);
    if ($raizEventos) {
        return $caminho . DIRECTORY_SEPARATOR . PAINEL_PASTA_EVENTOS;
    }
    return $evento === '' ? $caminho : $caminho . DIRECTORY_SEPARATOR . PAINEL_PASTA_EVENTOS . DIRECTORY_SEPARATOR . $evento;
}

// Miniaturas guardadas, na mesma divisão das fotos
function painelPastaMiniaturas($cliente, $evento = '') {
    $pasta = PAINEL_DADOS . DIRECTORY_SEPARATOR . 'miniaturas' . DIRECTORY_SEPARATOR . $cliente['id'];
    return $evento === '' ? $pasta : $pasta . DIRECTORY_SEPARATOR . PAINEL_PASTA_EVENTOS . DIRECTORY_SEPARATOR . $evento;
}

function painelNomeFotoValido($nome) {
    return is_string($nome) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,150}\.(jpe?g|png|webp)$/i', $nome) === 1;
}

// Espaço usado por todas as fotos (sem evento + todas as pastas de evento, até as
// que não estão mais na lista + a lixeira, que ainda ocupa espaço). Devolve
// array(bytes, quantidade fora da lixeira, bytes da lixeira).
function painelUsoFotos($cliente) {
    $lixeira = 0;
    foreach (painelItensLixeira($cliente) as $item) {
        $lixeira += $item['tamanho'];
    }
    list($bytes, $quantidade) = painelUsoFotosForaDaLixeira($cliente);
    return array($bytes + $lixeira, $quantidade, $lixeira);
}

function painelUsoFotosForaDaLixeira($cliente) {
    $pastas = array(painelPastaFotos($cliente));
    $raiz = painelPastaFotos($cliente, '', true);
    foreach ((is_dir($raiz) ? scandir($raiz) : false) ?: array() as $nome) {
        if (painelIdEventoValido($nome) && is_dir($raiz . DIRECTORY_SEPARATOR . $nome)) {
            $pastas[] = $raiz . DIRECTORY_SEPARATOR . $nome;
        }
    }
    $bytes = 0;
    $quantidade = 0;
    foreach ($pastas as $pasta) {
        foreach (scandir($pasta) ?: array() as $nome) {
            $caminho = $pasta . DIRECTORY_SEPARATOR . $nome;
            if (painelNomeFotoValido($nome) && is_file($caminho)) {
                $bytes += (float) sprintf('%u', filesize($caminho));
                $quantidade++;
            }
        }
    }
    return array($bytes, $quantidade);
}

// Limite de espaço das fotos, em bytes (limite_gb no config/painel.local.php, padrão 5)
function painelLimiteFotos() {
    $local = painelConfigLocal();
    $gb = $local && $local['limite_gb'] > 0 ? $local['limite_gb'] : 5;
    return $gb * 1073741824;
}

// Fotos da pasta de um evento ('' = sem evento), da mais nova para a mais
// antiga. Cada uma: nome, caminho, tamanho, tempo (timestamp) e dia (AAAA-MM-DD).
function painelFotos($cliente, $evento = '') {
    $pasta = painelPastaFotos($cliente, $evento);
    $fotos = array();
    foreach ((is_dir($pasta) ? scandir($pasta) : false) ?: array() as $nome) {
        $caminho = $pasta . DIRECTORY_SEPARATOR . $nome;
        if (!painelNomeFotoValido($nome) || !is_file($caminho)) {
            continue;
        }
        // photo_2026-10-30_14-05-09_ab12cd34.jpg: a hora do envio vem no nome
        if (preg_match('/^photo_(\d{4})-(\d{2})-(\d{2})_(\d{2})-(\d{2})-(\d{2})/', $nome, $m)) {
            $tempo = mktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[2], (int) $m[3], (int) $m[1]);
        } else {
            $tempo = filemtime($caminho);
        }
        $fotos[] = array(
            'nome' => $nome,
            'caminho' => $caminho,
            'tamanho' => (float) sprintf('%u', filesize($caminho)),
            'tempo' => $tempo,
            'dia' => date('Y-m-d', $tempo)
        );
    }
    usort($fotos, function ($a, $b) {
        if ($a['tempo'] !== $b['tempo']) {
            return $a['tempo'] < $b['tempo'] ? 1 : -1;
        }
        return strcmp($b['nome'], $a['nome']);
    });
    return $fotos;
}

// Apaga fotos pelo nome (só o que a galeria reconhece como foto, dentro da pasta
// do evento) e as miniaturas delas. Devolve array(apagadas, com falha).
function painelApagarFotos($cliente, $nomes, $evento = '') {
    $pasta = painelPastaFotos($cliente, $evento);
    $apagadas = 0;
    $falhas = 0;
    foreach ($nomes as $nome) {
        $caminho = $pasta . DIRECTORY_SEPARATOR . $nome;
        if (!painelNomeFotoValido($nome) || !is_file($caminho) || is_link($caminho)) {
            continue;
        }
        if (@unlink($caminho)) {
            $apagadas++;
        } else {
            $falhas++;
        }
    }

    // Miniaturas sem a foto original (também as que tenham sobrado de antes)
    foreach (glob(painelPastaMiniaturas($cliente, $evento) . DIRECTORY_SEPARATOR . '*') ?: array() as $mini) {
        $original = preg_replace('/\.jpg(\.tmp-[a-f0-9]+)?$/', '', basename($mini));
        if (is_file($mini) && !is_file($pasta . DIRECTORY_SEPARATOR . $original)) {
            @unlink($mini);
        }
    }
    return array($apagadas, $falhas);
}

// Quantas fotos há na pasta de um evento (sem ler data nem tamanho)
function painelContarFotos($cliente, $evento = '') {
    $pasta = painelPastaFotos($cliente, $evento);
    $n = 0;
    foreach ((is_dir($pasta) ? scandir($pasta) : false) ?: array() as $nome) {
        if (painelNomeFotoValido($nome) && is_file($pasta . DIRECTORY_SEPARATOR . $nome)) {
            $n++;
        }
    }
    return $n;
}

// Nomes das fotos de um evento que já existiam em $ate (horário em que a página
// abriu): foto que chegou depois não entra em "este dia", "todas" nem "apagar o
// evento". $dia ('AAAA-MM-DD') limita a um dia.
function painelNomesAte($cliente, $evento, $ate, $dia = '') {
    $nomes = array();
    foreach (painelFotos($cliente, $evento) as $foto) {
        if ($foto['tempo'] <= $ate && ($dia === '' || $foto['dia'] === $dia)) {
            $nomes[] = $foto['nome'];
        }
    }
    return $nomes;
}

// Apaga a pasta de um evento sem fotos. O uploadImage.php deixa um index.html
// vazio em cada pasta (para ninguém listar as fotos): ele sai junto. Outro
// arquivo qualquer segura a pasta. Devolve true se a pasta não existe mais.
function painelApagarPastaEvento($cliente, $evento) {
    if ($evento === '') {
        return false;
    }
    $pasta = painelPastaFotos($cliente, $evento);
    @rmdir(painelPastaMiniaturas($cliente, $evento));
    if (!is_dir($pasta)) {
        return true;
    }
    $sobra = array_diff(scandir($pasta) ?: array(), array('.', '..', 'index.html'));
    if ($sobra) {
        return false;
    }
    @unlink($pasta . DIRECTORY_SEPARATOR . 'index.html');
    return @rmdir($pasta);
}

// Registro simples das exclusões (data, quem, tipo, quantidade)
function painelAnotarExclusao($quem, $acao, $apagadas, $falhas) {
    @file_put_contents(
        PAINEL_DADOS . DIRECTORY_SEPARATOR . 'exclusoes.log',
        date('Y-m-d H:i:s') . ' ' . preg_replace('/[^a-z_]/', '', $quem) . ' ' . preg_replace('/[^a-z_]/', '', $acao)
            . ' apagadas=' . (int) $apagadas . ' falhas=' . (int) $falhas . "\n",
        FILE_APPEND | LOCK_EX
    );
}

function painelApagarEventoDeVez($cliente, $evento, $ate) {
    return painelExcluirEvento($cliente, $evento, $ate, false);
}

function painelEventoParaLixeira($cliente, $evento, $ate) {
    return painelExcluirEvento($cliente, $evento, $ate, true);
}

// Confere e remove sob a mesma trava que protege os vínculos e os uploads.
// Devolve o total removido e o erro, se a operação não pôde terminar.
function painelExcluirEvento($cliente, $evento, $ate, $paraLixeira) {
    $apagadas = 0;
    $erro = '';
    $salvo = painelAtualizarCliente($cliente['id'], function ($c) use ($evento, $ate, $paraLixeira, &$apagadas, &$erro) {
        $dadosEvento = painelEvento($c, $evento, !$paraLixeira);
        if (!$dadosEvento || painelEventoAberto($c, $dadosEvento)) {
            $erro = 'O evento aberto não pode ser apagado. Encerre o evento antes.';
            return null;
        }
        $nomes = painelNomesAte($c, $evento, $ate);
        if ($paraLixeira) {
            list($apagadas, $falhas) = painelMoverParaLixeira($c, $nomes, $evento);
        } else {
            list($apagadas, $falhas) = painelApagarFotos($c, $nomes, $evento);
        }
        if ($falhas > 0) {
            $erro = painelQuantidadeFotos($falhas)
                . ($paraLixeira ? ' não puderam ir para a lixeira' : ' não puderam ser excluídas') . ', então o evento não foi apagado.';
            return null;
        }
        if (painelContarFotos($c, $evento) > 0) {
            $erro = 'O evento ainda tem fotos (talvez tenham chegado agora). Ele não foi apagado.';
            return null;
        }
        if ($paraLixeira) {
            painelApagarPastaEvento($c, $evento);
            foreach ($c['config']['eventos'] as $i => $e) {
                if ($e['id'] === $evento) {
                    $c['config']['eventos'][$i]['apagado_em'] = date('c');
                    break;
                }
            }
            return $c;
        }
        $daLixeira = array();
        foreach (painelItensLixeira($c) as $item) {
            if ($item['evento'] === $evento) {
                $daLixeira[] = $item['chave'];
            }
        }
        painelExcluirDaLixeira($c, $daLixeira);
        if (!painelApagarPastaEvento($c, $evento)) {
            $erro = 'As fotos saíram, mas a pasta do evento tem outros arquivos e não foi apagada.';
            return null;
        }
        $c['config']['eventos'] = array_values(array_filter($c['config']['eventos'], function ($e) use ($evento) {
            return $e['id'] !== $evento;
        }));
        return $c;
    });
    return array($apagadas, $salvo ? '' : ($erro !== '' ? $erro : 'Não foi possível salvar a exclusão do evento.'));
}

// ---------- Lixeira ----------
//
// O cliente escolhe, ao excluir, entre a lixeira e "excluir de vez". Na
// lixeira, a foto sai da galeria e da pasta pública (o QR Code para de abrir),
// mas volta se for restaurada. Fica em config/painel/lixeira/<cliente>/<dia da
// exclusão>/<evento ou _sem-evento>/<foto> (fechada pelo .htaccess), com a
// miniatura em config/painel/miniaturas/<cliente>/lixeira/... Some sozinha
// PAINEL_LIXEIRA_DIAS dias depois (painelLimparLixeira, chamada nas páginas de
// fotos). Enquanto está lá, conta no espaço usado.

define('PAINEL_LIXEIRA_DIAS', 30);
define('PAINEL_LIXEIRA_SEM_EVENTO', '_sem-evento');

function painelPastaLixeira($cliente) {
    return PAINEL_DADOS . DIRECTORY_SEPARATOR . 'lixeira' . DIRECTORY_SEPARATOR . $cliente['id'];
}

function painelPastaMiniaturasLixeira($cliente) {
    return PAINEL_DADOS . DIRECTORY_SEPARATOR . 'miniaturas' . DIRECTORY_SEPARATOR . $cliente['id'] . DIRECTORY_SEPARATOR . 'lixeira';
}

// Move um arquivo, criando a pasta de destino (rename; se não der, copia e apaga)
function painelMoverArquivo($de, $para) {
    $pasta = dirname($para);
    if (!is_dir($pasta) && !@mkdir($pasta, 0755, true)) {
        return false;
    }
    if (@rename($de, $para)) {
        return true;
    }
    $tempo = @filemtime($de);
    if (@copy($de, $para)) {
        if ($tempo) {
            @touch($para, $tempo);
        }
        @unlink($de);
        return true;
    }
    return false;
}

// Manda fotos de um evento ('' = sem evento) para a lixeira de hoje. Devolve
// array(movidas, com falha). Só o que painelNomeFotoValido() aceita.
function painelMoverParaLixeira($cliente, $nomes, $evento = '') {
    $pasta = painelPastaFotos($cliente, $evento);
    $sub = date('Y-m-d') . DIRECTORY_SEPARATOR . ($evento === '' ? PAINEL_LIXEIRA_SEM_EVENTO : $evento);
    $destino = painelPastaLixeira($cliente) . DIRECTORY_SEPARATOR . $sub;
    $miniDe = painelPastaMiniaturas($cliente, $evento);
    $miniPara = painelPastaMiniaturasLixeira($cliente) . DIRECTORY_SEPARATOR . $sub;
    $movidas = 0;
    $falhas = 0;
    foreach ($nomes as $nome) {
        $caminho = $pasta . DIRECTORY_SEPARATOR . $nome;
        if (!painelNomeFotoValido($nome) || !is_file($caminho) || is_link($caminho)) {
            continue;
        }
        if (!is_file($destino . DIRECTORY_SEPARATOR . $nome) && painelMoverArquivo($caminho, $destino . DIRECTORY_SEPARATOR . $nome)) {
            $movidas++;
            if (is_file($miniDe . DIRECTORY_SEPARATOR . $nome . '.jpg')) {
                painelMoverArquivo($miniDe . DIRECTORY_SEPARATOR . $nome . '.jpg', $miniPara . DIRECTORY_SEPARATOR . $nome . '.jpg');
            }
        } else {
            $falhas++;
        }
    }
    return array($movidas, $falhas);
}

// Fotos na lixeira, da excluída por último para a mais antiga. Cada uma: chave
// ("dia/pasta/nome", usada nos formulários), dia (da exclusão), evento ('' =
// sem evento), nome, caminho, miniatura, tamanho, tempo (da foto) e expira.
function painelItensLixeira($cliente) {
    $raiz = painelPastaLixeira($cliente);
    $itens = array();
    foreach ((is_dir($raiz) ? scandir($raiz) : false) ?: array() as $dia) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia) || !is_dir($raiz . DIRECTORY_SEPARATOR . $dia)) {
            continue;
        }
        foreach (scandir($raiz . DIRECTORY_SEPARATOR . $dia) ?: array() as $sub) {
            if ($sub !== PAINEL_LIXEIRA_SEM_EVENTO && !painelIdEventoValido($sub)) {
                continue;
            }
            $pasta = $raiz . DIRECTORY_SEPARATOR . $dia . DIRECTORY_SEPARATOR . $sub;
            foreach ((is_dir($pasta) ? scandir($pasta) : false) ?: array() as $nome) {
                $caminho = $pasta . DIRECTORY_SEPARATOR . $nome;
                if (!painelNomeFotoValido($nome) || !is_file($caminho)) {
                    continue;
                }
                if (preg_match('/^photo_(\d{4})-(\d{2})-(\d{2})_(\d{2})-(\d{2})-(\d{2})/', $nome, $m)) {
                    $tempo = mktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[2], (int) $m[3], (int) $m[1]);
                } else {
                    $tempo = filemtime($caminho);
                }
                $itens[] = array(
                    'chave' => $dia . '/' . $sub . '/' . $nome,
                    'dia' => $dia,
                    'evento' => $sub === PAINEL_LIXEIRA_SEM_EVENTO ? '' : $sub,
                    'nome' => $nome,
                    'caminho' => $caminho,
                    'miniatura' => painelPastaMiniaturasLixeira($cliente) . DIRECTORY_SEPARATOR . $dia . DIRECTORY_SEPARATOR . $sub . DIRECTORY_SEPARATOR . $nome . '.jpg',
                    'tamanho' => (float) sprintf('%u', filesize($caminho)),
                    'tempo' => $tempo,
                    'expira' => strtotime($dia . ' +' . PAINEL_LIXEIRA_DIAS . ' days')
                );
            }
        }
    }
    usort($itens, function ($a, $b) {
        if ($a['dia'] !== $b['dia']) {
            return strcmp($b['dia'], $a['dia']);
        }
        if ($a['tempo'] !== $b['tempo']) {
            return $a['tempo'] < $b['tempo'] ? 1 : -1;
        }
        return strcmp($b['nome'], $a['nome']);
    });
    return $itens;
}

// Os itens da lixeira com essas chaves (as que não existem são ignoradas)
function painelItensLixeiraPorChave($cliente, $chaves) {
    $pedidas = array();
    foreach ($chaves as $chave) {
        if (is_string($chave)) {
            $pedidas[$chave] = true;
        }
    }
    $itens = array();
    foreach (painelItensLixeira($cliente) as $item) {
        if (isset($pedidas[$item['chave']])) {
            $itens[] = $item;
        }
    }
    return $itens;
}

// Tira da lixeira as pastas que ficaram vazias (dia/evento e dia)
function painelLimparPastasLixeira($cliente) {
    foreach (array(painelPastaLixeira($cliente), painelPastaMiniaturasLixeira($cliente)) as $raiz) {
        foreach ((is_dir($raiz) ? scandir($raiz) : false) ?: array() as $dia) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia)) {
                continue;
            }
            foreach (scandir($raiz . DIRECTORY_SEPARATOR . $dia) ?: array() as $sub) {
                if ($sub !== '.' && $sub !== '..') {
                    @rmdir($raiz . DIRECTORY_SEPARATOR . $dia . DIRECTORY_SEPARATOR . $sub);
                }
            }
            @rmdir($raiz . DIRECTORY_SEPARATOR . $dia);
        }
    }
}

// Evento apagado (na lixeira) que não tem mais foto nem item na lixeira sai de vez
function painelRemoverEventosApagados($cliente) {
    $naLixeira = array();
    foreach (painelItensLixeira($cliente) as $item) {
        $naLixeira[$item['evento']] = true;
    }
    $remover = array();
    foreach (painelEventos($cliente, true) as $evento) {
        if (!empty($evento['apagado_em']) && !isset($naLixeira[$evento['id']]) && painelContarFotos($cliente, $evento['id']) === 0
            && painelApagarPastaEvento($cliente, $evento['id'])) {
            $remover[$evento['id']] = true;
        }
    }
    if ($remover) {
        painelAtualizarCliente($cliente['id'], function ($c) use ($remover) {
            $c['config']['eventos'] = array_values(array_filter($c['config']['eventos'], function ($e) use ($remover) {
                return !isset($remover[$e['id']]);
            }));
            return $c;
        });
    }
}

// Volta fotos da lixeira para o evento delas (o evento apagado volta junto).
// Foto de um evento que não existe mais, ou com o mesmo nome já de volta na
// pasta, fica na lixeira. Devolve array(restauradas, com falha).
function painelRestaurarDaLixeira($cliente, $chaves) {
    $restauradas = 0;
    $falhas = 0;
    $reexibir = array();
    foreach (painelItensLixeiraPorChave($cliente, $chaves) as $item) {
        $evento = $item['evento'];
        $dados = $evento === '' ? null : painelEvento($cliente, $evento, true);
        $pasta = painelPastaFotos($cliente, $evento);
        $destino = $pasta . DIRECTORY_SEPARATOR . $item['nome'];
        if (($evento !== '' && !$dados) || is_file($destino) || !painelMoverArquivo($item['caminho'], $destino)) {
            $falhas++;
            continue;
        }
        $restauradas++;
        if (!is_file($pasta . DIRECTORY_SEPARATOR . 'index.html')) {
            @file_put_contents($pasta . DIRECTORY_SEPARATOR . 'index.html', '');
        }
        if (is_file($item['miniatura'])) {
            painelMoverArquivo($item['miniatura'], painelPastaMiniaturas($cliente, $evento) . DIRECTORY_SEPARATOR . $item['nome'] . '.jpg');
        }
        if ($dados && !empty($dados['apagado_em'])) {
            $reexibir[$evento] = true;
        }
    }
    if ($reexibir) {
        painelAtualizarCliente($cliente['id'], function ($c) use ($reexibir) {
            foreach ($c['config']['eventos'] as $i => $e) {
                if (isset($reexibir[$e['id']])) {
                    unset($c['config']['eventos'][$i]['apagado_em']);
                }
            }
            return $c;
        });
    }
    painelLimparPastasLixeira($cliente);
    return array($restauradas, $falhas);
}

// Apaga de vez itens da lixeira. Devolve array(apagadas, com falha).
function painelExcluirDaLixeira($cliente, $chaves) {
    $apagadas = 0;
    $falhas = 0;
    foreach (painelItensLixeiraPorChave($cliente, $chaves) as $item) {
        if (@unlink($item['caminho'])) {
            $apagadas++;
            @unlink($item['miniatura']);
        } else {
            $falhas++;
        }
    }
    painelLimparPastasLixeira($cliente);
    return array($apagadas, $falhas);
}

// Apaga de vez o que está na lixeira há mais de PAINEL_LIXEIRA_DIAS dias
function painelLimparLixeira($cliente) {
    $vencidas = array();
    foreach (painelItensLixeira($cliente) as $item) {
        if ($item['expira'] <= time()) {
            $vencidas[] = $item['chave'];
        }
    }
    if ($vencidas) {
        painelExcluirDaLixeira($cliente, $vencidas);
    }
    painelRemoverEventosApagados($cliente);
}

// Barra "X GB de 5 GB" (galeria e área da equipe)
function painelBarraArmazenamento($cliente, $textoCheio) {
    list($bytes, $quantidade, $lixeira) = painelUsoFotos($cliente);
    $limite = painelLimiteFotos();
    $porcento = $limite > 0 ? $bytes / $limite * 100 : 0;
    $estado = $porcento >= 100 ? 'cheio' : ($porcento >= 80 ? 'quase' : 'ok');
    $html = '<section class="cartao armazenamento armazenamento-' . $estado . '" aria-label="Armazenamento das fotos">'
        . '<div class="armazenamento-topo"><h2>Armazenamento das fotos</h2>'
        . '<span class="armazenamento-numeros"><strong>' . h(painelTamanhoTexto($bytes)) . '</strong> de ' . h(painelTamanhoTexto($limite)) . ' · ' . ($porcento > 0 && $porcento < 1 ? 'menos de 1%' : number_format(min($porcento, 999), 0, ',', '.') . '%') . '</span></div>'
        . '<div class="armazenamento-barra" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . (int) min(100, round($porcento)) . '" aria-label="Espaço usado">'
        . '<span style="width:' . number_format(min(100, $porcento), 2, '.', '') . '%"></span></div>'
        . '<p class="suave pequeno">' . painelQuantidadeFotos($quantidade) . ', somando todos os eventos'
        . ($lixeira > 0 ? ', mais ' . h(painelTamanhoTexto($lixeira)) . ' na lixeira (esvazie a lixeira para liberar esse espaço)' : '') . '.';
    if ($estado === 'cheio') {
        $html .= ' <strong>O limite foi atingido.</strong> ' . h($textoCheio);
    } elseif ($estado === 'quase') {
        $html .= ' <strong>Está quase cheio.</strong> ' . h($textoCheio);
    }
    return $html . '</p></section>';
}

// Seletor de evento da galeria e da área da equipe. $pagina = endereço da página.
function painelFiltroEventos($cliente, $selecionado, $pagina) {
    $atual = painelEventoAtual($cliente);
    $itens = array();
    foreach (painelEventos($cliente) as $evento) {
        $itens[] = array(
            'id' => $evento['id'],
            'nome' => $evento['nome'],
            'estado' => $atual && $atual['id'] === $evento['id'] ? 'padrão' : (!empty($evento['aberto']) ? 'aberto' : '')
        );
    }
    // Fotos antigas, de antes de o evento ser obrigatório. Nenhuma foto nova
    // chega aqui, então "Sem evento" nunca é o atual.
    if (painelContarFotos($cliente, '') > 0) {
        $itens[] = array('id' => '', 'nome' => 'Sem evento', 'estado' => '');
    }
    if (count($itens) < 2 && $selecionado === ($itens ? $itens[0]['id'] : '')) {
        return '';
    }
    $html = '<nav class="eventos-filtro" aria-label="Eventos">';
    foreach ($itens as $item) {
        $html .= '<a href="' . h($pagina . '?evento=' . rawurlencode($item['id'] === '' ? '-' : $item['id'])) . '" class="evento-chip"'
            . ($item['id'] === $selecionado ? ' aria-current="page"' : '') . '>'
            . h($item['nome']) . ' <span class="evento-chip-total">' . painelContarFotos($cliente, $item['id']) . '</span>'
            . ($item['estado'] !== '' ? ' <span class="evento-chip-atual">' . h($item['estado']) . '</span>' : '') . '</a>';
    }
    return $html . '</nav>';
}

// Evento pedido na página (?evento=id, "-" = sem evento); sem pedido, o atual.
// Sem evento aberto, o encerrado por último (ou o criado por último).
function painelEventoDaPagina($cliente, $valor) {
    if ($valor === '-') {
        return '';
    }
    if ($valor !== null && painelEvento($cliente, (string) $valor)) {
        return (string) $valor;
    }
    $atual = painelEventoAtual($cliente);
    if ($atual) {
        return $atual['id'];
    }
    $eventos = painelEventos($cliente);
    foreach ($eventos as $evento) {
        if (!empty($evento['aberto'])) {
            return $evento['id'];
        }
    }
    $encerrado = painelEventoEncerrado($cliente);
    if ($encerrado) {
        return $encerrado['evento']['id'];
    }
    return $eventos ? $eventos[0]['id'] : '';
}

function painelQuantidadeFotos($n) {
    return $n === 1 ? '1 foto' : number_format($n, 0, ',', '.') . ' fotos';
}

// "Hoje", "Ontem" ou a data, para os títulos da galeria
function painelDiaTexto($dia) {
    if ($dia === date('Y-m-d')) {
        return 'Hoje';
    }
    if ($dia === date('Y-m-d', strtotime('-1 day'))) {
        return 'Ontem';
    }
    $tempo = strtotime($dia);
    return $tempo ? date('d/m/Y', $tempo) : $dia;
}

// ---------- Sessão, login e formulários ----------

// Cada painel (buddyApp/painel/, buddyApp/<cliente>/painel/...) tem a sua
// sessão: nome de cookie próprio e cookie só no caminho dele. Assim, quem
// entrou no painel de um cliente não abre o painel de outro.
function painelIniciarSessao() {
    if (session_status() === PHP_SESSION_NONE) {
        $nomeSessao = 'painelfoto' . substr(sha1(PAINEL_RAIZ), 0, 10);
        session_name($nomeSessao);
        // Cookie de sessão adulterado (formato inválido): ignora e começa outra
        if (isset($_COOKIE[$nomeSessao]) && !preg_match('/^[a-zA-Z0-9,-]{1,128}$/', (string) $_COOKIE[$nomeSessao])) {
            unset($_COOKIE[$nomeSessao]);
        }
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $caminho = str_replace('\\', '/', dirname(isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '/x'));
        session_set_cookie_params(0, rtrim($caminho, '/') . '/', '', $https, true);
        session_start();
        // Sessão de outro painel (cookie antigo, de antes da separação): descarta
        if (isset($_SESSION['painel_raiz']) && $_SESSION['painel_raiz'] !== PAINEL_RAIZ) {
            $_SESSION = array();
        }
        $_SESSION['painel_raiz'] = PAINEL_RAIZ;
    }
}

// Login do painel, nome do cliente e pasta das fotos, em config/painel.local.php.
// Devolve null se o arquivo estiver faltando ou incompleto.
function painelConfigLocal() {
    static $local = false;
    if ($local !== false) {
        return $local;
    }

    $local = null;
    $caminho = PAINEL_RAIZ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'painel.local.php';
    $config = is_file($caminho) ? require $caminho : null;
    if (!is_array($config) || empty($config['usuario']) || empty($config['senha'])) {
        return $local;
    }
    // Relativa à pasta do uploadImage.php, com subpastas separadas por "/"
    // (ex.: "fotos/evento"). Vazia = as fotos ficam na própria pasta.
    $pasta = isset($config['pasta_fotos']) ? trim(trim((string) $config['pasta_fotos']), '/') : '';
    $primeira = strtolower(strtok($pasta . '/', '/'));
    if ($pasta !== '' && (!preg_match('#^[a-zA-Z0-9_-]{1,60}(/[a-zA-Z0-9_-]{1,60}){0,4}$#', $pasta) || in_array($primeira, painelPastasReservadas(), true))) {
        return $local;
    }
    $nome = isset($config['nome']) ? trim((string) $config['nome']) : '';

    // Login da área da equipe (equipe.php). Sem ele, ou com a mesma senha do
    // cliente, a área fica desligada: o cliente nunca pode entrar nela.
    $usuarioEquipe = isset($config['usuario_equipe']) ? strtolower(trim((string) $config['usuario_equipe'])) : '';
    $senhaEquipe = isset($config['senha_equipe']) ? (string) $config['senha_equipe'] : '';
    if ($usuarioEquipe === '' || $senhaEquipe === '' || hash_equals((string) $config['senha'], $senhaEquipe)) {
        $usuarioEquipe = '';
        $senhaEquipe = '';
    }

    $local = array(
        'usuario' => strtolower(trim($config['usuario'])),
        'senha' => (string) $config['senha'],
        'usuario_equipe' => $usuarioEquipe,
        'senha_equipe' => $senhaEquipe,
        'nome' => $nome !== '' ? $nome : 'App de Fotos',
        'pasta_fotos' => $pasta,
        // Espaço das fotos (barra da galeria), em GB; sem o campo, 5
        'limite_gb' => isset($config['limite_gb']) ? (float) $config['limite_gb'] : 0,
        // Login único dos clientes (pasta login/); vazio = só o login deste painel
        'login_unico' => isset($config['login_unico']) && preg_match('#^https?://[^\s"\'<>]+$#', (string) $config['login_unico']) ? (string) $config['login_unico'] : ''
    );
    return $local;
}

// Endereço do login único, ou '' se este painel usa só o próprio login
function painelLoginUnico() {
    $local = painelConfigLocal();
    return $local ? $local['login_unico'] : '';
}

function painelLogado() {
    return !empty($_SESSION['painel_logado']);
}

// Área da equipe (equipe.php): login à parte do cliente. Fecha sozinha depois
// de 30 minutos sem uso, caso alguém entre num computador que não é da equipe.
function painelEquipeAtiva() {
    $local = painelConfigLocal();
    return $local && $local['senha_equipe'] !== '';
}

function painelEquipeLogada() {
    return painelEquipeAtiva() && !empty($_SESSION['painel_equipe']) && time() - (int) $_SESSION['painel_equipe'] < 1800;
}

// Cliente da página, para quem está logado
function painelClienteDaPagina() {
    if (!painelLogado()) {
        painelIr('./');
    }

    $cliente = painelClienteUnico();
    if (!$cliente) {
        painelTopo('Erro');
        echo '<div class="aviso aviso-erro">Não foi possível abrir o painel. Confira o config/painel.local.php e se a pasta config/painel/ pode ser gravada.</div>';
        painelRodape();
        exit;
    }
    return $cliente;
}

function painelTokenCsrf() {
    if (empty($_SESSION['painel_csrf'])) {
        $_SESSION['painel_csrf'] = painelAleatorio(16);
    }
    return $_SESSION['painel_csrf'];
}

function painelCampoCsrf() {
    return '<input type="hidden" name="csrf" value="' . h(painelTokenCsrf()) . '">';
}

function painelConferirCsrf() {
    return isset($_POST['csrf']) && hash_equals(painelTokenCsrf(), (string) $_POST['csrf']);
}

function painelAviso($tipo, $mensagem) {
    $_SESSION['painel_avisos'][] = array('tipo' => $tipo, 'mensagem' => $mensagem);
}

function painelIr($url) {
    header('Location: ' . $url, true, 303);
    exit;
}

// ---------- Layout ----------

// Menu do topo (só logado): a página atual fica marcada
function painelMenu() {
    $atual = basename($_SERVER['SCRIPT_NAME']);
    // A lixeira é parte das fotos, e o formulário do QR Code é parte dos leads
    if ($atual === 'lixeira.php') {
        $atual = 'fotos.php';
    }
    if ($atual === 'leads_formulario.php') {
        $atual = 'leads.php';
    }
    $itens = array('cliente.php' => 'Configurar o app', 'fotos.php' => 'Fotos', 'leads.php' => 'Leads');
    $html = '<nav class="menu" aria-label="Menu">';
    foreach ($itens as $pagina => $rotulo) {
        $html .= '<a href="' . $pagina . '" class="menu-item"' . ($pagina === $atual ? ' aria-current="page"' : '') . '>' . h($rotulo) . '</a>';
    }
    return $html . '<a href="./?sair=1" class="menu-sair">Sair</a></nav>';
}

function painelTopo($titulo, $direita = '') {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Frame-Options: DENY');
    $versaoEstilo = (int) @filemtime(__DIR__ . '/estilo.css');
    $avisos = isset($_SESSION['painel_avisos']) ? $_SESSION['painel_avisos'] : array();
    unset($_SESSION['painel_avisos']);
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($titulo); ?> · Painel do App de Fotos</title>
<link rel="icon" type="image/png" sizes="32x32" href="marca/icone-32.png">
<link rel="icon" type="image/png" sizes="192x192" href="marca/icone-192.png">
<link rel="apple-touch-icon" sizes="180x180" href="marca/icone-180.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700;900&display=swap">
<link rel="stylesheet" href="estilo.css?v=<?php echo $versaoEstilo; ?>">
</head>
<body>
<header class="topo">
    <div class="topo-marca">
        <img class="topo-logo" src="marca/futuremedia.png" alt="Futuremedia" width="2501" height="347">
        <span class="topo-produto">Painel do App de Fotos</span>
    </div>
    <?php echo $direita; ?>
</header>
<main class="conteudo">
<?php foreach ($avisos as $aviso) { ?>
    <div class="aviso aviso-<?php echo h($aviso['tipo']); ?>"><?php echo h($aviso['mensagem']); ?></div>
<?php } ?>
<?php
}

function painelRodape() {
    ?>
</main>
<div class="enviando" id="enviando" hidden><div class="enviando-caixa"><div class="girando"></div>Enviando arquivo...</div></div>
<dialog class="confirmacao" id="confirmacao" aria-labelledby="confirmacao-titulo" aria-describedby="confirmacao-texto">
    <div class="confirmacao-icone" aria-hidden="true"></div>
    <h2 id="confirmacao-titulo"></h2>
    <p id="confirmacao-texto"></p>
    <div class="confirmacao-botoes">
        <button type="button" class="botao" data-resposta="nao">Cancelar</button>
        <button type="button" class="botao botao-primario" data-resposta="sim"></button>
        <button type="button" class="botao botao-perigo-cheio" data-resposta="alt" hidden></button>
    </div>
</dialog>
<script>
// Confirmação no lugar do confirm() do navegador. opcoes: titulo, texto,
// botao (texto do botão de confirmar), perigo (botão vermelho) e alternativa
// (texto de um segundo botão, vermelho, que chama alt: ex. "Excluir de vez").
window.painelConfirmar = (function () {
    var caixa = document.getElementById('confirmacao');
    var aoConfirmar = null;
    var aoAlternativa = null;
    if (!caixa.showModal) {
        return function (opcoes, sim) { if (window.confirm(opcoes.texto || opcoes.titulo)) sim(); };
    }
    caixa.addEventListener('click', function (e) {
        var botao = e.target.closest('[data-resposta]');
        if (botao) {
            caixa.close(botao.getAttribute('data-resposta'));
        } else if (e.target === caixa) {
            caixa.close('nao'); // clique no fundo escuro
        }
    });
    caixa.addEventListener('close', function () {
        var sim = aoConfirmar;
        var alt = aoAlternativa;
        aoConfirmar = null;
        aoAlternativa = null;
        if (sim && caixa.returnValue === 'sim') sim();
        if (alt && caixa.returnValue === 'alt') alt();
    });
    return function (opcoes, sim, alt) {
        document.getElementById('confirmacao-titulo').textContent = opcoes.titulo || 'Tem certeza?';
        document.getElementById('confirmacao-texto').textContent = opcoes.texto || '';
        var confirmar = caixa.querySelector('[data-resposta=sim]');
        confirmar.textContent = opcoes.botao || 'Confirmar';
        var alternativa = caixa.querySelector('[data-resposta=alt]');
        alternativa.textContent = opcoes.alternativa || '';
        alternativa.hidden = !opcoes.alternativa;
        caixa.classList.toggle('confirmacao-tres', !!opcoes.alternativa);
        caixa.classList.toggle('confirmacao-perigo', !!opcoes.perigo);
        aoConfirmar = sim;
        aoAlternativa = alt || null;
        caixa.returnValue = '';
        caixa.showModal();
        caixa.querySelector('[data-resposta=nao]').focus();
    };
})();
// Formulários de arquivo: envia ao escolher e mostra "Enviando..." (vídeos podem demorar)
document.querySelectorAll('input[type=file][data-auto]').forEach(function (campo) {
    campo.addEventListener('change', function () {
        if (campo.files.length) { campo.form.requestSubmit ? campo.form.requestSubmit() : campo.form.submit(); }
    });
});
document.querySelectorAll('form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        var confirmar = form.getAttribute('data-confirmar');
        if (confirmar && !form.confirmado) {
            // Segura o envio, pergunta, e reenvia com requestSubmit para os outros
            // ouvintes de submit (posições do editor) rodarem de novo
            e.preventDefault();
            // Com data-confirmar-alternativa: o botão principal envia modo=lixeira
            // e o alternativo envia modo=definitivo (campo "modo" do formulário)
            var modo = form.querySelector('input[name=modo]');
            var enviar = function (valor) {
                if (modo && valor) modo.value = valor;
                form.confirmado = true;
                form.requestSubmit ? form.requestSubmit() : form.submit();
                form.confirmado = false;
            };
            painelConfirmar({
                titulo: form.getAttribute('data-confirmar-titulo'),
                texto: confirmar,
                botao: form.getAttribute('data-confirmar-botao'),
                perigo: form.hasAttribute('data-confirmar-perigo'),
                alternativa: form.getAttribute('data-confirmar-alternativa')
            }, function () { enviar('lixeira'); }, function () { enviar('definitivo'); });
            return;
        }
        if (form.enctype === 'multipart/form-data') { document.getElementById('enviando').hidden = false; }
    });
});
// Botões "Copiar" (data-copiar="texto"), como o do código da licença
document.querySelectorAll('[data-copiar]').forEach(function (botao) {
    botao.addEventListener('click', function () {
        var texto = botao.getAttribute('data-copiar');
        var antes = botao.textContent;
        var feito = function () { botao.textContent = 'Copiado!'; setTimeout(function () { botao.textContent = antes; }, 1500); };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(texto).then(feito);
        } else {
            var campo = document.createElement('textarea');
            campo.value = texto; document.body.appendChild(campo); campo.select();
            try { document.execCommand('copy'); feito(); } catch (e) {}
            document.body.removeChild(campo);
        }
    });
});
// Campos só de números (PIN): tira na hora o que não é dígito, e o aviso ao
// salvar vem do data-so-numeros (em vez da mensagem genérica do navegador)
document.querySelectorAll('input[data-so-numeros]').forEach(function (campo) {
    campo.addEventListener('input', function () {
        var limpo = campo.value.replace(/[^0-9]/g, '');
        if (limpo !== campo.value) { campo.value = limpo; }
        campo.setCustomValidity('');
    });
    campo.addEventListener('invalid', function () {
        campo.setCustomValidity(campo.getAttribute('data-so-numeros'));
    });
});
</script>
</body>
</html>
<?php
}
