import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';

// Identificação do robô (serial de fábrica e modelo), lida no Android pelo
// SDK do robô (MainActivity.kt, canal "futuremedia/robo"). Vai junto na
// ativação da licença: a central e o painel do cliente mostram em qual robô
// está cada código.
class RoboInfo {
  RoboInfo._();

  static const MethodChannel _canal = MethodChannel('futuremedia/robo');

  // "" se o robô não informar
  static String serial = '';
  static String modelo = '';
  static bool _lido = false;

  static Future<void> carregar() async {
    if (_lido) return;
    try {
      final info = await _canal.invokeMapMethod<String, dynamic>('info');
      serial = limpar(info?['serial']);
      modelo = ((info?['modelo'] as String?) ?? '').trim();
      _lido = true;
    } catch (e) {
      // Fora do Android (testes) ou app sem o canal: segue sem serial
      debugPrint('Serial do robô indisponível: $e');
    }
  }

  // Só letras, números, ponto, traço e _ (a mesma regra da central)
  static String limpar(Object? valor) {
    final texto = (valor is String ? valor : '').replaceAll(RegExp(r'[^0-9a-zA-Z._-]'), '');
    return texto.length > 40 ? texto.substring(0, 40) : texto;
  }
}
