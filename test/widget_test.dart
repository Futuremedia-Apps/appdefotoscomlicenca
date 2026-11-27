import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:camerashot/main.dart';

// Sem licença, o app abre na tela de ativação, com o teclado completo
// (campo em cima, teclado embaixo) e o código montado tecla a tecla.
void main() {
  testWidgets('sem licença, abre a tela de ativação e o teclado digita o código', (tester) async {
    tester.view.physicalSize = const Size(1920, 1080);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(const MyApp());
    await tester.pump();

    expect(find.text('Ativar licença'), findsOneWidget);
    // Todas as teclas, inclusive 1 e 0
    for (final tecla in '1234567890QWERTYUIOPASDFGHJKLZXCVBNM'.split('')) {
      expect(find.text(tecla), findsOneWidget, reason: 'falta a tecla $tecla');
    }

    expect(find.text('FM-____-____'), findsOneWidget);
    for (final tecla in ['7', 'K', '2', 'P']) {
      await tester.tap(find.text(tecla));
      await tester.pump();
    }
    expect(find.text('FM-7K2P-____'), findsOneWidget);

    // Apagar tira a última
    await tester.tap(find.bySemanticsLabel('Apagar'));
    await tester.pump();
    expect(find.text('FM-7K2_-____'), findsOneWidget);
  });
}
