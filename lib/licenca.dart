import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;
import 'package:package_info_plus/package_info_plus.dart';
import 'package:path_provider/path_provider.dart';
import 'package:pointycastle/export.dart';

import 'app_config.dart';
import 'robo_info.dart';

// Licença do robô. Na primeira vez, o técnico digita o código (FM-XXXX-XXXX)
// e a Central de Licenças da Futuremedia devolve o painel do cliente e um
// "token" assinado. O token fica no robô, vai no cabeçalho X-Licenca do sync e
// do envio das fotos, e é renovado sozinho (ao abrir o app e ao sincronizar).
//
// Sem internet, o robô continua funcionando até o "valido_ate" do token
// (7 dias depois da última renovação, ou o fim do aviso de vencimento).
// A assinatura é conferida com a chave pública abaixo: editar o arquivo no
// robô não libera nada. A chave privada só existe na central.
class Licenca extends ChangeNotifier {
  Licenca._();
  static final Licenca instance = Licenca._();

  // Para testar com outra central: flutter build apk --dart-define=CENTRAL_LICENCAS=<endereço>
  static const String central = String.fromEnvironment(
    'CENTRAL_LICENCAS',
    defaultValue: 'https://suafoto.futuremedia.com.br/clienteFotos/buddyApp/licencas/',
  );

  // Letras do código, sem 0/O e 1/I/L (as mesmas da central, licencas/lib.php)
  static const String alfabeto = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
  static const int tamanhoCodigo = 8;

  // De quanto em quanto tempo o app confere a licença na central (com internet)
  static const Duration conferirACada = Duration(minutes: 1);

  // Chave pública da central (RSA 2048, expoente 65537). A mesma está em
  // clientes/painel/licenca.php. Trocar a chave exige APK novo.
  static final RSAPublicKey _chavePublica = RSAPublicKey(
    BigInt.parse(
      'F32A7A633BEA006730E12A5132F6055F024080F2186C7289DFB4C47629D3D6EE'
      '2CD1B8E6352140E688860D24E594EC4E7204AD57A0BB144880A59CD967C2492B'
      'F8A8C0BECED96DDDDDB822E5A4735701755AF5E7EB2ECC97151ABC6E05B8C148'
      'FB3A717AC0AC1B3F00469E273C3A758960B7B7A2BC0E3B5330D54BAD3FE1E103'
      '39A470CF32D407B6DE76F15B46E229FED5D8411E54283B3A3CBB68D701E9A1BA'
      'E4011F4C0F18ED16A850F56A2C1565158200CEC67564115D58CD37490C07A1CF'
      '6A33DC0EE689BDAFA04E9348D4426B1F418FA1C2C7446288D40A20C998914E2D'
      '47BBEB239EF89C3B4A97509CA43CCD1F198B8F8252657ACCF6B8CC937D49CF61',
      radix: 16,
    ),
    BigInt.from(65537),
  );

  // Recusas da central que travam o app na hora (as outras, como falta de
  // internet, não mudam nada: vale o token guardado até o "valido_ate")
  static const Set<String> _recusasDefinitivas = {'codigo', 'suspensa', 'expirada', 'desvinculado'};

  File? _arquivo;
  String _codigo = ''; // sem traços, ex.: 7K2P9QXA
  String? _token;
  Map<String, dynamic>? _dados; // conteúdo do token, já conferido
  DateTime _recebidoEm = DateTime(2000);
  DateTime _ultimaTentativa = DateTime(2000);

  // Texto da última recusa definitiva, mostrado na tela de ativação
  String motivoBloqueio = '';

  // Enquanto a tela de ativação baixa o visual do cliente, o app não sai dela
  bool preparando = false;
  bool renovando = false;

  String? get token => valida ? _token : null;
  String get codigo => _codigo;
  String get codigoTexto => _codigo.length == tamanhoCodigo ? 'FM-${_codigo.substring(0, 4)}-${_codigo.substring(4)}' : '';
  String get cliente => (_dados?['cliente'] as String?) ?? '';
  String? get painel => valida ? _dados!['painel'] as String : null;
  String get vence => (_dados?['vence'] as String?) ?? '';
  // 'ativa' ou 'vencida' (venceu, mas ainda no aviso)
  String get situacao => (_dados?['situacao'] as String?) ?? '';

  // Até quando vale sem falar com a central, no relógio do robô. Conta a
  // partir de quando o robô recebeu o token, então o relógio do robô pode
  // estar um pouco diferente do relógio do servidor.
  DateTime get validoAte {
    final d = _dados;
    if (d == null) return DateTime(2000);
    final segundos = (d['valido_ate'] as num).toInt() - (d['emitido'] as num).toInt();
    return _recebidoEm.add(Duration(seconds: segundos));
  }

  bool get valida {
    if (_dados == null) return false;
    final agora = DateTime.now();
    // Relógio do robô voltou para antes do recebimento: não dá para medir
    if (agora.isBefore(_recebidoEm.subtract(const Duration(minutes: 5)))) return true;
    return agora.isBefore(validoAte);
  }

  // Chamado no main(), antes do runApp (depois do AppConfig, que tem o ID do robô)
  Future<void> carregar() async {
    try {
      final suporte = await getApplicationSupportDirectory();
      final pasta = Directory('${suporte.path}/config_remota');
      await pasta.create(recursive: true);
      _arquivo = File('${pasta.path}/licenca.json');
      if (!await _arquivo!.exists()) return;

      final salvo = jsonDecode(await _arquivo!.readAsString());
      if (salvo is! Map) return;
      _codigo = (salvo['codigo'] as String?) ?? '';
      motivoBloqueio = (salvo['motivo'] as String?) ?? '';
      final token = salvo['token'] as String?;
      final dados = token == null ? null : _conferir(token);
      if (dados != null) {
        _token = token;
        _dados = dados;
        _recebidoEm = DateTime.tryParse((salvo['recebido_em'] as String?) ?? '') ?? DateTime(2000);
      }
    } catch (e) {
      debugPrint('Erro ao carregar a licença: $e');
    }
  }

  // Ativação com o código digitado no robô. Retorna null se deu certo ou o
  // texto do erro. Um código errado não apaga a licença que já estava no robô.
  Future<String?> ativar(String codigoDigitado) async {
    final codigo = normalizar(codigoDigitado);
    if (codigo == null) {
      // O teclado tem todas as letras e números, mas os códigos não usam
      // 0/O e 1/I/L: com uma delas, o código não existe
      final limpo = codigoDigitado.toUpperCase().replaceAll(RegExp(r'[^A-Z0-9]'), '');
      return limpo.length == tamanhoCodigo || limpo.length == tamanhoCodigo + 2
          ? 'Código não encontrado. Confira as letras e os números.'
          : 'O código tem $tamanhoCodigo letras e números.';
    }
    return _pedir('ativar', codigo);
  }

  // Renova o token (sem ocupar vaga nova). Retorna null se deu certo ou o erro.
  Future<String?> renovar() async {
    if (_codigo.isEmpty) return 'Este robô ainda não tem licença.';
    return _pedir('renovar', _codigo);
  }

  static const String motivoDesvinculado =
      'Este robô foi desvinculado da licença. Para usar o app, digite o código da licença.';

  // Desvincula este robô (tela de admin). O app trava na hora, sempre: apaga
  // a licença daqui e volta para a tela de ativação. Antes, tenta avisar a
  // central para liberar a vaga; se ela não responder (sem internet), o robô
  // trava do mesmo jeito e a vaga continua ocupada por ele (ativar de novo com
  // o mesmo código não gasta outra vaga; a equipe também libera pela central).
  Future<void> desvincular() async {
    final token = _token;
    renovando = true;
    notifyListeners();
    try {
      if (token != null) {
        await http.post(Uri.parse('${central}api/ativar.php'), body: {
          'acao': 'desvincular',
          'token': token,
        }).timeout(const Duration(seconds: 10));
      }
    } catch (e) {
      debugPrint('Desvincular: a central não foi avisada ($e)');
    } finally {
      renovando = false;
      await _apagarLicenca(motivo: motivoDesvinculado);
    }
  }

  Future<void> _apagarLicenca({String motivo = ''}) async {
    _token = null;
    _dados = null;
    _codigo = '';
    motivoBloqueio = motivo;
    await _salvar();
    // O visual baixado era do painel desta licença
    await AppConfig.instance.esquecerPainel();
    notifyListeners();
  }

  void iniciarPreparo() {
    preparando = true;
    notifyListeners();
  }

  void terminarPreparo() {
    preparando = false;
    notifyListeners();
  }

  // Confere a licença na central em segundo plano: ao abrir o app, ao voltar
  // para a tela inicial e a cada minuto parado nela (Tela1). Assim, uma
  // licença suspensa, excluída ou um robô desvinculado na central trava o app
  // em até ~1 minuto. Não espera nem mostra erro: sem internet, segue valendo
  // o token guardado (até o "valido_ate").
  void renovarSeNecessario() {
    if (_codigo.isEmpty || renovando || preparando) return;
    final agora = DateTime.now();
    final desde = agora.difference(_ultimaTentativa);
    if (!desde.isNegative && desde < conferirACada) return;
    _ultimaTentativa = agora;
    renovar();
  }

  Future<String?> _pedir(String acao, String codigo) async {
    renovando = true;
    notifyListeners();
    try {
      String versao = '';
      try {
        final info = await PackageInfo.fromPlatform();
        versao = '${info.version} (${info.buildNumber})';
      } catch (_) {}
      await RoboInfo.carregar();

      final http.Response resposta;
      try {
        resposta = await http.post(Uri.parse('${central}api/ativar.php'), body: {
          'acao': acao,
          'codigo': codigo,
          'dispositivo': AppConfig.instance.dispositivoId,
          'serial': RoboInfo.serial,
          'modelo': RoboInfo.modelo,
          'versao': versao,
        }).timeout(const Duration(seconds: 20));
      } on SocketException {
        return 'Sem conexão com a internet. Confira o Wi-Fi do robô.';
      } on TimeoutException {
        return 'A central de licenças demorou para responder. Tente de novo.';
      } on http.ClientException {
        return 'Sem conexão com a internet. Confira o Wi-Fi do robô.';
      }

      Map dados;
      try {
        final json = jsonDecode(resposta.body);
        if (json is! Map) throw const FormatException();
        dados = json;
      } catch (_) {
        return 'A central de licenças não respondeu direito (HTTP ${resposta.statusCode}). Tente de novo.';
      }

      if (dados['ok'] != true) {
        final mensagem = (dados['message'] as String?) ?? 'A licença foi recusada.';
        if (acao == 'renovar' && _recusasDefinitivas.contains(dados['erro'])) {
          // Suspensa, vencida, excluída ou robô desvinculado: trava agora
          _token = null;
          _dados = null;
          motivoBloqueio = mensagem;
          await _salvar();
        }
        return mensagem;
      }

      final token = dados['token'] as String?;
      final conteudo = token == null ? null : _conferir(token);
      if (conteudo == null) {
        return 'A resposta da central de licenças não é válida. Fale com a Futuremedia.';
      }

      final painelAntigo = _dados?['painel'] as String?;
      // Código que a central diz que este robô ocupa (cada robô tem o seu;
      // robô ativado antes dos códigos por robô pode ter recebido outro)
      _codigo = normalizar((conteudo['codigo'] as String?) ?? '') ?? codigo;
      _token = token;
      _dados = conteudo;
      _recebidoEm = DateTime.now();
      motivoBloqueio = '';
      await _salvar();

      // Licença de outro cliente (ou painel trocado na central): o visual
      // baixado era do painel antigo
      if (painelAntigo != null && painelAntigo != conteudo['painel']) {
        await AppConfig.instance.esquecerPainel();
      }
      return null;
    } finally {
      renovando = false;
      notifyListeners();
    }
  }

  Future<void> _salvar() async {
    final arquivo = _arquivo;
    if (arquivo == null) return;
    try {
      final temporario = File('${arquivo.path}.tmp');
      await temporario.writeAsString(jsonEncode({
        'codigo': _codigo,
        'token': _token,
        'recebido_em': _recebidoEm.toIso8601String(),
        'motivo': motivoBloqueio,
      }), flush: true);
      await temporario.rename(arquivo.path);
    } catch (e) {
      debugPrint('Erro ao salvar a licença: $e');
    }
  }

  // "fm-7k2p 9qxa" -> "7K2P9QXA"; null se não for um código possível
  static String? normalizar(String texto) {
    var limpo = texto.toUpperCase().replaceAll(RegExp(r'[^A-Z0-9]'), '');
    if (limpo.length == tamanhoCodigo + 2 && limpo.startsWith('FM')) limpo = limpo.substring(2);
    if (limpo.length != tamanhoCodigo || limpo.split('').any((c) => !alfabeto.contains(c))) return null;
    return limpo;
  }

  // Confere a assinatura e se o token é deste robô. Devolve o conteúdo ou null.
  static Map<String, dynamic>? _conferir(String token) => conferirToken(token, AppConfig.instance.dispositivoId);

  @visibleForTesting
  static Map<String, dynamic>? conferirToken(String token, String dispositivo) {
    try {
      final partes = token.split('.');
      if (partes.length != 2) return null;
      final assinatura = base64Url.decode(base64Url.normalize(partes[1]));
      final verificador = RSASigner(SHA256Digest(), '0609608648016503040201')
        ..init(false, PublicKeyParameter<RSAPublicKey>(_chavePublica));
      final ok = verificador.verifySignature(
        Uint8List.fromList(ascii.encode(partes[0])),
        RSASignature(Uint8List.fromList(assinatura)),
      );
      if (!ok) return null;

      final dados = jsonDecode(utf8.decode(base64Url.decode(base64Url.normalize(partes[0]))));
      if (dados is! Map || dados['painel'] is! String || dados['valido_ate'] is! num || dados['emitido'] is! num) {
        return null;
      }
      if (dados['dispositivo'] != dispositivo) return null;
      return Map<String, dynamic>.from(dados);
    } catch (e) {
      debugPrint('Token de licença inválido: $e');
      return null;
    }
  }
}
