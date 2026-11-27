import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
// ignore: depend_on_referenced_packages
import 'package:path_provider_platform_interface/path_provider_platform_interface.dart';

import 'package:camerashot/app_config.dart';
import 'package:camerashot/evento.dart';
import 'package:camerashot/licenca.dart';
import 'package:camerashot/pin_admin.dart';
import 'package:camerashot/tela1.dart';

import 'token_teste.dart';

// As fotos só podem ser tiradas com um evento: o robô pergunta ao painel
// (painel/api/evento.php) e, sem evento, a tela inicial vira o aviso
// "Nenhum evento ativo". Sem internet ou com painel antigo, não trava.

final _evento = EventoAtual.instance;

class _PastaConfigTeste extends PathProviderPlatform {
  _PastaConfigTeste(this.caminho);

  final String caminho;

  @override
  Future<String?> getApplicationSupportPath() async => caminho;
}

http.Response _json(int status, Object dados) =>
    http.Response(jsonEncode(dados), status, headers: {'content-type': 'application/json; charset=utf-8'});

final _ativada = _json(200, {'ok': true, 'token': tokenTeste, 'cliente': 'Buffet Alegria', 'painel': 'x', 'situacao': 'ativa', 'vence': ''});

// Central e painel de mentira: a central sempre renova; o painel responde [evento]
Future<T> comServidor<T>(Future<http.Response> Function() evento, Future<T> Function() corpo, {List<http.Request>? pedidos}) {
  return http.runWithClient(corpo, () => MockClient((r) async {
        if (r.url.path.endsWith('api/ativar.php')) return _ativada;
        pedidos?.add(r);
        return evento();
      }));
}

Future<http.Response> _semEvento() async => _json(200, {'ok': true, 'evento': null});
Future<http.Response> _comEvento() async => _json(200, {'ok': true, 'evento': {'nome': 'Casamento Ana'}});

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() async {
    AppConfig.instance.dispositivoId = dispositivoTeste;
    await comServidor(_semEvento, () async {
      expect(await Licenca.instance.ativar('FM-VSGY-4Z72'), isNull);
    });
    _evento.zerar();
  });

  test('pergunta ao painel da licença, com a licença no cabeçalho', () async {
    final pedidos = <http.Request>[];
    await comServidor(_comEvento, () => _evento.conferir(), pedidos: pedidos);
    expect(pedidos, hasLength(1));
    expect(pedidos.first.url.toString(), startsWith('${AppConfig.instance.servidor}painel/api/evento.php'));
    expect(pedidos.first.url.queryParameters['dispositivo'], dispositivoTeste);
    expect(pedidos.first.headers['X-Licenca'], tokenTeste);
    expect(_evento.ativo, isTrue);
    expect(_evento.nome, 'Casamento Ana');
    expect(_evento.bloqueado, isFalse);
  });

  test('painel sem evento: trava', () async {
    await comServidor(_semEvento, () => _evento.conferir());
    expect(_evento.ativo, isFalse);
    expect(_evento.bloqueado, isTrue);
  });

  test('evento criado: destrava na conferência seguinte', () async {
    await comServidor(_semEvento, () => _evento.conferir());
    expect(_evento.bloqueado, isTrue);
    await comServidor(_comEvento, () => _evento.conferir());
    expect(_evento.bloqueado, isFalse);
  });

  test('painel antigo, sem o evento.php (404): não trava', () async {
    await comServidor(_semEvento, () => _evento.conferir());
    await comServidor(() async => http.Response('Not Found', 404), () => _evento.conferir());
    expect(_evento.ativo, isNull);
    expect(_evento.bloqueado, isFalse);
  });

  test('sem internet ou erro do servidor: fica a última resposta', () async {
    await comServidor(_comEvento, () => _evento.conferir());
    await comServidor(() async => throw http.ClientException('sem rede'), () => _evento.conferir());
    expect(_evento.ativo, isTrue);
    await comServidor(() async => _json(500, {'ok': false}), () => _evento.conferir());
    expect(_evento.ativo, isTrue);

    await comServidor(_semEvento, () => _evento.conferir());
    await comServidor(() async => throw http.ClientException('sem rede'), () => _evento.conferir());
    expect(_evento.bloqueado, isTrue);
  });

  test('foto recusada por falta de evento: trava na hora', () async {
    await comServidor(_comEvento, () => _evento.conferir());
    _evento.semEvento();
    expect(_evento.bloqueado, isTrue);
  });

  test('sem licença válida: nem pergunta', () async {
    await comServidor(() async => _json(200, {'ok': true}), () => Licenca.instance.desvincular());
    final pedidos = <http.Request>[];
    await comServidor(_semEvento, () => _evento.conferir(), pedidos: pedidos);
    expect(pedidos, isEmpty);
    expect(_evento.bloqueado, isFalse);
  });

  group('tela inicial', () {
    late List<String> chamadas;

    Future<void> abrir(WidgetTester tester, GlobalKey<NavigatorState> navegador) async {
      tester.view.physicalSize = const Size(1920, 1080);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      // SystemNavigator.pop() = o app fecha
      chamadas = [];
      tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(SystemChannels.platform, (chamada) async {
        chamadas.add(chamada.method);
        return null;
      });
      addTearDown(() => tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(SystemChannels.platform, null));
      await tester.pumpWidget(MaterialApp(navigatorKey: navegador, home: const Tela1()));
      for (var i = 0; i < 5; i++) {
        await tester.pump(const Duration(milliseconds: 50));
      }
    }

    testWidgets('sem evento: mostra o aviso; com evento: volta a tela inicial', (tester) async {
      var temEvento = false;
      await comServidor(() async => temEvento ? _comEvento() : _semEvento(), () async {
        await abrir(tester, GlobalKey<NavigatorState>());
        expect(find.text('Nenhum evento ativo'), findsOneWidget);
        expect(find.text('Iniciar'), findsNothing);

        // Parado no aviso, o app não fecha sozinho (a tela inicial fecha em 60 s)
        await tester.pump(const Duration(seconds: 70));
        expect(chamadas, isNot(contains('SystemNavigator.pop')));

        // O cliente cria o evento; "Tentar de novo" confere na hora
        temEvento = true;
        await tester.tap(find.text('Tentar de novo'));
        for (var i = 0; i < 5; i++) {
          await tester.pump(const Duration(milliseconds: 50));
        }
        expect(find.text('Nenhum evento ativo'), findsNothing);
        expect(find.text('Iniciar'), findsOneWidget);
      });
    });

    testWidgets('no aviso sem evento, o canto secreto ainda abre o PIN da tela de admin', (tester) async {
      await comServidor(_semEvento, () async {
        await abrir(tester, GlobalKey<NavigatorState>());
        expect(find.text('Nenhum evento ativo'), findsOneWidget);
        for (var i = 0; i < 5; i++) {
          await tester.tapAt(const Offset(1900, 20));
          await tester.pump(const Duration(milliseconds: 100));
        }
        await tester.pump();
        expect(find.byType(DialogoPin), findsOneWidget);
      });
    });

    testWidgets('com outra tela por cima, a tela inicial não fecha o app (nem quando a licença é renovada)', (tester) async {
      final navegador = GlobalKey<NavigatorState>();
      await comServidor(_comEvento, () async {
        await abrir(tester, navegador);
        expect(find.text('Iniciar'), findsOneWidget);

        // Convidado no meio da sessão (Termos, câmera...): a tela inicial fica por baixo
        navegador.currentState!.push(MaterialPageRoute<void>(builder: (_) => const Scaffold(body: Text('Sessão'))));
        await tester.pump(const Duration(milliseconds: 500));
        await tester.pump(const Duration(milliseconds: 500));
        expect(find.text('Sessão'), findsOneWidget);

        // A licença e o evento são conferidos de novo enquanto isso (o aviso
        // para a tela inicial sai na hora; a renovação em si não termina no
        // relógio de mentira do teste, porque consulta a versão do app)
        unawaited(Licenca.instance.renovar());
        unawaited(_evento.conferir());
        for (var i = 0; i < 15; i++) {
          await tester.pump(const Duration(seconds: 10));
        }
        expect(chamadas, isNot(contains('SystemNavigator.pop')));
        expect(find.text('Sessão'), findsOneWidget);
      });
    });

    // O caso real: o robô abre o app, o convidado toca Iniciar e fica mais de
    // um minuto nos Termos. Antes, a contagem da tela inicial era ligada duas
    // vezes ao abrir; Iniciar parava só uma, e a outra fechava o app.
    testWidgets('tocou Iniciar: a tela inicial não fecha o app no meio da sessão', (tester) async {
      await comServidor(_comEvento, () async {
        await abrir(tester, GlobalKey<NavigatorState>());
        await tester.tap(find.text('Iniciar'));
        await tester.pump(const Duration(milliseconds: 500));
        await tester.pump(const Duration(milliseconds: 500));
        // Os Termos têm a sua própria contagem, de 120 s: antes disso, nada fecha
        for (var i = 0; i < 10; i++) {
          await tester.pump(const Duration(seconds: 10));
        }
        expect(chamadas, isNot(contains('SystemNavigator.pop')));
      });
    });

    testWidgets('na frente e parada, a tela inicial fecha o app em 60 s (como antes)', (tester) async {
      await comServidor(_comEvento, () async {
        await abrir(tester, GlobalKey<NavigatorState>());
        await tester.pump(const Duration(seconds: 62));
        expect(chamadas, contains('SystemNavigator.pop'));
      });
    });

    testWidgets('aplica o tempo sincronizado ao voltar para a tela inicial', (tester) async {
      final config = AppConfig.instance;
      final plataforma = PathProviderPlatform.instance;
      final pasta = (await tester.runAsync(() => Directory.systemTemp.createTemp('camerashot_tempo_')))!;
      PathProviderPlatform.instance = _PastaConfigTeste(pasta.path);
      addTearDown(() async {
        PathProviderPlatform.instance = plataforma;
        await config.esquecerPainel();
        await pasta.delete(recursive: true);
      });
      await tester.runAsync(config.carregar);

      final navegador = GlobalKey<NavigatorState>();
      await comServidor(_comEvento, () async {
        await abrir(tester, navegador);
        navegador.currentState!.push(MaterialPageRoute<void>(
          builder: (_) => const Scaffold(body: Text('Admin')),
        ));
        await tester.pumpAndSettle();

        final erro = await tester.runAsync(() => http.runWithClient(
          config.sincronizar,
          () => MockClient((pedido) async => pedido.url.path.endsWith('api/sync.php')
            ? _json(200, {'ok': true, 'tempo_inatividade': 3})
            : _ativada),
        ));
        expect(erro, isNull);
        expect(config.tempoInatividade, 3);
        await tester.pump(const Duration(seconds: 4));
        expect(chamadas, isNot(contains('SystemNavigator.pop')));

        navegador.currentState!.pop();
        await tester.pumpAndSettle();
        await tester.pump(const Duration(seconds: 2));
        expect(chamadas, isNot(contains('SystemNavigator.pop')));

        await tester.tapAt(const Offset(500, 500));
        await tester.pump(const Duration(milliseconds: 2999));
        expect(chamadas, isNot(contains('SystemNavigator.pop')));
        await tester.pump(const Duration(milliseconds: 1));
        expect(chamadas, contains('SystemNavigator.pop'));
        await tester.pumpWidget(const SizedBox.shrink());
      });
    });
  });
}
