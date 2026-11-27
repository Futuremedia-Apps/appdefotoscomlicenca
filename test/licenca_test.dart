import 'package:flutter_test/flutter_test.dart';
import 'package:camerashot/licenca.dart';

import 'token_teste.dart';

const token = tokenTeste;


void main() {
  test('aceita o token assinado pela central', () {
    final dados = Licenca.conferirToken(token, 'robo111111ab');
    expect(dados, isNotNull);
    expect(dados!['licenca'], 'FM-VSGY-4Z72');
    expect(dados['cliente'], 'Buffet Alegria');
  });

  test('recusa token de outro robô', () {
    expect(Licenca.conferirToken(token, 'outrorobo123'), isNull);
  });

  test('recusa token alterado', () {
    final partes = token.split('.');
    final trocado = partes[0].substring(0, 30) + (partes[0][30] == 'A' ? 'B' : 'A') + partes[0].substring(31);
    expect(Licenca.conferirToken('$trocado.${partes[1]}', 'robo111111ab'), isNull);
    expect(Licenca.conferirToken('lixo', 'robo111111ab'), isNull);
  });

  test('normaliza o código digitado', () {
    expect(Licenca.normalizar('fm-vsgy-4z72'), 'VSGY4Z72');
    expect(Licenca.normalizar('VSGY 4Z72'), 'VSGY4Z72');
    expect(Licenca.normalizar('VSGY4Z7'), isNull);
    expect(Licenca.normalizar('VSGY4Z7O'), isNull); // O não existe no código
  });
}
