import 'package:flutter_test/flutter_test.dart';

import 'package:camerashot/app_config.dart';

// Endereço do QR Code da tela final. Painel novo: o uploadImage.php devolve a
// página do painel (painel/qr.php, com o cadastro de leads) e o QR abre ela.
// Painel antigo: o QR abre a página antiga com o endereço completo da foto.
void main() {
  final config = AppConfig.instance;
  final servidor = config.servidor; // sem licença nos testes: o servidor padrão

  test('página do painel: o QR abre o qr.php do painel do cliente', () {
    const pagina = 'painel/qr.php?e=2026-11-24-festa&f=photo_2026-11-24_14-03-22_ab12cd34.jpg';
    expect(AppConfig.ehPaginaDoPainel(pagina), isTrue);
    expect(config.urlCompartilhar(pagina), '${servidor}painel/qr.php?e=2026-11-24-festa&f=photo_2026-11-24_14-03-22_ab12cd34.jpg');
  });

  test('painel antigo: o QR abre a página antiga com a foto', () {
    const foto = 'fotos/eventos/2026-11-24-festa/photo_2026-11-24_14-03-22_ab12cd34.jpg';
    expect(AppConfig.ehPaginaDoPainel(foto), isFalse);
    expect(config.urlCompartilhar(foto), '${AppConfig.paginaCompartilhar}?imagem=${Uri.encodeComponent(servidor + foto)}');
  });

  test('"pagina" estranha não vira o QR Code', () {
    expect(AppConfig.ehPaginaDoPainel('https://outro.site/painel/qr.php?e=x'), isFalse);
    expect(AppConfig.ehPaginaDoPainel('painel/qr.php?e=../../x'), isFalse);
    expect(AppConfig.ehPaginaDoPainel('painel/outra.php?e=x'), isFalse);
    expect(AppConfig.ehPaginaDoPainel(''), isFalse);
  });
}
