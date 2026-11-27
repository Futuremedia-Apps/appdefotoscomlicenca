<?php
// Configuração do painel do app de fotos (painel/). Troque à vontade.
//   usuario / senha  login do painel
//   nome             nome do cliente, aparece no painel e na tela de admin do robô
//   pasta_fotos      pasta onde o uploadImage.php guarda as fotos dos robôs,
//                    a partir da pasta dele. Vazia ('') = na própria pasta
//                    (clienteFotos/buddyApp/). Subpastas com "/", ex.: 'fotos'.
//                    Mudar aqui vale para os robôs na próxima sincronização.
return array(
    'usuario' => 'futuremedia',
    'senha' => 'puHWZRJ5jV9R4y',
    // Área da equipe (painel/equipe.php): excluir fotos. O cliente não pode saber este
    // login. Vazio, ou igual à senha do cliente, a área fica desligada (404).
    'usuario_equipe' => 'equipe',
    'senha_equipe' => '84FZVz3vC8pLtwE3gMKJKnSy',
    'nome' => 'App de Fotos',
    'pasta_fotos' => '',
    // Espaço para as fotos, em GB (barra da galeria). Passando disso, o painel
    // avisa, mas o robô continua enviando.
    'limite_gb' => 5,
    // Licenças: com true, o sync.php e o uploadImage.php só aceitam robôs com
    // licença válida da Central de Licenças para ESTE painel. Ligue depois de
    // atualizar e ativar todos os robôs deste cliente (APK com licença).
    'exigir_licenca' => false,
    // Login único: o cliente entra por este endereço (usuário e senha da
    // Central de Licenças) e cai direto neste painel. O login acima continua
    // em painel/?local=1, para a equipe. Em branco ('') = só o login acima.
    'login_unico' => 'https://suafoto.futuremedia.com.br/clienteFotos/buddyApp/login/'
);
