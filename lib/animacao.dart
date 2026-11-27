import 'dart:convert';
import 'dart:io';
import 'dart:isolate';
import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:http/http.dart' as http;
import 'package:image/image.dart' as img;
import 'package:video_player/video_player.dart';

import 'app_config.dart';
import 'evento.dart';
import 'tela1.dart';
import 'tela3.dart';
import 'tela4.dart';

class Animacao extends StatefulWidget {
  final String selectedFrame;
  final String imagePath;

  const Animacao({super.key, required this.selectedFrame, required this.imagePath});

  @override
  _AnimacaoState createState() => _AnimacaoState();
}

class _AnimacaoState extends State<Animacao> with WidgetsBindingObserver {
  late VideoPlayerController _controller;
  bool _isInitialized = false;
  bool _processingStarted = false;
  String _displayStatus = 'Iniciando...';
  String? _processedImagePath;
  String? _displayUrl;
  bool _controllerDisposed = false; // Flag para controlar se o controller foi destruído

  @override
  void initState() {
    super.initState();
    // Configurar para tela cheia
    SystemChrome.setEnabledSystemUIMode(SystemUiMode.immersive);

    // Registra este objeto como um observador do ciclo de vida da aplicação
    WidgetsBinding.instance.addObserver(this);

    // Iniciar o carregamento do vídeo de forma assíncrona
    _initializeVideoPlayer();
  }

  Future<void> _initializeVideoPlayer() async {
    try {
      setState(() {
        _displayStatus = 'Carregando vídeo...';
      });

      // Inicializa o controlador com o vídeo do asset | Animação de carregamento da foto

      // Vídeo definido no painel web (ou o padrão do APK, ver AppConfig.videoPadrao)
      _controller = Midia.video(AppConfig.instance.video);

      // Inicializar o vídeo
      await _controller.initialize();

      // Configura o vídeo para reprodução em loop automático
      await _controller.setLooping(true);
      await _controller.setVolume(0);
      await _controller.play();

      if (mounted) {
        setState(() {
          _isInitialized = true;
          _displayStatus = 'Iniciando...';
        });

        // Agendar o início do processamento de imagem após o vídeo começar
        // Apenas se ainda não foi iniciado
        if (!_processingStarted) {
          _scheduleImageProcessing();
        }
      }
    } catch (e) {
      print('Erro ao inicializar vídeo: $e');
    }
  }

  // Método auxiliar para destruir o controller de forma segura
  void _safeDisposeController() {
    if (_isInitialized && !_controllerDisposed) {
      try {
        _controller.dispose();
        _controllerDisposed = true;
        print('Controller destruído com sucesso');
      } catch (e) {
        print('Erro ao destruir controller: $e');
      }
    }
  }

  // Popup de erro para problemas com upload
  void _showUploadErrorDialog() {
    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (BuildContext context) {
        return AlertDialog(
          title: Text(
            "Erro no Upload",
            style: TextStyle(
              fontSize: 36,
              fontWeight: FontWeight.bold,
              color: AppConfig.instance.corPrimaria,
            ),),
          content: Text(
            "Ocorreu um problema ao enviar a imagem. Tente novamente?",
            style: TextStyle(
              fontSize: 24,
            ),),
          actions: [
            TextButton(
              onPressed: () {
                Navigator.of(context).pop();
                if (_processedImagePath != null) {
                  _uploadImageToServerFTP(File(_processedImagePath!)); // Tentar novamente
                }
              },
              child: Text('Tentar Novamente'),
            ),
            TextButton(
              onPressed: () {
                Navigator.of(context).pop();
                _navigateToTela3(); // Voltar para tela anterior
              },
              child: Text('Voltar'),
            ),
          ],
        );
      },
    );
  }

  // O painel ficou sem evento: a foto foi recusada e não adianta tentar de
  // novo. Volta ao início, que mostra o aviso "Nenhum evento ativo".
  void _showSemEventoDialog() {
    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (BuildContext context) {
        return AlertDialog(
          title: Text(
            "Nenhum evento ativo",
            style: TextStyle(
              fontSize: 36,
              fontWeight: FontWeight.bold,
              color: AppConfig.instance.corPrimaria,
            ),),
          content: Text(
            "Este robô só tira fotos com um evento aberto. Peça ao responsável para criar ou reabrir o evento no painel.",
            style: TextStyle(
              fontSize: 24,
            ),),
          actions: [
            TextButton(
              onPressed: () {
                Navigator.of(context).pop();
                _navigateToTela1();
              },
              child: Text('Voltar ao início'),
            ),
          ],
        );
      },
    );
  }

  void _navigateToTela1() {
    _safeDisposeController();

    Navigator.of(context).push(
      PageRouteBuilder(
        pageBuilder: (context, animation, secondaryAnimation) => Tela1(),
        transitionDuration: Duration.zero,
        reverseTransitionDuration: Duration.zero,
      ),
    );
  }

  // Navegação para tela3 (tela anterior)
  void _navigateToTela3() {
    // Destruir o controller antes de navegar
    _safeDisposeController();
    
    Navigator.of(context).pushReplacement(
      PageRouteBuilder(
        pageBuilder: (context, animation, secondaryAnimation) => 
            Tela3(
              selectedFrame: widget.selectedFrame,
              imagePath: widget.imagePath,
            ),
        transitionDuration: Duration.zero,
        reverseTransitionDuration: Duration.zero,
      ),
    );
  }

  void _atualizar_displayUrl(String novaUrl) {
    setState(() {
      _displayUrl = novaUrl;
    });
  }

  void _atualizar_displayStatus(String novoStatus) {
    setState(() {
      _displayStatus = novoStatus;
    });
  }

  // Verifica se podemos navegar para a próxima tela
  void _checkAndNavigate() async {
    if ((_processedImagePath != null) && (_displayUrl == null)) {
      _atualizar_displayStatus('Gerando...');

      //_uploadImageToServer(File(_processedImagePath!));
      _uploadImageToServerFTP(File(_processedImagePath!));
      return;
    }

    if ((_processedImagePath != null) && (_displayUrl != null)) {
      _navigateToNextScreen();
      return;
    }
  }

  // Navegação para a próxima tela
  void _navigateToNextScreen() async {
    if ((_processedImagePath == null) || (_displayUrl == null)) {
      return;
    }

    // Destruir o controller antes de navegar
    _safeDisposeController();

    Navigator.of(context).push(
        PageRouteBuilder(
          pageBuilder: (context, animation, secondaryAnimation) =>
              Tela4(
                  selectedFrame: widget.selectedFrame,
                  imagePath: widget.imagePath,
                  displayUrl: _displayUrl!
              ),
          transitionDuration: Duration.zero,
          reverseTransitionDuration: Duration.zero,
        )
    );
  }

  //------- FOTO -------//

  // Agenda o processamento da imagem após o vídeo estar rodando
  void _scheduleImageProcessing() {
    // Verificação dupla para garantir que o processamento só aconteça uma vez
    if (_processingStarted) {
      print('Processamento já foi iniciado anteriormente, ignorando.');
      return;
    }

    print('Agendando processamento de imagem...');
    // Definir flag imediatamente para evitar múltiplas chamadas
    _processingStarted = true;

    // Aguardar alguns quadros do vídeo antes de iniciar o processamento
    Future.delayed(Duration(seconds: 1), () {
      // Verificação adicional para maior segurança
      if (mounted && _processingStarted) {
        print('Iniciando processamento não-bloqueante...');
        _atualizar_displayStatus('Processando...');
        _startNonBlockingImageProcessing();
      }
    });
  }

  // Flag para evitar processamento múltiplo
  bool _processingInProgress = false;

  // Inicia o processamento da imagem de forma não-bloqueante
  Future<void> _startNonBlockingImageProcessing() async {
    // Verificação adicional para garantir que o processamento só aconteça uma vez
    if (_processingInProgress) {
      print('Processamento já está em andamento, ignorando chamada duplicada.');
      return;
    }

    // Marca que o processamento está em andamento
    _processingInProgress = true;

    try {
      print('Carregando dados de imagem para processamento...');

      // Pré-carregar os dados necessários na thread principal
      final File photoFile = File(widget.imagePath);
      final Uint8List photoBytes = await photoFile.readAsBytes();

      // Carregar a moldura
      final Uint8List frameBytes = await Midia.bytes(widget.selectedFrame);

      // Preparar o caminho de saída
      // final String outputPath = widget.imagePath.replaceAll(
      //     '.jpg', '_with_frame.jpg');
      final String outputPath = widget.imagePath;

      print('Dados carregados, iniciando isolate para processamento...');

      // Criar um ReceivePort para comunicação com o isolate
      final ReceivePort receivePort = ReceivePort();

      // Parâmetros para o processamento em isolate
      final Map<String, dynamic> params = {
        'photoBytes': photoBytes,
        'frameBytes': frameBytes,
        'sendPort': receivePort.sendPort,
      };

      // Iniciar um isolate para processamento pesado
      await Isolate.spawn(_isolateProcessImage, params);

      print('Isolate iniciado, aguardando resultados...');

      // Receber o resultado do isolate - apenas uma vez
      receivePort.listen((dynamic resultData) async {
        print('Resultado recebido do isolate');

        if (resultData is Uint8List) {
          // Salvar o resultado como um arquivo
          try {
            final File outputFile = File(outputPath);
            await outputFile.writeAsBytes(resultData);

            print('Imagem processada salva em: $outputPath');

            // Armazenar o caminho para uso posterior
            _processedImagePath = outputPath;

            // Verificar se devemos navegar imediatamente
            _checkAndNavigate();
          } catch (e) {
            print('Erro ao salvar imagem processada: $e');
          }
        } else if (resultData is String && resultData == 'error') {
          print('Erro no processamento da imagem no isolate');
        }

        // Fechar o receivePort para evitar chamadas adicionais
        receivePort.close();
      });
    } catch (e) {
      print('Erro ao preparar processamento de imagem: $e');
    }
  }

  // Função para processamento em isolate - preservando tamanho original com mínimo FullHD
  static void _isolateProcessImage(Map<String, dynamic> params) {
    final Uint8List photoBytes = params['photoBytes'];
    final Uint8List frameBytes = params['frameBytes'];
    final SendPort sendPort = params['sendPort'];

    try {
      // Decodificar imagens mantendo informações completas
      final img.Image? capturedImage = img.decodeImage(photoBytes);
      final img.Image? frameImage = img.decodeImage(frameBytes);

      if (capturedImage == null || frameImage == null) {
        sendPort.send('error');
        return;
      }

      print('Tamanho original da foto: ${capturedImage.width}x${capturedImage
          .height}');
      print('Tamanho original da moldura: ${frameImage.width}x${frameImage
          .height}');

      // ==================== CÓDIGO ORIGINAL (ATIVO) ====================
      // Revertido em 2026-10-29: voltou a ser o padrão (mínimo FullHD 1920x1080,
      // sem corte).

      // Tamanho mínimo FullHD
      const int minWidth = 1920;
      const int minHeight = 1080;

      // Verificar orientação da imagem original
      final bool isPhotoLandscape = capturedImage.width > capturedImage.height;

      // Determinar o fator de escala necessário para chegar ao mínimo FullHD
      double scaleFactorWidth = minWidth / capturedImage.width;
      double scaleFactorHeight = minHeight / capturedImage.height;

      // Usar o maior fator para garantir que ambas as dimensões sejam pelo menos FullHD
      double scaleFactor = isPhotoLandscape
          ? max(scaleFactorWidth, scaleFactorHeight)
          : max(scaleFactorHeight, scaleFactorWidth);

      // Garantir que não reduzimos o tamanho (apenas aumentamos se necessário)
      scaleFactor = scaleFactor < 1.0 ? 1.0 : scaleFactor;

      // Calcular as novas dimensões
      int finalWidth = (capturedImage.width * scaleFactor).round();
      int finalHeight = (capturedImage.height * scaleFactor).round();

      print(
          'Dimensões calculadas: ${finalWidth}x$finalHeight (fator: $scaleFactor)');

      // Redimensionar a foto original para as novas dimensões
      final img.Image resizedPhoto = img.copyResize(
          capturedImage,
          width: finalWidth,
          height: finalHeight,
          interpolation: img.Interpolation.cubic // Alta qualidade
      );
      // ================== FIM DO CÓDIGO ORIGINAL ==================

      // ============ CÓDIGO ALTERADO (DESATIVADO em 2026-10-29) ============
      // Versão 1620x1080 (3:2), redimensionada e cortada pelo centro.
      // Para usar de novo: comente o bloco "CÓDIGO ORIGINAL" acima e
      // descomente este.
      //
      // // Verificar orientação da imagem original
      // final bool isPhotoLandscape = capturedImage.width > capturedImage.height;
      //
      // // Tamanho final fixo: 1620x1080 (paisagem) ou 1080x1620 (retrato)
      // final int finalWidth = isPhotoLandscape ? 1620 : 1080;
      // final int finalHeight = isPhotoLandscape ? 1080 : 1620;
      //
      // // Usar o maior fator para cobrir todo o tamanho final sem distorcer
      // double scaleFactor = max(finalWidth / capturedImage.width,
      //     finalHeight / capturedImage.height);
      //
      // final int scaledWidth = (capturedImage.width * scaleFactor).round();
      // final int scaledHeight = (capturedImage.height * scaleFactor).round();
      //
      // print(
      //     'Dimensões calculadas: ${finalWidth}x$finalHeight (fator: $scaleFactor)');
      //
      // // Redimensionar mantendo a proporção
      // final img.Image scaledPhoto = img.copyResize(
      //     capturedImage,
      //     width: scaledWidth,
      //     height: scaledHeight,
      //     interpolation: img.Interpolation.cubic // Alta qualidade
      // );
      //
      // // Cortar o excesso pelo centro para chegar ao tamanho final
      // final img.Image resizedPhoto = img.copyCrop(
      //     scaledPhoto,
      //     x: (scaledWidth - finalWidth) ~/ 2,
      //     y: (scaledHeight - finalHeight) ~/ 2,
      //     width: finalWidth,
      //     height: finalHeight
      // );
      // ================== FIM DO CÓDIGO ALTERADO ==================

      // Verificar se a moldura precisa ser rotacionada
      final bool isFrameLandscape = frameImage.width > frameImage.height;

      // Ajustar orientação da moldura se necessário
      final img.Image orientedFrame;
      if (isPhotoLandscape != isFrameLandscape) {
        orientedFrame = img.copyRotate(frameImage, angle: 90);
      } else {
        orientedFrame = frameImage;
      }

      // Dimensionar a moldura proporcionalmente para cobrir a imagem
      double frameAspectRatio = orientedFrame.width / orientedFrame.height;
      double photoAspectRatio = finalWidth / finalHeight;

      int frameWidth, frameHeight;

      if (frameAspectRatio > photoAspectRatio) {
        // Moldura proporcionalmente mais larga que a foto
        frameWidth = finalWidth;
        frameHeight = (finalWidth / frameAspectRatio).round();
      } else {
        // Moldura proporcionalmente mais alta que a foto
        frameHeight = finalHeight;
        frameWidth = (finalHeight * frameAspectRatio).round();
      }

      // Redimensionar a moldura
      final img.Image resizedFrame = img.copyResize(
          orientedFrame,
          width: frameWidth,
          height: frameHeight,
          interpolation: img.Interpolation.cubic
      );

      // Criar imagem final com o tamanho da foto redimensionada
      final img.Image finalImage = img.Image(
        width: finalWidth,
        height: finalHeight,
      );

      // Primeiro colocar a foto
      img.compositeImage(
          finalImage,
          resizedPhoto,
          dstX: 0,
          dstY: 0
      );

      // Depois centralizar e aplicar a moldura
      final int frameOffsetX = (finalWidth - frameWidth) ~/ 2;
      final int frameOffsetY = (finalHeight - frameHeight) ~/ 2;

      img.compositeImage(
          finalImage,
          resizedFrame,
          dstX: frameOffsetX,
          dstY: frameOffsetY
      );

      print('Dimensões finais da imagem: ${finalImage.width}x${finalImage
          .height}');

      // Codificar sem redução de qualidade
      final Uint8List resultBytes = Uint8List.fromList(img.encodeJpg(
          finalImage,
          quality: 100
      ));

      // Enviar resultado
      sendPort.send(resultBytes);
    } catch (e) {
      print('Erro no processamento: $e');
      sendPort.send('error');
    }
  }

  // Função auxiliar para obter o máximo entre dois doubles
  static double max(double a, double b) {
    return a > b ? a : b;
  }

  //------- UPLOAD -------//

  Future<void> _uploadImageToServerFTP(File imageFile) async {
    print('Upload de imagem FTP...');
    try {
      await uploadImage(imageFile);
      AppConfig.instance.registrarFoto(enviada: true); // "Fotos de hoje" na tela de admin
      _checkAndNavigate();
    } catch (e) {
      print('Erro no upload FTP: $e');
      AppConfig.instance.registrarFoto(enviada: false);
      if (e is SemEventoException) {
        EventoAtual.instance.semEvento();
        if (mounted) _showSemEventoDialog();
        return;
      }
      if (mounted) {
        _showUploadErrorDialog();
      }
    }
  }

  Future<void> _uploadImageToServer(File imageFile) async {
    try {
      print('Convertendo imagem base64...');
      List<int> imageBytes = imageFile.readAsBytesSync();
      String base64Image = base64Encode(imageBytes);

      print('Upload de imagem...');
      var headers = {
        'Authorization': 'Basic YWRtaW46QEhvbW9sb2c5OQ=='
      };
      
      var request = http.MultipartRequest('POST', Uri.parse('https://api.imgbb.com/1/upload?key=cbeb3136eb817b6c3563e578681f3e65'));
      request.fields.addAll({
        'image': base64Image
      });

      request.headers.addAll(headers);
      
      // Adicionar timeout para evitar travamentos
      http.StreamedResponse response = await request.send().timeout(
        Duration(seconds: 30),
        onTimeout: () {
          throw TimeoutException('Timeout no upload', Duration(seconds: 30));
        },
      );

      if (response.statusCode == 200) {
        try {
          print('Upload de imagem concluido com sucesso...');
          String responseBody = await response.stream.bytesToString();
          final Map<String, dynamic> jsonResponse = jsonDecode(responseBody);

          if (jsonResponse['success'] == true && jsonResponse['data']!= null) {
            _atualizar_displayUrl(jsonResponse['data']['display_url']);
          }

          _checkAndNavigate();
        } catch (e) {
          print('Erro ao decodificar JSON: $e');
          if (mounted) {
            _showUploadErrorDialog();
          }
        }
      } else {
        throw HttpException('Erro HTTP: ${response.statusCode}');
      }
    } catch (e) {
      print('Erro ao enviar imagem: $e');
      if (mounted) {
        _showUploadErrorDialog();
      }
    }
  }

  Future<void> uploadImage(File imageFile) async {
    try {
      // Cria uma requisição multipart
      var request = http.MultipartRequest(
        'POST',
        Uri.parse(AppConfig.instance.urlUpload),
      );

      // Adiciona o arquivo ao corpo da requisição
      request.files.add(
        await http.MultipartFile.fromPath(
          'imagem', // Nome do campo no backend
          imageFile.path,
        ),
      );
      // Pasta das fotos: vem do painel web (config/painel.local.php no servidor)
      String pathFotos = AppConfig.instance.pastaFotos;

      // CORREÇÃO: Adiciona o parâmetro da pasta de fotos
      request.fields['pasta'] = pathFotos;
      // Licença do robô, conferida pelo painel (painel/licenca.php)
      request.headers.addAll(AppConfig.instance.cabecalhoLicenca);

      // Envia a requisição com timeout
      var response = await request.send().timeout(
        Duration(seconds: 30),
        onTimeout: () {
          throw TimeoutException('Timeout no upload', Duration(seconds: 30));
        },
      );

      // Lê a resposta completa
      String responseBody = await response.stream.bytesToString();

      // Verifica a resposta
      if (response.statusCode == 200) {
        // Parse da resposta JSON para obter informações
        var jsonResponse = json.decode(responseBody);

        // ==================== CÓDIGO ORIGINAL ====================
        // Para voltar ao padrão: descomente este bloco e apague o bloco
        // "CÓDIGO ALTERADO" abaixo.
        //
        // String imageName = imageFile.path.split('/').last;
        //
        // // Usa o caminho retornado pelo servidor ou constrói baseado na resposta
        // // String imageUrl = 'https://suafoto.futuremedia.com.br/$pathFotos/$imageName';
        // String imageUrl = '$pathFotos/$imageName';
        // ================== FIM DO CÓDIGO ORIGINAL ==================

        // ==================== CÓDIGO ALTERADO ====================
        // O servidor renomeia a foto (photo_<data>_<código>.jpg) e devolve o
        // caminho real em "filePath". O nome local só é usado se ele não vier.
        String imageName = imageFile.path.split('/').last;
        String? filePath = jsonResponse is Map ? jsonResponse['filePath'] as String? : null;
        String imageUrl = (filePath != null && filePath.isNotEmpty)
            ? filePath
            : (pathFotos.isEmpty ? imageName : '$pathFotos/$imageName');
        // Painel com a página do QR Code (cadastro de leads): o QR abre ela
        final pagina = jsonResponse is Map ? jsonResponse['pagina'] : null;
        if (pagina is String && AppConfig.ehPaginaDoPainel(pagina)) {
          imageUrl = pagina;
        }
        // ================== FIM DO CÓDIGO ALTERADO ==================

        _atualizar_displayUrl(imageUrl);

        // Opcional: mostrar mensagem de sucesso
        // ScaffoldMessenger.of(context).showSnackBar(
        //   SnackBar(content: Text(jsonResponse['message'] ?? 'Upload realizado com sucesso!')),
        // );

      } else {
        // Trata erros HTTP
        var errorResponse = json.decode(responseBody);
        String errorMessage = errorResponse['message'] ?? 'Erro no upload';

        // Painel sem evento: as fotos só podem ser tiradas com um evento
        if (errorResponse is Map && errorResponse['evento'] == false) {
          throw SemEventoException(errorMessage);
        }
        throw HttpException('Erro no servidor: $errorMessage');
      }
    } catch (e) {
      print('Erro no upload: $e');
      // Re-throw para ser capturado pelo método chamador
      rethrow;
    }
  }

  //----------------------//

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    super.didChangeAppLifecycleState(state);

    // Atualiza o estado com base no ciclo de vida do aplicativo
    switch (state) {
      case AppLifecycleState.resumed:
      // O aplicativo está visível e respondendo à entrada do usuário
        break;
      case AppLifecycleState.inactive:
      // O aplicativo não está atualmente recebendo entrada do usuário
      // Este estado ocorre brevemente quando o app troca de estado
        break;
      case AppLifecycleState.paused:
      // O aplicativo não é visível para o usuário (em background)
      // Este é o estado quando o botão HOME é pressionado
        
        // Destruir o controller antes de navegar
        _safeDisposeController();
        
        Navigator.of(context).push(
            PageRouteBuilder(
              pageBuilder: (context, animation, secondaryAnimation) => Tela1(),
              transitionDuration: Duration.zero,
              reverseTransitionDuration: Duration.zero,
            )
        );

        break;
      case AppLifecycleState.detached:
      // O aplicativo está em um estado suspenso de memória
        break;
      default:
        break;
    }
  }

  @override
  void dispose() {
    // Restaura a UI do sistema quando o widget é descartado
    SystemChrome.setEnabledSystemUIMode(SystemUiMode.edgeToEdge);

    // Cancela a inscrição da observação do ciclo de vida
    WidgetsBinding.instance.removeObserver(this);

    // Destruir o controller de forma segura
    _safeDisposeController();
    
    super.dispose();
  }

  // Variável para controlar que o build não inicie múltiplos processamentos
  final bool _scheduledFromBuild = false;

  @override
  Widget build(BuildContext context) {
    final screenSize = MediaQuery
        .of(context)
        .size;
    final screenWidth = screenSize.width;
    final screenHeight = screenSize.height;

    return Scaffold(
      backgroundColor: Colors.black,
      body: Stack(
        children: [
          // Vídeo em tela cheia
          (_isInitialized && !_controllerDisposed)
              ? _buildFullScreenVideo()
              : const Center(
                  child: CircularProgressIndicator(
                    color: Colors.white,
                  ),
                ),

          // Overlay para detectar toques na tela e navegar
          Positioned.fill(
            child: GestureDetector(
              onTap: () {
                if (_isInitialized && !_controllerDisposed) {
                  _navigateToNextScreen();
                }
              },
              child: Container(
                color: Colors.transparent,
              ),
            ),
          ),
          Positioned(
            bottom: screenHeight * 0.02, // 10% acima da parte inferior
            left: screenWidth * 0.27,
            right: 0,
            child: Center(
              child: Text(
                _displayStatus,
                style: TextStyle(
                    fontSize: screenWidth * 0.03,
                    color: Colors.white,
                    fontWeight: FontWeight.bold
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildFullScreenVideo() {
    // Verificação adicional de segurança
    if (_controllerDisposed || !_isInitialized) {
      return Container(color: Colors.black);
    }
    
    return Container(
      width: double.infinity,
      height: double.infinity,
      color: Colors.black,
      child: FittedBox(
        fit: BoxFit.cover,
        child: SizedBox(
          width: _controller.value.size.width,
          height: _controller.value.size.height,
          child: VideoPlayer(_controller),
        ),
      ),
    );
  }
}
