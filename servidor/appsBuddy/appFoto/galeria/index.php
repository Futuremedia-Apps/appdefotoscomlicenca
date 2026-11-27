<?php
// Galeria das fotos com impressão. Fica em appFoto/galeria/ (o galeria.php da
// raiz já redireciona para cá).
// Mostra as pastas de fotos: a de cada evento cadastrado (eventos/<id>/fotos)
// e as pastas soltas na raiz (ex.: TagCultSec, usada pelo app camerashot).
// O login grava a mesma sessão que archivePhoto.php e leadStatus.php conferem.
session_start();
date_default_timezone_set('America/Sao_Paulo');

$RAIZ = dirname(__DIR__);
require_once $RAIZ . DIRECTORY_SEPARATOR . 'eventRegistry.php';

// Intervalo da atualização automática, em segundos
$INTERVALO_ATUALIZACAO = 10;

// Pastas da raiz que não são pastas de fotos (mesma lista do index.php)
$PASTAS_IGNORAR = array('cgi-bin', '.well-known', 'galeria', 'leads', 'config', 'backup', 'eventos', 'fotos-arquivadas', 'cache', 'tools');

// Pastas em que o thumb.php gera miniatura; nas outras a galeria usa a foto original
$PASTAS_COM_MINIATURA = array('eventos', 'fotos', 'fotos-arquivadas');

function h($valor) {
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

// Senha em config/galeria.local.php ou na variável de ambiente FOTOAPP_GALERIA_SENHA
function galeriaSenhaConfigurada($raiz) {
    $ambiente = getenv('FOTOAPP_GALERIA_SENHA');
    if ($ambiente !== false && trim((string) $ambiente) !== '') {
        return trim((string) $ambiente);
    }

    $caminho = $raiz . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'galeria.local.php';
    if (!is_file($caminho)) {
        return '';
    }

    $config = require $caminho;
    if (is_string($config)) {
        return trim($config);
    }
    if (is_array($config) && isset($config['senha'])) {
        return trim((string) $config['senha']);
    }

    return '';
}

function galeriaEhImagem($arquivo) {
    $extensao = strtolower(pathinfo($arquivo, PATHINFO_EXTENSION));
    return in_array($extensao, array('jpg', 'jpeg', 'png', 'webp'), true);
}

function galeriaCodificarCaminho($caminho) {
    return implode('/', array_map('rawurlencode', explode('/', str_replace('\\', '/', $caminho))));
}

// Quantidade de fotos e horário da última, sem abrir os arquivos
function galeriaResumoPasta($absoluto) {
    $quantidade = 0;
    $ultima = 0;
    $itens = is_dir($absoluto) ? scandir($absoluto) : false;

    if (is_array($itens)) {
        foreach ($itens as $item) {
            if ($item[0] === '.' || !galeriaEhImagem($item)) {
                continue;
            }
            $caminho = $absoluto . DIRECTORY_SEPARATOR . $item;
            if (!is_file($caminho)) {
                continue;
            }
            $quantidade++;
            $ultima = max($ultima, fotoAppMaximusPhotoTimestamp($item, $caminho));
        }
    }

    return array($quantidade, $ultima);
}

// Pastas de eventos cadastrados + pastas soltas na raiz que têm fotos
function galeriaListarPastas($raiz, $pastasIgnorar) {
    $registro = fotoAppLoadEvents();
    $pastas = array();

    foreach (fotoAppEventsList($registro) as $evento) {
        $relativa = fotoAppEventRelativeFolder($evento);
        if ($relativa === '' || isset($pastas[$relativa])) {
            continue;
        }

        $absoluta = $raiz . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativa);
        list($quantidade, $ultima) = galeriaResumoPasta($absoluta);
        $pastas[$relativa] = array(
            'pasta' => $relativa,
            'titulo' => $evento['name'],
            'tipo' => 'Evento',
            'ativo' => $registro['active_event'] === $evento['id'],
            'habilitado' => !empty($evento['enabled']),
            'quantidade' => $quantidade,
            'ultima' => $ultima
        );
    }

    $itens = scandir($raiz);
    foreach (is_array($itens) ? $itens : array() as $item) {
        if ($item[0] === '.' || in_array($item, $pastasIgnorar, true) || isset($pastas[$item])) {
            continue;
        }
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $item) || !is_dir($raiz . DIRECTORY_SEPARATOR . $item)) {
            continue;
        }

        list($quantidade, $ultima) = galeriaResumoPasta($raiz . DIRECTORY_SEPARATOR . $item);
        if ($quantidade === 0) {
            continue;
        }

        $pastas[$item] = array(
            'pasta' => $item,
            'titulo' => $item,
            'tipo' => 'Pasta',
            'ativo' => false,
            'habilitado' => true,
            'quantidade' => $quantidade,
            'ultima' => $ultima
        );
    }

    // Pastas com fotos mais recentes primeiro
    uasort($pastas, function ($a, $b) {
        return $b['ultima'] - $a['ultima'];
    });

    return $pastas;
}

function galeriaListarFotos($raiz, $pasta, $pastasComMiniatura) {
    $absoluta = $raiz . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pasta);
    $itens = is_dir($absoluta) ? scandir($absoluta) : false;
    $registros = array();

    foreach (is_array($itens) ? $itens : array() as $item) {
        if ($item[0] === '.' || !galeriaEhImagem($item)) {
            continue;
        }
        $caminho = $absoluta . DIRECTORY_SEPARATOR . $item;
        if (!is_file($caminho)) {
            continue;
        }
        $registros[] = array(
            'nome' => $item,
            'tamanho' => (int) @filesize($caminho),
            'timestamp' => fotoAppMaximusPhotoTimestamp($item, $caminho),
            'versao' => (int) @filemtime($caminho)
        );
    }

    // Esconde cópias da mesma foto, como o painel faz
    $registros = fotoAppDeduplicatePhotoRecords($registros, $absoluta);

    usort($registros, function ($a, $b) {
        if ($a['timestamp'] === $b['timestamp']) {
            return strcmp($b['nome'], $a['nome']);
        }
        return $b['timestamp'] > $a['timestamp'] ? 1 : -1;
    });

    $temMiniatura = in_array(strtok($pasta, '/'), $pastasComMiniatura, true);
    $fotos = array();

    foreach ($registros as $registro) {
        $relativo = $pasta . '/' . $registro['nome'];
        $url = '../' . galeriaCodificarCaminho($relativo);

        $fotos[] = array(
            'nome' => $registro['nome'],
            'url' => $url,
            'miniatura' => $temMiniatura ? '../' . fotoAppThumbUrl($relativo, $registro['versao']) : $url,
            'abrir' => '../camerashot.html?imagem=' . rawurlencode($relativo) . '&origem=galeria',
            'data' => date('d/m/Y H:i', $registro['timestamp'])
        );
    }

    return $fotos;
}

// ---------- Login ----------

$senhaGaleria = galeriaSenhaConfigurada($RAIZ);
$autorizada = isset($_SESSION['fotoapp_galeria_autorizada']) && $_SESSION['fotoapp_galeria_autorizada'] === true;
$erroLogin = '';

if (isset($_GET['sair'])) {
    unset($_SESSION['fotoapp_galeria_autorizada']);
    header('Location: ./', true, 302);
    exit;
}

if (!$autorizada && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['senha'])) {
    if ($senhaGaleria !== '' && hash_equals($senhaGaleria, (string) $_POST['senha'])) {
        session_regenerate_id(true);
        $_SESSION['fotoapp_galeria_autorizada'] = true;
        header('Location: ' . $_SERVER['REQUEST_URI'], true, 303);
        exit;
    }
    $erroLogin = 'Senha incorreta.';
}

if (!$autorizada && isset($_GET['json'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(array('ok' => false, 'message' => 'Senha da galeria obrigatoria.'));
    exit;
}

// ---------- Dados ----------

$pastas = array();
$pastaSelecionada = '';
$fotos = array();

if ($autorizada) {
    $pastas = galeriaListarPastas($RAIZ, $PASTAS_IGNORAR);
    $pedida = isset($_GET['pasta']) ? fotoAppNormalizeRelativeFolder($_GET['pasta']) : '';
    if ($pedida !== '' && isset($pastas[$pedida])) {
        $pastaSelecionada = $pedida;
        $fotos = galeriaListarFotos($RAIZ, $pastaSelecionada, $PASTAS_COM_MINIATURA);
    }

    // Resposta JSON para a atualização automática
    if (isset($_GET['json'])) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode(array('ok' => true, 'fotos' => $fotos));
        exit;
    }
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Galeria de fotos</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: Arial, Helvetica, sans-serif;
        }

        body {
            background-color: #edf2ef;
            color: #16211d;
            line-height: 1.4;
        }

        header {
            position: sticky;
            top: 0;
            z-index: 10;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px 20px;
            padding: 16px 24px;
            background-color: white;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
        }

        header h1 {
            font-size: 22px;
        }

        .info {
            color: #5f6f68;
            font-size: 14px;
        }

        .acoes {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-left: auto;
        }

        .btn {
            display: inline-block;
            padding: 10px 18px;
            border: none;
            border-radius: 6px;
            font-weight: bold;
            font-size: 14px;
            cursor: pointer;
            color: white;
            background-color: #00856f;
            text-decoration: none;
        }

        .btn:disabled {
            opacity: 0.45;
            cursor: default;
        }

        .btn-secundario {
            background-color: #e0e6e2;
            color: #16211d;
        }

        .voltar {
            color: #00856f;
            font-weight: bold;
            text-decoration: none;
        }

        .seletor-pasta {
            padding: 8px 10px;
            border: 1px solid #d6ded8;
            border-radius: 6px;
            font-size: 14px;
            background-color: white;
            max-width: 320px;
        }

        main {
            padding: 24px;
        }

        .vazio {
            text-align: center;
            color: #5f6f68;
            padding: 80px 20px;
            font-size: 18px;
        }

        /* Login */
        .login {
            max-width: 360px;
            margin: 12vh auto 0;
            padding: 28px;
            border-radius: 8px;
            background-color: white;
            box-shadow: 0 18px 50px rgba(22, 33, 29, 0.13);
        }

        .login h1 {
            font-size: 24px;
            margin-bottom: 16px;
        }

        .login input {
            width: 100%;
            padding: 12px;
            margin-bottom: 12px;
            border: 1px solid #d6ded8;
            border-radius: 6px;
            font-size: 16px;
        }

        .login .btn {
            width: 100%;
        }

        .erro {
            margin-bottom: 12px;
            color: #b42318;
            font-weight: bold;
        }

        /* Lista de pastas */
        .pastas {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 20px;
        }

        .pasta {
            display: block;
            padding: 20px;
            border-radius: 8px;
            background-color: white;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.1);
            color: inherit;
            text-decoration: none;
            border: 3px solid transparent;
        }

        .pasta:hover {
            border-color: #00856f;
        }

        .pasta h2 {
            font-size: 18px;
            margin-bottom: 6px;
            word-break: break-word;
        }

        .pasta p {
            color: #5f6f68;
            font-size: 14px;
        }

        .pasta .caminho {
            font-family: monospace;
            font-size: 12px;
            word-break: break-all;
        }

        .etiqueta {
            display: inline-block;
            margin: 0 6px 8px 0;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: bold;
            background-color: #e0e6e2;
            color: #16211d;
        }

        .etiqueta.ativo {
            background-color: #00856f;
            color: white;
        }

        /* Grade de fotos */
        .grade {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 20px;
        }

        .card {
            position: relative;
            background-color: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.1);
            border: 3px solid transparent;
        }

        .card.selecionada {
            border-color: #00856f;
        }

        .card .miniatura {
            display: block;
            width: 100%;
            aspect-ratio: 3 / 2;
            object-fit: contain;
            background-color: #f6faf8;
            cursor: zoom-in;
        }

        .card .marcar {
            position: absolute;
            top: 10px;
            left: 10px;
            width: 26px;
            height: 26px;
            cursor: pointer;
            accent-color: #00856f;
        }

        .card .impressa {
            position: absolute;
            top: 10px;
            right: 10px;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
            color: white;
            background-color: #00856f;
        }

        .card .rodape {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding: 10px 12px;
            font-size: 13px;
            color: #5f6f68;
        }

        .card .rodape .btn {
            padding: 6px 14px;
            font-size: 13px;
        }

        /* Visualização ampliada */
        .ampliada {
            position: fixed;
            inset: 0;
            z-index: 100;
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 16px;
            padding: 24px;
            background-color: rgba(0, 0, 0, 0.9);
        }

        .ampliada.aberta {
            display: flex;
        }

        .ampliada img {
            max-width: 100%;
            max-height: calc(100vh - 120px);
            object-fit: contain;
        }

        .ampliada .controles {
            display: flex;
            gap: 10px;
        }

        .ampliada .navegar {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 52px;
            height: 52px;
            border: none;
            border-radius: 50%;
            font-size: 26px;
            cursor: pointer;
            color: white;
            background-color: rgba(255, 255, 255, 0.15);
        }

        .ampliada .anterior { left: 20px; }
        .ampliada .proxima { right: 20px; }

        .toast-message {
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            background-color: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 12px 20px;
            border-radius: 25px;
            font-size: 14px;
            z-index: 1000;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        /* Área usada só na impressão */
        #area-impressao {
            display: none;
        }

        @page {
            margin: 0;
        }

        @page paisagem {
            size: landscape;
        }

        @page retrato {
            size: portrait;
        }

        @media print {
            body {
                background: white;
            }

            body > *:not(#area-impressao) {
                display: none !important;
            }

            #area-impressao {
                display: block;
            }

            .folha {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 100vw;
                height: 100vh;
                break-after: page;
                overflow: hidden;
            }

            .folha:last-child {
                break-after: auto;
            }

            .folha.paisagem { page: paisagem; }
            .folha.retrato { page: retrato; }

            .folha img {
                width: 100%;
                height: 100%;
                object-fit: contain;
            }
        }
    </style>
</head>
<body>
<?php if (!$autorizada): ?>
    <form class="login" method="post">
        <h1>Galeria de fotos</h1>
        <?php if ($senhaGaleria === ''): ?>
            <p class="erro">Senha da galeria não configurada no servidor (config/galeria.local.php).</p>
        <?php else: ?>
            <?php if ($erroLogin !== ''): ?>
                <p class="erro"><?php echo h($erroLogin); ?></p>
            <?php endif; ?>
            <input type="password" name="senha" placeholder="Senha" autofocus required>
            <button class="btn" type="submit">Entrar</button>
        <?php endif; ?>
    </form>
<?php elseif ($pastaSelecionada === ''): ?>
    <header>
        <h1>Pastas de fotos</h1>
        <span class="info"><?php echo count($pastas) === 1 ? '1 pasta' : count($pastas) . ' pastas'; ?></span>
        <div class="acoes">
            <a class="btn btn-secundario" href="?sair=1">Sair</a>
        </div>
    </header>

    <main>
        <?php if (isset($_GET['pasta']) && $_GET['pasta'] !== ''): ?>
            <div class="vazio">A pasta "<?php echo h($_GET['pasta']); ?>" não foi encontrada. Escolha uma abaixo.</div>
        <?php endif; ?>

        <?php if (count($pastas) === 0): ?>
            <div class="vazio">Nenhuma pasta de fotos encontrada.</div>
        <?php else: ?>
            <div class="pastas">
                <?php foreach ($pastas as $pasta): ?>
                    <a class="pasta" href="?pasta=<?php echo h(rawurlencode($pasta['pasta'])); ?>">
                        <span class="etiqueta"><?php echo h($pasta['tipo']); ?></span>
                        <?php if ($pasta['ativo']): ?><span class="etiqueta ativo">Evento ativo</span><?php endif; ?>
                        <?php if (!$pasta['habilitado']): ?><span class="etiqueta">Desabilitado</span><?php endif; ?>
                        <h2><?php echo h($pasta['titulo']); ?></h2>
                        <p class="caminho"><?php echo h($pasta['pasta']); ?></p>
                        <p><?php echo $pasta['quantidade'] === 1 ? '1 foto' : $pasta['quantidade'] . ' fotos'; ?></p>
                        <?php if ($pasta['ultima'] > 0): ?>
                            <p>Última foto: <?php echo date('d/m/Y H:i', $pasta['ultima']); ?></p>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
<?php else: ?>
    <header>
        <a class="voltar" href="./">&#8592; Pastas</a>
        <select class="seletor-pasta" id="seletor-pasta" title="Trocar de pasta">
            <?php foreach ($pastas as $pasta): ?>
                <option value="<?php echo h($pasta['pasta']); ?>"<?php echo $pasta['pasta'] === $pastaSelecionada ? ' selected' : ''; ?>><?php echo h($pasta['titulo'] . ($pasta['tipo'] === 'Evento' ? ' (' . $pasta['pasta'] . ')' : '')); ?></option>
            <?php endforeach; ?>
        </select>
        <span class="info" id="info"></span>
        <div class="acoes">
            <button class="btn btn-secundario" id="btn-selecionar-todas">Selecionar todas</button>
            <button class="btn btn-secundario" id="btn-limpar" disabled>Limpar seleção</button>
            <button class="btn" id="btn-imprimir-selecionadas" disabled>Imprimir selecionadas</button>
            <a class="btn btn-secundario" href="?sair=1">Sair</a>
        </div>
    </header>

    <main>
        <div class="grade" id="grade"></div>
    </main>

    <div class="ampliada" id="ampliada">
        <button class="navegar anterior" id="btn-anterior" title="Anterior (←)">&#8249;</button>
        <img id="img-ampliada" alt="">
        <button class="navegar proxima" id="btn-proxima" title="Próxima (→)">&#8250;</button>
        <div class="controles">
            <button class="btn" id="btn-imprimir-ampliada">Imprimir</button>
            <a class="btn btn-secundario" id="link-abrir" href="#" target="_blank" rel="noopener">Abrir página da foto</a>
            <button class="btn btn-secundario" id="btn-fechar">Fechar (Esc)</button>
        </div>
    </div>

    <div id="area-impressao"></div>

    <script>
        const PASTA = <?php echo json_encode($pastaSelecionada, $jsonFlags); ?>;
        const INTERVALO = <?php echo (int) $INTERVALO_ATUALIZACAO; ?> * 1000;
        const CHAVE_IMPRESSAS = 'galeria_impressas_' + PASTA;

        let fotos = <?php echo json_encode($fotos, $jsonFlags); ?>;
        const selecionadas = new Set();
        let indiceAmpliada = -1;

        // Fotos já impressas ficam marcadas neste computador
        function lerImpressas() {
            try {
                return new Set(JSON.parse(localStorage.getItem(CHAVE_IMPRESSAS) || '[]'));
            } catch (e) {
                return new Set();
            }
        }

        function marcarImpressas(nomes) {
            const impressas = lerImpressas();
            nomes.forEach(nome => impressas.add(nome));
            try {
                localStorage.setItem(CHAVE_IMPRESSAS, JSON.stringify([...impressas]));
            } catch (e) {
                // Sem localStorage: só não guarda a marcação
            }
        }

        function showToast(message, duration = 3000) {
            const existingToast = document.querySelector('.toast-message');
            if (existingToast) {
                existingToast.remove();
            }

            const toast = document.createElement('div');
            toast.className = 'toast-message';
            toast.textContent = message;
            document.body.appendChild(toast);

            setTimeout(() => { toast.style.opacity = 1; }, 10);
            setTimeout(() => {
                toast.style.opacity = 0;
                setTimeout(() => toast.remove(), 300);
            }, duration);
        }

        function renderizar() {
            const grade = document.getElementById('grade');
            const impressas = lerImpressas();

            document.getElementById('info').textContent =
                fotos.length + (fotos.length === 1 ? ' foto' : ' fotos');

            if (fotos.length === 0) {
                grade.innerHTML = '<div class="vazio">Nenhuma foto ainda. As novas fotos aparecem aqui automaticamente.</div>';
                atualizarBotoes();
                return;
            }

            grade.innerHTML = '';
            fotos.forEach((foto, indice) => {
                const card = document.createElement('div');
                card.className = 'card' + (selecionadas.has(foto.nome) ? ' selecionada' : '');

                const img = document.createElement('img');
                img.className = 'miniatura';
                img.src = foto.miniatura;
                img.alt = foto.nome;
                img.loading = 'lazy';
                img.addEventListener('click', () => abrirAmpliada(indice));

                const marcar = document.createElement('input');
                marcar.type = 'checkbox';
                marcar.className = 'marcar';
                marcar.checked = selecionadas.has(foto.nome);
                marcar.title = 'Selecionar para imprimir';
                marcar.addEventListener('change', () => {
                    if (marcar.checked) {
                        selecionadas.add(foto.nome);
                    } else {
                        selecionadas.delete(foto.nome);
                    }
                    card.classList.toggle('selecionada', marcar.checked);
                    atualizarBotoes();
                });

                const rodape = document.createElement('div');
                rodape.className = 'rodape';

                const hora = document.createElement('span');
                hora.textContent = foto.data;

                const btnImprimir = document.createElement('button');
                btnImprimir.className = 'btn';
                btnImprimir.textContent = 'Imprimir';
                btnImprimir.addEventListener('click', () => imprimir([foto]));

                rodape.append(hora, btnImprimir);
                card.append(img, marcar, rodape);

                if (impressas.has(foto.nome)) {
                    const selo = document.createElement('span');
                    selo.className = 'impressa';
                    selo.textContent = 'Impressa';
                    card.appendChild(selo);
                }

                grade.appendChild(card);
            });

            atualizarBotoes();
        }

        function atualizarBotoes() {
            const total = selecionadas.size;
            const btnImprimir = document.getElementById('btn-imprimir-selecionadas');
            btnImprimir.disabled = total === 0;
            btnImprimir.textContent = total > 0 ? 'Imprimir selecionadas (' + total + ')' : 'Imprimir selecionadas';
            document.getElementById('btn-limpar').disabled = total === 0;
            document.getElementById('btn-selecionar-todas').disabled = fotos.length === 0;
        }

        // Monta uma folha por foto (foto original, não a miniatura) e abre a impressão
        function imprimir(lista) {
            if (lista.length === 0) {
                return;
            }

            const area = document.getElementById('area-impressao');
            area.innerHTML = '';
            showToast('Preparando impressão...');

            const carregamentos = lista.map(foto => new Promise(resolve => {
                const folha = document.createElement('div');
                folha.className = 'folha paisagem';

                const img = document.createElement('img');
                img.onload = () => {
                    folha.className = 'folha ' + (img.naturalWidth >= img.naturalHeight ? 'paisagem' : 'retrato');
                    resolve(true);
                };
                img.onerror = () => resolve(false);
                img.src = foto.url;

                folha.appendChild(img);
                area.appendChild(folha);
            }));

            Promise.all(carregamentos).then(resultados => {
                if (resultados.includes(false)) {
                    showToast('Alguma foto não carregou. Tente novamente.');
                    area.innerHTML = '';
                    return;
                }

                window.print();

                marcarImpressas(lista.map(foto => foto.nome));
                area.innerHTML = '';
                renderizar();
            });
        }

        function abrirAmpliada(indice) {
            indiceAmpliada = indice;
            document.getElementById('img-ampliada').src = fotos[indice].url;
            document.getElementById('link-abrir').href = fotos[indice].abrir;
            document.getElementById('ampliada').classList.add('aberta');
        }

        function fecharAmpliada() {
            indiceAmpliada = -1;
            document.getElementById('ampliada').classList.remove('aberta');
        }

        function navegar(direcao) {
            if (indiceAmpliada < 0 || fotos.length === 0) {
                return;
            }
            abrirAmpliada((indiceAmpliada + direcao + fotos.length) % fotos.length);
        }

        // Busca fotos novas sem recarregar a página
        async function atualizar() {
            try {
                const params = new URLSearchParams(window.location.search);
                params.set('json', '1');
                const resposta = await fetch('?' + params.toString(), { cache: 'no-store' });
                if (resposta.status === 401) {
                    window.location.reload();
                    return;
                }
                if (!resposta.ok) {
                    return;
                }
                const dados = await resposta.json();
                const novas = dados.fotos || [];

                const mudou = novas.length !== fotos.length ||
                    novas.some((foto, i) => foto.nome !== fotos[i].nome);
                if (!mudou) {
                    return;
                }

                // Mantém a foto ampliada aberta mesmo se a posição mudar
                const nomeAmpliada = indiceAmpliada >= 0 ? fotos[indiceAmpliada].nome : null;
                const qtdNovas = novas.length - fotos.length;

                fotos = novas;
                const nomes = new Set(fotos.map(foto => foto.nome));
                [...selecionadas].forEach(nome => { if (!nomes.has(nome)) selecionadas.delete(nome); });

                if (nomeAmpliada !== null) {
                    const novoIndice = fotos.findIndex(foto => foto.nome === nomeAmpliada);
                    if (novoIndice >= 0) {
                        indiceAmpliada = novoIndice;
                    } else {
                        fecharAmpliada();
                    }
                }

                renderizar();
                if (qtdNovas > 0) {
                    showToast(qtdNovas === 1 ? '1 foto nova' : qtdNovas + ' fotos novas');
                }
            } catch (e) {
                console.log('Falha ao atualizar a galeria:', e);
            }
        }

        document.getElementById('seletor-pasta').addEventListener('change', evento => {
            window.location.href = '?pasta=' + encodeURIComponent(evento.target.value);
        });

        document.getElementById('btn-selecionar-todas').addEventListener('click', () => {
            fotos.forEach(foto => selecionadas.add(foto.nome));
            renderizar();
        });

        document.getElementById('btn-limpar').addEventListener('click', () => {
            selecionadas.clear();
            renderizar();
        });

        document.getElementById('btn-imprimir-selecionadas').addEventListener('click', () => {
            imprimir(fotos.filter(foto => selecionadas.has(foto.nome)));
        });

        document.getElementById('btn-imprimir-ampliada').addEventListener('click', () => {
            if (indiceAmpliada >= 0) {
                imprimir([fotos[indiceAmpliada]]);
            }
        });

        document.getElementById('btn-fechar').addEventListener('click', fecharAmpliada);
        document.getElementById('btn-anterior').addEventListener('click', () => navegar(-1));
        document.getElementById('btn-proxima').addEventListener('click', () => navegar(1));

        document.getElementById('ampliada').addEventListener('click', evento => {
            if (evento.target.id === 'ampliada') {
                fecharAmpliada();
            }
        });

        document.addEventListener('keydown', evento => {
            if (indiceAmpliada < 0) {
                return;
            }
            if (evento.key === 'Escape') fecharAmpliada();
            if (evento.key === 'ArrowLeft') navegar(-1);
            if (evento.key === 'ArrowRight') navegar(1);
        });

        renderizar();
        setInterval(atualizar, INTERVALO);
    </script>
<?php endif; ?>
</body>
</html>
