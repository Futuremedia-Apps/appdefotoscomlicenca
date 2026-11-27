<?php
// Atualização automática do código do painel de cada cliente (24/11/2026).
//
// O painel principal (buddyApp/painel/) é o modelo. Cada cliente tem uma cópia
// do código em buddyApp/<cliente>/ (a pasta painel/, o uploadImage.php e
// alguns index.html). Antes, a cópia só mudava pelo botão "Atualizar os
// arquivos do painel" da central. Agora basta subir os arquivos do principal
// pelo FTP: no fim de cada página do painel do cliente (e de cada consulta do
// robô, que pergunta pelo evento a cada 30 s), ele confere, no máximo uma vez
// por minuto, se o código do principal mudou, e se copia sozinho.
//
// Proteções:
// - só copia depois de 'auto_atualizar_espera' segundos (padrão 120) sem
//   nenhum arquivo do principal mudar: o FTP pode estar no meio do envio;
// - cada arquivo vai para um temporário e é renomeado: quem estiver usando
//   o painel nunca pega um arquivo pela metade;
// - uma atualização por vez (trava), e só os arquivos que mudaram;
// - nunca mexe na configuração, nos dados, nas mídias nem nas fotos do cliente
//   (só copia o que existe no modelo, menos painel/midia/<id>/);
// - o painel principal nunca se atualiza (ele é o modelo);
// - 'auto_atualizar' => false no config/painel.local.php do principal desliga.
//
// Situação de cada cliente: config/painel/codigo.php (marca do código copiado,
// quando e o último erro). A central mostra e avisa quem ficou para trás.
//
// Este arquivo não depende do lib.php: a central (licencas/lib.php) também usa.

define('ATUALIZAR_INTERVALO', 60);
define('ATUALIZAR_ESPERA_PADRAO', 120);

// Arquivos de código fora da pasta painel/ (caminhos a partir da raiz)
function atualizarArquivosAvulsos() {
    return array(
        'uploadImage.php',
        'index.html',
        'config/index.html',
        'config/painel/.htaccess',
        'config/painel/index.html',
        'config/painel/clientes/index.html'
    );
}

// Nunca copiado: mídias do cliente do principal (painel/midia/<id>/) e
// temporários (do painel e dos programas de FTP)
function atualizarIgnorar($relativo) {
    return preg_match('#^painel/midia/[^/]+/#', $relativo) === 1
        || preg_match('#(\.tmp-[a-z0-9]+|\.filepart|\.part|\.tmp|~)$#i', $relativo) === 1;
}

// Arquivos de código do modelo: caminho relativo ("painel/lib.php") => completo
function atualizarArquivosDoModelo($raiz) {
    $arquivos = array();
    $painel = $raiz . DIRECTORY_SEPARATOR . 'painel';
    if (is_dir($painel)) {
        $inicio = strlen($painel) + 1;
        $filtro = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($painel, FilesystemIterator::SKIP_DOTS),
            function ($item) use ($inicio) {
                $relativo = 'painel/' . str_replace('\\', '/', substr($item->getPathname(), $inicio));
                return !atualizarIgnorar($relativo . ($item->isDir() ? '/' : ''));
            }
        );
        foreach (new RecursiveIteratorIterator($filtro) as $item) {
            if ($item->isFile()) {
                $arquivos['painel/' . str_replace('\\', '/', substr($item->getPathname(), $inicio))] = $item->getPathname();
            }
        }
    }
    foreach (atualizarArquivosAvulsos() as $relativo) {
        $caminho = $raiz . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativo);
        if (is_file($caminho)) {
            $arquivos[$relativo] = $caminho;
        }
    }
    ksort($arquivos);
    return $arquivos;
}

// Marca do código do modelo: muda quando algum arquivo muda (nome, tamanho ou
// data). "ultima_mudanca" é o arquivo mexido por último (data de gravação ou
// de troca, que o FTP não consegue voltar para trás).
function atualizarMarca($raiz) {
    $partes = array();
    $ultima = 0;
    foreach (atualizarArquivosDoModelo($raiz) as $relativo => $caminho) {
        $mtime = (int) @filemtime($caminho);
        $partes[] = $relativo . '|' . sprintf('%u', @filesize($caminho)) . '|' . $mtime;
        $ultima = max($ultima, $mtime, (int) @filectime($caminho));
    }
    return array('marca' => md5(implode("\n", $partes)), 'ultima_mudanca' => $ultima, 'arquivos' => count($partes));
}

// Raiz do painel principal, se $raiz for o painel de um cliente criado pela
// central (buddyApp/<cliente>/, com a central em buddyApp/licencas/). Senão
// null: o principal (e qualquer outra instalação) nunca se atualiza.
function atualizarModeloDe($raiz) {
    $modelo = dirname($raiz);
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', basename($raiz))
        || !is_file($modelo . DIRECTORY_SEPARATOR . 'painel' . DIRECTORY_SEPARATOR . 'lib.php')
        || !is_file($modelo . DIRECTORY_SEPARATOR . 'licencas' . DIRECTORY_SEPARATOR . 'lib.php')
        || !is_file($raiz . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'painel.local.php')) {
        return null;
    }
    return realpath($modelo) === realpath($raiz) ? null : $modelo;
}

// Configuração da atualização, do config/painel.local.php do principal
function atualizarConfig($modelo) {
    $arquivo = $modelo . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'painel.local.php';
    $config = is_file($arquivo) ? @include $arquivo : null;
    $config = is_array($config) ? $config : array();
    return array(
        'ligado' => !array_key_exists('auto_atualizar', $config) || !empty($config['auto_atualizar']),
        'espera' => isset($config['auto_atualizar_espera']) ? max(0, (int) $config['auto_atualizar_espera']) : ATUALIZAR_ESPERA_PADRAO
    );
}

function atualizarArquivoEstado($raiz) {
    return $raiz . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'painel' . DIRECTORY_SEPARATOR . 'codigo.php';
}

// Situação do código de um painel de cliente: marca, atualizado_em, erro, erro_em
function atualizarEstado($raiz) {
    $conteudo = @file_get_contents(atualizarArquivoEstado($raiz));
    $cabecalho = "<?php exit; ?>\n";
    if (is_string($conteudo) && strpos($conteudo, $cabecalho) === 0) {
        $conteudo = substr($conteudo, strlen($cabecalho));
    }
    $dados = is_string($conteudo) ? json_decode($conteudo, true) : null;
    return array_merge(array('marca' => '', 'atualizado_em' => '', 'erro' => '', 'erro_em' => ''), is_array($dados) ? $dados : array());
}

function atualizarGravarEstado($raiz, $dados) {
    $arquivo = atualizarArquivoEstado($raiz);
    $pasta = dirname($arquivo);
    if (!is_dir($pasta) && !@mkdir($pasta, 0755, true)) {
        return false;
    }
    $temporario = $arquivo . '.tmp-' . substr(md5(uniqid('', true)), 0, 8);
    $json = json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (@file_put_contents($temporario, "<?php exit; ?>\n" . $json) === false) {
        return false;
    }
    if (@rename($temporario, $arquivo) || (@unlink($arquivo) && @rename($temporario, $arquivo))) {
        return true;
    }
    @unlink($temporario);
    return false;
}

// Copia do modelo para o painel do cliente só os arquivos que mudaram (tamanho
// ou data). Cada um vai para um temporário na mesma pasta e é renomeado.
// Devolve null se deu certo ou o texto do erro.
function atualizarCopiar($modelo, $destino) {
    foreach (atualizarArquivosDoModelo($modelo) as $relativo => $origem) {
        $alvo = $destino . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativo);
        $tamanho = @filesize($origem);
        $mtime = @filemtime($origem);
        if (is_file($alvo) && @filesize($alvo) === $tamanho && @filemtime($alvo) === $mtime) {
            continue;
        }
        $pasta = dirname($alvo);
        if (!is_dir($pasta) && !@mkdir($pasta, 0755, true)) {
            return 'Não foi possível criar a pasta ' . dirname($relativo) . '/.';
        }
        $temporario = $alvo . '.tmp-' . substr(md5(uniqid('', true)), 0, 8);
        if (!@copy($origem, $temporario)) {
            @unlink($temporario);
            return 'Não foi possível copiar ' . $relativo . '.';
        }
        if ($mtime) {
            @touch($temporario, $mtime);
        }
        // No Windows, rename não troca um arquivo que já existe
        if (!@rename($temporario, $alvo) && !(@unlink($alvo) && @rename($temporario, $alvo))) {
            @unlink($temporario);
            return 'Não foi possível gravar ' . $relativo . '.';
        }
    }
    return null;
}

// Copia agora (botões da central) e anota a situação. Devolve null ou o erro.
function atualizarAgora($modelo, $destino) {
    $marca = atualizarMarca($modelo);
    $erro = atualizarCopiar($modelo, $destino);
    $estado = atualizarEstado($destino);
    if ($erro === null) {
        $estado = array('marca' => $marca['marca'], 'atualizado_em' => date('c'), 'erro' => '', 'erro_em' => '');
    } else {
        $estado['erro'] = $erro;
        $estado['erro_em'] = date('c');
    }
    atualizarGravarEstado($destino, $estado);
    return $erro;
}

// Chamada no fim de cada página do painel (lib.php). Não escreve nada na
// resposta: as consultas do robô são JSON.
function atualizarSeNecessario($raiz) {
    $modelo = atualizarModeloDe($raiz);
    if ($modelo === null) {
        return;
    }
    $dados = $raiz . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'painel';
    $marcador = $dados . DIRECTORY_SEPARATOR . 'codigo-conferido';
    clearstatcache();
    if (is_file($marcador) && time() - (int) @filemtime($marcador) < ATUALIZAR_INTERVALO) {
        return;
    }
    @touch($marcador);
    $config = atualizarConfig($modelo);
    if (!$config['ligado']) {
        return;
    }
    $trava = @fopen($dados . DIRECTORY_SEPARATOR . 'codigo.lock', 'c');
    if (!$trava) {
        return;
    }
    if (flock($trava, LOCK_EX | LOCK_NB)) {
        $marca = atualizarMarca($modelo);
        $estado = atualizarEstado($raiz);
        if ($marca['marca'] !== $estado['marca'] && time() - $marca['ultima_mudanca'] >= $config['espera']) {
            // A resposta já foi: quem pediu a página não espera a cópia
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            @ignore_user_abort(true);
            @set_time_limit(300);
            atualizarAgora($modelo, $raiz);
        }
        flock($trava, LOCK_UN);
    }
    fclose($trava);
}
