# Contexto do projeto: App de Fotos do robô Buddy + Painel Web

> **Não suba este arquivo para o servidor.** Ele fica aqui só como referência.
> Na hora de copiar a pasta `clientes/` para `clienteFotos/buddyApp/`, deixe este `.md` de fora
> (o servidor entregaria o arquivo como texto para quem abrisse o endereço).

Última atualização: 29/10/2026.

---

## 1. O que é

- **App:** "camerashot", em Flutter 3.35.4, roda no robô Orion/Buddy. O visitante tira a foto com moldura, a foto sobe para o servidor e um QR Code abre a foto no celular.
- **Painel web:** o cliente troca sozinho fundo, molduras, vídeo de carregamento, cor, PIN e outras imagens. Assim não é preciso alterar o código e gerar um APK novo a cada evento.
- **Tela de admin no app:** tem um botão **Sincronizar** que baixa o que foi configurado no painel.

### Decisões tomadas

1. **Um cliente só.** Depois do login, o painel já abre na tela de molduras, fundo e vídeo. Não existe lista de clientes nem superadmin.
2. **Vínculo pela licença (13/11/2026).** O robô descobre o painel do cliente pela licença: na primeira vez, o técnico digita o código `FM-XXXX-XXXX` e a Central de Licenças devolve o painel. Um APK serve para todos os clientes. Veja a seção 3b.
3. **A opção "Avançado" foi removida da tela de admin.** O endereço do servidor é fixo no APK.
4. **As fotos ficam na raiz** de `https://suafoto.futuremedia.com.br/clienteFotos/buddyApp/`, porque `pasta_fotos` está vazio.
5. **As mídias são baixadas para o Android.** Molduras, fundos e vídeo ficam numa pasta do app, então ele funciona offline depois de sincronizar. Se faltar algum arquivo, o app usa o que veio no APK (`assets/`).
6. **Os arquivos que sobem para o servidor ficam todos na pasta `clientes/`**, já na estrutura certa.

---

## 2. Endereços

| O quê | Endereço |
|---|---|
| Painel (login) | `https://suafoto.futuremedia.com.br/clienteFotos/buddyApp/painel/` |
| Endpoint de sincronização | `https://suafoto.futuremedia.com.br/clienteFotos/buddyApp/painel/api/sync.php` |
| Upload das fotos | `https://suafoto.futuremedia.com.br/clienteFotos/buddyApp/uploadImage.php` |
| Fotos salvas | `https://suafoto.futuremedia.com.br/clienteFotos/buddyApp/photo_<data>_<código>.jpg` |
| Página do QR Code (app 1.0.3+, com cadastro de leads) | `https://suafoto.futuremedia.com.br/clienteFotos/buddyApp/<cliente>/painel/qr.php?e=<evento>&f=<foto>` |
| Página do QR Code antiga (app até 1.0.2) | `https://suafoto.futuremedia.com.br/appsBuddy/appFoto/camerashot.html?imagem=<endereço completo da foto, codificado>` |

- Esses endereços ficam fixos no app, em `lib/app_config.dart`. Para trocar, é preciso gerar um APK novo.
- A página do QR Code continua no `appsBuddy/appFoto/`, porque é lá que estão `camerashot.html`, `saveLead.php` e os outros arquivos dela. Ela **não** existe em `clienteFotos/buddyApp/`.
- O formulário de lead da página do QR já é pulado, porque o `eventRegistry.php` não existe mais no servidor.

### Acessos

| O quê | Onde está |
|---|---|
| Usuário e senha do painel | `config/painel.local.php` (usuário `futuremedia`; a senha está nesse arquivo) |
| PIN da tela de admin do robô | Definido no painel, **só números, de 4 a 8** (o campo apaga na hora o que não é número; o servidor recusa o resto com `/^[0-9]{4,8}$/`). O padrão é `1234`. |
| Abrir a tela de admin no robô | Na tela inicial, **5 toques seguidos no canto superior direito**, depois o PIN |

---

## 3. Como funciona a sincronização

1. O cliente entra no painel e envia as imagens. Cada alteração aumenta a **versão da configuração**.
2. O robô chama `GET painel/api/sync.php?dispositivo=<id do robô>&versao=<versão que ele tem>`.
3. O servidor responde em JSON:
   ```json
   {
     "ok": true,
     "cliente": "App de Fotos",
     "versao": 7,
     "atualizado_em": "2026-10-29T...",
     "pasta_fotos": "",
     "cor_primaria": "#e71313",
     "pin_admin": "1234",
     "tempo_inatividade": 60,
     "midia": { "background": { "arquivo": "<hash>.jpg", "caminho": "painel/midia/<id>/<hash>.jpg" } },
     "molduras": [ { "arquivo": "<hash>.png", "caminho": "painel/midia/<id>/<hash>.png" } ]
   }
   ```
4. Cada arquivo tem como nome o **hash do conteúdo**, então o robô só baixa o que ainda não tem. Os arquivos ficam em `ApplicationSupport/config_remota/midia/`, e a configuração em `config_remota/`.
5. O robô **só sincroniza quando alguém toca em Sincronizar** na tela de admin. Não há sincronização automática (removida em 30/10/2026, para não ficar fazendo requisições o tempo todo).
6. O servidor anota cada robô que sincronizou (os 30 últimos, em `dispositivos` no arquivo do cliente). O painel **não mostra** essa lista: a tabela "Robôs" saiu porque o cliente não precisa ver IDs de robô. Os dados continuam no `config/painel/clientes/<id>.php` para a equipe consultar.

### Fechamento por inatividade

Em **Configurar o app → Acesso e inatividade**, defina o PIN do admin e quantos segundos o app espera na tela inicial antes de fechar. Essa seção aparece com qualquer visual selecionado e vale para todos os robôs do painel. O padrão é **60 segundos** quando o painel ou a configuração salva ainda não tem esse campo. Depois de salvar, toque em **Sincronizar** no admin de cada robô. O app aplica o tempo configurado a partir do APK **1.0.4**.

Para publicar o ajuste, envie `painel/lib.php`, `painel/api/sync.php`, `painel/estilo.css` e `painel/cliente.php` de `clientes/` para `clienteFotos/buddyApp/`, mantendo os caminhos. Se Eventos por robô também estiver pendente, use a lista daquela seção e inclua `painel/api/sync.php`.

## 3b. Licenças (13/11/2026)

**Para que serve:** vincular cada robô ao painel certo e controlar quem pode usar o app (validade, suspensão, número de robôs).

**Peças:**

| Peça | Onde | O que faz |
|---|---|---|
| Central de Licenças | pasta `clientes/licencas/` → `https://suafoto.futuremedia.com.br/clienteFotos/buddyApp/licencas/` | Só a equipe Futuremedia entra. Cadastra licenças e mostra os robôs de cada uma. |
| `licencas/api/ativar.php` | central | O robô ativa (`acao=ativar`, ocupa o código digitado; cada robô tem o seu) ou renova (`acao=renovar`). Manda o serial. Responde um token assinado. |
| `painel/licenca.php` | painel de cada cliente | O `sync.php` e o `uploadImage.php` conferem o token do cabeçalho `X-Licenca`. |
| `lib/licenca.dart` e `lib/tela_licenca.dart` | app | Guardam o token, renovam e mostram a tela de ativação. |

**Licença:** cliente, pasta do painel (só o nome; a central monta `<base_paineis><pasta>/`, com `base_paineis` = `https://suafoto.futuremedia.com.br/clienteFotos/buddyApp/`; em branco = o painel principal; nomes do sistema como `painel`, `login` e `licencas` são recusados; se não houver painel instalado na pasta, a central avisa), vencimento (em branco = vitalícia), quantos robôs, situação (ativa/suspensa) e observação. Código `FM-` + 8 caracteres de `23456789ABCDEFGHJKMNPQRSTUVWXYZ` (sem 0/O, 1/I/L). Os dados ficam em `licencas/dados/licencas.php`, gravados com trava (`flock`).

**Token:** `base64url(json).base64url(assinatura)`, assinado com RSA 2048 / SHA-256.
- O JSON tem: `licenca`, `cliente`, `painel`, `dispositivo`, `situacao`, `vence`, `emitido` e `valido_ate`.
- A chave privada fica só em `licencas/config/licencas.local.php`, com o login da central.
- A chave pública fica no app (`Licenca._chavePublica`, como módulo em hexadecimal) e em `painel/licenca.php` (PEM).
- **Se a chave privada vazar:** gere outro par de chaves, troque nos três lugares e gere um APK novo.

**Regras:**
- **Sem internet:** o robô funciona até o `valido_ate` do token, que é 7 dias depois da última renovação (`dias_offline`). O app conta esse prazo a partir de quando recebeu o token, então o relógio do robô não precisa bater com o do servidor.
- **Renovação:** o app renova em segundo plano ao abrir a tela inicial, se o token tiver mais de 6 horas, e sempre antes de sincronizar.
- **Vencimento:** depois da data, a licença fica "vencida" por mais 7 dias (`dias_carencia`). O app funciona, e a tela de admin mostra o aviso em vermelho. Depois disso, a licença fica "expirada", e a central recusa a renovação. O `valido_ate` nunca passa do fim da carência, nem sem internet.
- **Suspensa, expirada, excluída ou robô desvinculado:** na próxima renovação, a central recusa (`erro` = `suspensa`, `expirada`, `codigo` ou `desvinculado`), e o app trava na hora, mostrando o motivo. Erros de internet ou do servidor não travam o app.
- **Vagas:** um código por robô (ver "Um código por robô"). Código já usado por outro robô é recusado com `em_uso`. "Desvincular" na central libera a vaga. O robô desvinculado precisa digitar o código de novo.
- **Tentativas:** 10 códigos errados por IP em 15 minutos bloqueiam esse IP (`dados/tentativas.php`).

**No painel do cliente:**
- **Ligar a exigência:** `'exigir_licenca' => true` em `config/painel.local.php`. Sem essa linha, ou com `false`, os robôs antigos sem licença continuam funcionando. Ligue só depois de atualizar e ativar todos os robôs daquele cliente.
- **O que é conferido:** a assinatura, se o token não passou do `valido_ate`, se o robô é o mesmo do token (só no sync) e se o painel do token é este. O painel compara host e caminho sem `www.`, porta nem `http`/`https`. Atrás de um proxy, fixe o endereço com `painel_url`.
- **Recusa:** responde 403 com `licenca: false`.
- **Teste de conexão do app:** o GET no `uploadImage.php` continua respondendo 405 antes da conferência, então o teste segue funcionando.

**No app:**
- Sem licença válida, a `Tela1` vira a `TelaLicenca`. Isso cobre todas as voltas para o início, porque todas criam uma `Tela1()` nova. Nessa tela, o timer que fecha o app em 60 s fica parado.
- **Ativação:** o técnico digita o código no teclado próprio (`TeclaToque`, a mesma tecla de toque rápido do PIN) e toca em Ativar. O app então sincroniza com o painel do cliente.
- **Se a sincronização falhar depois de ativar:** aparece "Continuar", e dá para sincronizar depois.
- **Tela de admin:** a "Situação" mostra o código da licença e a validade, e tem o botão **"Trocar licença"**, que abre a mesma tela com "Voltar".
- **Licença de outro painel:** o app apaga o `config.json` (`AppConfig.esquecerPainel`) e volta ao padrão do APK até sincronizar.
- **Central de teste:** para usar outra central, gere com `flutter build apk --dart-define=CENTRAL_LICENCAS=<endereço>`.

**Testes (13/11/2026)**, no PHP 5.6 local com `php -S`:
- **Central:** login; criar licença; código errado (404); ativar com o código em minúsculas e sem traço; segundo robô além do limite (`limite`); renovar um robô não vinculado (`desvinculado`).
- **Suspensão e vencimento:** suspender (`suspensa`) e reativar; vencida ontem (`situacao=vencida`, `valido_ate` no fim da carência); vencida há 10 dias (`expirada`); desvincular e ativar de novo; excluir com o código errado (recusado).
- **Painel do cliente:**
  - sem token, 403;
  - com token, 200;
  - token de outro robô, 403;
  - token adulterado, 403;
  - outro endereço de painel, 403;
  - upload com e sem token;
  - com `exigir_licenca => false`, os robôs antigos continuam funcionando.
- **Dart:** `test/licenca_test.dart` confere a assinatura de um token real do PHP e recusa token alterado ou de outro robô.
- **No robô:** com o APK de teste e `adb reverse`, o código foi digitado no teclado, a ativação passou e a sincronização com o painel exigindo licença funcionou. Depois disso, o robô voltou ao APK anterior.

### Login único (13/11/2026)

- **Link único dos clientes:** `https://suafoto.futuremedia.com.br/clienteFotos/buddyApp/login/` (pasta `clientes/login/`, que lê `../licencas/lib.php`). **Tudo fica dentro de `clienteFotos/buddyApp/`** (pedido do usuário em 18/11/2026).
  - Cada licença tem `usuario` (único) e `senha_hash` (`password_hash`). A senha aparece uma vez só, na central (`$_SESSION['lic_senha_nova']`).
  - 10 erros por IP em 15 minutos bloqueiam o login (chave `login:<ip>` em `tentativas.php`).
- **Passe:** `licEmitirPasseLogin()` assina `{tipo: "login", painel, expira: +120 s, nonce}`. O redirecionamento vai para `<painel>painel/entrar.php?t=…`.
- **`entrar.php` (em cada painel):**
  - Confere assinatura, `tipo`, validade e se o painel do passe é este (`licencaConferirPasse`).
  - Guarda o nonce em `config/painel/passes_usados.php` (com `flock`) e abre a sessão com `painel_logado` e `painel_via_login_unico`.
  - Passe repetido ou vencido volta ao login com "O acesso expirou".
- **Tokens separados:**
  - Um token de robô não serve como passe, porque não tem `tipo`.
  - Um passe não serve como licença de robô: `licencaConferir` recusa o que tem `tipo`.
- **`painel/index.php` (do cliente):** com `login_unico` em `painel.local.php`, redireciona para o link único. O login próprio continua em `?local=1`, para a equipe. "Sair" volta ao login único com "Você saiu do painel".
- **Central:** o cartão "Acesso ao painel" tem usuário, nova senha (digitada ou gerada) e o botão "Abrir o painel do cliente", que gera um passe para a equipe dar suporte.
- **Testado (local):**
  - redirecionamento do endereço antigo;
  - senha errada e senha certa (o usuário não diferencia maiúscula de minúscula);
  - passe reutilizado, passe de outro painel, passe adulterado e token de robô usado como passe;
  - sair;
  - login `?local=1`;
  - licença suspensa recusando o login;
  - "Abrir o painel" da equipe;
  - gerar senha nova (a antiga deixa de valer);
  - senha curta recusada;
  - usuário repetido recusado.

### Um código por robô, com o serial (18/11/2026, pedido do usuário)
Pedido: "Se a plataforma foi setada para usar 2 robôs, o ideal é que tenhamos 2 códigos de licenças e um identificador para sabermos em qual robô está. De preferência o serial dele."
- **Dados (central):** cada licença tem `codigos` (um por robô; o 1º é o próprio id da licença, o código de antes) e `robos = count(codigos)`. Cada robô em `dispositivos[<ID do app>]` guarda `codigo` (o que ocupa), `serial`, `modelo`, `versao_app`, `ativado_em`, `ultimo_contato`. Um código vale para um robô só.
- **Migração automática:** `licCompletarCodigos()` roda em toda leitura/gravação (`licTodas`/`licAlterar`). Licença antiga ganha os códigos que faltam, e os robôs já ativados ficam com o 1º código livre, na ordem de ativação. Nada para fazer à mão no servidor.
- **Ativar (`api/ativar.php`):** procura a licença pelo código (`licBuscarCodigo`). Código ocupado por outro robô → recusa `em_uso` (mostra o serial do outro; não trava o robô atual). Mesmo serial com ID novo (app reinstalado) → o código passa para o ID novo. Robô que ativa outro código (desta ou de outra licença) larga o anterior. `renovar` aceita o robô que está na licença com qualquer código dela (robôs antigos digitaram o 1º código) e a resposta/token trazem o `codigo` certo; o app guarda esse código.
- **Token:** ganhou `codigo` e `serial`. O painel (`licencaSituacaoNaCentral`) recusa token de um código que o robô já largou.
- **Mais/menos robôs:** mudar "Robôs" em "Dados da licença". A mais: códigos novos. A menos: apaga só códigos livres, do último para o 1º (o 1º nunca sai); se não houver livres suficientes, recusa pedindo para desvincular antes.
- **Telas:** central (lista "Robôs" com #, código + Copiar, serial/modelo/ID, contatos, Desvincular; busca acha qualquer código; lista geral mostra "e mais N"); painel do cliente (cartão "Licença do robô" lista "Robô 1, Robô 2…" com código, Copiar e "Em uso · serial X" ou "Livre", sem o ID interno); app (admin mostra "Serial do robô"; tela de ativação mostra "Serial deste robô").
- **Serial no app:** `MainActivity.kt` canal `futuremedia/robo` método `info` → `RobotSettings.getSystemSn()` do SDK OrionStar (lê `ro.robot.serialno`), senão `ro.serialno`/`ro.boot.serialno`. `lib/robo_info.dart` (`RoboInfo.serial`, `RoboInfo.modelo`). No robô de teste: **MC1BHW0C140224517BCA** (o `ZTS39P2201T0` do adb é o tablet).
- **Testes:** seção 11 do e2e do servidor (144/144) e 2 testes novos no app (17/17). Conferido no robô: admin mostra o serial.
### Backup, registro de ações e avisos da central (19/11/2026, pedido do usuário)
Pedido: "O que mais podemos melhorar em relação a segurança e confiabilidade?" → "Implemente isso!". Tudo dentro de `licencas/dados/` (fechada pelo `.htaccess`; cada arquivo começa com `<?php exit; ?>`).
- **Cópia de segurança (`dados/backup/`):** `licAlterar()` copia o `licencas.php` na 1ª gravação do dia (`licencas-AAAA-MM-DD.php`, guarda 30) e `licBackupAgora()` faz uma cópia na hora antes de excluir licença e antes de restaurar (`licencas-<data_hora>-antes-de-excluir|antes-de-restaurar.php`, guarda 20). Página **Backup** (`backup.php`): "Baixar as licenças agora" (JSON), lista das cópias com Baixar e Restaurar (com confirmação; o estado de antes vira uma cópia). Também mostra a impressão digital da chave (SHA-256 da chave pública, 16 caracteres). **A chave nunca é mostrada nem baixada:** guardar uma cópia do `licencas.local.php` fora do servidor, pelo FTP.
- **Arquivo estragado ou apagado:** `licLerLicencas()` devolve null se o JSON não lê. Aí `licTodasNoArquivo()` usa a cópia boa mais nova (`licEstadoArquivo() = 'recuperado'`), o arquivo estragado vai para `backup/estragado-<data>.php` (nunca é apagado sozinho), o principal é regravado e o registro anota. Sem cópia boa (`'indisponivel'`): nada é gravado e o `api/ativar.php` responde 503 `servidor` (não trava o robô; "codigo" travaria). Gravação: `licGravarArquivo()` só troca o arquivo se gravou tudo (disco cheio não deixa arquivo cortado).
- **Registro (`dados/registro.php`, uma linha JSON por ação; passando de 1 MB vira `registro-anterior.php`):** `licRegistrar(quem, ação, licença, detalhe)` com IP. Anota: login da central (certo e errado), licença criada, dados alterados (só o que mudou), acesso do cliente, equipe abriu o painel, painel criado/atualizado, suspensa/reativada, robô desvinculado (central ou app), excluída, backup baixado/restaurado, robô ativado/reinstalado/código em uso, login do cliente (entrou, senha errada, recusado). Renovação a cada minuto **não** é anotada. Página **Registro** (`registro.php`, filtro por quem e por licença) e "Últimas ações" na página da licença.
- **Avisos ("Atenção" no topo da lista, `licAlertas()`):** licença vencendo em até 15 dias ou vencida (em aviso); robô sem contato há mais de `dias_sem_contato` (padrão = `dias_offline`, 7); espaço das fotos ≥ 80% ou cheio (`licUsoPainel()`, mesma conta do painel, guardada 15 min em `dados/uso-paineis.php`); painel que não exige licença (inclusive o principal); arquivo recuperado; cópia do dia que não foi feita. Lista e página da licença mostram "ligado agora" (contato há menos de 10 min), "há N dias" e a barra de espaço.
- **Reforços:** login da central trava depois de 10 erros em 15 min por IP (como o login dos clientes); `uploadImage.php` sem `Access-Control-Allow-Origin` e com `nosniff`; `fotos/.htaccess` (criado pela central e pelo upload) proíbe rodar scripts na pasta das fotos (só na pasta configurada, nunca na raiz do painel). Produção é Apache e respeita `Require all denied` (conferido: `licencas/dados/` responde 403).
- **Testes:** e2e do servidor 144/144 + parte 12 (75/75): trava do login, cópia do dia, baixar, excluir e restaurar, arquivo estragado/apagado/sem cópia, registro, avisos, upload e `.htaccess`.
### Fotos só com evento (23/11/2026, pedido do usuário)
Pedido: "É melhor que as fotos só possam ser tiradas com um evento vinculado". O evento continua sendo do painel (todos os robôs do cliente usam o evento atual). Depois que o cliente cria o primeiro evento, sempre há um atual: o evento atual não pode ser apagado, e não existe "voltar para sem evento".
- **Servidor decide:** `uploadImage.php` recusa a foto com **409** `{"ok":false,"evento":false,...}` quando `config/painel/evento_atual.php` não tem evento (logo depois da licença; o GET do teste de conexão continua 405). Vale para todos os painéis, inclusive o principal e os robôs com APK antigo (que mostram "Erro no Upload").
- **Robô pergunta:** `painel/api/evento.php` (GET, `?dispositivo=`, licença no `X-Licenca` se o painel exigir) responde `{"ok":true,"evento":{"nome":"..."}}` ou `{"ok":true,"evento":null}`. Ele regrava o `evento_atual.php` antes, para a resposta e o envio nunca discordarem. A central copia a pasta `painel/` inteira, então o "Atualizar os arquivos do painel" leva o arquivo novo.
- **App (`lib/evento.dart`, `EventoAtual`):** confere ao abrir, a cada 30 s na tela inicial (10 s enquanto não houver evento), ao voltar para o app e ao abrir a tela de admin. Sem evento, a `Tela1` mostra `TelaSemEvento` (`lib/tela_sem_evento.dart`, cores da Futuremedia, "Tentar de novo", "Fechar app", e o canto secreto da tela de admin continua valendo); a tela sai sozinha quando o evento é criado. Sem internet, erro do servidor ou painel antigo sem o `evento.php` (404): **não trava**, vale a última resposta. Se o envio volta com `"evento": false`, o app mostra "Nenhum evento ativo" (no lugar do "Erro no Upload") e volta para o início. A tela de admin ganhou a linha "Evento atual".
- **Painel:** cartão "Evento atual" amarelo quando não há evento ("O robô não tira fotos sem um evento"); aviso do primeiro evento diz que o robô libera em até 30 s; galeria sem evento mostra "Nenhum evento ainda". As fotos "Sem evento" de antes continuam na galeria e na área da equipe, mas nunca como "atual". O `evento_atual.php` agora guarda também o `nome`.
- **Central:** aviso "o painel não tem evento, então os robôs não tiram fotos" para licença com robô ligado (não suspensa/expirada); a página da licença mostra "Evento atual: X" ou o aviso (`licEventoDoPainel()`; arquivo antigo sem nome mostra o id).
- **Defeito corrigido junto (já estava no APK com licença):** a `Tela1` ligava a contagem de 60 s duas vezes ao abrir o app (a renovação da licença avisa a tela ainda dentro do `initState`). "Iniciar" parava só uma, e a outra **fechava o app no meio da sessão**. Agora `_startTimer()` desliga a anterior, e a contagem só roda/fecha com a tela inicial na frente (`ModalRoute.isCurrent`). Confirmado por teste: com a `tela1.dart` antiga, o app fechava nos Termos; com a nova, não.
- **APK:** versão **1.0.1 (2)** (antes 1.0.0 (1), para a tela de admin mostrar qual está instalado): `build/apk-evento/camerashot-1.0.1-evento-obrigatorio.apk`.
- **Testes:** e2e do servidor 144/144 + parte 12 (75/75) + parte 13 (45/45: `evento.php` com e sem licença, 409 sem evento, foto com evento, central, painel, fotos antigas "Sem evento", arquivo sem nome). App: 29/29 (`test/evento_test.dart` novo, com a tela inicial, o aviso, o canto secreto e a contagem).
- **Ordem para subir:** antes do `uploadImage.php` novo, **criar um evento em cada painel em uso** (inclusive o principal, se algum robô envia para ele). Sem isso, os robôs param de tirar fotos assim que o arquivo sobe.
### Encerrar e reabrir evento, com 5 minutos de tolerância (23/11/2026, pedido do usuário)
Pedido: "O cliente precisa ter a opção de 'fechar' um evento e abrir ele depois" → "Vamos seguir com os 5 minutos de tolerância".
- **Painel (`cliente.php`):**
  - **Encerrar evento** (ação `encerrar_evento`): botão no cartão "Evento atual", com confirmação. O painel fica sem evento aberto, e os robôs mostram "Nenhum evento ativo" em até 30 s.
  - **Reabrir:** "Voltar para um evento" virou "Reabrir um evento" (`usar_evento`). A lista mostra "(encerrado em …)". Sem evento aberto, o cartão diz qual foi encerrado e quando, e tem o botão **Reabrir "<nome>"**.
  - Criar um evento novo com outro aberto encerra o anterior.
- **Dados:** o evento que sai ganha `encerrado_em` e o que entra perde (`painelMudarEventoAtual()`). O "encerrado por último" (`painelEventoEncerrado()`) é o de `encerrado_em` mais novo. Se a equipe apagar esse evento, vale o encerrado antes dele.
- **Tolerância:**
  - Sem evento aberto, o `evento_atual.php` leva `"encerrado": {"pasta", "nome", "em"}`.
  - O `uploadImage.php` (`uploadEventoDaFoto()`) aceita a foto no evento encerrado até `UPLOAD_TOLERANCIA_ENCERRAR` = 300 s depois de `em`. Assim, quem estava no meio da sessão não perde a foto. Depois disso, recusa com 409.
  - O mesmo número está no painel (`PAINEL_TOLERANCIA_ENCERRAR`, usado nos textos).
  - O `evento.php` responde `evento: null` logo ao encerrar, então nenhuma sessão nova começa.
- **Galeria:** sem evento aberto, abre no encerrado por último. O evento que não é o aberto aparece como "(encerrado)".
- **Central:** evento encerrado de propósito **não** gera aviso em "Atenção". O aviso continua só para quem nunca criou evento. A página da licença mostra "Evento encerrado: X em <data>. Os robôs estão parados…".
- **App 1.0.2:** só textos ("crie ou reabra o evento", "evento aberto"). O 1.0.1 já funciona com encerrar/reabrir. Arquivo: `build/apk-evento/camerashot-1.0.2-encerrar-evento.apk`.
- **Testes:** e2e parte 14 (45/45: encerrar, tolerância com 4min59 aceita e 5min01 recusa, central, galeria, reabrir pelo botão e pela lista, criar com outro aberto, equipe apagando o encerrado). As partes anteriores continuam 144 + 75 + 45. App 29/29.
### Eventos por robô (25/11/2026)

- Em **Configurar o app → Evento por robô**, o cliente escolhe um evento para cada robô ativado, identificado pelo serial quando disponível. Pode escolher o mesmo evento em várias linhas. A opção **Usar evento padrão** acompanha o padrão do painel.
- Em **Novo evento**, use **Criar sem mudar o padrão** para abrir outro evento. Feche cada evento no próprio cartão. Os robôs dele param na próxima consulta e têm cinco minutos para terminar o envio ao mesmo evento. Os outros robôs seguem normalmente.
- A seleção é guardada em `config.eventos_dos_robos` pelo código de ativação. O servidor identifica o código pelo token de licença que o APK já envia. A consulta `painel/api/evento.php` e o upload consultam o mesmo vínculo. A foto, o QR Code e os leads ficam no evento do envio.
- O APK 1.0.3 já envia a identificação necessária. O ajuste vai no PHP e no CSS do painel, com cópias em `clientes/` e `servidor/appsBuddy/appFoto/`.
- Para publicar, suba os arquivos abaixo para `clienteFotos/buddyApp/`, mantendo os caminhos, na mesma sessão de FTP. Depois confira a atualização dos painéis dos clientes na Central de Licenças.
  1. `painel/lib.php`, `painel/licenca.php`, `painel/estilo.css`, `painel/fotos.php` e `painel/equipe.php`.
  2. `uploadImage.php` e `painel/api/evento.php`.
  3. `painel/cliente.php`.
  4. `licencas/lib.php` e `licencas/licenca.php`.

### Visual por robô

Em **Configurar o app → Visual do app**, clique no cartão **Visual compartilhado** ou no cartão do robô. Cada cartão de robô mostra o serial e qual visual ele usa. O visual compartilhado vale para todos os robôs nesse modo.

No robô selecionado, clique em **Usar visual próprio**. Na primeira vez, o painel copia o visual compartilhado. O editor abaixo passa a salvar as molduras, fundos, imagens dos botões, posições, tamanhos, cor e vídeo de carregamento daquele robô. Ao voltar para **Usar visual compartilhado**, o visual próprio fica salvo para reativar depois.

O vínculo do evento é independente do visual. O PIN e o tempo de inatividade continuam na configuração do painel. Para aplicar o visual escolhido, toque em **Sincronizar** no admin do robô. O APK instalado já usa os campos retornados pelo painel.

Para publicar, envie de `clientes/painel/` para `clienteFotos/buddyApp/painel/` os arquivos `lib.php`, `licenca.php`, `api/sync.php`, `estilo.css` e `cliente.php`, nessa ordem. As cópias também estão em `servidor/appsBuddy/appFoto/painel/`.

### Painéis dos clientes se atualizam sozinhos (24/11/2026, pedido do usuário)
Pedido: "Só de jogar os arquivos via FTP já atualizaria tudo?" → opção escolhida: atualização automática. Antes, a cópia do código de cada cliente (`buddyApp/<cliente>/`) só mudava pelo botão "Atualizar os arquivos do painel" da central.
- **`painel/atualizar.php`** (novo, sem depender do `lib.php`; a central também usa):
  - **Marca da versão:** `atualizarMarca()` gera um md5 de nome, tamanho e data de cada arquivo de código do principal: `painel/`, menos `midia/<id>/` e temporários (`.tmp-`, `.filepart`, `.part`, `.tmp`, `~`), mais `atualizarArquivosAvulsos()` (`uploadImage.php` e os `index.html`/`.htaccess`).
  - **Disparo:** o `painel/lib.php` registra um `register_shutdown_function` que chama `atualizarSeNecessario(PAINEL_RAIZ)` no fim de cada página e de cada consulta do robô. O `evento.php` é chamado a cada 30 s.
  - **Proteções:**
    - confere no máximo 1 vez por minuto (`config/painel/codigo-conferido`);
    - só copia depois de `auto_atualizar_espera` segundos (padrão 120) sem nenhum arquivo do principal mudar, usando data de gravação e de troca, que o FTP não consegue voltar;
    - uma cópia por vez (`codigo.lock`);
    - só copia o que mudou, cada arquivo para um temporário e depois rename;
    - nada sai na resposta (`error_reporting(0)` e try/catch), e com FPM o `fastcgi_finish_request()` libera o robô antes;
    - nunca toca em config, dados, mídias e fotos do cliente;
    - o principal nunca se atualiza (`atualizarModeloDe()` só aceita `buddyApp/<pasta>/` com a central ao lado).
  - **Desligar:** `'auto_atualizar' => false` no `config/painel.local.php` do **principal**.
  - **Situação de cada cliente:** `config/painel/codigo.php` (marca, `atualizado_em`, `erro`).
- **Central:**
  - `licInstalarPainel()` usa `atualizarAgora()`, que copia e anota a versão.
  - Botão **"Atualizar todos os painéis"** no fim da lista (`acao=atualizar_todos`, `licAtualizarTodosPaineis()`, anotado no Registro).
  - Avisos: "N painéis ainda estão com o código de antes da atualização automática" (os que nunca anotaram versão: precisam desse botão **uma vez**) e erro vermelho quando um painel não conseguiu se atualizar.
  - A página da licença mostra "Código do painel: igual ao do painel principal (copiado em …)", "o painel principal mudou, este painel pega a versão nova sozinho…", "de antes da atualização automática" ou o erro.
- **Atenção:** um erro que suba pelo FTP chega a todos os clientes em poucos minutos. Suba tudo de uma vez (painel e central juntos); se for subir em partes, desligue antes com `auto_atualizar`.
- **Testes:** e2e parte 16 (36/36). Cobre:
  - painel novo já anotado;
  - espera depois do FTP;
  - cópia depois da espera (arquivo novo, `.filepart` ignorado, mídia, config e dados intactos, sem temporários);
  - 1 vez por minuto;
  - consulta do robô atualiza e o JSON sai limpo (`evento.php` e `sync.php`);
  - o principal não se atualiza;
  - desligar;
  - avisos da central (antigo e erro);
  - "Atualizar todos".

  As partes anteriores continuam 144 + 75 + 45 + 45 + 69.
### Leads pelo QR Code (24/11/2026, pedido do usuário)
Pedido: captar leads ao escanear o QR Code; o cliente escolhe os campos, vê os leads numa aba do painel, ligados à foto, e baixa em algum formato. Escolhas do usuário:
- página nova dentro do painel de cada cliente, com APK novo (1.0.3);
- o cliente escolhe se o cadastro é obrigatório, opcional ou desligado;
- campos livres: nome, e-mail e telefone, mais texto, número e múltipla escolha;
- LGPD: só um aviso embaixo do botão, sem caixa de marcar (texto editável).

**Página do QR Code: `painel/qr.php`** (pública, sem login)
- **Endereço:** `buddyApp/<cliente>/painel/qr.php?e=<evento>&f=<foto>`. O `uploadImage.php` devolve `"pagina": "painel/qr.php?e=…&f=…"` (relativo ao painel) e o app 1.0.3 monta o QR com `servidor + pagina` (`AppConfig.ehPaginaDoPainel` / `urlCompartilhar`). App antigo ignora `pagina` e continua abrindo `appsBuddy/appFoto/camerashot.html` (sem cadastro).
- **Fluxo:**
  - obrigatório: cadastro primeiro (com a foto minúscula e borrada atrás, `&previa=1`, 48 px com blur do GD) e só depois a foto;
  - opcional: o mesmo, com "Agora não, ver a foto" (`&pular=1`); enviar tudo vazio, sem campo obrigatório, é o mesmo que "Agora não";
  - desligado: a foto direto.
- **Foto:** sempre entregue pelo `qr.php` (`&img=1`, `&baixar=1`), nunca pelo endereço da pasta. No obrigatório, só para o celular que se cadastrou no evento: o cookie `lead_<hash>` (por evento, `Path` do painel, HttpOnly, SameSite=Lax, 30 dias) tem o id do lead (16 hex, aleatório). Outra foto do mesmo evento abre direto e entra no mesmo lead (linha `"t":"foto"`).
- **Botões:** "Baixar foto" e "Compartilhar" (envia o arquivo pelo `navigator.share`, já baixado antes porque o iPhone exige; no iPhone aparece a dica "Salvar imagem").
- **Cores:** as do cliente (`cor_primaria`, a mesma do app). O texto do botão fica preto ou branco pelo contraste.
- **Proteções:**
  - evento conferido na lista do painel (fora da lixeira) e nome da foto conferido;
  - evento ou foto que não existe, ou foto na lixeira, mostra "Esta foto não está mais disponível" (404);
  - campo escondido contra robô de spam (`site`);
  - no máximo 120 cadastros por IP a cada 10 minutos (alto porque num evento todos estão na mesma rede; o IP não é guardado, só um hash com a janela);
  - `X-Frame-Options: DENY`, `noindex`, `Referrer-Policy: no-referrer`.
- **Prévia do painel:** `qr.php?teste=1&e=<evento>`, só por POST, logado e com csrf. Desenha o formulário que está na tela, sem salvar nada, e pode ficar dentro do painel (`SAMEORIGIN`).

**Dados:** `painel/leads_lib.php`
- Cada evento guarda seu `formulario_leads` na própria entrada de `config.eventos`. O QR Code usa o evento da foto, inclusive em links já emitidos. O formulário inclui `modo`, `titulo`, `texto`, `botao`, `aviso` e `campos` (`id`, `tipo`, `rotulo`, `obrigatorio`, e na múltipla escolha `opcoes` e `varias`). Se o evento ainda não tem formulário próprio, usa o legado de `config.leads` ou Nome e E-mail obrigatórios. Salvar o formulário mantém a versão de sincronização do app.
- Tipos: `nome`, `email`, `telefone` (um de cada, id = tipo) e `texto`, `numero`, `escolha` (id `c` + 6 hex, até 12 campos, até 20 opções).
- Conferências:
  - e-mail com `FILTER_VALIDATE_EMAIL`, guardado em minúsculas;
  - telefone do Brasil com DDD, guardado como "(11) 98765-4321" (aceita +55 e 0 da operadora; com "+" de outro país, "+números");
  - número como o brasileiro escreve ("1.234,5"), guardado como "1234.5";
  - na múltipla escolha, só as opções que existem, na ordem delas.
- Cadastros em `config/painel/leads/<cliente>/<evento>.php`: `<?php exit; ?>` e uma linha JSON por registro, só acrescentando:
  - `{"t":"lead","id","em","foto","valores","rotulos","tipos"}`, que guarda a pergunta e o tipo da hora do cadastro;
  - `{"t":"foto","id","em","foto"}`.
- Uma trava por cliente (`trava.lock`) para acrescentar e para excluir; excluir regrava num temporário e renomeia.
- Os leads de eventos apagados continuam na aba Leads com a etiqueta "apagado". Ao recriar um evento no mesmo dia com o mesmo nome, o painel reserva o ID antigo enquanto houver cadastros associados.

**Painel**
- **Menu:** "Configurar o app", "Fotos", "Leads". O formulário marca "Leads".
- **`painel/leads.php`:**
  - um botão por evento, com a quantidade de leads;
  - resumo do formulário do evento selecionado e botão "Editar o formulário";
  - busca sem acento em todas as respostas;
  - `&foto=<nome>` mostra só os leads de uma foto;
  - tabela com miniatura da foto (até 2, depois "+N"; "Foto excluída" se saiu), data, as respostas e e-mail clicável; 100 por página;
  - excluir marcados, com confirmação vermelha "são apagados PERMANENTEMENTE", e as fotos ficam;
  - botões "Baixar em Excel (.xlsx)" e "CSV", e "Baixar os leads de todos os eventos (.xlsx)".
- **`painel/leads_formulario.php?evento=<id>`:**
  - 1. Pedir cadastro? (3 opções);
  - 2. Campos: adicionar, ↑↓, remover, obrigatório; na múltipla escolha, opções uma por linha e "Pode marcar mais de uma" (caixas de marcar; sem isso, bolinhas);
  - 3. Textos: título, texto, botão e aviso LGPD;
  - prévia ao vivo do evento num celular ao lado, em iframe com sandbox, desenhada pelo próprio `qr.php`;
  - confere no navegador as mesmas regras do servidor e avisa ao sair sem salvar.
- **`painel/leads_baixar.php`:**
  - `.xlsx` montado na mão (XML + zip com `gzdeflate`, sem biblioteca, PHP 5.6):
    - data e hora como data do Excel (`dd/mm/aaaa hh:mm`);
    - número como número;
    - foto como link `HYPERLINK` "Abrir foto", para o endereço público da foto;
    - cabeçalho azul, primeira linha fixa e filtro;
  - CSV com `;` e BOM UTF-8;
  - texto que começa com `= + - @` ganha `'` na frente (fórmula do visitante não roda no Excel);
  - colunas: Data e hora, Evento, os campos de agora, os campos que saíram do formulário mas têm respostas, Foto e "Outras fotos" (se alguém abriu mais de uma).
  - no download de todos os eventos, campos com rótulos diferentes têm colunas próprias para preservar as perguntas de cada evento.
- **Galeria (`fotos.php`):** selo "👤 N" na miniatura da foto que tem cadastro. No visor aparecem "Cadastro: nomes" e o botão "👤 Ver cadastro" (`leads.php?evento=…&foto=…`).

**Publicação dos formulários por evento:** atualize juntos `cliente.php`, `lib.php`, `leads.php`, `leads_lib.php`, `leads_formulario.php`, `leads_baixar.php`, `qr.php` e `estilo.css` da pasta `clientes/painel/` na pasta `painel/` de cada cliente.

**Testes**
- e2e parte 17: 176/176 (a suíte inteira: 144 + 75 + 45 + 45 + 69 + 36 + 176, sem aviso do PHP). Cobre:
  - o upload devolve a página;
  - obrigatório, cadastro com erro e certo, cookie;
  - outra foto do mesmo celular e outro celular;
  - lista, busca, filtro por foto e galeria;
  - xlsx (aberto com ZipArchive + DOM) e CSV;
  - prévia e erros do formulário;
  - opcional com os 5 tipos de campo;
  - excluir lead;
  - vazio = Agora não;
  - desligado;
  - endereços inventados, lixeira e limite;
  - evento apagado de vez mantendo os leads (e o nome dele, guardado em `evento_nome` de cada cadastro).
- App: 32/32 (3 novos em `test/compartilhar_test.dart`).
- **Limite conhecido:** o arquivo da foto continua no endereço público da pasta (`<cliente>/fotos/eventos/<evento>/<foto>`), como antes. A página nunca mostra esse endereço, mas quem souber montar o caminho abre a foto sem cadastro.
- **APK 1.0.3:** `build/apk-evento/camerashot-1.0.3-leads.apk`, **instalado no robô `ZTS39P2201T0` em 24/11/2026** (licença mantida, abriu na tela inicial). Sem ele, o QR Code continua abrindo a página antiga (sem cadastro). Com ele e o servidor antigo (sem `pagina`), também continua na antiga, então dá para instalar antes ou depois de subir os arquivos.

### Cliente exclui fotos, com lixeira (23/11/2026, pedido do usuário)
Pedido: "O cliente tem que ter o poder de excluir as fotos caso queira". **Isso muda a regra anterior** de que só a equipe excluía. Escolhas do usuário:
- o cliente pode excluir fotos marcadas, um dia inteiro e todas do evento, e pode apagar um evento;
- em cada exclusão, ele escolhe entre lixeira e excluir de vez.
- **Galeria (`fotos.php`):**
  - Cada foto ganha uma caixa de marcar, e o clique na foto continua abrindo o visor. Com alguma marcada, aparece a barra "Excluir N fotos".
  - Cada dia tem "Marcar o dia" e "Excluir este dia". Acima das fotos ficam "Excluir todas do evento" e, só para evento encerrado, "Apagar o evento".
  - A confirmação tem três saídas: **Mover para a lixeira** (padrão; é também o que vale sem JavaScript), **Excluir de vez** (ou "Apagar de vez") e Cancelar. Ela usa o `painelConfirmar` com `alternativa` e o campo `modo` (`lixeira`/`definitivo`).
  - "Este dia", "todas" e "apagar o evento" só pegam fotos que já existiam quando a página abriu (`ate`, `painelNomesAte()`).
- **Lixeira:**
  - Os arquivos ficam em `config/painel/lixeira/<cliente>/<dia da exclusão>/<evento ou _sem-evento>/<foto>`, protegidos pelo `.htaccess` de `config/painel/`. As miniaturas ficam em `miniaturas/<cliente>/lixeira/...`.
  - A foto sai da pasta pública, então o QR Code dá 404. Restaurando, ele volta a abrir.
  - Some sozinha depois de `PAINEL_LIXEIRA_DIAS` = 30 dias. A limpeza (`painelLimparLixeira()`) roda ao abrir `fotos.php`, `lixeira.php` e `equipe.php`.
  - Enquanto está na lixeira, conta no espaço: a barra mostra "mais X na lixeira (esvazie a lixeira para liberar esse espaço)", e o `licUsoPainel` da central também soma.
- **Página Lixeira (`lixeira.php`, botão "🗑 Lixeira (N)" na galeria):**
  - Agrupa por dia da exclusão e por evento, e mostra "somem em …".
  - Ações: marcar e restaurar, marcar e excluir de vez (com confirmação), Restaurar todas e Esvaziar a lixeira.
  - Os formulários mandam a chave `dia/pasta/nome`, sempre conferida contra a lista real (`painelItensLixeiraPorChave()`).
  - `foto.php?…&lixeira=<dia>&ev=<pasta>` mostra a foto da lixeira; o dia e a pasta são conferidos pelo formato.
- **Apagar o evento (só encerrado):**
  - **Para a lixeira** (`painelEventoParaLixeira()`): as fotos vão para a lixeira e o evento ganha `apagado_em`. Ele some das listas (`painelEventos()` sem `$comApagados`) e volta se alguma foto for restaurada. Quando a lixeira dele esvazia, `painelRemoverEventosApagados()` tira o evento de vez.
  - **De vez** (`painelApagarEventoDeVez()`, usado também pela equipe): apaga as fotos, a pasta, o que dele estiver na lixeira e o evento da lista.
- **Defeito corrigido:** o "Apagar o evento" da equipe falhava ("a pasta do evento tem outros arquivos") em todo evento que tinha recebido foto, por causa do `index.html` que o `uploadImage.php` deixa em cada pasta. `painelApagarPastaEvento()` agora tira esse `index.html` vazio antes.
- **Último encerrado:** `encerrado_ordem` (microtime) desempata dois eventos encerrados no mesmo segundo.
- **Registro:** `exclusoes.log` anota quem excluiu (`cliente` ou `equipe`), a ação e as quantidades (`painelAnotarExclusao()`).
- **Testes:** e2e parte 15 (69/69). Cobre:
  - marcada para a lixeira, QR 404, miniatura, `../` e outro cliente recusados;
  - restaurar, com o QR voltando a abrir;
  - de vez; sem modo (vai para a lixeira);
  - dia, todas, esvaziar;
  - apagar o evento para a lixeira e restaurar (o evento volta), e apagar de vez com o `index.html`;
  - a equipe apagando um evento com foto;
  - "Sem evento";
  - os 30 dias, que levam também o evento apagado;
  - a central somando a lixeira.

  As partes anteriores continuam 144 + 75 + 45 + 45.
### Fotos de cada cliente em `fotos/` (18/11/2026, pedido do usuário)

- **Padrão:** as fotos de cada cliente ficam em `buddyApp/<cliente>/fotos/` (e `fotos/eventos/<evento>/`). Os arquivos do painel ficam separados delas.
  - O painel novo da central já nasce com `'pasta_fotos' => 'fotos'` (`LIC_PASTA_FOTOS`, `licGravarConfigPainel()`).
  - `fotos` passou a ser nome reservado de pasta de cliente.
- **Migração:** o "Atualizar os arquivos do painel" chama `licMigrarFotos()`. Se o painel tem `pasta_fotos` vazia, ele:
  1. move as fotos soltas (`jpg`, `jpeg`, `png`, `webp`) e a pasta `eventos/` para `fotos/`;
  2. só no fim, grava `pasta_fotos = 'fotos'` no config do cliente, mantendo logins e senhas.

  Painel que já usa uma pasta fica como está, então dá para rodar de novo sem risco.
- **QR Codes antigos:** os QR Codes de fotos de antes da migração deixam de abrir, porque a foto mudou de lugar. Não usei redirecionamento por `.htaccess` (`mod_rewrite`): se o servidor não permitir, a pasta inteira do cliente daria erro 500. Na data, só o clienteteste00 tinha fotos, e eram de teste.
- **O servidor decide a pasta:** o `uploadImage.php` usa o `pasta_fotos` do `config/painel.local.php` dele (`uploadPastaDoPainel()`) e ignora a pasta que o robô manda. Assim, mudar a pasta vale na hora, sem sincronizar. Se o config não tem a chave, vale a pasta enviada (comportamento antigo).
- **Privacidade:** o `uploadImage.php` põe um `index.html` em branco na pasta de cada foto e nas pastas acima dela (`uploadProtegerPasta()`), para ninguém listar as fotos pelo navegador.
- **Painel principal** (`buddyApp/`, sem licença de cliente): continua com as fotos na raiz (`pasta_fotos` vazia).
- **Testado:** a seção 10 do roteiro (106 de 106 no total) cobriu:
  - config novo;
  - `index.html` nas pastas;
  - pasta do robô ignorada;
  - migração de painel antigo (fotos soltas e eventos);
  - logins e senhas mantidos;
  - galeria mostrando as fotos;
  - rodar a migração de novo sem efeito.

### Garantia de que cada login entra só no próprio painel (18/11/2026)

- **Como é garantido:**
  1. O destino do login sai da licença do usuário, gravada na central. O navegador não escolhe: parâmetros extras como `painel` ou `destino` são ignorados.
  2. O passe é assinado e vale para um painel só; o `entrar.php` confere se o passe é dele.
  3. A sessão é separada por painel: o cookie tem nome próprio e vale só no caminho do painel. Um cookie com formato inválido é descartado antes do `session_start` (`painelIniciarSessao()`).
  4. **Uma licença por painel:** `licLicencaDoPainel()` recusa criar ou editar uma licença com o painel de outra. A comparação é a mesma do painel: sem http/https, `www.`, porta nem diferença de maiúsculas. Antes era possível cadastrar a mesma pasta para dois clientes, e os dois entrariam no mesmo painel.
- **Testado:** a seção 9 do roteiro (21 verificações) tentou burlar de todas as formas:
  - usar o passe do A no painel B e no principal;
  - usar a sessão do A no B (cliente, fotos, zip e foto);
  - forçar o cookie do A no painel B;
  - mandar parâmetros extras no login;
  - cadastrar a pasta do A em outra licença (também com maiúsculas e espaço);
  - mudar o B para a pasta do A;
  - criar duas licenças no painel principal.

  Tudo foi recusado. A suíte completa dá 92 de 92, sem aviso do PHP.
- **App:** o nome é "Futuremedia Fotos" (`android:label` e `MaterialApp.title`). O ícone é o oficial (`Logos/icon.png` do manual, 1009 px) nos 5 `mipmap-*/ic_launcher.png`. A tela de apps do robô corta o nome em "Future-media Fo…".

### Código da licença no painel do cliente (18/11/2026, pedido do usuário)

- **Onde:** o `cliente.php` tem o cartão "Licença do robô" (`#licenca`, também no mapa), depois da dica "Como atualizar o robô".
- **O que mostra:**
  - o código `FM-XXXX-XXXX` com "Copiar" (handler genérico `data-copiar` no `painelRodape()`);
  - a situação (ativa, vencida ou suspensa, com aviso vermelho quando não está ativa);
  - a validade;
  - "N de M em uso";
  - os passos para ativar.
- **De onde vêm os dados:** `licencasDestePainel(1)` em `painel/licenca.php` lê a central (`licencaLerCentral()`, a mesma usada pela conferência na hora) e compara o painel da licença com o endereço deste painel (`licencaChaveUrl`). Cada cliente só vê a licença do painel dele. Sem licença, o cartão diz "Este painel ainda não tem licença. Fale com a Futuremedia".
- **Testado (local):**
  - o cliente vê o código dele, "Ativa", "Sem vencimento" e "1 de 1 em uso";
  - o outro cliente vê só o código dele;
  - o painel principal sem licença mostra o aviso;
  - a suíte do servidor continua em 71 de 71.

### Bloqueio na hora (18/11/2026, pedido do usuário)

- **Regra:** desvincular pelo app ou bloquear pela central (suspender, desvincular o robô, excluir, vencer) deixa o app **travado na tela de ativação**, com o motivo.
- **Desvincular no app** (`Licenca.desvincular()`): trava sempre, com `motivoDesvinculado`. A central é avisada para liberar a vaga (10 s de limite). Sem internet, trava do mesmo jeito e a vaga continua com o robô; ativar de novo com o mesmo código não gasta outra vaga.
- **App:** confere a licença na central a cada 1 minuto (`conferirACada`), via `renovarSeNecessario()`, que é chamado:
  - ao abrir;
  - ao voltar para a `Tela1`;
  - num `Timer.periodic` de 30 s na `Tela1`;
  - quando o app volta ao primeiro plano.

  Recusa definitiva (`suspensa`, `desvinculado`, `codigo`, `expirada`) trava. Falta de internet ou erro 500 não trava.
- **Painel:** `licencaSituacaoNaCentral()` em `painel/licenca.php` lê `licencas/dados/licencas.php` (procura em `buddyApp/licencas/` a partir do painel principal ou de `buddyApp/<cliente>/`). Recusa na hora, sem esperar o token vencer:
  - licença excluída;
  - licença suspensa;
  - robô fora da lista.

  Vale para o sync e o upload quando `exigir_licenca` está ligado.
- **Testes:**
  - `test/licenca_bloqueio_test.dart`, com a central simulada (`http.runWithClient` + `MockClient`): 9 casos;
  - o app tem 15 de 15 testes passando;
  - servidor: 71 de 71 verificações (seção 8 do roteiro: bloqueio na hora pelo painel).
- **Painéis de clientes** (`buddyApp/<pasta>/`) têm a própria cópia do `painel/licenca.php`. Depois de subir o principal, use "Atualizar os arquivos do painel" em cada licença.

### Desvincular pelo robô, teclado novo e teste de ponta a ponta (18/11/2026)

- **Desvincular (tela de admin):** o link discreto "Desvincular este robô" chama `Licenca.desvincular()`.
  - O app manda `acao=desvincular` e `token` para `licencas/api/ativar.php`. A central confere a assinatura com `licLerAssinado()` (aceita token vencido) e tira o robô da licença.
  - Depois disso, o app apaga a licença e o visual (`esquecerPainel`) e volta para a ativação.
  - Sem internet, o pedido é recusado com um aviso, e o robô continua licenciado.
- **Teclado da licença (`tela_licenca.dart`):**
  - o campo e os botões ficam em cima;
  - o teclado completo fica embaixo, com `1234567890` e as letras QWERTY, apagar e "Ativar" (3 teclas de largura);
  - as teclas usam o `TeclaToque`, e o `DialogoPin` do PIN não mudou;
  - com 0, O, 1, I ou L, `Licenca.ativar()` responde "Código não encontrado".
- **Admin, linha "Validade":** com a licença bloqueada, mostra "Bloqueada" e o motivo. Antes mostrava "Conecte… até 01/01/2000"; o teste de ponta a ponta achou o problema e ele foi corrigido.
- **Testes automáticos do app:**
  - `test/licenca_test.dart`: assinatura do PHP, token de outro robô e token alterado;
  - `test/widget_test.dart`: tela de ativação com todas as teclas e a digitação. Substitui o teste de contador do modelo do Flutter, que nunca serviu para este app.
- **Teste de ponta a ponta (18/11/2026), servidor:** 63 de 63 verificações (roteiro com `curl`), cobrindo:
  - central;
  - criação automática do painel;
  - login único;
  - cor, PIN, moldura e evento no painel;
  - ativação, sync, upload no evento, galeria, miniatura, zip e área da equipe;
  - suspensão e vencimento;
  - desvincular;
  - isolamento entre clientes;
  - arquivos protegidos.
- **Teste de ponta a ponta, robô** (APK de teste e `adb reverse`):
  - código errado e ativação;
  - visual do cliente;
  - foto com moldura enviada para a pasta do evento, com o QR Code certo;
  - PIN do cliente;
  - sync com mudança de cor;
  - trocar licença para outro cliente (o visual anterior foi descartado);
  - suspensão travando e "Tentar de novo" liberando;
  - funcionando sem servidor;
  - desvincular com e sem internet.
- **Relógio do robô ZTS39P2201T0:** cerca de 8 horas adiantado. Ligue "Data e hora automáticas" nas Definições do robô. A licença conta o prazo a partir do recebimento, então não é afetada.

### Um painel por cliente, criado pela central (18/11/2026)

- **Pastas:** cada licença tem uma pasta dentro de `buddyApp/` (`clienteteste01` → `buddyApp/clienteteste01/`). Em branco, usa o painel principal (`buddyApp/`).
- **Criação automática:** ao criar a licença, ou ao trocar a pasta, a central cria o painel do cliente se ele não existir (`licInstalarPainel()` em `licencas/lib.php`).
  - **Modelo:** o painel principal. Copia `painel/` inteira, menos `painel/midia/<id>/` (as mídias do principal), e os arquivos de `licArquivosAvulsosPainel()`: `uploadImage.php`, `index.html`, `config/index.html` e os `.htaccess`/`index.html` de `config/painel/`.
  - **Nunca copia** os dados, as miniaturas, os eventos nem as fotos do principal.
  - **Config:** gera o `config/painel.local.php` do cliente, com:
    - `nome` = o cliente;
    - `pasta_fotos` vazia, então as fotos ficam em `buddyApp/<pasta>/`;
    - `exigir_licenca` = true;
    - `login_unico`;
    - o login da equipe igual ao do principal;
    - uma senha local aleatória (a equipe entra pelo "Abrir o painel do cliente").
- **Página da licença:**
  - **"Criar o painel agora"** aparece quando a pasta não tem painel.
  - **"Atualizar os arquivos do painel"** copia a versão nova do código do principal e mantém o config e os dados do cliente. Use depois de atualizar o painel principal.
- **Sessão por painel:** `painelIniciarSessao()` usa um cookie com nome próprio (`painelfoto` + hash de `PAINEL_RAIZ`), que vale só no caminho daquele painel. Antes, a sessão era a mesma para todos, e quem entrava no painel de um cliente abria o principal. O teste de 18/11 achou esse problema, e ele foi corrigido.
- **Excluir a licença não apaga a pasta do cliente**, para as fotos não se perderem.
- **Testado (local):**
  - criar a licença com pasta (o painel é criado e as mídias do principal não vão junto);
  - login único entrando no painel do cliente, com o nome certo;
  - robô ativado sincronizando e enviando foto para `buddyApp/<pasta>/`;
  - sessão de um cliente sem abrir o principal;
  - "Atualizar" e "Criar o painel agora".

### Trajeto e editor de telas (30/10/2026)

O painel abre com o **trajeto do app**: as telas na ordem, com o destino de cada botão. Cada tela tem uma seção com um **editor**.

| Nº | Tela | Imagem da tela | Elementos que se movem |
|---|---|---|---|
| 1 | Início | `tela_inicio` | Iniciar → 2, Sair → fecha o app |
| 2 | Termos de uso | `tela_termos` | Não aceito → 2a, Aceito → 3 |
| 2a | Aviso "Não aceito" | `tela_recusa` | Não tirar foto → 1, Voltar para Termos → 2 |
| 3 | Câmera | (câmera + moldura) | Tirar foto → 4, Balão, Moldura → 3a (só com 2+ molduras), Início → 1 |
| 3a | Escolha da moldura | fixa | (nada) |
| 4 | Revisão | (foto + moldura) | Salvar → 5, Tirar nova foto → 3 |
| 5 | Processando | vídeo | (nada) |
| 6 | Final | `tela_final` | Foto, QR Code, Finalizar → 1 |

- **Uma imagem por tela:** o fundo e os textos vêm juntos, numa imagem 1920x1080. Isso substituiu o fundo, o logo, o texto dos termos, o aviso e a arte do QR Code, que eram imagens separadas. As imagens padrão foram montadas com essas peças antigas, nas mesmas posições.
- **Botões:** podem receber uma imagem (PNG transparente). Sem imagem, o app desenha o botão padrão na cor principal.
- **Editor:**
  - Arraste para mover, puxe o canto ◢ para mudar o tamanho, ou digite os valores em %.
  - A âncora é o **meio** do elemento (mudança de 30/10/2026): os campos "Horizontal" e "Vertical" (antes "Esquerda" e "Topo") medem o meio, então 50% e 50% é o centro da tela. Mudar o tamanho, pelo canto ◢ ou pelos campos, cresce para os dois lados sem tirar o meio do lugar. O elemento selecionado mostra uma mira no meio.
  - Isso é só na tela do editor (`editor.js`). O que se guarda e o que o app lê continua sendo o canto superior esquerdo (`x`, `y`), então o app não mudou.
  - **Câmera com 1 moldura:** o botão Moldura some, e o app fecha o espaço dele (`AppConfig.retanguloCamera`). O botão de depois (Início) sobe para o lugar da Moldura, os dois são centralizados no espaço que o grupo ocupava, e o balão acompanha o Tirar foto. Com o padrão, Tirar foto vai de 12–40% para 24–52% da altura, e Início de 66–88% para 54–76%. A conta só acontece se a Moldura estiver entre os dois botões (na vertical ou na horizontal). O editor tem a chave "Prévia: Com 2 ou mais molduras / Com 1 moldura", que faz a mesma conta (`semMoldura` em `editor.js`). A prévia é só para ver; o que se salva é sempre a posição com 2 ou mais molduras.
  - Mostra o centro e a área secreta do admin.
  - A caixa de cada elemento usa a classe `el-tipo-<tipo>`. Antes era `el-<tipo>`, que pegava sem querer o estilo de `.el-botao`/`.el-foto` (`overflow: hidden`), cortava metade da alça ◢ e o nome do elemento, e punha fundo branco atrás da imagem do botão.
  - Elementos com imagem, a foto (16:9) e o QR Code (quadrado) mantêm o formato.
  - Ao enviar uma imagem para um botão, a caixa dele se ajusta ao formato da imagem.
- **Posições:** em frações da tela (`x`, `y`, `w`, `h`). São gravadas em `config.layout` e enviadas no `sync.php` em `layout`. Os padrões ficam em `painelTelas()` (PHP) e `AppConfig.layoutPadrao` (Dart), que precisam ficar iguais.
  - **Ao corrigir um padrão**, ponha o valor antigo em `painelPadroesAntigos()` (`lib.php`). Salvar uma tela no editor grava todos os elementos dela, mesmo os não mexidos; com isso, quem ficou com o valor antigo salvo passa a usar o novo. Quem moveu o elemento de propósito mantém a posição.
  - Exemplo: o QR Code da tela final passou de `0.77, 0.2422` para `0.75, 0.2444` (mesmo tamanho). O centro dele ficou alinhado com os textos "Obrigado por participar!" e "Escaneie e pegue sua foto!" da `tela_final.jpg` padrão (x ≈ 1593 px) e no meio do espaço entre eles. Antes estava 38 px para a direita.
- **Configurações antigas:** imagens enviadas nos itens antigos (`background`, `capa`, `termos`, `confirmacao`, `qrcode`, legendas) deixaram de ser usadas e saem da configuração na próxima alteração salva no painel.

### O que mais o cliente pode trocar

| Item no painel | Onde aparece no app | Padrão do APK |
|---|---|---|
| Molduras (várias, com ordem) | Câmera, revisão, foto final | `moldura1.png` |
| Balão (imagem e posição) | Câmera | `balao_img2.png` |
| Vídeo de carregamento (MP4) | Processando | `buddy-animacao.mp4` |
| Cor principal | Botões sem imagem e textos | `#0b3675` |
| PIN da tela de admin | Acesso ao admin | `1234` |

A pasta das fotos **não** é trocada pelo painel. Ela vem de `config/painel.local.php` (`pasta_fotos`), e os robôs pegam a mudança na próxima sincronização.

---

## 4. Publicar no servidor

Copie **o conteúdo** de `clientes/` (menos este `.md`) para `clienteFotos/buddyApp/` e aceite substituir os arquivos.

```
clientes/                         → dentro de clienteFotos/buddyApp/
├── index.html                    página em branco (o navegador não lista as fotos)
├── uploadImage.php               recebe as fotos do robô; pasta vazia = salva aqui mesmo
├── config/
│   ├── index.html                página em branco
│   ├── painel.local.php          login, nome do cliente e pasta das fotos
│   └── painel/
│       ├── .htaccess             bloqueia o acesso pelo navegador (403)
│       ├── index.html
│       └── clientes/index.html
└── painel/
    ├── .user.ini                 aumenta o limite de upload para 64 MB (vídeo)
    ├── index.php                 login
    ├── cliente.php               o painel em si (trajeto + uma seção por tela)
    ├── fotos.php                 galeria das fotos tiradas
    ├── foto.php                  entrega uma foto (inteira, miniatura ou download), só com login
    ├── fotos_zip.php             baixa as fotos num .zip (todas ou de um dia)
    ├── equipe.php                área da equipe: excluir fotos (login próprio, o cliente não acessa)
    ├── editor.js                 editor de arrastar e redimensionar os botões
    ├── lib.php                   funções comuns, telas e elementos (painelTelas) e arquivos (painelSlots)
    ├── estilo.css
    ├── api/sync.php              endpoint do app
    ├── marca/                    futuremedia.png (logo do topo) e icone-32/180/192.png (ícone do site,
    │                             gerado do icon.png oficial do manual de marca) + index.html
    ├── midia/index.html          (as mídias enviadas ficam em midia/<id>/)
    └── padrao/                   imagens padrão do APK (4 telas, balão, moldura) para a prévia + index.html
```

- **Não suba este `CONTEXTO_DO_PROJETO.md` para o servidor.** Ele fica nesta pasta, mas descreve a área da equipe, que o cliente não deve conhecer. Em 03/11/2026 ele não estava no servidor (404).
- **Colar por cima não apaga nada do que foi feito no painel.** Os dados do cliente ficam em `config/painel/clientes/<id>.php` e as mídias em `painel/midia/<id>/`, e nenhum desses arquivos está na pasta.
- **Ao colar, o `painel.local.php` substitui o do servidor.** Se a senha foi trocada no servidor, coloque a mesma senha no arquivo local antes de colar.
- O PHP precisa ter permissão de **gravar** em `config/painel/`, em `painel/midia/` e na própria `buddyApp/` (as fotos).
- O `.user.ini` só funciona em PHP-FPM/CGI. Se o painel mostrar um limite menor ("Limite por arquivo: …"), ajuste `upload_max_filesize` e `post_max_size` na hospedagem.
- O código é compatível com PHP 5.6.
- **Visual do painel** (`estilo.css`, tokens no `:root`): segue a **ID visual da Futuremedia** (manual em `https://roboparaeventos.com.br/manual-marca-futuremedia/`).
  - **Cores:** Azul Institucional `#174786` (`--cor`, principal), Ciano `#008BCC` (`--ciano`, apoio: foco, anel do mapa), Grafite Digital `#2E2F35` (`--texto`), Off-white Tecnológico `#F1EFEB` (`--fundo`) e o degradê do logo, do azul ao ciano (`--degrade`: linha de baixo do topo e número da etapa atual no mapa). O manual pede para não inventar cores, então o painel não usa nada fora disso. As exceções são as cores funcionais (verde/vermelho de aviso) e o amarelo de seleção do editor, que é ferramenta e não marca.
  - **Fonte:** Roboto (Google Fonts, carregada no `painelTopo()`): Black (900) no título da página, Bold nos outros títulos, Regular/Medium no texto.
  - **Topo:** branco, com o logo horizontal colorido (`painel/marca/futuremedia.png`, a versão positiva do manual, com 152 px, acima do mínimo de 120 px), "Painel do App de Fotos" e o menu (`painelMenu()`: "Configurar o app", "Fotos" e "Sair"; a página atual fica marcada com `aria-current`). No celular, o menu desce para uma segunda linha.
  - A prévia das telas do app (`.palco`) fica com a fonte do sistema, e os botões dela usam a cor do cliente (`--cor-app`), então o visual do app não muda com o do painel.

### Situação do servidor antes do envio

- O painel e o `sync.php` já respondem em `clienteFotos/buddyApp/painel/`.
- `config/painel/` já devolve 403 (protegido).
- O servidor ainda devolvia `pasta_fotos: "clienteFotos/buddyApp"` e provavelmente tem o `uploadImage.php` antigo. **Enquanto os arquivos novos não forem enviados, as fotos podem ir para o lugar errado.**
- A raiz de `buddyApp/` e a pasta `config/` mostravam a lista de arquivos. Os `index.html` em branco resolvem isso.

---

## 5. Arquivos do projeto

### Servidor

- **Fonte:** `servidor/appsBuddy/appFoto/`. A pasta `clientes/` é uma cópia dela, pronta para subir. **Ao alterar um arquivo do painel, atualize as duas.**
- **`uploadImage.php`**
  - Funciona sozinho, sem o `eventRegistry.php`, que não existe mais no servidor e causava erro 500.
  - Toda alteração está marcada com `// ==== CÓDIGO ORIGINAL ====` / `// ==== CÓDIGO ALTERADO ====`. Para voltar ao modo com eventos, siga os comentários do arquivo.
  - Sobre o campo `pasta`:
    - sem o campo `pasta`, responde 400;
    - `pasta` vazia salva na própria pasta do script;
    - aceita subpastas com `/`;
    - cada parte só pode ter `[a-zA-Z0-9_-]`, então `..` não passa.
  - O nome do arquivo é `photo_<AAAA-MM-DD_HH-MM-SS>_<8 hex>.<ext>`. Aceita jpg, jpeg, png e webp.
- **`painel/lib.php`**
  - `painelConfigLocal()` lê `config/painel.local.php` e valida `pasta_fotos`: vazia, ou até 5 partes de `[a-zA-Z0-9_-]`, e a primeira parte não pode ser uma pasta reservada.
  - `painelClienteUnico()` cria o cadastro sozinho no primeiro acesso (com lock em `criar.lock`). Se o `nome` ou a `pasta_fotos` mudarem no arquivo local, ele atualiza o cadastro e aumenta a versão.
  - `painelLogado()` / `painelClienteDaPagina()` cuidam da sessão. Sem login, a pessoa é mandada para o login.
  - No rodapé (`painelRodape()`), o modal de confirmação (`<dialog id="confirmacao">`) substitui o `confirm()` do navegador. Use `painelConfirmar({titulo, texto, botao, perigo}, aoConfirmar)` no JS. Num formulário, basta pôr `data-confirmar="texto"` e, se quiser, `data-confirmar-titulo`, `data-confirmar-botao` e `data-confirmar-perigo` (botão vermelho). O envio é segurado e refeito com `requestSubmit()` depois do "sim", para o `editor.js` ainda pôr as posições no formulário. O único aviso nativo que sobra é o "Sair do site? Há mudanças não salvas", que o navegador não deixa trocar.
- **`painel/index.php`**: o login compara com `painelConfigLocal()` usando `hash_equals`. Se errar, espera 1 s.
- **`painel/cliente.php`**
  - No topo, um bloco de dica ("💡 Dica", classe `.dica`) com os passos "Como atualizar o robô". O contador da versão da configuração saiu do painel (a versão continua existindo e aparece na tela de admin do robô).
  - O trajeto fica em uma linha só (as telas principais), com os ramos (2a, 3a) embaixo da tela de onde saem. Em telas estreitas, a linha rola para o lado.
  - **Mapa do app** (`<nav class="mapa">`, no fim do `cliente.php`): fixo na lateral direita, com Trajeto, as telas (na ordem de `painelTelas()`, mesma miniatura do trajeto) e "Cor e acesso".
    - Um script marca a etapa na tela: a última seção cujo topo passou de 30% da altura da janela (no fim da página, a última). A atual fica azul com anel amarelo e `aria-current="step"`, e as anteriores ficam "percorridas" (a linha entre os números vai enchendo de azul).
    - A partir de 1640 px de largura, mostra miniatura e nome. De 1290 a 1639 px, só os números (o nome aparece no `title`). Abaixo de 1290 px, some (o trajeto do topo continua).
    - Tocar num item rola suave até a seção (`scroll-behavior: smooth`, desligado com "reduzir movimento").
  - Tem as seções de mídias, molduras, cor e PIN. O topo tem os links "Fotos" e "Sair", e o cabeçalho tem o botão "📷 Ver fotos tiradas (N)".
- **Eventos** (cartão "Evento atual" no topo do `cliente.php`, `#evento`, também no mapa)
  - **O que o cliente faz:** cria um evento ("Novo evento" → "Criar e usar") ou volta para um antigo ("Voltar para um evento"). Nome repetido é recusado. O cliente não apaga eventos; isso é na área da equipe.
  - **Dados:** `config.eventos` (`id`, `nome`, `criado_em`) e `config.evento_atual` no arquivo do cliente. `id` = data de criação + nome sem acentos (`2026-11-09-casamento-ana-pedro`, `-2` se já existir). Trocar o evento **não** sobe a `versao` da configuração, porque o robô não precisa saber.
  - **Pastas:** `<pasta das fotos>/eventos/<id>/`. Desde 23/11/2026 **não há foto sem evento**: sem evento atual, o `uploadImage.php` recusa (ver "Fotos só com evento"), salvo nos 5 minutos depois de encerrar (ver "Encerrar e reabrir evento"). As fotos de antes dos eventos continuam na própria pasta das fotos ("Sem evento" na galeria).
  - **O servidor escolhe a pasta a cada envio.** O `uploadImage.php` lê os dados do cliente e usa o evento vinculado ao robô ou o evento padrão. O `filePath` e o QR Code da resposta apontam para a pasta escolhida. O arquivo `config/painel/evento_atual.php` resume o padrão e os vínculos ativos para a Central de Licenças.
- **Armazenamento:** barra "X de 5 GB" no topo da galeria e da área da equipe (`painelBarraArmazenamento()`), somando todas as fotos: sem evento + todas as pastas em `eventos/`, até as que saíram da lista (`painelUsoFotos()`). O limite é `limite_gb` em `config/painel.local.php` (padrão 5). A partir de 80% fica amarela ("Está quase cheio"), e a partir de 100% fica vermelha ("O limite foi atingido"). **É só aviso:** o `uploadImage.php` continua aceitando fotos, para não parar o robô no meio de um evento.
- **Galeria de fotos** (`fotos.php`, `foto.php`, `fotos_zip.php`)
  - **Por evento:** `?evento=<id>` (`-` = sem evento; sem nada, o evento atual). Os botões de evento (`painelFiltroEventos()`) mostram a quantidade de fotos e marcam o atual. `foto.php` recebe `&ev=<id>` e `fotos_zip.php` recebe `&evento=<id>`. O evento sempre é conferido na lista do painel (`painelEvento()`), então o endereço nunca escolhe uma pasta: `ev=../../config` dá 404. As miniaturas ficam em `config/painel/miniaturas/<cliente>/eventos/<id>/`.
  - Lê a pasta onde o `uploadImage.php` grava (`painelPastaFotos($cliente, $evento)` = pasta do `uploadImage.php` + `pasta_fotos` + `eventos/<id>`). `painelFotos()` lista só imagens (jpg, jpeg, png, webp), da mais nova para a mais antiga. A data e a hora vêm do nome (`photo_AAAA-MM-DD_HH-MM-SS_…`); outros nomes usam a data do arquivo. Se a `pasta_fotos` mudar, a galeria passa a mostrar só a pasta nova.
  - `fotos.php`: 60 fotos por página, separadas por dia ("Hoje", "Ontem", data), com total de fotos e MB. Botões "Baixar todas (.zip)" e "Baixar este dia (.zip)". Tocar numa foto abre o visor (`<dialog id="visor">`), com ←/→ (também pelas setas do teclado), Baixar e Fechar.
  - `foto.php?f=<nome>` (`&mini=1` miniatura, `&baixar=1` download): exige login, valida o nome (sem `/`, sem `..`) e libera a sessão logo (`session_write_close`), para as dezenas de miniaturas carregarem em paralelo. A miniatura (480 px, JPEG 80) é feita com GD e guardada em `config/painel/miniaturas/<id>/` (protegida pelo `.htaccess`). Sem GD, manda a foto inteira.
  - `fotos_zip.php` (`?dia=AAAA-MM-DD` opcional): monta o .zip **enquanto envia**, sem arquivo temporário e sem `ZipArchive`. As fotos vão sem compressão (JPEG não comprime mais), então o tamanho é calculado antes (`Content-Length`) e o navegador mostra o progresso. "Todas" organiza em `fotos/<dia>/`. Passando de 4 GB ou 65.535 fotos, usa ZIP64. Os números são escritos byte a byte (`zipU32`/`zipU64`), então funciona até em PHP de 32 bits.
  - Testado: .zip normal e ZIP64 real (4,7 GB) abertos pelo tar do Windows e pelo `System.IO.Compression` (o mesmo do Explorer), com conteúdo idêntico e datas certas.
- **Área da equipe** (`painel/equipe.php`): a equipe Futuremedia exclui fotos sempre de vez. Desde 23/11/2026 o cliente também exclui, pela galeria, escolhendo entre lixeira e "de vez" (ver "Cliente exclui fotos, com lixeira").
  - **Acesso:** login próprio, `usuario_equipe` e `senha_equipe` em `config/painel.local.php` (a senha fica só nesse arquivo). O login do cliente não vale aqui, e o login da equipe não abre o painel do cliente. Nenhuma página do cliente, nem o CSS, cita esse endereço: **não coloque link nem comentário citando-o em nada que o navegador receba**. Sem o login configurado, ou com a mesma senha do cliente, responde 404. A sessão fecha depois de 30 minutos sem uso.
  - **Por evento:** os mesmos botões de evento da galeria. Tudo o que se exclui é do evento escolhido. **"Apagar o evento"** (só para eventos que não são o atual) exclui as fotos, apaga a pasta e as miniaturas e tira o evento da lista do cliente. Se chegou foto depois de a página abrir, o evento não é apagado.
  - **Excluir:** fotos marcadas, um dia inteiro ou todas, sempre com a confirmação vermelha. Cada dia e o topo têm também o download .zip (`foto.php` e `fotos_zip.php` atendem as duas sessões).
  - **Proteções:** `painelApagarFotos()` só apaga o que `painelNomeFotoValido()` aceita, dentro da pasta das fotos, e também as miniaturas. "Este dia" e "Todas" levam o horário em que a página abriu (`ate`): foto que chegou depois não é apagada. Cada exclusão é anotada em `config/painel/exclusoes.log` (data, tipo, quantidade).
  - **É definitivo:** a foto sai do servidor e da galeria do cliente, e o QR Code dela para de funcionar.
- **`painel/api/sync.php`**: veja a seção 3.
- **`painel/admin.php`**: foi apagado (era a parte de vários clientes).

### App (`lib/`)

- **`app_config.dart`**
  - `servidorPadrao` = `https://suafoto.futuremedia.com.br/clienteFotos/buddyApp/`
  - `paginaCompartilhar` = `https://suafoto.futuremedia.com.br/appsBuddy/appFoto/camerashot.html`
  - `pastaFotosPadrao` = `''`
  - `servidor` é o painel da licença (`Licenca.instance.painel`). O `servidorPadrao` só vale sem licença, e aí o app não passa da ativação.
  - `cabecalhoLicenca` (`X-Licenca`) vai no sync e no envio das fotos.
  - `vinculo.json` guarda só o `dispositivoId`. A licença fica em `config_remota/licenca.json` (`codigo`, `token`, `recebido_em`, `motivo`).
  - `sincronizar({progresso})` mostra mensagens claras de erro: sem conexão, demora do servidor, HTTP 404 (painel não encontrado).
  - `urlCompartilhar(imagem)` monta `paginaCompartilhar?imagem=<url completa codificada>`.
  - O helper `Midia` usa o arquivo baixado ou, se faltar, o asset do APK.
  - Foram removidos: código, `vinculado`, `desvincular()` e o servidor configurável.
- **`elementos_tela.dart`**
  - `ElementoTela` põe qualquer widget no retângulo definido no painel. O parâmetro `retangulo` troca esse retângulo por outro já calculado (ex.: `retanguloCamera`).
  - `BotaoTela` mostra a imagem que o cliente enviou ou, sem imagem, o botão padrão (texto, ou ícone + texto).
  - No botão padrão, os tamanhos saem da caixa do botão, iguais aos da prévia do painel: ícone com 50% do menor lado, legenda com 13% da altura, texto sozinho com 50% da altura. O `FittedBox(scaleDown)` só diminui, se o texto não couber. Antes era `FittedBox(contain)` com ícone 48 e texto 14 fixos, e dentro do `ElevatedButton` o conteúdo ficava minúsculo em botões grandes.
- **`admin.dart`**: é o cliente quem vê esta tela, então ela **não mostra** endereços do servidor, pasta das fotos, ID do robô nem a versão da configuração. As mensagens de erro da sincronização (`AppConfig.sincronizar`) também não trazem endereços: o detalhe técnico só vai para o log (`print`).
  - Coluna da esquerda:
    - "Sincronizar com o painel": o botão **Sincronizar**, o status e, depois de sincronizar, o resumo do que mudou (`AppConfig.ultimoResumo`, montado por `_resumirMudancas`: molduras novas/removidas/ordem, itens trocados ou de volta ao padrão, posições, cor, PIN; ou "Nada mudou desde a última sincronização").
    - "Conexão": Internet (`https://www.gstatic.com/generate_204` responde 204) e Envio de fotos (um GET no `uploadImage.php` devolve JSON; ele responde 405 sem gravar nada). Roda ao abrir a tela, depois de sincronizar e no botão "Testar de novo". Se falhar, explica o que fazer.
  - Coluna da direita:
    - "Situação": nome do painel, data da última sincronização, fotos de hoje e versão do app (`package_info_plus`, vem do `version:` do `pubspec.yaml`), mais o botão "Voltar ao app".
    - "O que está no robô": miniaturas das 4 telas e das molduras, cada uma marcada "Padrão do app" ou "Do painel". Sempre 4 por linha (`LayoutBuilder`).
  - **Fotos de hoje**: `animacao.dart` chama `AppConfig.registrarFoto(enviada: true/false)` a cada tentativa de envio. O contador fica em `config_remota/contador.json` (`dia`, `enviadas`, `falhas`) e zera quando o dia muda. "Tentar novamente" depois de uma falha conta 1 falha e, se der certo, 1 enviada.
- **`tela1.dart`**: área secreta (5 toques) e PIN. Sem licença mostra a `TelaLicenca`; sem evento, a `TelaSemEvento`. A contagem de 60 s que fecha o app só roda com ela na frente e nunca em dobro.
- **`pin_admin.dart`**: popup do PIN (80% x 88% da tela) com teclado numérico próprio, sem o teclado do Android. Toques fora não fecham (`barrierDismissible: false`), só o Cancelar. PIN errado mostra "PIN incorreto" e limpa, sem fechar.
  - As teclas (`TeclaToque`, também usada no teclado da licença) contam **no instante em que o dedo encosta** (`Listener.onPointerDown`), não ao soltar. Com `ElevatedButton`, digitar rápido perdia teclas: o dedo escorregava um pouco (o Flutter cancela o toque) ou o próximo toque começava antes do anterior acabar. No robô, 2 de 5 toques rápidos se perdiam; agora conta 5 de 5. A tecla escurece enquanto o dedo está nela.
- **`animacao.dart`**: envia a foto. Com a pasta vazia, o caminho é só o nome da imagem. Recusa por falta de evento (`"evento": false`) vira o aviso "Nenhum evento ativo" e volta ao início.
- **`evento.dart`** e **`tela_sem_evento.dart`**: evento atual do painel e o aviso "Nenhum evento ativo" (ver "Fotos só com evento").
- As outras telas leem imagens, molduras, vídeo, cor, pasta e URLs de `AppConfig.instance`.

### Novo item editável

1. Adicione a chave em `painelSlots()` (`painel/lib.php`) e a imagem padrão em `painel/padrao/`.
2. Adicione a mesma chave em `AppConfig.imagensPadrao` (`lib/app_config.dart`).
3. Na tela, use `AppConfig.instance.imagem('chave')` no lugar de `AssetImage(...)`.

A documentação geral fica em `DOCUMENTACAO_PAINEL_WEB.md`, na raiz do projeto.

---

## 6. Robô e APK

| Item | Valor |
|---|---|
| Robô | Orion/Buddy, número de série `ZTS39P2201T0` |
| Pacote | `com.bynexti.camerashot` |
| Build | Release, assinado com a chave de debug. O app não é debuggable, então `run-as` não funciona. |

Gerar e instalar:

```sh
flutter build apk --release
adb -s ZTS39P2201T0 install -r build/app/outputs/flutter-apk/app-release.apk
```

O APK mais novo (23/11/2026, versão 1.0.1, fotos só com evento + correção do app que fechava no meio da sessão) está em `build/apk-evento/camerashot-1.0.1-evento-obrigatorio.apk`. **Instalado no robô `ZTS39P2201T0` em 23/11/2026**. A licença foi mantida e o app abriu na tela inicial; com o servidor ainda sem o `evento.php`, o app não trava. Ele também ficou 108 s nos Termos sem fechar sozinho (o APK anterior fechava).

O APK com licença (13/11/2026) está em `build/apk-licenca/camerashot-com-licenca.apk`. **Ele ainda não foi instalado no robô**, que continua com o APK anterior, sem licença. Instale só depois de a central estar no servidor; sem ela, o robô fica parado na tela de ativação.

---

## 7. Testes feitos

- **Testes locais** (cópia do servidor com `C:\xampp\php\php.exe -S`) passaram:
  - login, envio de moldura e de fundo;
  - sincronização sem código;
  - troca da pasta no `painel.local.php` aparecendo no sync;
  - upload com pasta vazia (vai para a raiz), em subpasta, sem o campo `pasta` (recusado) e com `..` (bloqueado).
- **No robô:** a cor `#e71313` definida no painel foi aplicada, então a sincronização com `clienteFotos/buddyApp` funciona.
- `flutter analyze`: só sobraram os 66 avisos que já existiam antes.
- `php -l` sem erros em todos os PHP da pasta `clientes/`.

---

## 8. Problemas encontrados

- **Erro 404 na sincronização:** o app apontava para `appsBuddy/appFoto/painel/`, mas o painel estava em `clienteFotos/buddyApp/painel/`. O app foi apontado para o endereço certo.
- **Caminho das fotos:** no começo, "raiz do app" foi entendido como `appFoto/clienteFotos/buddyApp`. O certo é a base `https://suafoto.futuremedia.com.br/clienteFotos/buddyApp`, com as fotos na raiz dela.
- **Git Bash e curl:** o Git Bash troca `/clienteFotos/...` por `C:/Program Files/Git/...` nos testes com curl. Isso é coisa do terminal, não do código. Use a URL completa (`https://...`) ou `MSYS_NO_PATHCONV=1`.

---

## 9. Pendências

- [ ] **Login único:** subir `clientes/login/` para `https://suafoto.futuremedia.com.br/clienteFotos/buddyApp/login/` **antes** do `painel.local.php` com `login_unico`. Sem isso, o painel do cliente redireciona para uma página que não existe.
- [ ] **Licenças:** subir `clientes/licencas/` para `https://suafoto.futuremedia.com.br/clienteFotos/buddyApp/licencas/`. Confira se `licencas/config/` e `licencas/dados/` respondem 403, entre na central, crie a licença do cliente e instale o APK com licença no robô. Depois de ativar todos os robôs do cliente, ligue `exigir_licenca` no `painel.local.php` dele.

- [ ] Subir a nova versão do painel (trajeto e editor) e testar o editor no navegador do cliente: arrastar, redimensionar e enviar a imagem de um botão.
- [ ] Sincronizar o robô com o painel novo e conferir se os botões aparecem onde foram colocados.
- [x] Com uma moldura só, sobrava um espaço vazio entre Tirar foto e Início. Resolvido em 30/10/2026: o app fecha o espaço sozinho (`retanguloCamera`), e o editor tem a prévia "Com 1 moldura".

- [ ] Enviar o conteúdo de `clientes/` para `clienteFotos/buddyApp/` no servidor.
- [ ] Tirar uma foto no robô e conferir se ela aparece como `https://suafoto.futuremedia.com.br/clienteFotos/buddyApp/photo_….jpg`.
- [ ] Conferir se o QR Code abre a foto no celular.
- [ ] (Opcional) A `galeria/index.php` só lista pastas de primeiro nível. Ela não mostra fotos em subpastas como `clienteFotos/buddyApp`. Ainda não foi alterada.
