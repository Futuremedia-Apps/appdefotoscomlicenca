import 'dart:async';
import 'dart:io';
import 'package:flutter/material.dart';
import 'app_config.dart';
import 'elementos_tela.dart';

import 'tela1.dart';
import 'tela2.dart';
import 'animacao.dart';

class Tela3 extends StatefulWidget {
  final String selectedFrame;
  final String imagePath;

  const Tela3({super.key, required this.selectedFrame, required this.imagePath});

  @override
  _Tela3 createState() => _Tela3();
}

class _Tela3 extends State<Tela3> with WidgetsBindingObserver {
  Timer? _timer;
  int _secondsRemaining = 120; // Total tempo em segundos
  bool _showCountdown = false; // Controla a exibição do contador

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
          }
        } else {
          _showCountdown = false;

          _timer?.cancel();
          _timer = null;

          Navigator.of(context).push(
            PageRouteBuilder(
              pageBuilder: (context, animation, secondaryAnimation) => Animacao(selectedFrame: widget.selectedFrame, imagePath: widget.imagePath),
              transitionDuration: Duration.zero,
              reverseTransitionDuration: Duration.zero,
            ),
          );
        }
      });
    });
  }

  void _resetTimer() {
    setState(() {
      _secondsRemaining = 120;
      _showCountdown = false;
    });
  }

  void _onScreenTap() {
    // Só reinicia o timer se o countdown estiver sendo mostrado
    if (_showCountdown) {
      _resetTimer();
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
      body: GestureDetector(
        onTap: _onScreenTap,
        child: Stack(
          children: [
            Container(color: Colors.black),
            Positioned.fill(
              child: Image.file(
                File(widget.imagePath),
                fit: BoxFit.fill,
              ),
            ),
            Image(
              image: Midia.provider(widget.selectedFrame),
              fit: BoxFit.fill,
              width: screenWidth,
              height: screenHeight,
            ),
            // Botões: imagem, posição e tamanho vêm do editor do painel
            BotaoTela(
              elemento: 'salvar',
              texto: 'Salvar',
              icone: Icons.check_circle,
              corIcone: Colors.green,
              onPressed: () {
                _showCountdown = false;

                _timer?.cancel(); // Cancela o timer
                _timer = null;

                Navigator.of(context).push(
                  PageRouteBuilder(
                    pageBuilder: (context, animation, secondaryAnimation) => Animacao(selectedFrame: widget.selectedFrame, imagePath: widget.imagePath),
                    transitionDuration: Duration.zero,
                    reverseTransitionDuration: Duration.zero,
                  ),
                );
              },
            ),
            BotaoTela(
              elemento: 'refazer',
              texto: 'Tirar nova foto',
              icone: Icons.cancel_rounded,
              corIcone: Colors.red,
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
    );
  }
}

class BubblePainter extends CustomPainter {
  final Color color;
  BubblePainter({required this.color});

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = color
      ..style = PaintingStyle.fill;

    final path = Path();
    final radius = 20.0;

    path.addRRect(RRect.fromRectAndRadius(
      Rect.fromLTWH(0, 0, size.width, size.height),
      Radius.circular(radius),
    ));

    final trianglePath = Path();
    trianglePath.moveTo(size.width, size.height * 0.4);
    trianglePath.lineTo(size.width + 12, size.height * 0.2);
    trianglePath.lineTo(size.width, size.height * 0.15);
    trianglePath.close();

    canvas.drawPath(path, paint);
    canvas.drawPath(trianglePath, paint);
  }

  @override
  bool shouldRepaint(CustomPainter oldDelegate) => false;
}

