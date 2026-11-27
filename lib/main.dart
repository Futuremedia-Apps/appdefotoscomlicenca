import 'package:flutter/material.dart';
import 'package:flutter/services.dart'; // Importe services
import 'app_config.dart';
import 'licenca.dart';
import 'robo_info.dart';
import 'tela1.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Molduras, fundos e vídeo baixados do painel (ou os padrões do APK)
  await AppConfig.instance.carregar();
  // Licença do robô (depois do AppConfig, que tem o ID do robô). Sem ela, a
  // tela inicial vira a tela de ativação.
  await Licenca.instance.carregar();
  // Serial de fábrica do robô (vai na ativação e aparece na tela de admin)
  await RoboInfo.carregar();

  // Configura tela cheia
  SystemChrome.setEnabledSystemUIMode(
    SystemUiMode.immersive,
    overlays: [], // remove todas as barras do sistema
  );

  SystemChrome.setPreferredOrientations([
    DeviceOrientation.landscapeLeft,
    DeviceOrientation.landscapeRight,
  ]).then((_) {
    runApp(MyApp());
  });

  //runApp(MyApp());
}

class MyApp extends StatelessWidget {
  const MyApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Futuremedia Fotos',
      onGenerateRoute: (settings) {
        // Verificar se é a rota inicial '/'
        if (settings.name == '/') {
          // Verificar alguma condição se necessário
          // bool usuarioLogado = verificarLogin();
          // return MaterialPageRoute(builder: (context) =>
          //   usuarioLogado ? HomeScreen() : LoginScreen());

          return MaterialPageRoute(builder: (context) => Tela1());
        }
        // Lidar com outras rotas...
        return null;
      },
      debugShowCheckedModeBanner: false,
      home: Tela1(),
    );
  }
}