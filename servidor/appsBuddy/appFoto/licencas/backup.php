<?php
// Central de Licenças: cópias de segurança das licenças (baixar e restaurar).
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib.php';
licExigirLogin();

// Baixa um arquivo de licenças como JSON (sem o LIC_CABECALHO do começo)
function backupEnviar($caminho, $nomeArquivo) {
    $conteudo = @file_get_contents($caminho);
    if ($conteudo === false) {
        licAviso('erro', 'Não foi possível ler a cópia.');
        licIr('backup.php');
    }
    if (strpos($conteudo, LIC_CABECALHO) === 0) {
        $conteudo = substr($conteudo, strlen(LIC_CABECALHO));
    }
    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');
    header('Content-Length: ' . strlen($conteudo));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $conteudo;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!licConferirCsrf()) {
        licAviso('erro', 'A página expirou. Tente de novo.');
        licIr('backup.php');
    }
    $acao = isset($_POST['acao']) ? (string) $_POST['acao'] : '';
    $arquivo = isset($_POST['arquivo']) ? (string) $_POST['arquivo'] : '';

    if ($acao === 'baixar_agora') {
        if (!is_file(LIC_ARQUIVO) || licLerLicencas(LIC_ARQUIVO) === null) {
            licAviso('erro', 'O arquivo das licenças não pode ser lido agora. Baixe uma das cópias abaixo.');
            licIr('backup.php');
        }
        licRegistrar('equipe', 'Licenças baixadas (estado atual)');
        backupEnviar(LIC_ARQUIVO, 'licencas-' . date('Y-m-d_H-i') . '.json');
    }

    if ($acao === 'baixar') {
        $copia = licBackupPorNome($arquivo);
        if (!$copia) {
            licAviso('erro', 'Cópia não encontrada.');
            licIr('backup.php');
        }
        licRegistrar('equipe', 'Cópia de segurança baixada', null, $copia['arquivo']);
        backupEnviar($copia['caminho'], preg_replace('/\.php$/', '.json', $copia['arquivo']));
    }

    if ($acao === 'restaurar') {
        $copia = licBackupPorNome($arquivo);
        $erro = licRestaurarBackup($arquivo);
        if ($erro !== null) {
            licAviso('erro', $erro);
        } else {
            licRegistrar('equipe', 'Cópia de segurança restaurada', null, $copia['arquivo'] . ' (de ' . licDataBr($copia['quando'], true) . ')');
            licAviso('ok', 'As licenças voltaram para a cópia de ' . licDataBr($copia['quando'], true) . '. O estado de antes ficou guardado na cópia "Antes de restaurar uma cópia".');
        }
        licIr('backup.php');
    }
    licIr('backup.php');
}

$copias = licBackups();
$config = licConfig();
$impressao = licImpressaoDigitalChave();
licTodas(); // se o arquivo estava estragado, a leitura já o recupera da cópia
$atual = is_file(LIC_ARQUIVO) ? licLerLicencas(LIC_ARQUIVO) : null;

licTopo('Backup', true);
?>
<div class="central-titulo">
    <div>
        <h1>Backup</h1>
        <p class="suave">Cópias de segurança das licenças, dos robôs e dos acessos dos clientes.</p>
    </div>
</div>

<section class="cartao">
    <div class="acesso-topo">
        <h2>Licenças agora</h2>
        <form method="post">
            <?php echo licCampoCsrf(); ?>
            <input type="hidden" name="acao" value="baixar_agora">
            <button type="submit" class="botao botao-primario botao-pequeno"<?php echo $atual === null ? ' disabled' : ''; ?>>Baixar as licenças agora</button>
        </form>
    </div>
    <p class="suave">
        <?php if ($atual !== null) { ?>
            <?php echo count($atual) === 1 ? '1 licença' : count($atual) . ' licenças'; ?>, gravadas em <?php echo h(licDataBr((int) @filemtime(LIC_ARQUIVO), true)); ?>.
        <?php } ?>
        A central faz sozinha uma cópia por dia (guarda as 30 mais novas) e outra antes de excluir uma licença. Elas ficam em licencas/dados/backup/, uma pasta que o navegador não abre.
        De vez em quando, baixe uma cópia e guarde fora do servidor.
    </p>
    <p class="suave pequeno">O arquivo baixado tem os dados dos clientes e as senhas deles cifradas (não dá para ler a senha). Guarde num lugar seguro.</p>
</section>

<section class="cartao">
    <h2>Cópias guardadas</h2>
    <?php if (!$copias) { ?>
        <p class="suave">Nenhuma cópia ainda. A primeira é feita na próxima vez que a central gravar (um robô conferindo a licença já conta).</p>
    <?php } else { ?>
    <div class="tabela-rolagem">
    <table class="tabela">
        <thead><tr><th>Feita em</th><th>Tipo</th><th>Licenças</th><th>Tamanho</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($copias as $copia) {
            $conteudoCopia = licLerLicencas($copia['caminho']);
            ?>
            <tr>
                <td><?php echo h(licDataBr($copia['quando'], true)); ?><span class="tabela-sub"><?php echo h(licHaQuantoTempo($copia['quando'])); ?></span></td>
                <td><?php echo h(licTipoBackupTexto($copia['tipo'])); ?></td>
                <td><?php echo $conteudoCopia === null ? '<span class="selo selo-expirada">Estragada</span>' : count($conteudoCopia); ?></td>
                <td><?php echo h(licTamanhoTexto($copia['tamanho'])); ?></td>
                <td class="tabela-acao">
                    <div class="backup-botoes">
                        <form method="post">
                            <?php echo licCampoCsrf(); ?>
                            <input type="hidden" name="acao" value="baixar">
                            <input type="hidden" name="arquivo" value="<?php echo h($copia['arquivo']); ?>">
                            <button type="submit" class="botao botao-pequeno">Baixar</button>
                        </form>
                        <?php if ($copia['tipo'] !== 'estragado' && $conteudoCopia !== null) { ?>
                        <form method="post" data-confirmar="Todas as licenças voltam a ficar como estavam em <?php echo h(licDataBr($copia['quando'], true)); ?>. O que mudou depois disso se perde (licenças novas, robôs ativados depois, senhas trocadas): esses robôs precisam digitar o código de novo. O estado de agora fica guardado numa cópia, para desfazer se precisar." data-confirmar-titulo="Restaurar esta cópia?" data-confirmar-botao="Restaurar" data-confirmar-perigo>
                            <?php echo licCampoCsrf(); ?>
                            <input type="hidden" name="acao" value="restaurar">
                            <input type="hidden" name="arquivo" value="<?php echo h($copia['arquivo']); ?>">
                            <button type="submit" class="botao botao-pequeno botao-perigo">Restaurar</button>
                        </form>
                        <?php } ?>
                    </div>
                </td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
    </div>
    <?php } ?>
</section>

<section class="cartao">
    <h2>Chave de assinatura</h2>
    <p class="suave">A chave fica em <strong>licencas/config/licencas.local.php</strong>. Guarde uma cópia desse arquivo fora do servidor (pen drive ou gerenciador de senhas), pelo FTP.</p>
    <ul class="lista-explica">
        <li><strong>Se ela se perder</strong>, a central não consegue mais emitir licenças e todos os robôs travam quando a licença guardada neles vencer (em até <?php echo (int) ($config ? $config['dias_offline'] : 7); ?> dias). Os robôs só voltam com um app novo.</li>
        <li><strong>Se ela vazar</strong>, alguém pode criar licenças falsas. Por isso a central não mostra nem deixa baixar esse arquivo.</li>
    </ul>
    <?php if ($impressao !== '') { ?>
        <p class="suave">Impressão digital da chave: <code class="codigo"><?php echo h($impressao); ?></code>. Anote junto com a cópia: se um dia precisar repor o arquivo, a central mostra este mesmo número quando a chave for a certa.</p>
    <?php } ?>
</section>
<?php
licRodape();
