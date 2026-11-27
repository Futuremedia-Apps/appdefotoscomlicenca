import 'package:flutter/material.dart';

import 'app_config.dart';

// Popup do PIN da tela de admin, com teclado numérico próprio (botões grandes,
// sem o teclado do Android). Fecha com true quando o PIN está certo, ou null
// pelo Cancelar. PIN errado não fecha: mostra o aviso e limpa para tentar de novo.
class DialogoPin extends StatefulWidget {
  const DialogoPin({super.key});

  // O painel aceita PIN de 4 a 8 números
  static const int maximoDigitos = 8;

  @override
  State<DialogoPin> createState() => _DialogoPinState();
}

class _DialogoPinState extends State<DialogoPin> {
  String _digitado = '';
  bool _errou = false;

  void _digitar(String numero) {
    if (_digitado.length >= DialogoPin.maximoDigitos) return;
    setState(() {
      _digitado += numero;
      _errou = false;
    });
  }

  void _apagar() {
    if (_digitado.isEmpty) return;
    setState(() {
      _digitado = _digitado.substring(0, _digitado.length - 1);
      _errou = false;
    });
  }

  void _entrar() {
    if (_digitado.isEmpty) return;
    if (_digitado == AppConfig.instance.pin) {
      Navigator.of(context).pop(true);
    } else {
      setState(() {
        _digitado = '';
        _errou = true;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final tela = MediaQuery.of(context).size;
    final cor = AppConfig.instance.corPrimaria;
    final largura = tela.width * 0.8;
    final altura = tela.height * 0.88;
    final margem = altura * 0.06;

    return Dialog(
      insetPadding: EdgeInsets.zero,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(altura * 0.06)),
      child: SizedBox(
        width: largura,
        height: altura,
        child: Padding(
          padding: EdgeInsets.all(margem),
          child: Row(
            children: [
              // Esquerda: título, PIN digitado (bolinhas), aviso e Cancelar
              Expanded(
                flex: 4,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    FittedBox(
                      fit: BoxFit.scaleDown,
                      alignment: Alignment.centerLeft,
                      child: Text(
                        'Acesso administrativo',
                        style: TextStyle(fontSize: altura * 0.09, fontWeight: FontWeight.bold, color: cor),
                      ),
                    ),
                    SizedBox(height: altura * 0.02),
                    Text(
                      'Digite o PIN',
                      style: TextStyle(fontSize: altura * 0.06, color: Colors.black54),
                    ),
                    const Spacer(),
                    Container(
                      height: altura * 0.2,
                      alignment: Alignment.center,
                      decoration: BoxDecoration(
                        color: Colors.black.withAlpha(12),
                        borderRadius: BorderRadius.circular(altura * 0.04),
                        border: Border.all(color: _errou ? Colors.red : Colors.black12, width: 2),
                      ),
                      child: FittedBox(
                        fit: BoxFit.scaleDown,
                        child: Text(
                          _digitado.isEmpty ? ' ' : '●' * _digitado.length,
                          style: TextStyle(fontSize: altura * 0.09, letterSpacing: altura * 0.03, color: cor),
                        ),
                      ),
                    ),
                    SizedBox(height: altura * 0.02),
                    SizedBox(
                      height: altura * 0.08,
                      child: _errou
                          ? FittedBox(
                              fit: BoxFit.scaleDown,
                              alignment: Alignment.centerLeft,
                              child: Text(
                                'PIN incorreto. Tente de novo.',
                                style: TextStyle(fontSize: altura * 0.055, color: Colors.red, fontWeight: FontWeight.bold),
                              ),
                            )
                          : null,
                    ),
                    const Spacer(),
                    SizedBox(
                      height: altura * 0.16,
                      child: OutlinedButton(
                        style: OutlinedButton.styleFrom(
                          foregroundColor: Colors.black87,
                          side: const BorderSide(color: Colors.black26, width: 2),
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(altura * 0.04)),
                        ),
                        onPressed: () => Navigator.of(context).pop(),
                        child: FittedBox(
                          fit: BoxFit.scaleDown,
                          child: Text('Cancelar', style: TextStyle(fontSize: altura * 0.065)),
                        ),
                      ),
                    ),
                  ],
                ),
              ),
              SizedBox(width: margem),
              // Direita: teclado 3x4 (1–9, apagar, 0, entrar)
              Expanded(
                flex: 5,
                child: Column(
                  children: [
                    for (final linha in const [
                      ['1', '2', '3'],
                      ['4', '5', '6'],
                      ['7', '8', '9'],
                      ['apagar', '0', 'entrar'],
                    ])
                      Expanded(
                        child: Row(
                          children: [for (final tecla in linha) Expanded(child: _tecla(tecla, cor, altura))],
                        ),
                      ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _tecla(String tecla, Color cor, double altura) {
    final Widget conteudo;
    final String rotulo;
    final VoidCallback aoTocar;
    Color fundo = Colors.black.withAlpha(15);
    Color texto = Colors.black87;

    switch (tecla) {
      case 'apagar':
        conteudo = Icon(Icons.backspace_outlined, size: altura * 0.08);
        rotulo = 'Apagar';
        aoTocar = _apagar;
        break;
      case 'entrar':
        conteudo = Icon(Icons.check_rounded, size: altura * 0.1);
        rotulo = 'Entrar';
        aoTocar = _entrar;
        fundo = cor;
        texto = Colors.white;
        break;
      default:
        conteudo = Text(tecla, style: TextStyle(fontSize: altura * 0.1, fontWeight: FontWeight.bold));
        rotulo = tecla;
        aoTocar = () => _digitar(tecla);
    }

    return Padding(
      padding: EdgeInsets.all(altura * 0.012),
      child: TeclaToque(
        rotulo: rotulo,
        fundo: fundo,
        texto: texto,
        raio: altura * 0.04,
        aoTocar: aoTocar,
        child: conteudo,
      ),
    );
  }
}

// Tecla do teclado do PIN (e do código da licença). Conta no instante em que o dedo encosta
// (onPointerDown), e não ao soltar como um botão comum: digitando rápido, o
// dedo escorrega um pouco ou o próximo toque começa antes do anterior acabar,
// e o botão comum cancelava esses toques. Cada toque é uma tecla.
class TeclaToque extends StatefulWidget {
  const TeclaToque({
    required this.rotulo,
    required this.fundo,
    required this.texto,
    required this.raio,
    required this.aoTocar,
    required this.child,
  });

  final String rotulo;
  final Color fundo;
  final Color texto;
  final double raio;
  final VoidCallback aoTocar;
  final Widget child;

  @override
  State<TeclaToque> createState() => _TeclaToqueState();
}

class _TeclaToqueState extends State<TeclaToque> {
  // Dedos encostados agora (a tecla fica escura enquanto houver algum)
  int _dedos = 0;

  void _encostou(PointerDownEvent _) {
    setState(() => _dedos++);
    widget.aoTocar();
  }

  void _soltou(PointerEvent _) {
    if (_dedos > 0) setState(() => _dedos--);
  }

  @override
  Widget build(BuildContext context) {
    final pressionada = _dedos > 0;
    return Semantics(
      button: true,
      label: widget.rotulo,
      onTap: widget.aoTocar,
      excludeSemantics: true,
      child: Listener(
        onPointerDown: _encostou,
        onPointerUp: _soltou,
        onPointerCancel: _soltou,
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 60),
          decoration: BoxDecoration(
            color: pressionada ? Color.alphaBlend(Colors.black.withAlpha(40), widget.fundo) : widget.fundo,
            borderRadius: BorderRadius.circular(widget.raio),
          ),
          child: SizedBox.expand(
            child: Center(
              child: IconTheme(
                data: IconThemeData(color: widget.texto),
                child: DefaultTextStyle.merge(
                  style: TextStyle(color: widget.texto),
                  child: FittedBox(fit: BoxFit.scaleDown, child: widget.child),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
