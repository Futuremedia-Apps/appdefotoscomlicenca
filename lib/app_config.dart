import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:math';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:http/http.dart' as http;
import 'package:path_provider/path_provider.dart';
import 'package:video_player/video_player.dart';

import 'licenca.dart';

// Configuração remota do app: molduras, fundos, vídeo de carregamento etc.
// O cliente edita tudo no painel web (appFoto/painel/) e o robô baixa pela
// tela de admin (admin.dart), só quando alguém toca em Sincronizar.
// Antes da primeira sincronização, sem internet ou sem o arquivo no painel,
// vale o que está no APK (assets/), então o app sempre funciona.
class AppConfig extends ChangeNotifier {
  AppConfig._();
  static final AppConfig instance = AppConfig._();

  // Painel, sync e upload das fotos
  static const String servidorPadrao = 'https://suafoto.futuremedia.com.br/clienteFotos/buddyApp/';
  // Página antiga do QR Code, só para painel que ainda não devolve "pagina"
  // (o painel novo tem a própria página, com o cadastro de leads: urlCompartilhar)
  static const String paginaCompartilhar = 'https://suafoto.futuremedia.com.br/appsBuddy/appFoto/camerashot.html';
  // Pasta das fotos dentro do servidor; vazia = na raiz (clienteFotos/buddyApp/)
  static const String pastaFotosPadrao = '';
  static const String pinPadrao = '1234';
  static const int _tempoInatividadePadrao = 60;
  static const Color corPrimariaPadrao = Color(0xFF0b3675);

  // Imagens que podem ser trocadas no painel -> imagem padrão do APK.
  // As chaves são as mesmas do painel (painel/lib.php, painelSlots()).
  // Cada tela é uma imagem inteira, com o fundo e os textos juntos.
  static const Map<String, String> imagensPadrao = {
    'tela_inicio': 'assets/images/tela_inicio.jpg',
    'tela_termos': 'assets/images/tela_termos.jpg',
    'tela_recusa': 'assets/images/tela_recusa.jpg',
    'tela_final': 'assets/images/tela_final.jpg',
    'balao': 'assets/images/balao_img2.png',
  };

  // Posição e tamanho dos botões e elementos, em frações da tela:
  // [esquerda, topo, largura, altura]. Valem até a primeira sincronização;
  // depois vem o que foi arrumado no editor do painel. Os nomes e valores são
  // os mesmos do painel (painel/lib.php, painelTelas()).
  static const Map<String, List<double>> layoutPadrao = {
    // 1. Início
    'iniciar': [0.35, 0.81, 0.30, 0.16],
    'sair': [0.01, 0.02, 0.12, 0.10],
    // 2. Termos e 2a. Aviso "Não aceito"
    'nao_aceito': [0.18, 0.81, 0.30, 0.16],
    'aceito': [0.52, 0.81, 0.30, 0.16],
    'recusa_sair': [0.1317, 0.692, 0.30, 0.16],
    'recusa_voltar': [0.4683, 0.692, 0.40, 0.16],
    // 3. Câmera
    'tirar_foto': [0.8225, 0.12, 0.1575, 0.28],
    'balao': [0.5625, 0.1983, 0.25, 0.1235],
    'trocar_moldura': [0.85625, 0.42, 0.12375, 0.22],
    'camera_inicio': [0.85625, 0.66, 0.12375, 0.22],
    // 4. Revisão
    'salvar': [0.8225, 0.24, 0.1575, 0.28],
    'refazer': [0.839375, 0.54, 0.12375, 0.22],
    // 6. Final
    'foto': [0.05, 0.05, 0.636, 0.6484],
    'qrcode': [0.75, 0.2444, 0.16, 0.2844], // alinhado com os textos da tela_final.jpg
    'finalizar': [0.35, 0.81, 0.30, 0.16],
  };
  static const String videoPadrao = 'assets/videos/buddy-animacao.mp4';
  static const List<String> moldurasPadrao = ['assets/images/moldura1.png'];

  Directory? _pasta;

  // Painel do cliente: vem da licença do robô (licenca.dart). O padrão só
  // vale enquanto o robô não tem licença, e aí o app nem passa da ativação.
  String get servidor {
    final painel = Licenca.instance.painel;
    if (painel == null || painel.isEmpty) return servidorPadrao;
    return painel.endsWith('/') ? painel : '$painel/';
  }

  // Cabeçalho que o painel confere (painel/licenca.php)
  Map<String, String> get cabecalhoLicenca {
    final token = Licenca.instance.token;
    return token == null ? const {} : {'X-Licenca': token};
  }

  // ID deste robô (vinculo.json), aparece na lista de robôs do painel
  String dispositivoId = '';

  // Última configuração baixada (config.json), no formato do sync.php
  Map<String, dynamic> _config = {};

  bool sincronizando = false;

  // O que mudou na última sincronização (mostrado na tela de admin)
  List<String> ultimoResumo = [];

  // Fotos do dia (contador.json): enviadas ao servidor e tentativas que falharam
  String _diaContador = '';
  int _fotosEnviadas = 0;
  int _fotosComFalha = 0;
  int get fotosHoje => _diaContador == _hoje() ? _fotosEnviadas : 0;
  int get falhasHoje => _diaContador == _hoje() ? _fotosComFalha : 0;

  bool get sincronizado => _config.isNotEmpty;
  String get nomeCliente => (_config['cliente'] as String?) ?? '';
  int get versao => (_config['versao'] as num?)?.toInt() ?? 0;
  String get atualizadoEm => (_config['atualizado_em'] as String?) ?? '';
  String get sincronizadoEm => (_config['sincronizado_em'] as String?) ?? '';
  int get tempoInatividade => _lerTempoInatividade(_config);

  static int _lerTempoInatividade(Map<String, dynamic> config) {
    final segundos = config['tempo_inatividade'];
    return segundos is int && segundos > 0 && segundos <= 0x7fffffff
        ? segundos
        : _tempoInatividadePadrao;
  }

  // Vazia quer dizer a raiz do servidor, então só cai no padrão sem configuração
  String get pastaFotos => (_config['pasta_fotos'] as String?) ?? pastaFotosPadrao;

  String get pin {
    final valor = _config['pin_admin'] as String?;
    return (valor != null && valor.isNotEmpty) ? valor : pinPadrao;
  }

  Color get corPrimaria {
    final hex = ((_config['cor_primaria'] as String?) ?? '').replaceAll('#', '');
    final valor = int.tryParse(hex, radix: 16);
    return (hex.length == 6 && valor != null) ? Color(0xFF000000 | valor) : corPrimariaPadrao;
  }

  String get urlUpload => '${servidor}uploadImage.php';

  // Página do QR Code no painel do cliente (painel/qr.php: cadastro de leads e
  // a foto), como o uploadImage.php devolve em "pagina". Só esse formato vale.
  static bool ehPaginaDoPainel(String caminho) =>
      caminho.startsWith('painel/qr.php?') && !caminho.contains('..') && !caminho.contains('://');

  // Endereço do QR Code: a página do painel, se o servidor mandou; senão (painel
  // antigo) a página antiga, com o endereço completo da foto
  String urlCompartilhar(String imagem) {
    if (ehPaginaDoPainel(imagem)) return servidor + imagem;
    final urlFoto = imagem.startsWith('http') ? imagem : servidor + imagem;
    return '$paginaCompartilhar?imagem=${Uri.encodeComponent(urlFoto)}';
  }

  // Caminho de uma imagem: arquivo baixado do painel ou o padrão do APK
  String caminhoImagem(String chave) {
    final midia = _config['midia'];
    final item = midia is Map ? midia[chave] : null;
    return _arquivoLocal(item) ?? imagensPadrao[chave]!;
  }

  ImageProvider imagem(String chave) => Midia.provider(caminhoImagem(chave));

  // Imagem que o cliente colocou no botão, ou null para o botão padrão do app
  ImageProvider? imagemBotao(String elemento) {
    final midia = _config['midia'];
    final caminho = _arquivoLocal(midia is Map ? midia['botao_$elemento'] : null);
    return caminho == null ? null : Midia.provider(caminho);
  }

  // Retângulo do elemento em frações da tela (0 a 1)
  Rect retangulo(String elemento) {
    final layout = _config['layout'];
    final item = layout is Map ? layout[elemento] : null;
    if (item is Map) {
      final valores = ['x', 'y', 'w', 'h'].map((k) => item[k]).toList();
      if (valores.every((v) => v is num)) {
        final v = valores.map((v) => (v as num).toDouble()).toList();
        return Rect.fromLTWH(v[0], v[1], v[2], v[3]);
      }
    }
    final p = layoutPadrao[elemento]!;
    return Rect.fromLTWH(p[0], p[1], p[2], p[3]);
  }

  // Botões da câmera. Sem o botão Moldura (só 1 moldura), o buraco que ele
  // deixa entre Tirar foto e Início é fechado: o botão de depois sobe para o
  // lugar da Moldura e os dois são centralizados no espaço que o grupo ocupava.
  // O balão acompanha o Tirar foto, para continuar apontando para ele.
  // A mesma conta está no editor do painel (editor.js, semMoldura).
  Rect retanguloCamera(String elemento, {required bool comMoldura}) {
    final r = retangulo(elemento);
    if (comMoldura || !const ['tirar_foto', 'camera_inicio', 'balao'].contains(elemento)) return r;
    final referencia = elemento == 'balao' ? 'tirar_foto' : elemento;

    final foto = retangulo('tirar_foto');
    final moldura = retangulo('trocar_moldura');
    final inicio = retangulo('camera_inicio');
    // Os botões estão um embaixo do outro ou lado a lado?
    final vertical = (inicio.center.dy - foto.center.dy).abs() >= (inicio.center.dx - foto.center.dx).abs();
    double comeco(Rect r) => vertical ? r.top : r.left;
    double meio(Rect r) => vertical ? r.center.dy : r.center.dx;

    final fotoAntes = meio(foto) <= meio(inicio);
    final primeiro = fotoAntes ? foto : inicio;
    final ultimo = fotoAntes ? inicio : foto;
    // Só fecha o buraco se a Moldura estiver mesmo entre os dois
    if (meio(moldura) <= meio(primeiro) || meio(moldura) >= meio(ultimo)) return r;

    final sobe = comeco(ultimo) - comeco(moldura);
    if (sobe <= 0) return r;
    // O último sobe "sobe"; depois os dois descem metade disso para ficar no meio
    final desloca = (referencia == 'tirar_foto') == fotoAntes ? sobe / 2 : -sobe / 2;
    return vertical ? r.translate(0, desloca) : r.translate(desloca, 0);
  }

  String get video {
    final midia = _config['midia'];
    return _arquivoLocal(midia is Map ? midia['video_carregamento'] : null) ?? videoPadrao;
  }

  List<String> get molduras {
    final lista = _config['molduras'];
    final caminhos = <String>[];
    if (lista is List) {
      for (final item in lista) {
        final caminho = _arquivoLocal(item);
        if (caminho != null) caminhos.add(caminho);
      }
    }
    return caminhos.isNotEmpty ? caminhos : moldurasPadrao;
  }

  String? _arquivoLocal(dynamic item) {
    if (_pasta == null || item is! Map) return null;
    final nome = item['arquivo'];
    if (nome is! String || !_nomeArquivoValido(nome)) return null;
    final arquivo = File('${_pastaMidia.path}/$nome');
    return arquivo.existsSync() ? arquivo.path : null;
  }

  static bool _nomeArquivoValido(String nome) => RegExp(r'^[a-zA-Z0-9_-]+\.[a-z0-9]+$').hasMatch(nome);

  Directory get _pastaMidia => Directory('${_pasta!.path}/midia');
  File get _arquivoVinculo => File('${_pasta!.path}/vinculo.json');
  File get _arquivoConfig => File('${_pasta!.path}/config.json');
  File get _arquivoContador => File('${_pasta!.path}/contador.json');

  // Chamado uma vez no main(), antes do runApp
  Future<void> carregar() async {
    try {
      final suporte = await getApplicationSupportDirectory();
      _pasta = Directory('${suporte.path}/config_remota');
      await _pastaMidia.create(recursive: true);

      if (await _arquivoVinculo.exists()) {
        final vinculo = jsonDecode(await _arquivoVinculo.readAsString());
        dispositivoId = (vinculo['dispositivo'] as String?) ?? '';
      }
      if (await _arquivoConfig.exists()) {
        _config = Map<String, dynamic>.from(jsonDecode(await _arquivoConfig.readAsString()));
      }
      if (dispositivoId.isEmpty) {
        dispositivoId = _gerarId();
        await _salvarVinculo();
      }
      if (await _arquivoContador.exists()) {
        final contador = jsonDecode(await _arquivoContador.readAsString());
        _diaContador = (contador['dia'] as String?) ?? '';
        _fotosEnviadas = (contador['enviadas'] as num?)?.toInt() ?? 0;
        _fotosComFalha = (contador['falhas'] as num?)?.toInt() ?? 0;
      }

      // Arquivos de versões antigas só são apagados aqui, com o app parado,
      // para não sumir uma moldura que esteja sendo usada numa sessão.
      await _limparArquivosAntigos();
    } catch (e) {
      print('Erro ao carregar configuração remota: $e');
    }
  }

  // Baixa a configuração do painel e os arquivos que ainda não estão no robô.
  // Retorna null se deu certo ou a mensagem de erro.
  Future<String?> sincronizar({void Function(String)? progresso}) async {
    if (_pasta == null) return 'Armazenamento do app indisponível.';
    if (sincronizando) return 'Já existe uma sincronização em andamento.';

    sincronizando = true;
    notifyListeners();

    try {
      // Renova a licença antes: pega o painel atualizado e confirma que ela
      // ainda vale. Sem internet aqui, o sync também não daria certo.
      progresso?.call('Conferindo a licença...');
      final erroLicenca = await Licenca.instance.renovar();
      if (!Licenca.instance.valida) return erroLicenca ?? 'Este robô não tem licença válida.';

      final servidorUsado = servidor;
      progresso?.call('Conectando ao painel...');
      final url = Uri.parse('${servidorUsado}painel/api/sync.php').replace(queryParameters: {
        'dispositivo': dispositivoId,
        'versao': versao.toString(),
      });
      final http.Response resposta;
      try {
        resposta = await http.get(url, headers: cabecalhoLicenca).timeout(const Duration(seconds: 20));
      } on SocketException {
        return 'Sem conexão com o servidor. Confira a internet do robô.';
      } on TimeoutException {
        return 'O servidor demorou para responder. Tente de novo.';
      }

      // As mensagens não mostram endereços: a tela de admin é vista pelo cliente
      if (resposta.statusCode == 404) {
        return 'Painel não encontrado no servidor (erro 404). Fale com o suporte.';
      }
      Map<String, dynamic> dados;
      try {
        dados = Map<String, dynamic>.from(jsonDecode(resposta.body));
      } catch (_) {
        return 'Resposta inválida do servidor (HTTP ${resposta.statusCode}).';
      }
      if (resposta.statusCode != 200 || dados['ok'] != true) {
        return (dados['message'] as String?) ?? 'Erro HTTP ${resposta.statusCode}.';
      }

      // Lista de arquivos que ainda não estão no aparelho
      final itens = <Map>[];
      final midia = dados['midia'];
      if (midia is Map) itens.addAll(midia.values.whereType<Map>());
      final molduras = dados['molduras'];
      if (molduras is List) itens.addAll(molduras.whereType<Map>());

      final faltando = itens.where((item) {
        final nome = item['arquivo'];
        return nome is String && _nomeArquivoValido(nome) && !File('${_pastaMidia.path}/$nome').existsSync();
      }).toList();

      for (var i = 0; i < faltando.length; i++) {
        progresso?.call('Baixando arquivo ${i + 1} de ${faltando.length}...');
        await _baixar(servidorUsado + (faltando[i]['caminho'] as String), faltando[i]['arquivo'] as String);
      }

      dados['sincronizado_em'] = DateTime.now().toIso8601String();
      await _gravar(_arquivoConfig, jsonEncode(dados));
      ultimoResumo = _resumirMudancas(_config, dados);
      _config = dados;

      progresso?.call('Sincronizado.');
      return null;
    } on SocketException catch (e) {
      print('Falha na sincronização: $e');
      return 'A conexão caiu no meio da sincronização. Confira a internet do robô e tente de novo.';
    } catch (e) {
      print('Falha na sincronização: $e');
      return 'Não foi possível baixar todos os arquivos. Tente de novo.';
    } finally {
      sincronizando = false;
      notifyListeners();
    }
  }

  // Licença de outro painel: esquece o visual baixado (os arquivos que
  // sobrarem são apagados na próxima vez que o app abrir) e volta ao padrão
  // do APK até a próxima sincronização.
  Future<void> esquecerPainel() async {
    _config = {};
    ultimoResumo = [];
    try {
      if (_pasta != null && await _arquivoConfig.exists()) await _arquivoConfig.delete();
    } catch (e) {
      debugPrint('Erro ao apagar a configuração antiga: $e');
    }
    notifyListeners();
  }

  // Baixa direto para o disco (o vídeo pode ter dezenas de MB)
  Future<void> _baixar(String url, String nome) async {
    final temporario = File('${_pastaMidia.path}/$nome.part');
    final cliente = http.Client();
    try {
      final resposta = await cliente.send(http.Request('GET', Uri.parse(url))).timeout(const Duration(seconds: 30));
      if (resposta.statusCode != 200) {
        throw HttpException('HTTP ${resposta.statusCode} ao baixar $nome');
      }
      final saida = temporario.openWrite();
      try {
        await resposta.stream.timeout(const Duration(seconds: 60)).pipe(saida);
      } catch (e) {
        await saida.close();
        rethrow;
      }
      await temporario.rename('${_pastaMidia.path}/$nome');
    } catch (e) {
      if (await temporario.exists()) await temporario.delete();
      rethrow;
    } finally {
      cliente.close();
    }
  }

  Future<void> _limparArquivosAntigos() async {
    final usados = <String>{};
    final midia = _config['midia'];
    if (midia is Map) {
      for (final item in midia.values) {
        if (item is Map && item['arquivo'] is String) usados.add(item['arquivo']);
      }
    }
    final molduras = _config['molduras'];
    if (molduras is List) {
      for (final item in molduras) {
        if (item is Map && item['arquivo'] is String) usados.add(item['arquivo']);
      }
    }

    await for (final entrada in _pastaMidia.list()) {
      final nome = entrada.uri.pathSegments.last;
      if (entrada is File && !usados.contains(nome)) {
        await entrada.delete();
      }
    }
  }

  // Nomes que o cliente vê no painel, para o resumo da sincronização
  static const Map<String, String> _nomesMidia = {
    'tela_inicio': 'tela Início',
    'tela_termos': 'tela Termos de uso',
    'tela_recusa': 'tela Aviso "Não aceito"',
    'tela_final': 'tela Final',
    'balao': 'balão',
    'video_carregamento': 'vídeo de carregamento',
  };
  static const Map<String, String> _nomesBotoes = {
    'iniciar': 'Iniciar',
    'sair': 'Sair',
    'nao_aceito': 'Não aceito',
    'aceito': 'Aceito',
    'recusa_sair': 'Não tirar foto',
    'recusa_voltar': 'Voltar para Termos',
    'tirar_foto': 'Tirar foto',
    'trocar_moldura': 'Moldura',
    'camera_inicio': 'Início (câmera)',
    'salvar': 'Salvar',
    'refazer': 'Tirar nova foto',
    'finalizar': 'Finalizar',
  };

  static String _nomeMidia(String chave) {
    if (chave.startsWith('botao_')) {
      final botao = chave.substring(6);
      return 'botão ${_nomesBotoes[botao] ?? botao}';
    }
    return _nomesMidia[chave] ?? chave;
  }

  // Compara a configuração antiga com a nova, em frases para o cliente
  static List<String> _resumirMudancas(Map<String, dynamic> antes, Map<String, dynamic> depois) {
    if (antes.isEmpty) return ['Primeira sincronização: tudo foi baixado do painel.'];

    String? arquivo(dynamic item) => item is Map ? item['arquivo'] as String? : null;
    Map<String, String> midias(Map<String, dynamic> c) {
      final m = c['midia'];
      final saida = <String, String>{};
      if (m is Map) {
        m.forEach((chave, item) {
          final nome = arquivo(item);
          if (nome != null) saida['$chave'] = nome;
        });
      }
      return saida;
    }
    List<String> molduras(Map<String, dynamic> c) {
      final l = c['molduras'];
      return l is List ? l.map(arquivo).whereType<String>().toList() : <String>[];
    }

    final resumo = <String>[];

    final moldurasAntes = molduras(antes);
    final moldurasDepois = molduras(depois);
    final novas = moldurasDepois.where((m) => !moldurasAntes.contains(m)).length;
    final removidas = moldurasAntes.where((m) => !moldurasDepois.contains(m)).length;
    final partes = <String>[
      if (novas > 0) novas == 1 ? '1 nova' : '$novas novas',
      if (removidas > 0) removidas == 1 ? '1 removida' : '$removidas removidas',
    ];
    if (partes.isNotEmpty) {
      resumo.add('Molduras: ${partes.join(', ')}.');
    } else if (moldurasAntes.join() != moldurasDepois.join()) {
      resumo.add('Molduras: a ordem mudou.');
    }

    final midiaAntes = midias(antes);
    final midiaDepois = midias(depois);
    final trocados = <String>[];
    final padrao = <String>[];
    for (final chave in {...midiaAntes.keys, ...midiaDepois.keys}) {
      if (!midiaDepois.containsKey(chave)) {
        padrao.add(_nomeMidia(chave));
      } else if (midiaAntes[chave] != midiaDepois[chave]) {
        trocados.add(_nomeMidia(chave));
      }
    }
    if (trocados.isNotEmpty) resumo.add('Trocados: ${trocados.join(', ')}.');
    if (padrao.isNotEmpty) resumo.add('Voltaram ao padrão: ${padrao.join(', ')}.');

    if (jsonEncode(antes['layout']) != jsonEncode(depois['layout'])) {
      resumo.add('Posição ou tamanho dos botões mudou.');
    }
    if (antes['cor_primaria'] != depois['cor_primaria']) resumo.add('A cor principal mudou.');
    if (antes['pin_admin'] != depois['pin_admin']) resumo.add('O PIN desta tela mudou.');
    final novoTempo = _lerTempoInatividade(depois);
    if (_lerTempoInatividade(antes) != novoTempo) {
      resumo.add('Tempo de inatividade: $novoTempo segundos.');
    }

    return resumo.isEmpty ? ['Nada mudou desde a última sincronização.'] : resumo;
  }

  static String _hoje() {
    final agora = DateTime.now();
    return '${agora.year}-${agora.month.toString().padLeft(2, '0')}-${agora.day.toString().padLeft(2, '0')}';
  }

  // Chamado depois de cada tentativa de enviar uma foto ao servidor
  Future<void> registrarFoto({required bool enviada}) async {
    final hoje = _hoje();
    if (_diaContador != hoje) {
      _diaContador = hoje;
      _fotosEnviadas = 0;
      _fotosComFalha = 0;
    }
    if (enviada) {
      _fotosEnviadas++;
    } else {
      _fotosComFalha++;
    }
    notifyListeners();
    if (_pasta == null) return;
    try {
      await _gravar(_arquivoContador, jsonEncode({
        'dia': _diaContador,
        'enviadas': _fotosEnviadas,
        'falhas': _fotosComFalha,
      }));
    } catch (e) {
      print('Erro ao salvar o contador de fotos: $e');
    }
  }

  // Teste da tela de admin. internet: o robô chega na internet; envio: o
  // uploadImage.php responde (um GET dá 405 com JSON, sem gravar nada).
  Future<({bool internet, bool envio})> testarConexao() async {
    Future<bool> responde(String url, bool Function(http.Response) ok) async {
      try {
        return ok(await http.get(Uri.parse(url)).timeout(const Duration(seconds: 10)));
      } catch (_) {
        return false;
      }
    }

    final resultados = await Future.wait([
      responde('https://www.gstatic.com/generate_204', (r) => r.statusCode == 204),
      responde(urlUpload, (r) {
        try {
          return jsonDecode(r.body) is Map;
        } catch (_) {
          return false;
        }
      }),
    ]);
    return (internet: resultados[0], envio: resultados[1]);
  }

  Future<void> _salvarVinculo() => _gravar(_arquivoVinculo, jsonEncode({
        'dispositivo': dispositivoId,
      }));

  // Grava num temporário e renomeia, para nunca deixar um JSON pela metade
  static Future<void> _gravar(File arquivo, String conteudo) async {
    final temporario = File('${arquivo.path}.tmp');
    await temporario.writeAsString(conteudo, flush: true);
    await temporario.rename(arquivo.path);
  }

  static String _gerarId() {
    final aleatorio = Random.secure();
    return List.generate(12, (_) => aleatorio.nextInt(16).toRadixString(16)).join();
  }
}

// Imagens e vídeos podem vir do APK (assets/...) ou de um arquivo baixado
class Midia {
  static bool ehAsset(String caminho) => caminho.startsWith('assets/');

  static ImageProvider provider(String caminho) =>
      ehAsset(caminho) ? AssetImage(caminho) : FileImage(File(caminho)) as ImageProvider;

  static Future<Uint8List> bytes(String caminho) async {
    if (ehAsset(caminho)) {
      final dados = await rootBundle.load(caminho);
      return dados.buffer.asUint8List();
    }
    return File(caminho).readAsBytes();
  }

  static VideoPlayerController video(String caminho) =>
      ehAsset(caminho) ? VideoPlayerController.asset(caminho) : VideoPlayerController.file(File(caminho));
}
