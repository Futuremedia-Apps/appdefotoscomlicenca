<?php
// Central de Licenças: registro de ações (quem fez o quê e quando).
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib.php';
licExigirLogin();

$id = isset($_GET['id']) ? (string) $_GET['id'] : '';
// Licença excluída: $licenca fica null, mas o registro dela continua valendo
$licenca = $id !== '' ? licBuscar($id) : null;
$quem = isset($_GET['quem']) && in_array($_GET['quem'], array('equipe', 'robo', 'cliente', 'sistema'), true) ? $_GET['quem'] : '';

$itens = licLerRegistro($quem === '' ? 300 : 2000, $id);
if ($quem !== '') {
    $itens = array_slice(array_values(array_filter($itens, function ($item) use ($quem) {
        return isset($item['quem']) && $item['quem'] === $quem;
    })), 0, 300);
}
$todas = licTodas();

function registroLink($id, $quem) {
    $partes = array();
    if ($id !== '') {
        $partes[] = 'id=' . rawurlencode($id);
    }
    if ($quem !== '') {
        $partes[] = 'quem=' . rawurlencode($quem);
    }
    return 'registro.php' . ($partes ? '?' . implode('&', $partes) : '');
}

licTopo('Registro', true);
?>
<div class="central-titulo">
    <div>
        <h1>Registro</h1>
        <p class="suave"><?php if ($id !== '') { ?>
            Só a licença <strong><?php echo h($licenca ? $licenca['cliente'] : licCodigoTexto($id) . ' (excluída)'); ?></strong> ·
            <a href="<?php echo h(registroLink('', $quem)); ?>">ver todas</a>
        <?php } else { ?>
            Quem fez o quê na central, nos robôs e no login dos clientes. Mostra as 300 ações mais novas.
        <?php } ?></p>
    </div>
    <nav class="registro-filtro" aria-label="Filtrar por quem fez">
        <?php foreach (array('' => 'Todos', 'equipe' => 'Equipe', 'robo' => 'Robôs', 'cliente' => 'Clientes', 'sistema' => 'Sistema') as $valor => $texto) { ?>
            <a href="<?php echo h(registroLink($id, $valor)); ?>" class="menu-item"<?php echo $quem === $valor ? ' aria-current="page"' : ''; ?>><?php echo h($texto); ?></a>
        <?php } ?>
    </nav>
</div>

<section class="cartao">
<?php if (!$itens) { ?>
    <p class="suave">Nada registrado ainda. O registro começa a contar a partir desta versão da central.</p>
<?php } else { ?>
    <div class="tabela-rolagem">
    <table class="tabela tabela-registro">
        <thead><tr><th>Quando</th><th>Quem</th><th>O quê</th><th>Licença</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($itens as $item) {
            $licId = isset($item['lic']) ? (string) $item['lic'] : '';
            ?>
            <tr>
                <td class="registro-quando"><?php echo h(licDataBr($item['t'], true)); ?></td>
                <td><span class="selo selo-quem-<?php echo h($item['quem']); ?>"><?php echo h(licQuemTexto($item['quem'])); ?></span></td>
                <td><strong><?php echo h($item['acao']); ?></strong>
                    <?php if (!empty($item['det'])) { ?><span class="tabela-sub"><?php echo h($item['det']); ?></span><?php } ?></td>
                <td><?php if ($licId !== '') { ?>
                    <?php if (isset($todas[$licId])) { ?>
                        <a href="licenca.php?id=<?php echo h(rawurlencode($licId)); ?>" class="tabela-link"><?php echo h($todas[$licId]['cliente']); ?></a>
                    <?php } else { ?>
                        <?php echo h(!empty($item['cliente']) ? $item['cliente'] : licCodigoTexto($licId)); ?> <span class="suave">(excluída)</span>
                    <?php } ?>
                    <?php if ($id === '') { ?><a href="<?php echo h(registroLink($licId, $quem)); ?>" class="tabela-sub">só esta licença</a><?php } ?>
                <?php } else { ?><span class="suave">-</span><?php } ?></td>
                <td class="registro-ip"><?php echo h(isset($item['ip']) ? $item['ip'] : ''); ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
    </div>
<?php } ?>
</section>
<?php
licRodape();
