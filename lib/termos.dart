import 'dart:async';

import 'package:flutter/material.dart';
import 'app_config.dart';
import 'elementos_tela.dart';

import 'tela1.dart';
import 'tela2.dart';

class Termos extends StatefulWidget {
  const Termos({super.key});

  @override
  _TermosState createState() => _TermosState();
}

class _TermosState extends State<Termos> with WidgetsBindingObserver {
  Timer? _timer;
  int _secondsRemaining = 120; // Total tempo em segundos
  bool _showCountdown = false; // Controla a exibição do contador
  bool _isDialogOpen = false; // Nova variável para rastrear se o diálogo está aberto

  @override
  void initState() {
    super.initState();

    // Registra este objeto como um observador do ciclo de vida da aplicação
    WidgetsBinding.instance.addObserver(this);

    _startTimer();
  }

  void _startTimer() {
    const oneSecond = Duration(seconds: 1);
    _timer = Timer.periodic(oneSecond, (timer) {
      setState(() {
        if (_secondsRemaining > 0) {
          _secondsRemaining--;

          // Mostra o contador quando faltam 30 segundos
          if (_secondsRemaining <= 30 && !_showCountdown) {
            _showCountdown = false;

            // Se o diálogo estiver aberto, feche-o automaticamente
            if (_isDialogOpen) {
              Navigator.of(context).pop(); // Fecha o diálogo
              _isDialogOpen = false;
            }
          }
        } else {
          _showCountdown = false;

          // Se o diálogo estiver aberto, feche-o automaticamente
          if (_isDialogOpen) {
            Navigator.of(context).pop(); // Fecha o diálogo
            _isDialogOpen = false;
          }

          _timer?.cancel();
          _timer = null;

          Navigator.of(context).push(
              PageRouteBuilder(
                pageBuilder: (context, animation, secondaryAnimation) => Tela1(),
                transitionDuration: Duration.zero,
                reverseTransitionDuration: Duration.zero,
              )
          );
        }
      });
    });
  }

  void _resetTimer() {
    // Cancelar o timer existente
    _showCountdown = false;

    _timer?.cancel();
    _timer = null;

    // Redefinir o contador para o valor inicial (120 segundos)
    setState(() {
      _secondsRemaining = 120;
      _showCountdown = false;
    });

    // Iniciar um novo timer
    _startTimer();
  }

  void _onScreenTap() {
    // Só reinicia o timer se o countdown estiver sendo mostrado e o diálogo não estiver aberto
    if (_showCountdown && !_isDialogOpen) {
      setState(() {
        _secondsRemaining = 120;
        _showCountdown = false;
      });
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    super.didChangeAppLifecycleState(state);

    // Atualiza o estado com base no ciclo de vida do aplicativo
    switch (state) {
      case AppLifecycleState.resumed:
      // O aplicativo está visível e respondendo à entrada do usuário
        break;
      case AppLifecycleState.inactive:
      // O aplicativo não está atualmente recebendo entrada do usuário
      // Este estado ocorre brevemente quando o app troca de estado
        break;
      case AppLifecycleState.paused:
      // O aplicativo não é visível para o usuário (em background)
      // Este é o estado quando o botão HOME é pressionado
        _showCountdown = false;

        // Se o diálogo estiver aberto, feche-o automaticamente
        if (_isDialogOpen) {
          _isDialogOpen = false;
        }

        _timer?.cancel();
        _timer = null;

        Navigator.of(context).push(
            PageRouteBuilder(
              pageBuilder: (context, animation, secondaryAnimation) => Tela1(),
              transitionDuration: Duration.zero,
              reverseTransitionDuration: Duration.zero,
            )
        );

        break;
      case AppLifecycleState.detached:
      // O aplicativo está em um estado suspenso de memória
        break;
      default:
        break;
    }
  }

  // Aviso "Não aceito": tela inteira com a imagem do painel (tela_recusa) e os
  // dois botões, na posição do editor do painel
  Future<void> _showConfirmationDialog() async {
    _isDialogOpen = true;

    return showDialog<void>(
      context: context,
      barrierDismissible: false, // O usuário deve clicar em um botão para fechar
      builder: (BuildContext context) {
        return Dialog.fullscreen(
          child: Stack(
            children: [
              Positioned.fill(
                child: Image(
                  image: AppConfig.instance.imagem('tela_recusa'),
                  fit: BoxFit.fill,
                ),
              ),
              BotaoTela(
                elemento: 'recusa_sair',
                texto: 'Não tirar foto',
                cheio: true,
                onPressed: () {
                  _showCountdown = false;

                  _timer?.cancel(); // Cancela o timer
                  _timer = null;

                  Navigator.of(context).pop(); // Fecha o diálogo
                  _isDialogOpen = false;

                  Navigator.of(context).push(
                    PageRouteBuilder(
                      pageBuilder: (context, animation, secondaryAnimation) => Tela1(),
                      transitionDuration: Duration.zero,
                      reverseTransitionDuration: Duration.zero,
                    ),
                  );
                },
              ),
              BotaoTela(
                elemento: 'recusa_voltar',
                texto: 'Voltar para Termos',
                cheio: true,
                onPressed: () {
                  _showCountdown = false;

                  _timer?.cancel(); // Cancela o timer
                  _timer = null;

                  Navigator.of(context).pop(); // Fecha o diálogo
                  _isDialogOpen = false;
                },
              ),
            ],
          ),
        );
      },
    ).then((_) {
      // Garante que _isDialogOpen seja definido como false quando o diálogo é fechado
      _isDialogOpen = false;
    });
  }

  @override
  void dispose() {
    // Cancela a inscrição da observação do ciclo de vida
    WidgetsBinding.instance.removeObserver(this);

    _timer?.cancel();
    _timer = null;

    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final screenSize = MediaQuery.of(context).size;
    final screenWidth = screenSize.width;
    final screenHeight = screenSize.height;

    return Scaffold(
      body: GestureDetector(
        onTap: _onScreenTap,
        child: Container(
          constraints: BoxConstraints.expand(),
          // Imagem da tela inteira (fundo + texto dos termos), definida no painel web
          decoration: BoxDecoration(
            image: DecorationImage(
              image: AppConfig.instance.imagem('tela_termos'),
              fit: BoxFit.fill,
              alignment: Alignment.center,
            ),
          ),
          child: Stack(
            children: [
              // Botões: imagem, posição e tamanho vêm do editor do painel
              BotaoTela(
                elemento: 'nao_aceito',
                texto: 'Não aceito',
                onPressed: () {
                  setState(() {
                    _showCountdown = false;
                  });

                  _resetTimer();

                  // Mostra o popup de confirmação
                  _showConfirmationDialog();
                },
              ),
              BotaoTela(
                elemento: 'aceito',
                texto: 'Aceito',
                onPressed: () {
                  _showCountdown = false;

                  _timer?.cancel(); // Cancela o timer
                  _timer = null;

                  Navigator.of(context).push(
                      PageRouteBuilder(
                        pageBuilder: (context, animation, secondaryAnimation) => Tela2(),
                        transitionDuration: Duration.zero,
                        reverseTransitionDuration: Duration.zero,
                      ));
                },
              ),

              // Contador regressivo (só aparece quando faltam 30 segundos)
              if (_showCountdown)
                Positioned(
                  bottom: screenHeight * 0.03,  // Posicionado acima do botão Finalizar
                  right: screenWidth * 0.03,    // Posicionado no canto direito
                  child: Container(
                    padding: EdgeInsets.all(screenWidth * 0.02),
                    decoration: BoxDecoration(
                      color: Colors.red.withAlpha(200),
                      borderRadius: BorderRadius.circular(screenWidth * 0.02),
                    ),
                    child: Text(
                      "$_secondsRemaining",
                      style: TextStyle(
                        color: Colors.white,
                        fontWeight: FontWeight.bold,
                        fontSize: screenWidth * 0.045,
                      ),
                    ),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}