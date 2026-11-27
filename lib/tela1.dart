import 'dart:async';

import 'package:android_intent_plus/android_intent.dart';
import 'package:android_intent_plus/flag.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'admin.dart';
import 'app_config.dart';
import 'elementos_tela.dart';
import 'evento.dart';
import 'licenca.dart';
import 'pin_admin.dart';
import 'tela_licenca.dart';
import 'tela_sem_evento.dart';
import 'termos.dart';

class Tela1 extends StatefulWidget {
  const Tela1({super.key});

  @override
  _Tela1State createState() => _Tela1State();
}

class _Tela1State extends State<Tela1> with WidgetsBindingObserver {
  Timer? _timer;
  int _tempoInatividade = AppConfig.instance.tempoInatividade;

  @override
  void initState() {
    super.initState();

    // Registra este objeto como um observador do ciclo de vida da aplicação
    WidgetsBinding.instance.addObserver(this);

    // Redesenha quando uma sincronização (tela de admin) terminar
    AppConfig.instance.addListener(_aoMudarConfig);

    // Sem licença válida, esta tela vira a tela de ativação (tela_licenca.dart)
    Licenca.instance.addListener(_aoMudarLicenca);
    Licenca.instance.renovarSeNecessario();
    // Sem evento no painel, vira o aviso "Nenhum evento ativo" (tela_sem_evento.dart)
    EventoAtual.instance.addListener(_aoMudarLicenca);
    EventoAtual.instance.conferirSeNecessario();
    // Confere a licença (a cada minuto: bloqueio pela central trava o app) e
    // o evento (a cada 30 s, ou 10 s sem evento). Cada um controla o seu ritmo.
    _conferirLicenca = Timer.periodic(const Duration(seconds: 10), (_) {
      Licenca.instance.renovarSeNecessario();
      EventoAtual.instance.conferirSeNecessario();
    });
  }

  bool get _liberado => Licenca.instance.valida && !Licenca.instance.preparando;

  // Licença valendo e evento no painel: mostra a tela inicial de verdade
  bool get _podeComecar => _liberado && !EventoAtual.instance.bloqueado;

  Timer? _conferirLicenca;

  // As outras telas abrem por cima desta (push), e ela continua montada por
  // baixo, ouvindo a licença e o evento. A contagem que fecha o app só pode
  // rodar com ela na frente; senão o app fechava no meio de uma sessão.
  bool _naFrente = false;

  bool get _podeContar {
    final ciclo = WidgetsBinding.instance.lifecycleState;
    return _podeComecar &&
        _naFrente &&
        (ciclo == null || ciclo == AppLifecycleState.resumed);
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final naFrente = ModalRoute.isCurrentOf(context) ?? true;
    if (_naFrente != naFrente) {
      _naFrente = naFrente;
      _reiniciarTimer();
    }
  }

  void _aoMudarConfig() {
    if (!mounted) return;
    final tempo = AppConfig.instance.tempoInatividade;
    if (_tempoInatividade != tempo) {
      _tempoInatividade = tempo;
      _reiniciarTimer();
    }
    setState(() {});
  }

  // Licença ou evento mudou
  void _aoMudarLicenca() {
    if (!mounted) return;
    if (_liberado) EventoAtual.instance.conferirSeNecessario();
    if (!_podeContar) {
      _pararTimer();
    } else if (_timer == null) {
      _reiniciarTimer();
    }
    setState(() {});
  }

  // Acesso à tela de admin: 5 toques seguidos no canto superior direito + PIN
  int _toquesSecretos = 0;
  DateTime _ultimoToqueSecreto = DateTime(2000);

  void _toqueSecreto() {
    _reiniciarTimer();
    final agora = DateTime.now();
    if (agora.difference(_ultimoToqueSecreto) > Duration(seconds: 2)) {
      _toquesSecretos = 0;
    }
    _ultimoToqueSecreto = agora;
    _toquesSecretos++;

    if (_toquesSecretos >= 5) {
      _toquesSecretos = 0;
      _pedirPinAdmin();
    }
  }

  Future<void> _pedirPinAdmin() async {
    _pararTimer();

    // Teclado numérico próprio (pin_admin.dart). PIN errado não fecha o popup.
    final pinCorreto = await showDialog<bool>(
      context: context,
      // Toques a mais no canto (6º, 7º…) caem fora do popup: não fecham ele.
      // Para sair, só pelo Cancelar.
      barrierDismissible: false,
      builder: (context) => const DialogoPin(),
    );

    if (pinCorreto == true && mounted) {
      await Navigator.of(context).push(MaterialPageRoute(builder: (context) => AdminScreen()));
    }
  }

  void _pararTimer() {
    _timer?.cancel();
    _timer = null;
  }

  void _reiniciarTimer() {
    _pararTimer();
    if (!_podeContar) return;
    _timer = Timer(Duration(seconds: _tempoInatividade), () {
      _timer = null;
      if (mounted && _podeContar) abrirApp();
    });
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    super.didChangeAppLifecycleState(state);

    if (state == AppLifecycleState.resumed) {
      Licenca.instance.renovarSeNecessario();
      EventoAtual.instance.conferirSeNecessario();
      _reiniciarTimer();
    } else {
      _pararTimer();
    }
  }

  @override
  void dispose() {
    // Cancela a inscrição da observação do ciclo de vida
    WidgetsBinding.instance.removeObserver(this);
    AppConfig.instance.removeListener(_aoMudarConfig);
    Licenca.instance.removeListener(_aoMudarLicenca);
    EventoAtual.instance.removeListener(_aoMudarLicenca);
    _conferirLicenca?.cancel();

    _pararTimer();

    super.dispose();
  }

  void abrirApp() async {
    /*
    String packageName = "com.ainirobot.moduleapp";
    String className = "com.ainirobot.platform.react.EveActivity";
    //String packageName = "zorabots.com.launcher";
    //String className = "com.zorabots.launcher.MainActivity";

    try {
      // Tenta abrir o aplicativo usando android_intent_plus
      final AndroidIntent intent = AndroidIntent(
        action: 'android.intent.action.MAIN',
        package: packageName,
        category: 'android.intent.category.LAUNCHER',
        componentName: className,
        // Adicione flags para garantir melhor comportamento de inicialização
        flags: <int>[Flag.FLAG_ACTIVITY_NEW_TASK, Flag.FLAG_ACTIVITY_CLEAR_TASK],
      );

      await intent.launch().then((_) {
        debugPrint("Aplicativo aberto com sucesso");
      }).catchError((error) {
        debugPrint("Erro ao abrir o aplicativo: $error");
        if (context.mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text("Não foi possível abrir o aplicativo 1")),
          );
        }
      });
    } catch (e) {
      debugPrint("Erro ao abrir o aplicativo: $e");
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text("Não foi possível abrir o aplicativo 2")),
        );
      }
    }
    */
    
    // Fecha o aplicativo
    SystemNavigator.pop();
  }

  @override
  Widget build(BuildContext context) {
    if (!_liberado) return const TelaLicenca();
    if (EventoAtual.instance.bloqueado) return TelaSemEvento(aoToqueSecreto: _toqueSecreto);

    // Obtém as dimensões da tela
    final screenSize = MediaQuery.of(context).size;
    final screenWidth = screenSize.width;
    final screenHeight = screenSize.height;

    return Scaffold(
      body: GestureDetector(
        onTap: _reiniciarTimer,
        child: Container(
          constraints: BoxConstraints.expand(),
          // Imagem da tela inteira (fundo + textos), definida no painel web
          decoration: BoxDecoration(
            image: DecorationImage(
              image: AppConfig.instance.imagem('tela_inicio'),
              fit: BoxFit.fill,
              alignment: Alignment.center,
            ),
          ),
          child: Stack(
              children: [
                // Botões: imagem, posição e tamanho vêm do editor do painel
                BotaoTela(
                  elemento: 'sair',
                  texto: 'Sair',
                  onPressed: () {
                    _pararTimer();
                    abrirApp();
                  },
                ),

                BotaoTela(
                  elemento: 'iniciar',
                  texto: 'Iniciar',
                  onPressed: () {
                    _pararTimer();
                    Navigator.of(context).push(
                      PageRouteBuilder(
                        pageBuilder: (context, animation, secondaryAnimation) => Termos(),
                        transitionDuration: Duration.zero,
                        reverseTransitionDuration: Duration.zero,
                      ),
                    );
                  },
                ),

                // Área invisível do acesso à tela de admin
                Positioned(
                  top: 0,
                  right: 0,
                  width: screenWidth * 0.12,
                  height: screenHeight * 0.15,
                  child: GestureDetector(
                    behavior: HitTestBehavior.opaque,
                    onTap: _toqueSecreto,
                  ),
                ),
              ],
          ),
        ),
      ),
    );
  }
}
