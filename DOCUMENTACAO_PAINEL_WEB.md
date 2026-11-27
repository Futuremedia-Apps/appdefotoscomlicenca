# Painel Web do Cliente + Tela de Admin do App

Com isto, o cliente troca molduras, fundos e o vídeo de carregamento sozinho, por um painel web. Não é mais preciso alterar o código e gerar um APK novo a cada evento.

## Como funciona

O painel atende **um cliente só**: depois do login, a pessoa já cai na página de molduras, imagens e vídeo.

1. O login, o nome do cliente e a pasta das fotos ficam em `config/painel.local.php`. No primeiro acesso, o painel cria o cadastro sozinho.
2. O cliente entra no painel, envia as imagens e arruma os botões de cada tela.
3. No robô: na tela inicial, **5 toques seguidos no canto superior direito**, depois o **PIN** (padrão `1234`, definido no painel) e **Sincronizar**. Qual é o painel do robô vem da licença dele (veja "Licenças", abaixo).
4. O robô **só** busca o painel quando alguém toca em Sincronizar: não há sincronização automática. Ele só baixa os arquivos que mudaram.

Sem vínculo, sem internet ou com um item não enviado, o app usa o que veio no APK (`assets/`). Ou seja, o app nunca fica sem moldura ou fundo.

## Licenças

Cada robô precisa de uma licença. A licença diz qual é o painel do cliente e até quando o robô pode funcionar. Assim, um único APK serve para todos os clientes.

1. **Na Central de Licenças** (`https://suafoto.futuremedia.com.br/clienteFotos/buddyApp/licencas/`, só a equipe Futuremedia): **Nova licença** com o cliente, a **pasta do painel** dele (só o nome, ex.: `clienteteste01` → `.../buddyApp/clienteteste01/`; em branco = o painel principal em `buddyApp/`), o vencimento (em branco = vitalícia) e quantos robôs. A central gera o código `FM-XXXX-XXXX`.
2. **No robô:** sem licença, o app abre na tela **"Ativar licença"**. Digite o código no teclado da tela e toque em **Ativar**. O robô fica vinculado ao painel daquele cliente e já sincroniza.
3. **Depois:**
   - O robô renova a licença sozinho, ao abrir o app e ao sincronizar.
   - Sem internet, ele funciona por até 7 dias.
   - Cada robô tem o **seu código** (licença de 2 robôs = 2 códigos). A central e o painel do cliente mostram em qual robô está cada código pelo **serial de fábrica** do robô.
   - Na central dá para **suspender** (o robô trava na próxima renovação), mudar o vencimento e o número de robôs, **desvincular** um robô (o código dele fica livre) ou excluir a licença.
4. **Vencimento:** depois da data, o app ainda funciona por 7 dias. Nesse período, a tela de admin mostra o aviso em vermelho. Depois disso, o app trava.
5. **Trocar o robô de cliente:** na tela de admin, use **"Trocar licença"** e digite o código da nova licença.
6. **Desvincular o robô:** na tela de admin, no rodapé do cartão "Situação", há o link discreto **"Desvincular este robô"**.
   - Ele pede confirmação e **trava o app na hora** na tela de ativação, com o aviso de que precisa do código.
   - A central é avisada para liberar a vaga. O pedido vai com a licença assinada do robô, então só o próprio robô consegue.
   - Sem internet, trava do mesmo jeito.
7. **Bloqueio pela central:** suspender, desvincular o robô, excluir ou vencer a licença trava o app em até ~1 minuto. Com internet, o app confere a licença a cada minuto.
   - O painel do cliente recusa na hora o sync e as fotos desse robô, porque lê a situação direto da central.
   - Sem internet, o robô não tem como saber do bloqueio. Ele funciona até o fim do prazo offline (7 dias) e trava assim que conectar.

**O código no painel do cliente:** o painel do cliente tem o cartão **"Licença do robô"** (também no mapa lateral). Ele mostra:
- o código de ativação, com o botão **Copiar**;
- a situação (ativa, vencida ou suspensa);
- a validade;
- quantos robôs estão em uso;
- o passo a passo para ativar.

Assim, o próprio cliente ativa os robôs. Os dados vêm direto da central, que fica no mesmo servidor (`licencasDestePainel()` em `painel/licenca.php`), e cada cliente vê só a licença do painel dele.

**Tela de ativação:** o código fica em cima, e o teclado completo fica embaixo, na largura toda. O teclado tem números de 1 a 0 e letras QWERTY, e o "Ativar" fica ao lado do M. Os códigos nunca usam 0, O, 1, I nem L; se alguém digitar uma delas, aparece "Código não encontrado". O teclado do PIN da tela de admin é outro e não mudou.

A tela de admin mostra o código e a validade da licença.

O painel do cliente só passa a **exigir** licença com `'exigir_licenca' => true` em `config/painel.local.php`. Aí o `sync.php` e o `uploadImage.php` recusam robôs sem licença válida para aquele painel. Ligue depois de atualizar e ativar todos os robôs do cliente.

## Um painel por cliente

Ao criar a licença com uma pasta (ex.: `clienteteste01`), a central já cria o painel do cliente em `buddyApp/clienteteste01/`. É uma cópia do painel principal, só o código, sem molduras, fotos ou dados do principal.
- O painel novo já vem com o nome do cliente, o login único e a exigência de licença ligada.
- **Fotos:** as fotos de cada cliente ficam em `buddyApp/<cliente>/fotos/`, e as de cada evento em `fotos/eventos/<evento>/`.
  - Quem escolhe a pasta é o servidor (`pasta_fotos` no config do cliente), não o robô.
  - Painéis criados antes desse padrão passam a usar `fotos/` ao tocar em "Atualizar os arquivos do painel": as fotos que já existiam são movidas para lá.
- **Atualizar o painel de um cliente:** depois de subir uma versão nova do painel principal, abra a licença e toque em **"Atualizar os arquivos do painel"**. Isso troca só o código; os dados do cliente ficam como estão.
- **Pasta sem painel** (ex.: licença criada antes desta versão): a licença mostra o botão **"Criar o painel agora"**.
- **Login:** cada painel tem a sua sessão. Quem entrou no painel de um cliente não abre o de outro.

## Login único dos clientes

Todos os clientes entram pelo mesmo link: **`https://suafoto.futuremedia.com.br/clienteFotos/buddyApp/login/`**. Cada um cai direto no painel dele.

1. **Na central:** cada licença tem o cartão **"Acesso ao painel"**, com o usuário do cliente (único entre todos) e a senha.
   - A senha é gerada ao criar a licença ou em "Gerar senha nova". Ela aparece **uma vez só**, com o botão "Copiar os três" (endereço, usuário e senha).
   - Também dá para digitar uma senha de pelo menos 8 caracteres.
2. **Entrada no painel:** o login único confere usuário e senha e manda a pessoa para o painel com um passe assinado (`painel/entrar.php?t=…`). O passe vale 2 minutos, uma vez só e apenas naquele painel.
3. **Licença suspensa ou expirada:** o login é recusado com o motivo.
4. **Endereço antigo do painel:** quem abrir `.../buddyApp/painel/` vai para o login único. Ao sair, a pessoa volta para ele.
5. **Equipe:** o login próprio do painel (usuário e senha de `config/painel.local.php`) continua em `.../buddyApp/painel/?local=1`. Na central, o botão **"Abrir o painel do cliente"** entra sem precisar da senha dele.
6. **Configuração no painel:** `'login_unico' => 'https://suafoto.futuremedia.com.br/clienteFotos/buddyApp/login/'` em `config/painel.local.php`. Em branco, o painel volta a usar só o login próprio.

## Trajeto e editor de telas

O painel abre com o **trajeto do app**: as telas na ordem em que a pessoa passa por elas, com o destino de cada botão. Tocar numa tela leva à seção dela.

| Nº | Tela | Imagem da tela | Elementos que se movem |
|---|---|---|---|
| 1 | Início | `tela_inicio` | Iniciar → 2, Sair → fecha o app |
| 2 | Termos de uso | `tela_termos` | Não aceito → 2a, Aceito → 3 |
| 2a | Aviso "Não aceito" | `tela_recusa` | Não tirar foto → 1, Voltar para Termos → 2 |
| 3 | Câmera | (câmera + moldura) | Tirar foto → 4, Balão, Moldura → 3a (só com 2+ molduras; sem ele, Tirar foto, balão e Início fecham o espaço, veja a prévia "Com 1 moldura"), Início → 1 |
| 3a | Escolha da moldura | fixa | (nada) |
| 4 | Revisão | (foto + moldura) | Salvar → 5, Tirar nova foto → 3 |
| 5 | Processando | vídeo | (nada) |
| 6 | Final | `tela_final` | Foto, QR Code, Finalizar → 1 |

- **Imagem da tela:** uma imagem só, 1920x1080, com o fundo e os textos juntos. Ela substitui o antigo esquema de fundo + logo + texto dos termos + aviso + arte do QR Code. As imagens padrão (`assets/images/tela_*.jpg`, cópia em `painel/padrao/`) foram montadas a partir dessas peças antigas, nas mesmas posições.
- **Botões:** cada botão pode ganhar uma imagem (PNG transparente). Sem imagem, o app desenha o botão padrão na cor principal.
- **Editor:** prévia da tela em 16:9. Arraste para mover, puxe o canto ◢ para mudar o tamanho, ou digite os valores em %. A âncora é o **meio** do elemento: "Horizontal" e "Vertical" medem o meio (50% e 50% = centro da tela), e mudar o tamanho (pelo canto ou pelos campos) cresce para os dois lados sem tirar o meio do lugar. Só a tela mostra o meio; o que se guarda continua sendo o canto superior esquerdo (abaixo). O editor marca o centro e a área secreta do admin. Elementos com imagem, a foto (16:9) e o QR Code (quadrado) mantêm o formato. Ao enviar uma imagem para um botão, a caixa dele ganha o formato da imagem, com a mesma largura e o mesmo centro.
- **Posições:** ficam em `config.layout` como frações da tela (`x`, `y` = canto superior esquerdo, `w`, `h`) e vão no `sync.php` em `layout`. Todo formulário de uma tela leva junto as posições do editor, então enviar uma imagem não perde o que foi arrastado.

## O que mais o cliente pode trocar

| Item no painel | Onde aparece no app | Padrão do APK |
|---|---|---|
| Molduras (várias, com ordem) | Câmera, revisão, foto final | `moldura1.png` |
| Balão (imagem e posição) | Câmera | `balao_img2.png` |
| Vídeo de carregamento (MP4) | Processando | `buddy-animacao.mp4` |
| Cor principal | Botões sem imagem e textos | `#0b3675` |
| PIN da tela de admin | Acesso à tela de admin | `1234` |

A **pasta das fotos** (para onde o `uploadImage.php` envia) é definida em `config/painel.local.php` (`pasta_fotos`), a partir da pasta do `uploadImage.php`. Hoje é vazia (`''`): as fotos ficam direto em `clienteFotos/buddyApp/`. Também aceita subpastas separadas por `/`. Se ela for trocada lá, os robôs passam a usar a nova pasta na próxima sincronização. Antes da primeira sincronização, o app usa `AppConfig.pastaFotosPadrao` (vazia, ou seja, a raiz).

## Endereços

| O quê | Endereço | No app |
|---|---|---|
| Central de Licenças | `https://suafoto.futuremedia.com.br/clienteFotos/buddyApp/licencas/` | `Licenca.central` |
| Painel, sync e upload das fotos | Vem da licença do robô (ex.: `https://suafoto.futuremedia.com.br/clienteFotos/buddyApp/`) | `Licenca.instance.painel` |
| Fotos | `https://suafoto.futuremedia.com.br/clienteFotos/buddyApp/photo_….jpg` | |
| Página do QR Code | `https://suafoto.futuremedia.com.br/appsBuddy/appFoto/camerashot.html?imagem=<endereço completo da foto>` | `AppConfig.paginaCompartilhar` |

A central e a página do QR Code ficam fixas no app. Para trocar, é preciso gerar um APK novo. O painel de cada cliente muda pela central, sem APK novo.

## Publicar no servidor

A pasta **`clientes/`** (na raiz do projeto) tem só o que precisa subir, já na estrutura certa. Envie o **conteúdo** dela para `clienteFotos/buddyApp/` no servidor:

- `painel/` (pasta inteira): o painel do cliente
- `login/` (pasta inteira): o login único dos clientes (`.../buddyApp/login/`)
- `licencas/` (pasta inteira): a Central de Licenças (`.../buddyApp/licencas/`), só para a equipe
- `config/painel.local.php`: login do painel, nome do cliente e pasta das fotos (troque a senha antes de subir)
- `config/painel/` (com `.htaccess`, `index.html` e a subpasta `clientes/`)
- `uploadImage.php`: aceita subpastas e pasta vazia (= a própria pasta)
- `index.html` e `config/index.html`: páginas em branco para o navegador não listar os arquivos

O PHP precisa conseguir **gravar** em `config/painel/`, em `painel/midia/` e na pasta das fotos.

A pasta `clientes/` é uma cópia dos arquivos do painel em `servidor/appsBuddy/appFoto/`. Ao alterar algum arquivo do painel, atualize as duas.

**Tudo roda dentro de `clienteFotos/buddyApp/`**: o painel, o login único (`login/`) e a central (`licencas/`). O `login/` lê os dados da central, então os dois ficam lado a lado.

Sobre a central (`licencas/`):
- O PHP precisa gravar em `licencas/dados/`.
- `licencas/config/` e `licencas/dados/` têm `.htaccess` que bloqueia o navegador. Depois de subir, confira se respondem 403. Se o programa de FTP esconder os arquivos que começam com ponto, mande mostrar.
- `licencas/config/licencas.local.php` tem o login da central e a **chave privada** que assina as licenças. Não envie esse arquivo para nenhum outro lugar.
- `login` e `licencas` são pastas reservadas: a pasta das fotos não pode ter esses nomes.

Para o envio de vídeos, o `painel/.user.ini` sobe o limite de upload para 64 MB. Isso só vale em PHP-FPM/CGI. Se o painel mostrar um limite menor ("Limite por arquivo: …"), ajuste `upload_max_filesize` e `post_max_size` no painel da hospedagem.

## Arquivos

**Servidor** (`servidor/appsBuddy/appFoto/`)
- `painel/index.php`: login (usuário e senha de `config/painel.local.php`)
- `painel/cliente.php`: o painel em si (trajeto, uma seção por tela com o editor, molduras, vídeo, cor, PIN)
- `painel/fotos.php`: galeria das fotos que os robôs enviaram, por evento e por dia, com visor e download de uma foto, de um dia ou de todas (.zip), e a barra do espaço usado (limite `limite_gb`, padrão 5 GB, só aviso)
- **Eventos:** o cliente cria e escolhe o evento atual no topo do `cliente.php`. As fotos vão para `eventos/<data>-<nome>/` dentro da pasta das fotos. Quem decide é o `uploadImage.php`, lendo `config/painel/evento_atual.php` a cada foto, então a troca vale na hora, sem sincronizar o robô.
- `painel/foto.php`: entrega uma foto (inteira, miniatura ou download), só com login. As miniaturas ficam em `config/painel/miniaturas/`.
- `painel/fotos_zip.php`: monta o .zip enquanto envia (sem arquivo temporário; ZIP64 acima de 4 GB)
- `painel/equipe.php`: área da equipe Futuremedia para excluir fotos (marcadas, um dia ou todas). Tem login próprio (`usuario_equipe` e `senha_equipe` em `config/painel.local.php`), e o cliente não tem acesso nem link para ela. Não cite este endereço em nada que o navegador do cliente receba.
- `painel/editor.js`: o editor de arrastar e redimensionar
- `painel/api/sync.php`: endpoint do app: `GET ?dispositivo=…&versao=…`
- `painel/licenca.php`: confere a licença do robô (cabeçalho `X-Licenca`) para o `sync.php` e o `uploadImage.php`, quando `exigir_licenca` está ligado

**Central de Licenças** (`licencas/`)
- `index.php`: login e lista das licenças, com o formulário de licença nova
- `licenca.php`: uma licença (código, robôs, dados, suspender, desvincular, excluir)
- `api/ativar.php`: endpoint dos robôs (`acao=ativar` ou `renovar`), que devolve o token assinado
- `lib.php`: funções, situação (ativa, vencida, expirada, suspensa), assinatura do token e do passe de login

**Login único** (`clientes/login/` → `.../buddyApp/login/`)
- `index.php`: usuário e senha do cliente (da central), depois redireciona ao painel dele com o passe
- `clientes/painel/entrar.php` (em cada painel): confere o passe (`licencaConferirPasse` em `painel/licenca.php`), guarda o nonce em `config/painel/passes_usados.php` e abre a sessão
- `painel/lib.php`: funções comuns, as telas e os elementos (`painelTelas()`) e os arquivos editáveis (`painelSlots()`, gerado a partir das telas)
- `painel/midia/<cliente>/`: arquivos enviados, com nome igual ao hash do conteúdo
- `painel/padrao/`: cópia das imagens padrão do APK (só para pré-visualizar no painel)
- `config/painel/clientes/<id>.php`: dados do cliente (configuração e robôs que sincronizaram). A primeira linha é `<?php exit; ?>`, para nada aparecer se o arquivo for aberto pelo navegador.

**App** (`lib/`)
- `app_config.dart`: carrega, sincroniza e guarda a configuração em `ApplicationSupport/config_remota/`. Também tem o helper `Midia` (asset ou arquivo baixado).
- `admin.dart`: tela de admin, vista pelo cliente (sem endereços, IDs ou números internos). Tem o botão Sincronizar com o resumo do que mudou, o teste de conexão (internet e envio de fotos), a situação (painel, última sincronização, fotos de hoje, versão do app) e miniaturas das telas e molduras que estão no robô. O endereço do servidor é fixo (`AppConfig.servidorPadrao`); para trocar, é preciso gerar um APK novo.
- `elementos_tela.dart`: `ElementoTela` (põe qualquer widget no retângulo do painel) e `BotaoTela` (imagem do painel ou botão padrão)
- `licenca.dart`: guarda, confere (chave pública) e renova a licença; dá o painel do robô
- `tela_licenca.dart`: tela "Ativar licença" (e "Trocar a licença", pela tela de admin)
- `tela1.dart`: área secreta (5 toques) + PIN. Sem licença válida, mostra a tela de ativação
- `pin_admin.dart`: popup grande do PIN, com teclado numérico próprio (sem o teclado do Android). Não fecha com toques fora dele; PIN errado mostra o aviso e limpa.
- As outras telas leem imagens, posições, molduras, vídeo, cor, pasta e URLs de `AppConfig.instance`.

## Novo botão ou elemento

1. Em `painelTelas()` (`painel/lib.php`), adicione o elemento à tela, com tipo, título, texto, destino e posição padrão.
2. Em `AppConfig.layoutPadrao` (`lib/app_config.dart`), adicione o mesmo nome com a mesma posição padrão.
3. Na tela, coloque `BotaoTela(elemento: 'nome', ...)` (ou `ElementoTela`) dentro do `Stack` de tela cheia.

Para uma imagem de tela nova, adicione `fundo` em `painelTelas()`, a chave em `AppConfig.imagensPadrao` e a imagem padrão em `assets/images/` e em `painel/padrao/`.
