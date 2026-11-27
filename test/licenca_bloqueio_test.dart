import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

import 'package:camerashot/app_config.dart';
import 'package:camerashot/licenca.dart';

import 'token_teste.dart';

// Simula a Central de Licenças: o app tem que travar quando a central
// suspende, exclui ou desvincula, e quando o robô é desvinculado pela tela de
// admin (mesmo sem internet). Sem internet na renovação, NÃO pode travar.

final _licenca = Licenca.instance;

http.Response _json(int status, Map<String, dynamic> dados) =>
    http.Response(jsonEncode(dados), status, headers: {'content-type': 'application/json; charset=utf-8'});

final _ativada = _json(200, {'ok': true, 'token': tokenTeste, 'cliente': 'Buffet Alegria', 'painel': 'x', 'situacao': 'ativa', 'vence': ''});

// Roda o corpo com a "central" respondendo pelo [responder]
Future<void> comCentral(Future<http.Response> Function(http.Request) responder, Future<void> Function() corpo) {
  return http.runWithClient(corpo, () => MockClient(responder));
}

Future<void> ativar() => comCentral((_) async => _ativada, () async {
      expect(await _licenca.ativar('FM-VSGY-4Z72'), isNull);
    });

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  setUp(() => AppConfig.instance.dispositivoId = dispositivoTeste);

  test('ativa com o código', () async {
    await ativar();
    expect(_licenca.valida, isTrue);
    expect(_licenca.codigoTexto, 'FM-VSGY-4Z72');
  });

  test('um código por robô: manda serial e guarda o código que a central diz', () async {
    Map<String, String>? enviado;
    await comCentral((r) async {
      enviado = r.bodyFields;
      return _json(200, {'ok': true, 'token': tokenTesteRobo2, 'codigo': 'FM-2345-6789', 'cliente': 'Buffet Alegria', 'painel': 'x', 'situacao': 'ativa', 'vence': ''});
    }, () async {
      expect(await _licenca.ativar('FM-VSGY-4Z72'), isNull);
    });
    expect(enviado!.containsKey('serial'), isTrue);
    expect(enviado!.containsKey('modelo'), isTrue);
    expect(_licenca.valida, isTrue);
    expect(_licenca.codigoTexto, 'FM-2345-6789');
    // a renovação usa o código do robô
    await comCentral((r) async {
      enviado = r.bodyFields;
      return _json(200, {'ok': true, 'token': tokenTesteRobo2});
    }, () => _licenca.renovar());
    expect(enviado!['codigo'], '23456789');
  });

  test('código em uso em outro robô: recusa a ativação sem travar a licença atual', () async {
    await ativar();
    await comCentral((_) async => _json(403, {'ok': false, 'erro': 'em_uso', 'message': 'Este código já está em uso no robô de serial X.'}), () async {
      expect(await _licenca.ativar('FM-2345-6789'), contains('em uso'));
    });
    expect(_licenca.valida, isTrue);
    expect(_licenca.codigoTexto, 'FM-VSGY-4Z72');
  });

  for (final caso in {
    'suspensa': 'A licença deste robô está suspensa. Fale com a Futuremedia.',
    'desvinculado': 'Este robô foi desvinculado da licença. Digite o código de novo ou fale com a Futuremedia.',
    'codigo': 'A licença deste robô não existe mais. Fale com a Futuremedia.',
    'expirada': 'A licença deste robô venceu em 01/01/2026. Fale com a Futuremedia para renovar.',
  }.entries) {
    test('central responde "${caso.key}": o app trava com o motivo', () async {
      await ativar();
      await comCentral((_) async => _json(403, {'ok': false, 'erro': caso.key, 'message': caso.value}), () async {
        await _licenca.renovar();
      });
      expect(_licenca.valida, isFalse);
      expect(_licenca.motivoBloqueio, caso.value);
    });
  }

  test('sem internet na conferência: o app continua funcionando', () async {
    await ativar();
    await comCentral((_) async => throw http.ClientException('sem rede'), () async {
      expect(await _licenca.renovar(), isNotNull);
    });
    expect(_licenca.valida, isTrue);
  });

  test('erro do servidor (500) na conferência: o app continua funcionando', () async {
    await ativar();
    await comCentral((_) async => _json(500, {'ok': false, 'erro': 'servidor', 'message': 'erro'}), () async {
      await _licenca.renovar();
    });
    expect(_licenca.valida, isTrue);
  });

  test('desvincular pela tela de admin: avisa a central e trava', () async {
    await ativar();
    http.Request? pedido;
    await comCentral((r) async {
      pedido = r;
      return _json(200, {'ok': true});
    }, () => _licenca.desvincular());
    expect(pedido?.bodyFields['acao'], 'desvincular');
    expect(pedido?.bodyFields['token'], tokenTeste);
    expect(_licenca.valida, isFalse);
    expect(_licenca.codigo, isEmpty);
    expect(_licenca.motivoBloqueio, Licenca.motivoDesvinculado);
  });

  test('desvincular sem internet: trava do mesmo jeito', () async {
    await ativar();
    await comCentral((_) async => throw http.ClientException('sem rede'), () => _licenca.desvincular());
    expect(_licenca.valida, isFalse);
    expect(_licenca.motivoBloqueio, Licenca.motivoDesvinculado);
  });

  test('conferência a cada minuto: não repete antes disso', () async {
    await ativar();
    var pedidos = 0;
    await comCentral((_) async {
      pedidos++;
      return _ativada;
    }, () async {
      _licenca.renovarSeNecessario();
      await Future<void>.delayed(const Duration(milliseconds: 50));
      _licenca.renovarSeNecessario(); // logo em seguida: não confere de novo
      await Future<void>.delayed(const Duration(milliseconds: 50));
    });
    expect(pedidos, 1);
  });
}
