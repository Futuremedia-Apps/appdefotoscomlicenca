<?php
$PASTA_PADRAO = ''; // Vazio = pega automaticamente a pasta de fotos mais recente.
$PASTAS_IGNORAR = array('cgi-bin', '.well-known', 'galeria', 'leads', 'config', 'backup', 'eventos', 'fotos-arquivadas', 'cache', 'tools');
$EXTENSOES_PERMITIDAS = array('jpg', 'jpeg', 'png', 'webp');
$TIMEZONE = 'America/Sao_Paulo';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'appSettings.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'eventRegistry.php';

date_default_timezone_set($TIMEZONE);

$erroEntradaMaximus = '';
fotoAppRouteMaximusInbox($erroEntradaMaximus);

$scriptName = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', (string) $_SERVER['SCRIPT_NAME']) : '/app/index.php';
$APP_BASE_PATH = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
if ($APP_BASE_PATH === '') {
    $APP_BASE_PATH = '/';
}

function h($valor) {
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function normalizarPasta($pasta) {
    $pasta = str_replace('\\', '/', (string) $pasta);
    $partes = explode('/', $pasta);
    $limpas = array();

    foreach ($partes as $parte) {
        if ($parte === '' || $parte === '.' || $parte === '..') {
            continue;
        }

        $parte = preg_replace('/[^a-zA-Z0-9_-]/', '', $parte);
        if ($parte !== '') {
            $limpas[] = $parte;
        }
    }

    return implode('/', $limpas);
}

function codificarCaminhoUrl($caminho) {
    $caminho = str_replace('\\', '/', $caminho);
    $partes = explode('/', $caminho);
    $codificadas = array_map('rawurlencode', $partes);

    return implode('/', $codificadas);
}

function extrairDataDoNome($nomeArquivo) {
    $nomeSemExtensao = pathinfo($nomeArquivo, PATHINFO_FILENAME);

    if (!preg_match('/^photo_(\d{4})-(\d{2})-(\d{2})_(\d{2})-(\d{2})-(\d{2})/i', $nomeSemExtensao, $m)) {
        return null;
    }

    $data = DateTime::createFromFormat(
        'Y-m-d H-i-s',
        $m[1] . '-' . $m[2] . '-' . $m[3] . ' ' . $m[4] . '-' . $m[5] . '-' . $m[6]
    );

    return $data ? $data->getTimestamp() : null;
}

function listarImagensDaPasta($diretorio, $pastaRelativa, $extensoesPermitidas) {
    $itens = scandir($diretorio);
    $imagens = array();

    foreach ($itens as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $caminhoCompleto = $diretorio . DIRECTORY_SEPARATOR . $item;
        if (!is_file($caminhoCompleto)) {
            continue;
        }

        $extensao = strtolower(pathinfo($item, PATHINFO_EXTENSION));
        if (!in_array($extensao, $extensoesPermitidas, true)) {
            continue;
        }

        $timestampNome = extrairDataDoNome($item);
        $timestamp = $timestampNome !== null ? $timestampNome : filemtime($caminhoCompleto);

        $imagens[] = array(
            'nome' => $item,
            'relativo' => trim($pastaRelativa . '/' . $item, '/'),
            'timestamp' => $timestamp
        );
    }

    $imagens = fotoAppDeduplicatePhotoRecords($imagens, $diretorio);

    usort($imagens, function ($a, $b) {
        if ($a['timestamp'] === $b['timestamp']) {
            return strcmp($b['nome'], $a['nome']);
        }

        return $b['timestamp'] > $a['timestamp'] ? 1 : -1;
    });

    return $imagens;
}

function encontrarPastaMaisRecente($raiz, $pastasIgnorar, $extensoesPermitidas) {
    $diretorios = scandir($raiz);
    $candidatas = array();

    foreach ($diretorios as $diretorio) {
        if ($diretorio === '.' || $diretorio === '..' || $diretorio[0] === '.') {
            continue;
        }

        if (in_array($diretorio, $pastasIgnorar, true)) {
            continue;
        }

        $caminho = $raiz . DIRECTORY_SEPARATOR . $diretorio;
        if (!is_dir($caminho)) {
            continue;
        }

        $imagens = listarImagensDaPasta($caminho, $diretorio, $extensoesPermitidas);
        if (count($imagens) === 0) {
            continue;
        }

        $candidatas[] = array(
            'pasta' => $diretorio,
            'timestamp' => $imagens[0]['timestamp']
        );
    }

    usort($candidatas, function ($a, $b) {
        return $b['timestamp'] > $a['timestamp'] ? 1 : -1;
    });

    return count($candidatas) > 0 ? $candidatas[0]['pasta'] : '';
}

function prepararFotoParaCliente($foto, $evento = null) {
    $src = codificarCaminhoUrl($foto['relativo']);
    $camerashotPath = __DIR__ . DIRECTORY_SEPARATOR . 'camerashot.html';
    $versaoCamerashot = is_file($camerashotPath) ? (string) filemtime($camerashotPath) : (string) time();
    $href = 'camerashot.html?imagem=' . rawurlencode($src) . '&v=' . rawurlencode($versaoCamerashot);

    if (is_array($evento) && !empty($evento['public_key'])) {
        $href .= '&evento=' . rawurlencode($evento['public_key']);
    }

    return array(
        'id' => $foto['relativo'],
        'src' => $src,
        'href' => $href,
        'data' => date('d/m/Y H:i:s', $foto['timestamp']),
        'timestamp' => $foto['timestamp']
    );
}

function buscarFotoMaisRecente($raiz, $pasta, $pastasIgnorar, $extensoesPermitidas, &$erro) {
    if ($pasta === '') {
        $pasta = encontrarPastaMaisRecente($raiz, $pastasIgnorar, $extensoesPermitidas);
    }

    if ($pasta === '') {
        $erro = 'Nenhuma pasta com fotos foi encontrada.';
        return null;
    }

    $diretorioAlvo = realpath($raiz . DIRECTORY_SEPARATOR . $pasta);
    $raizReal = realpath($raiz);

    if (!$diretorioAlvo || strpos($diretorioAlvo, $raizReal) !== 0 || !is_dir($diretorioAlvo)) {
        $erro = 'Pasta de fotos nao encontrada.';
        return null;
    }

    $imagens = listarImagensDaPasta($diretorioAlvo, $pasta, $extensoesPermitidas);
    if (count($imagens) === 0) {
        $erro = 'Nenhuma foto foi encontrada nessa pasta.';
        return null;
    }

    return prepararFotoParaCliente($imagens[0]);
}

function buscarFotoMaisRecenteEvento($raiz, $evento, $extensoesPermitidas, &$erro) {
    if (!$evento) {
        $erro = 'Evento nao encontrado.';
        return null;
    }

    $pasta = fotoAppEventRelativeFolder($evento);
    if ($pasta === '') {
        $erro = 'Pasta do evento nao configurada.';
        return null;
    }

    $diretorioAlvo = realpath($raiz . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pasta));
    $raizReal = realpath($raiz);

    if (!$diretorioAlvo || strpos($diretorioAlvo, $raizReal) !== 0 || !is_dir($diretorioAlvo)) {
        $erro = 'Pasta de fotos do evento nao encontrada.';
        return null;
    }

    $imagens = listarImagensDaPasta($diretorioAlvo, $pasta, $extensoesPermitidas);
    if (count($imagens) === 0) {
        $erro = 'Nenhuma foto foi encontrada nesse evento.';
        return null;
    }

    return prepararFotoParaCliente($imagens[0], $evento);
}

$raiz = __DIR__;
$erro = '';
$eventoSelecionado = null;
$eventoInformado = isset($_GET['evento']) || isset($_GET['event']) || isset($_GET['evento_id']) || isset($_GET['event_id']);
$pasta = isset($_GET['pasta']) ? normalizarPasta($_GET['pasta']) : normalizarPasta($PASTA_PADRAO);

if ($eventoInformado) {
    $eventoSelecionado = fotoAppResolveEventFromInput($_GET, false, true);
    $foto = buscarFotoMaisRecenteEvento($raiz, $eventoSelecionado, $EXTENSOES_PERMITIDAS, $erro);
} elseif ($pasta !== '') {
    $foto = buscarFotoMaisRecente($raiz, $pasta, $PASTAS_IGNORAR, $EXTENSOES_PERMITIDAS, $erro);
} else {
    $eventoSelecionado = fotoAppResolveEventFromInput($_GET, true, true);
    $foto = buscarFotoMaisRecenteEvento($raiz, $eventoSelecionado, $EXTENSOES_PERMITIDAS, $erro);
}

// Frase configurada no painel; '' quando o evento desligou o texto.
$settingsEvento = fotoAppLoadSettings($eventoSelecionado);
$textoAcesso = fotoAppAccessText($settingsEvento);
$textoStatus = $foto ? $textoAcesso : $erro;

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode(array(
        'ok' => $foto !== null,
        'erro' => $erro,
        'foto' => $foto,
        'evento' => $eventoSelecionado ? $eventoSelecionado['public_key'] : '',
        'eventoNome' => $eventoSelecionado ? $eventoSelecionado['name'] : '',
        'atualizadoEm' => date('H:i:s')
    ));
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <base href="<?php echo h(rtrim($APP_BASE_PATH, '/') . '/'); ?>">
    <title>Sua foto</title>
    <style>
        :root {
            --bg: #edf2ef;
            --text: #16211d;
            --muted: #5f6f68;
            --line: #d6ded8;
            --accent: #00856f;
            --accent-dark: #006b59;
            --surface: #ffffff;
            --danger: #b42318;
            --shadow: 0 18px 50px rgba(22, 33, 29, 0.13);
        }

        * {
            box-sizing: border-box;
        }

        html {
            height: 100%;
        }

        body {
            margin: 0;
            height: 100vh;
            height: 100svh;
            background:
                linear-gradient(180deg, rgba(255, 255, 255, 0.72), rgba(237, 242, 239, 0) 46%),
                var(--bg);
            color: var(--text);
            font-family: Arial, Helvetica, sans-serif;
            overflow: hidden;
        }

        .page {
            width: min(880px, calc(100% - 32px));
            height: 100vh;
            height: 100svh;
            margin: 0 auto;
            padding: clamp(14px, 2.8vw, 28px) 0;
            display: grid;
            grid-template-rows: auto auto minmax(0, 1fr);
            align-content: stretch;
            gap: clamp(8px, 1.8vw, 14px);
            overflow: hidden;
        }

        .page.sem-status {
            grid-template-rows: auto minmax(0, 1fr);
        }

        .topbar {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: end;
            gap: 12px;
            border-bottom: 1px solid var(--line);
            padding-bottom: 12px;
        }

        .copy {
            max-width: 620px;
        }

        h1 {
            margin: 0;
            font-size: clamp(27px, 4.8vw, 46px);
            line-height: 1;
            letter-spacing: 0;
        }

        .intro {
            max-width: 560px;
            margin: 8px 0 0;
            color: var(--muted);
            font-size: clamp(13px, 2vw, 17px);
            line-height: 1.3;
        }

        .refresh-btn {
            min-height: 40px;
            padding: 0 14px;
            border: none;
            border-radius: 6px;
            background: var(--accent);
            color: #fff;
            font: inherit;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 10px 24px rgba(0, 133, 111, 0.24);
            transition: background 160ms ease, transform 160ms ease, box-shadow 160ms ease;
        }

        .refresh-btn:hover {
            background: var(--accent-dark);
            transform: translateY(-1px);
            box-shadow: 0 14px 28px rgba(0, 133, 111, 0.26);
        }

        .refresh-btn:disabled {
            cursor: progress;
            opacity: 0.7;
            transform: none;
        }

        .status {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            padding: 11px 12px;
            border: 1px solid rgba(0, 133, 111, 0.18);
            border-left: 4px solid var(--accent);
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.72);
            color: var(--muted);
            font-size: 13px;
            line-height: 1.3;
            box-shadow: 0 10px 28px rgba(22, 33, 29, 0.07);
        }

        #photo-status {
            color: var(--text);
            font-size: clamp(14px, 2.2vw, 17px);
            font-weight: 800;
        }

        .photo {
            min-height: 0;
            height: 100%;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            border-radius: 8px;
            background: var(--surface);
            border: 1px solid rgba(214, 222, 216, 0.9);
            color: inherit;
            text-decoration: none;
            box-shadow: var(--shadow);
            transition: transform 180ms ease, box-shadow 180ms ease;
        }

        .photo:hover {
            transform: translateY(-2px);
            box-shadow: 0 24px 60px rgba(22, 33, 29, 0.16);
        }

        .photo:focus-visible {
            outline: 3px solid rgba(0, 133, 111, 0.34);
            outline-offset: 4px;
        }

        .thumb {
            width: 100%;
            min-height: 0;
            height: 100%;
            flex: 1 1 auto;
            display: block;
            object-fit: contain;
            background: #f6faf8;
        }

        .meta {
            flex: 0 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 10px 12px 11px;
        }

        .date {
            color: var(--muted);
            font-size: 13px;
            font-weight: 700;
        }

        .open {
            color: var(--accent);
            font-size: 14px;
            font-weight: 700;
            white-space: nowrap;
        }

        .empty {
            padding: 18px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--surface);
            color: var(--danger);
            text-align: center;
            font-weight: 700;
        }

        [hidden] {
            display: none !important;
        }

        @media (max-width: 560px) {
            .page {
                width: min(100% - 16px, 880px);
                padding: 8px 0 calc(8px + env(safe-area-inset-bottom, 0px));
                gap: 7px;
            }

            .topbar {
                grid-template-columns: minmax(0, 1fr) auto;
                align-items: end;
                gap: 8px;
                padding-bottom: 8px;
            }

            h1 {
                font-size: clamp(22px, 6.2vw, 28px);
            }

            .intro {
                margin-top: 5px;
                font-size: 12.5px;
                line-height: 1.25;
            }

            .status,
            .meta {
                align-items: flex-start;
                flex-direction: column;
            }

            .status {
                gap: 3px;
                padding: 8px 9px;
                line-height: 1.22;
            }

            #photo-status {
                font-size: 13.5px;
            }

            #sync-status {
                font-size: 11.5px;
            }

            .refresh-btn {
                min-height: 36px;
                padding: 0 10px;
                font-size: 13px;
            }

            .meta {
                gap: 4px;
                padding: 8px 10px;
            }

            .date,
            .open {
                font-size: 12.5px;
            }
        }

        @media (max-width: 380px), (max-height: 680px) {
            .intro {
                font-size: 11.8px;
            }

            .status {
                padding: 7px 8px;
            }

            #photo-status {
                font-size: 12.8px;
            }

            #sync-status {
                display: none;
            }

            .meta {
                padding: 7px 9px;
            }
        }
    </style>
</head>
<body>
    <main class="page<?php echo $textoStatus === '' ? ' sem-status' : ''; ?>" id="page">
        <header class="topbar">
            <div class="copy">
                <h1>Painel de acesso a foto</h1>
                <?php if ($eventoSelecionado): ?>
                    <p class="intro">Evento: <?php echo h($eventoSelecionado['name']); ?>.</p>
                <?php endif; ?>
                <p class="intro">A foto exibida é a última foto tirada no Maximus. Se ainda não aparecer a sua, clique em Atualizar para conferir a versão mais recente.</p>
            </div>
            <button class="refresh-btn" type="button" id="refresh-btn">Atualizar</button>
        </header>

        <section class="status" id="status-bar" <?php echo $textoStatus === '' ? 'hidden' : ''; ?>>
            <span id="photo-status"><?php echo h($textoStatus); ?></span>
            <span id="sync-status">Atualiza automaticamente.</span>
        </section>

        <section class="empty" id="empty-state" <?php echo $foto ? 'hidden' : ''; ?>><?php echo h($erro); ?></section>

        <?php if ($foto): ?>
            <a class="photo" id="photo-link" data-photo-id="<?php echo h($foto['id']); ?>" href="<?php echo h($foto['href']); ?>">
                <img class="thumb" id="photo-thumb" src="<?php echo h($foto['src']); ?>" alt="Foto de <?php echo h($foto['data']); ?>">
                <span class="meta">
                    <span class="date" id="photo-date"><?php echo h($foto['data']); ?></span>
                    <span class="open">Abrir foto</span>
                </span>
            </a>
        <?php else: ?>
            <a class="photo" id="photo-link" data-photo-id="" href="#" hidden>
                <img class="thumb" id="photo-thumb" src="" alt="">
                <span class="meta">
                    <span class="date" id="photo-date"></span>
                    <span class="open">Abrir foto</span>
                </span>
            </a>
        <?php endif; ?>
    </main>

    <script>
        (function () {
            const page = document.getElementById('page');
            const statusBar = document.getElementById('status-bar');
            const refreshBtn = document.getElementById('refresh-btn');
            const photoLink = document.getElementById('photo-link');
            const photoThumb = document.getElementById('photo-thumb');
            const photoDate = document.getElementById('photo-date');
            const photoStatus = document.getElementById('photo-status');
            const syncStatus = document.getElementById('sync-status');
            const emptyState = document.getElementById('empty-state');
            const AUTO_REFRESH_MS = 4000;
            const ACCESS_TEXT = <?php echo json_encode($textoAcesso, JSON_UNESCAPED_UNICODE); ?>;
            let shareFile = null;
            let shareFilePromise = null;
            let leadStatusPromise = null;

            // Texto vazio esconde a faixa inteira (evento com o texto desligado);
            // mensagens de erro sempre aparecem.
            function setStatus(text) {
                photoStatus.textContent = text;
                statusBar.hidden = text === '';
                page.classList.toggle('sem-status', text === '');
            }

            function ajaxUrl() {
                const url = new URL(window.location.href);
                url.searchParams.set('ajax', '1');
                url.searchParams.set('_', String(Date.now()));

                return url.toString();
            }

            function imageMimeType(src, blob) {
                if (blob && blob.type) {
                    return blob.type;
                }

                const ext = src.split('?')[0].split('.').pop().toLowerCase();
                if (ext === 'png') {
                    return 'image/png';
                }
                if (ext === 'webp') {
                    return 'image/webp';
                }

                return 'image/jpeg';
            }

            function imageFileName(src) {
                const path = src.split('?')[0].split('#')[0];
                return decodeURIComponent(path.split('/').pop() || 'sua-foto.jpg');
            }

            function prepareShareFile(src) {
                if (!src) {
                    shareFile = null;
                    shareFilePromise = null;
                    return;
                }

                const imageUrl = new URL(src, window.location.href).toString();
                shareFile = null;
                shareFilePromise = fetch(imageUrl, {
                    cache: 'no-store',
                    credentials: 'same-origin'
                })
                    .then(function(response) {
                        if (!response.ok) {
                            throw new Error('Nao foi possivel preparar a imagem.');
                        }

                        return response.blob();
                    })
                    .then(function(blob) {
                        shareFile = new File([blob], imageFileName(imageUrl), {
                            type: imageMimeType(imageUrl, blob),
                            lastModified: Date.now()
                        });

                        return shareFile;
                    })
                    .catch(function(error) {
                        console.error('Nao foi possivel preparar a imagem para compartilhar:', error);
                        shareFile = null;
                        return null;
                    });
            }

            function checkLeadStatus(src) {
                if (!src) {
                    photoLink.dataset.leadExists = '0';
                    photoLink.dataset.shareFromApp = '0';
                    leadStatusPromise = null;
                    return;
                }

                const url = new URL('leadStatus.php', window.location.href);
                url.searchParams.set('imagem', src);
                url.searchParams.set('_', String(Date.now()));

                photoLink.dataset.leadExists = '0';
                photoLink.dataset.shareFromApp = '0';
                leadStatusPromise = fetch(url.toString(), {
                    headers: { 'Accept': 'application/json' },
                    cache: 'no-store',
                    credentials: 'same-origin'
                })
                    .then(function(response) {
                        if (!response.ok) {
                            throw new Error('Nao foi possivel verificar lead.');
                        }

                        return response.json();
                    })
                    .then(function(data) {
                        const exists = !!(data && data.ok && data.leadSaved);
                        const shareFromApp = !!(data && data.ok && data.shareFromAppWhenLeadExists);
                        photoLink.dataset.leadExists = exists ? '1' : '0';
                        photoLink.dataset.shareFromApp = shareFromApp ? '1' : '0';
                        if (photoLink.dataset.photoId) {
                            setStatus(ACCESS_TEXT);
                        }
                        return exists;
                    })
                    .catch(function(error) {
                        console.error('Nao foi possivel verificar lead:', error);
                        photoLink.dataset.leadExists = '0';
                        photoLink.dataset.shareFromApp = '0';
                        return false;
                    });
            }

            function renderPhoto(foto) {
                const changed = photoLink.dataset.photoId !== foto.id;

                photoLink.dataset.photoId = foto.id;
                photoLink.href = foto.href;
                photoDate.textContent = foto.data;
                photoThumb.alt = 'Foto de ' + foto.data;

                if (changed) {
                    photoThumb.src = foto.src;
                }

                // A foto so e baixada de novo para compartilhar quando muda
                // (antes era baixada inteira a cada 4 segundos em cada celular).
                if (changed || !shareFilePromise) {
                    prepareShareFile(foto.src);
                }
                checkLeadStatus(foto.src);

                photoLink.hidden = false;
                emptyState.hidden = true;
                setStatus(ACCESS_TEXT);
            }

            async function sharePhotoFromCard(event) {
                const href = photoLink.getAttribute('href');

                if (!href || href === '#') {
                    return;
                }

                const targetUrl = new URL(href, window.location.href).toString();

                if (!navigator.share) {
                    return;
                }

                if (photoLink.dataset.leadExists !== '1' || photoLink.dataset.shareFromApp !== '1') {
                    return;
                }

                event.preventDefault();

                try {
                    const file = shareFile || (shareFilePromise ? await shareFilePromise : null);

                    if (file && navigator.canShare && navigator.canShare({ files: [file] })) {
                        await navigator.share({
                            files: [file]
                        });
                    } else {
                        await navigator.share({
                            title: 'Sua foto',
                            text: 'Acesse sua foto:',
                            url: targetUrl
                        });
                    }
                } catch (error) {
                    if (!error || error.name !== 'AbortError') {
                        console.error('Nao foi possivel compartilhar:', error);
                    }
                }

                window.location.href = targetUrl;
            }

            async function refreshLatest(manual) {
                if (manual) {
                    refreshBtn.disabled = true;
                    refreshBtn.textContent = 'Atualizando';
                }

                try {
                    const response = await fetch(ajaxUrl(), {
                        headers: { 'Accept': 'application/json' },
                        cache: 'no-store'
                    });
                    const data = await response.json();

                    if (!data.ok || !data.foto) {
                        throw new Error(data.erro || 'Nenhuma foto encontrada.');
                    }

                    renderPhoto(data.foto);
                    syncStatus.textContent = 'Atualizado ' + data.atualizadoEm + '.';
                } catch (error) {
                    setStatus(error.message || 'Nao foi possivel atualizar.');
                    syncStatus.textContent = 'Nao foi possivel atualizar.';
                    if (!photoLink.dataset.photoId) {
                        photoLink.hidden = true;
                        emptyState.hidden = false;
                        emptyState.textContent = error.message;
                    }
                } finally {
                    if (manual) {
                        refreshBtn.disabled = false;
                        refreshBtn.textContent = 'Atualizar';
                    }
                }
            }

            refreshBtn.addEventListener('click', function () {
                refreshLatest(true);
            });

            photoLink.addEventListener('click', sharePhotoFromCard);

            if (photoLink.dataset.photoId && photoThumb.getAttribute('src')) {
                prepareShareFile(photoThumb.getAttribute('src'));
                checkLeadStatus(photoThumb.getAttribute('src'));
            }

            window.setInterval(function () {
                if (!document.hidden) {
                    refreshLatest(false);
                }
            }, AUTO_REFRESH_MS);

            document.addEventListener('visibilitychange', function () {
                if (!document.hidden) {
                    refreshLatest(false);
                }
            });
        }());
    </script>
</body>
</html>
