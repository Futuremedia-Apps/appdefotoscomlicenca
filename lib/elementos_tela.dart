import 'dart:math' as math;

import 'package:flutter/material.dart';

import 'app_config.dart';

// Botões e elementos que o cliente posiciona no editor do painel web. A
// posição vem em frações da tela (AppConfig.retangulo), então estes widgets
// vão direto dentro do Stack de tela cheia de cada tela.

// Coloca o filho no lugar do elemento, convertendo as frações para a tela.
// "retangulo" troca a posição do painel por outra já calculada (ex.: câmera
// sem o botão Moldura, AppConfig.retanguloCamera).
class ElementoTela extends StatelessWidget {
  final String elemento;
  final Rect? retangulo;
  final Widget child;

  const ElementoTela({super.key, required this.elemento, this.retangulo, required this.child});

  @override
  Widget build(BuildContext context) {
    final tela = MediaQuery.of(context).size;
    final r = retangulo ?? AppConfig.instance.retangulo(elemento);
    return Positioned(
      left: r.left * tela.width,
      top: r.top * tela.height,
      width: r.width * tela.width,
      height: r.height * tela.height,
      child: child,
    );
  }
}

// Botão de uma tela. Se o cliente enviou uma imagem para ele no painel, o botão
// é a imagem; senão, o app desenha o botão padrão (texto, ou ícone + texto).
class BotaoTela extends StatelessWidget {
  final String elemento;
  final Rect? retangulo;
  final String texto;
  final VoidCallback onPressed;
  final IconData? icone;
  final Color? corIcone;
  // Fundo na cor principal com texto branco (padrão: fundo branco)
  final bool cheio;

  const BotaoTela({
    super.key,
    required this.elemento,
    this.retangulo,
    required this.texto,
    required this.onPressed,
    this.icone,
    this.corIcone,
    this.cheio = false,
  });

  @override
  Widget build(BuildContext context) {
    final imagem = AppConfig.instance.imagemBotao(elemento);
    return ElementoTela(
      elemento: elemento,
      retangulo: retangulo,
      child: imagem != null
          ? GestureDetector(
              behavior: HitTestBehavior.opaque,
              onTap: onPressed,
              child: Image(image: imagem, fit: BoxFit.contain),
            )
          : LayoutBuilder(builder: (context, caixa) => _botaoPadrao(context, caixa.biggest)),
    );
  }

  Widget _botaoPadrao(BuildContext context, Size caixa) {
    final cor = AppConfig.instance.corPrimaria;
    final largura = MediaQuery.of(context).size.width;
    final corTexto = cheio ? Colors.white : cor;

    // Tamanhos tirados da caixa do botão (iguais aos da prévia do painel,
    // editor.js): assim o ícone e o texto crescem junto com o botão. O
    // FittedBox só diminui, caso o texto não caiba na largura.
    final Widget conteudo = icone != null
        ? Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(icone, color: corIcone ?? corTexto, size: math.min(caixa.width, caixa.height) * 0.5),
              SizedBox(height: caixa.height * 0.02),
              Text(
                texto,
                textAlign: TextAlign.center,
                style: TextStyle(color: corTexto, fontSize: caixa.height * 0.13, fontWeight: FontWeight.bold),
              ),
            ],
          )
        // Texto com metade da altura do botão
        : Text(
            texto,
            style: TextStyle(color: corTexto, fontSize: caixa.height * 0.5, fontWeight: FontWeight.bold),
          );

    return ElevatedButton(
      style: ElevatedButton.styleFrom(
        padding: EdgeInsets.zero,
        minimumSize: Size.zero,
        tapTargetSize: MaterialTapTargetSize.shrinkWrap,
        backgroundColor: cheio ? cor : Colors.white.withAlpha(200),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(largura * (icone != null ? 0.04 : 0.02)),
        ),
      ),
      onPressed: onPressed,
      child: SizedBox.expand(
        child: Padding(
          padding: EdgeInsets.all(caixa.shortestSide * 0.06),
          child: Center(child: FittedBox(fit: BoxFit.scaleDown, child: conteudo)),
        ),
      ),
    );
  }
}
