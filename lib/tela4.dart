import 'dart:async';
import 'dart:io';
import 'package:flutter/material.dart';
import 'app_config.dart';
import 'elementos_tela.dart';
import 'package:qr_flutter/qr_flutter.dart';

import 'tela1.dart';

class Tela4 extends StatefulWidget {
  final String selectedFrame;
  final String imagePath;
  final String? displayUrl;

  const Tela4({super.key, required this.selectedFrame, required this.imagePath, required this.displayUrl});

  @override
  _Tela4State createState() => _Tela4State();
}

class _Tela4State extends State<Tela4> with WidgetsBindingObserver {
  Timer? _timer;
  int _secondsRemaining = 120; // Total tempo em segundos
  bool _showCountdown = false; // Controla a exibição do contador
  bool _showPopup = false; // Controla a exibição do popup

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

          // Mostra o contador e popup quando faltam 30 segundos
          if (_secondsRemaining <= 30 && !_showCountdown) {
            _showCountdown = false;
            _showPopup = true;
          }
        } else {
          _showCountdown = false;
          _showPopup = false;

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
    setState(() {
      _secondsRemaining = 120;
      _showCountdown = false;
      _showPopup = false;
    });
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
        _showPopup = false;

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
      body: Stack(
        children: [
          Container(
            constraints: BoxConstraints.expand(),
            // Imagem da tela inteira (fundo + textos + arte do QR Code), definida no painel web
            decoration: BoxDecoration(
              image: DecorationImage(
                image: AppConfig.instance.imagem('tela_final'),
                fit: BoxFit.fill,
                alignment: Alignment.center,
              ),
            ),
            child: Stack(
              //fit: StackFit.expand,
              children: [
                // Foto, QR Code e botão: posição e tamanho vêm do editor do painel
                ElementoTela(
                  elemento: 'foto',
                  child: Container(
                    decoration: BoxDecoration(
                      color: Colors.white,
                      border: Border.all(
                        color: Colors.white,
                        width: screenWidth * 0.008, // Borda branca em volta da foto
                      ),
                      boxShadow: [
                        BoxShadow(
                          color: Colors.black.withAlpha(80),
                          spreadRadius: screenWidth * 0.01,
                          blurRadius: screenWidth * 0.015,
                          offset: Offset(0, screenHeight * 0.004),
                        ),
                      ],
                    ),
                    child: Stack(
                      fit: StackFit.expand,
                      children: [
                        // Imagem base
                        Image.file(
                          File(widget.imagePath),
                          fit: BoxFit.fill,
                        ),
                        // Moldura sobreposta
                        Image(
                          image: Midia.provider(widget.selectedFrame),
                          fit: BoxFit.fill,
                        ),
                      ],
                    ),
                  ),
                ),

                // QR Code sempre quadrado, no meio do espaço marcado no painel
                ElementoTela(
                  elemento: 'qrcode',
                  child: Center(
                    child: AspectRatio(
                      aspectRatio: 1,
                      child: widget.displayUrl != null
                          ? Container(
                              decoration: BoxDecoration(
                                color: Colors.white,
                                borderRadius: BorderRadius.circular(screenWidth * 0.01),
                              ),
                              child: QrImageView(
                                data: AppConfig.instance.urlCompartilhar(widget.displayUrl!),
                                version: QrVersions.auto,
                                backgroundColor: Colors.white,
                              ),
                            )
                          : Center(child: CircularProgressIndicator(color: Colors.white)),
                    ),
                  ),
                ),

                BotaoTela(
                  elemento: 'finalizar',
                  texto: 'Finalizar',
                  onPressed: () {
                    _showCountdown = false;
                    _showPopup = false;

                    _timer?.cancel(); // Cancela o timer
                    _timer = null;

                    Navigator.of(context).push(
                      PageRouteBuilder(
                        pageBuilder: (context, animation, secondaryAnimation) => Tela1(),
                        transitionDuration: Duration.zero,
                        reverseTransitionDuration: Duration.zero,
                      ),
                    );
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
          
          // Popup de confirmação
          if (_showPopup)
            Container(
              color: Colors.black.withAlpha(150), // Overlay escuro
              child: Center(
                child: Container(
                  width: screenWidth * 0.6,
                  padding: EdgeInsets.all(screenWidth * 0.02),
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(screenWidth * 0.03),
                    boxShadow: [
                      BoxShadow(
                        color: Colors.black.withAlpha(100),
                        blurRadius: 10,
                        spreadRadius: 2,
                      ),
                    ],
                  ),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(
                        "Você ainda está aí?",
                        style: TextStyle(
                          fontSize: screenWidth * 0.05,
                          fontWeight: FontWeight.bold,
                          color: AppConfig.instance.corPrimaria,
                        ),
                        textAlign: TextAlign.center,
                      ),
                      SizedBox(height: screenHeight * 0.04),
                      SizedBox(
                        width: double.infinity,
                        height: screenHeight * 0.20,
                        child: ElevatedButton(
                          style: ElevatedButton.styleFrom(
                            backgroundColor: AppConfig.instance.corPrimaria,
                            shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(screenWidth * 0.02),
                            ),
                          ),
                          onPressed: () {
                            _resetTimer();
                          },
                          child: Text(
                            "SIM, AINDA ESTOU AQUI",
                            style: TextStyle(
                              color: Colors.white,
                              fontSize: screenWidth * 0.04,
                              fontWeight: FontWeight.bold,
                            ),
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}
