import 'dart:async';
import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;

import 'app_config.dart';
import 'licenca.dart';

// Evento atual do painel do cliente. As fotos só podem ser tiradas com um
// evento: o cliente cria ou escolhe o evento no painel web, e o robô pergunta
// ao painel (painel/api/evento.php) ao abrir, a cada 30 segundos na tela
// inicial e a cada 10 enquanto não houver evento. Sem evento, a tela inicial
// vira o aviso "Nenhum evento ativo" (tela_sem_evento.dart). O uploadImage.php
// também recusa a foto, então o servidor sempre tem a palavra final.
//
// Sem internet ou com um painel antigo (sem o evento.php), o app não trava por
// causa disso: vale a última resposta do painel.
class EventoAtual extends ChangeNotifier {
  EventoAtual._();
  static final EventoAtual instance = EventoAtual._();

  static const Duration conferirACada = Duration(seconds: 30);
  static const Duration conferirSemEvento = Duration(seconds: 10);

  // true: há evento; false: o painel disse que não há; null: ainda não sabe
  bool? _ativo;
  String _nome = '';
  // Painel da última resposta: com a licença trocada, ela não vale mais
  String _painel = '';
  bool conferindo = false;
  DateTime _ultimaTentativa = DateTime(2000);

  bool get _doPainelAtual => _painel == AppConfig.instance.servidor;
  bool? get ativo => _doPainelAtual ? _ativo : null;
  String get nome => _doPainelAtual ? _nome : '';

  // Só trava com a resposta "sem evento" do painel deste robô
  bool get bloqueado => ativo == false;

  void conferirSeNecessario() {
    if (conferindo) return;
    final intervalo = bloqueado ? conferirSemEvento : conferirACada;
    final desde = DateTime.now().difference(_ultimaTentativa);
    if (!desde.isNegative && desde < intervalo) return;
    conferir();
  }

  Future<void> conferir() async {
    if (conferindo || !Licenca.instance.valida) return;
    conferindo = true;
    _ultimaTentativa = DateTime.now();
    final config = AppConfig.instance;
    final painel = config.servidor;
    notifyListeners();
    try {
      final url = Uri.parse('${painel}painel/api/evento.php').replace(queryParameters: {
        'dispositivo': config.dispositivoId,
      });
      final resposta = await http.get(url, headers: config.cabecalhoLicenca).timeout(const Duration(seconds: 10));
      if (resposta.statusCode == 404) {
        // Painel ainda sem o evento.php: não dá para saber, então não trava
        _guardar(painel, null, '');
        return;
      }
      if (resposta.statusCode != 200) return; // licença recusada, erro do servidor: fica o que estava
      final dados = jsonDecode(resposta.body);
      if (dados is! Map || dados['ok'] != true) return;
      final evento = dados['evento'];
      _guardar(painel, evento is Map, evento is Map ? ((evento['nome'] as String?) ?? '') : '');
    } catch (e) {
      debugPrint('Evento: não deu para conferir ($e)');
    } finally {
      conferindo = false;
      notifyListeners();
    }
  }

  // O servidor recusou a foto por falta de evento (resposta "evento": false)
  void semEvento() {
    _guardar(AppConfig.instance.servidor, false, '');
    notifyListeners();
  }

  void _guardar(String painel, bool? ativo, String nome) {
    _painel = painel;
    _ativo = ativo;
    _nome = nome;
  }

  @visibleForTesting
  void zerar() {
    _guardar('', null, '');
    conferindo = false;
    _ultimaTentativa = DateTime(2000);
  }
}

// O uploadImage.php recusou a foto porque o painel não tem evento
class SemEventoException implements Exception {
  SemEventoException(this.mensagem);
  final String mensagem;

  @override
  String toString() => 'SemEventoException: $mensagem';
}
