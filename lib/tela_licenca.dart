import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'app_config.dart';
import 'licenca.dart';
import 'pin_admin.dart';
import 'robo_info.dart';

// Tela de ativação da licença. Aparece no lugar da tela inicial enquanto o
// robô não tem licença válida (troca: false), ou pela tela de admin, para
// passar o robô para outra licença (troca: true, com o botão Voltar).
// O técnico digita o código FM-XXXX-XXXX no teclado próprio (campo em cima,
// teclado completo embaixo); depois de ativar,
// o app já sincroniza com o painel do cliente.
class TelaLicenca extends StatefulWidget {
  const TelaLicenca({super.key, this.troca = false});

  final bool troca;

  @override
  State<TelaLicenca> createState() => _TelaLicencaState();
}

class _TelaLicencaState extends State<TelaLicenca> {
  // Cores da Futuremedia: esta tela é da Futuremedia, não do cliente
  static const Color _azul = Color(0xFF174786);
  static const Color _ciano = Color(0xFF008BCC);
  static const Color _fundo = Color(0xFFF1EFEB);
  static const Color _texto = Color(0xFF2E2F35);


  final Licenca _licenca = Licenca.instance;
  String _digitado = '';
  String _mensagem = '';
  bool _erro = false;
  bool _ocupado = false;
  // Licença ativada, mas a sincronização falhou: mostra "Continuar"
  bool _ativadaComAviso = false;

  void _digitar(String letra) {
    if (_ocupado || _digitado.length >= Licenca.tamanhoCodigo) return;
    setState(() {
      _digitado += letra;
      if (_erro) _mensagem = '';
      _erro = false;
    });
  }

  void _apagar() {
    if (_ocupado || _digitado.isEmpty) return;
    setState(() {
      _digitado = _digitado.substring(0, _digitado.length - 1);
      if (_erro) _mensagem = '';
      _erro = false;
    });
  }

  void _status(String texto, {bool erro = false}) {
    if (mounted) {
      setState(() {
        _mensagem = texto;
        _erro = erro;
      });
    }
  }

  Future<void> _ativar() async {
    if (_ocupado || _digitado.length != Licenca.tamanhoCodigo) return;
    setState(() => _ocupado = true);
    _status('Conferindo o código...');

    // Segura a tela até baixar o visual do cliente
    _licenca.iniciarPreparo();
    final erro = await _licenca.ativar(_digitado);
    if (erro != null) {
      _licenca.terminarPreparo();
      if (mounted) setState(() => _ocupado = false);
      _status(erro, erro: true);
      return;
    }

    _status('Licença ativada para ${_licenca.cliente}. Baixando as telas e molduras...');
    final erroSync = await AppConfig.instance.sincronizar(progresso: _status);
    if (!mounted) {
      _licenca.terminarPreparo();
      return;
    }
    if (erroSync != null) {
      setState(() {
        _ocupado = false;
        _ativadaComAviso = true;
      });
      _status('Licença ativada, mas não deu para baixar as telas do painel: $erroSync '
          'Dá para sincronizar depois pela tela de admin.', erro: true);
      return;
    }
    _concluir();
  }

  void _concluir() {
    _licenca.terminarPreparo();
    if (widget.troca && mounted) Navigator.of(context).pop(true);
  }

  // Licença suspensa ou vencida que a Futuremedia já liberou
  Future<void> _tentarDeNovo() async {
    if (_ocupado) return;
    setState(() => _ocupado = true);
    _status('Conferindo a licença...');
    final erro = await _licenca.renovar();
    if (!mounted) return;
    setState(() => _ocupado = false);
    _status(erro ?? 'Licença liberada.', erro: erro != null);
  }


  // Layout: em cima, título e o código digitado; embaixo, o teclado na
  // largura toda (teclas grandes, fáceis de acertar no robô).
  @override
  Widget build(BuildContext context) {
    final tela = MediaQuery.of(context).size;
    final altura = tela.height;
    final margem = altura * 0.045;
    final bloqueio = !widget.troca && _licenca.motivoBloqueio.isNotEmpty && !_ativadaComAviso;

    return Scaffold(
      backgroundColor: _fundo,
      body: SafeArea(
        child: Padding(
          padding: EdgeInsets.all(margem),
          child: Column(
            children: [
              // Em cima: textos à esquerda, código e botões à direita
              Expanded(
                flex: 40,
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(flex: 5, child: _cabecalho(altura, bloqueio)),
                    SizedBox(width: margem),
                    Expanded(flex: 6, child: _areaCodigo(altura)),
                  ],
                ),
              ),
              SizedBox(height: margem * 0.6),
              // Embaixo: teclado completo
              Expanded(flex: 60, child: _teclado(altura)),
            ],
          ),
        ),
      ),
    );
  }

  Widget _cabecalho(double altura, bool bloqueio) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // Faixa com o degradê da Futuremedia
        Align(
          alignment: Alignment.centerLeft,
          child: Container(
            height: altura * 0.01,
            width: altura * 0.13,
            margin: EdgeInsets.only(bottom: altura * 0.018),
            decoration: BoxDecoration(
              gradient: const LinearGradient(colors: [_azul, _ciano]),
              borderRadius: BorderRadius.circular(altura * 0.005),
            ),
          ),
        ),
        FittedBox(
          fit: BoxFit.scaleDown,
          alignment: Alignment.centerLeft,
          child: Text(
            widget.troca ? 'Trocar a licença' : 'Ativar licença',
            style: TextStyle(fontSize: altura * 0.065, fontWeight: FontWeight.w900, color: _azul),
          ),
        ),
        SizedBox(height: altura * 0.008),
        Text(
          widget.troca
              ? 'Digite o código da nova licença. O robô passa a usar o painel do cliente dessa licença.'
              : 'Digite o código da licença que a Futuremedia enviou para este robô.',
          maxLines: 2,
          overflow: TextOverflow.ellipsis,
          style: TextStyle(fontSize: altura * 0.026, color: _texto.withAlpha(190), height: 1.3),
        ),
        // Cada robô tem o seu código: o serial ajuda a saber qual é este
        if (RoboInfo.serial.isNotEmpty) ...[
          SizedBox(height: altura * 0.006),
          Text(
            'Serial deste robô: ${RoboInfo.serial}',
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(fontSize: altura * 0.022, color: _texto.withAlpha(150), fontWeight: FontWeight.w600),
          ),
        ],
        if (bloqueio) ...[
          SizedBox(height: altura * 0.015),
          Flexible(child: _caixaAviso(_licenca.motivoBloqueio, altura)),
        ],
      ],
    );
  }

  Widget _areaCodigo(double altura) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _codigoDigitado(altura),
        SizedBox(height: altura * 0.01),
        // Mensagem (erro, andamento)
        Expanded(
          child: _mensagem.isEmpty
              ? const SizedBox.shrink()
              : Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    if (_ocupado && !_erro)
                      Padding(
                        padding: EdgeInsets.only(right: altura * 0.012, top: altura * 0.004),
                        child: SizedBox(
                          width: altura * 0.026,
                          height: altura * 0.026,
                          child: const CircularProgressIndicator(strokeWidth: 3, color: _azul),
                        ),
                      ),
                    Expanded(
                      child: Text(
                        _mensagem,
                        maxLines: 3,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(
                          fontSize: altura * 0.024,
                          height: 1.25,
                          fontWeight: FontWeight.bold,
                          color: _erro ? Colors.red.shade700 : _azul,
                        ),
                      ),
                    ),
                  ],
                ),
        ),
        _botoesSecundarios(altura),
      ],
    );
  }

  Widget _caixaAviso(String texto, double altura) {
    return Container(
      padding: EdgeInsets.all(altura * 0.016),
      decoration: BoxDecoration(
        color: const Color(0xFFFDECEA),
        borderRadius: BorderRadius.circular(altura * 0.018),
        border: Border.all(color: const Color(0xFFF5C6C2), width: 2),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(Icons.lock_outline, color: Colors.red.shade700, size: altura * 0.036),
          SizedBox(width: altura * 0.012),
          Expanded(
            child: Text(
              texto,
              maxLines: 3,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(fontSize: altura * 0.023, height: 1.3, color: Colors.red.shade800, fontWeight: FontWeight.w600),
            ),
          ),
        ],
      ),
    );
  }

  // FM-7K2P-9QX_ : os espaços ainda vazios aparecem como traço baixo
  Widget _codigoDigitado(double altura) {
    final letras = List.generate(Licenca.tamanhoCodigo, (i) => i < _digitado.length ? _digitado[i] : '_');
    final texto = 'FM-${letras.sublist(0, 4).join()}-${letras.sublist(4).join()}';
    return Container(
      height: altura * 0.14,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(altura * 0.025),
        border: Border.all(color: _erro ? Colors.red : _azul.withAlpha(60), width: 2),
      ),
      child: FittedBox(
        fit: BoxFit.scaleDown,
        child: Padding(
          padding: EdgeInsets.symmetric(horizontal: altura * 0.03),
          child: Text(
            texto,
            style: TextStyle(
              fontSize: altura * 0.075,
              fontWeight: FontWeight.bold,
              letterSpacing: altura * 0.01,
              fontFamily: 'monospace',
              color: _azul,
            ),
          ),
        ),
      ),
    );
  }

  Widget _botoesSecundarios(double altura) {
    Widget botao(String rotulo, IconData icone, VoidCallback? aoTocar) => SizedBox(
          height: altura * 0.075,
          child: OutlinedButton.icon(
            style: OutlinedButton.styleFrom(
              foregroundColor: _texto,
              side: const BorderSide(color: Colors.black26, width: 2),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(altura * 0.02)),
              padding: EdgeInsets.symmetric(horizontal: altura * 0.025),
            ),
            onPressed: aoTocar,
            icon: Icon(icone, size: altura * 0.032),
            label: FittedBox(fit: BoxFit.scaleDown, child: Text(rotulo, style: TextStyle(fontSize: altura * 0.026))),
          ),
        );

    final botoes = <Widget>[
      if (widget.troca) botao('Voltar', Icons.arrow_back, _ocupado ? null : () => Navigator.of(context).pop()),
      if (!widget.troca && _licenca.codigo.isNotEmpty && !_ativadaComAviso)
        botao('Tentar de novo', Icons.refresh, _ocupado ? null : _tentarDeNovo),
      if (!widget.troca) botao('Fechar app', Icons.close, _ocupado ? null : () => SystemNavigator.pop()),
    ];
    return Row(
      mainAxisAlignment: MainAxisAlignment.end,
      children: [
        for (var i = 0; i < botoes.length; i++) ...[
          if (i > 0) SizedBox(width: altura * 0.015),
          botoes[i],
        ],
      ],
    );
  }

  // Teclado completo, como o de um computador: números em cima e letras QWERTY.
  // Os códigos não usam 0/O e 1/I/L, mas as teclas existem para ninguém
  // estranhar; se digitar uma delas, o código é recusado como não encontrado.
  Widget _teclado(double altura) {
    final completo = _digitado.length == Licenca.tamanhoCodigo;
    final ativarLigado = _ativadaComAviso || (completo && !_ocupado);
    return Column(
      children: [
        for (final linha in const ['1234567890', 'QWERTYUIOP', 'ASDFGHJKL', 'ZXCVBNM'])
          Expanded(
            child: Row(
              children: [
                for (final letra in linha.split('')) Expanded(child: _tecla(letra, altura)),
                if (linha == 'ASDFGHJKL') Expanded(child: _tecla('apagar', altura)),
                if (linha == 'ZXCVBNM')
                  Expanded(
                    flex: 3,
                    child: Padding(
                      padding: EdgeInsets.all(altura * 0.006),
                      child: TeclaToque(
                        rotulo: _ativadaComAviso ? 'Continuar' : 'Ativar',
                        fundo: ativarLigado ? _azul : _azul.withAlpha(70),
                        texto: Colors.white,
                        raio: altura * 0.02,
                        aoTocar: _ativadaComAviso ? _concluir : _ativar,
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Icon(_ativadaComAviso ? Icons.arrow_forward : Icons.verified_user_outlined, size: altura * 0.05),
                            SizedBox(width: altura * 0.012),
                            Text(
                              _ativadaComAviso ? 'Continuar' : 'Ativar',
                              style: TextStyle(fontSize: altura * 0.05, fontWeight: FontWeight.bold),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),
              ],
            ),
          ),
      ],
    );
  }

  Widget _tecla(String tecla, double altura) {
    final apagar = tecla == 'apagar';
    return Padding(
      padding: EdgeInsets.all(altura * 0.006),
      child: TeclaToque(
        rotulo: apagar ? 'Apagar' : tecla,
        fundo: apagar ? _azul.withAlpha(30) : Colors.white,
        texto: apagar ? _azul : _texto,
        raio: altura * 0.02,
        aoTocar: apagar ? _apagar : () => _digitar(tecla),
        child: apagar
            ? Icon(Icons.backspace_outlined, size: altura * 0.05)
            : Text(tecla, style: TextStyle(fontSize: altura * 0.058, fontWeight: FontWeight.bold)),
      ),
    );
  }
}
