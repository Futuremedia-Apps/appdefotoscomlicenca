import 'dart:io';
import 'dart:async';
import 'package:camera/camera.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:outlined_text/outlined_text.dart';

import 'app_config.dart';
import 'elementos_tela.dart';
import 'tela1.dart';
import 'tela3.dart';

class Tela2 extends StatefulWidget {
  const Tela2({super.key});

  @override
  _Tela2State createState() => _Tela2State();
}

class _Tela2State extends State<Tela2> with WidgetsBindingObserver {
  Timer? _timer;
  CameraController? _cameraController;
  List<CameraDescription>? _cameras = [];
  XFile? imageFile; // Para armazenar a foto tirada
  final int _currentCameraIndex = 0;
  bool _isCapturing = false;
  bool _isCameraInitialized = false;
  bool _isCountdown = false;
  bool _isLoading = false;
  bool _isPhotoTaken = false; // Para controlar a visibilidade do preview
  String _countdownValue = '5';
  int _secondsRemaining = 120; // Total tempo em segundos
  bool _showCountdown = false; // Controla a exibiÃ§Ã£o do contador

  // VariÃ¡veis para o popup de molduras
  bool _showFrameSelector = true;
  String _selectedFrame = 'assets/images/moldura1.png'; // Moldura padrÃ£o
  final PageController _framePageController = PageController();
  List<String> _availableFrames = []; // Lista de molduras disponÃ­veis
  int _currentFramePage = 0; // Página atual do carrossel de molduras

  @override
  void initState() {
    super.initState();

    WidgetsBinding.instance.addObserver(this);

    _loadAvailableFrames();
    _initializeCamera();
    _startTimer();
  }

  // MÃ©todo para carregar as molduras disponÃ­veis
  void _loadAvailableFrames() async {
    try {
      // Molduras cadastradas no painel web (ou a padrão do APK, ver
      // AppConfig.moldurasPadrao). Não é mais preciso mudar aqui.
      _availableFrames = AppConfig.instance.molduras;
    } catch (e) {
      print('Erro ao carregar molduras: $e');
      _availableFrames = AppConfig.moldurasPadrao; // Fallback
    }
    _selectedFrame = _availableFrames.first;
  }

  // Método para navegar para a página anterior
  void _goToPreviousPage() {
    if (_currentFramePage > 0) {
      _framePageController.previousPage(
        duration: Duration(milliseconds: 300),
        curve: Curves.easeInOut,
      );
    }
  }

  // Método para navegar para a próxima página
  void _goToNextPage() {
    final totalPages = (_availableFrames.length / 2).ceil();
    if (_currentFramePage < totalPages - 1) {
      _framePageController.nextPage(
        duration: Duration(milliseconds: 300),
        curve: Curves.easeInOut,
      );
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final CameraController? cameraController = _cameraController;

    // NÃ£o fazer nada se o controlador nÃ£o estiver inicializado
    if (cameraController == null || !cameraController.value.isInitialized) {
      return;
    }

    if (state == AppLifecycleState.inactive) {
      // Liberar a cÃ¢mera quando o app ficar inativo
      _disposeCamera();
    } else if (state == AppLifecycleState.resumed) {
      // Reinicializar a cÃ¢mera quando o app retornar
      _initializeCamera();
    } else if (state == AppLifecycleState.paused) {
      _showCountdown = false;

      _timer?.cancel();
      _timer = null;

      _disposeCamera();

      Navigator.of(context).push(
          PageRouteBuilder(
            pageBuilder: (context, animation, secondaryAnimation) => Tela1(),
            transitionDuration: Duration.zero,
            reverseTransitionDuration: Duration.zero,
          )
      );
    }
  }

  void _startTimer() {
    const oneSecond = Duration(seconds: 1);
    _timer = Timer.periodic(oneSecond, (timer) {
      setState(() {
        if (_secondsRemaining > 0) {
          _secondsRemaining--;

          // Mostra o contador quando faltam 30 segundos - ESCONDER O CONTADOR TIMEOUT
          if (_secondsRemaining <= 30 && !_showCountdown) {
            _showCountdown = false;
          }
        } else {
          _showCountdown = false;

          _timer?.cancel();
          _timer = null;

          _disposeCamera();

          Navigator.of(context).push(
              PageRouteBuilder(
                pageBuilder: (context, animation, secondaryAnimation) => Tela1(),
                transitionDuration: Duration.zero,
                reverseTransitionDuration: Duration.zero,
              )
          );
        }
      });
    });
  }

  void _resetTimer() {
    setState(() {
      _secondsRemaining = 120;
      _showCountdown = false;
    });
  }

  void _onScreenTap() {
    // SÃ³ reinicia o timer se o countdown estiver sendo mostrado e nÃ£o estiver no popup de molduras
    if (_showCountdown && !_showFrameSelector) {
      _resetTimer();
    }
  }

  Future<void> _initializeCamera() async {
    try {
      // Certifique-se de que qualquer controlador anterior estÃ¡ descartado
      await _disposeCamera();

      // Obter cÃ¢meras disponÃ­veis somente se nÃ£o tivermos ainda
      if (_cameras!.isEmpty) {
        _cameras = await availableCameras();
      }

      if (_cameras!.isEmpty) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('Nenhuma cÃ¢mera encontrada'),
        ));
        return;
      }

      _cameraController = CameraController(
        _cameras![_currentCameraIndex],
        ResolutionPreset.veryHigh,
        enableAudio: false,
        fps: 30,
        imageFormatGroup: ImageFormatGroup.jpeg,
      );

      await _cameraController!.initialize().timeout(
        Duration(seconds: 10),
        onTimeout: () {
          throw TimeoutException('Timeout ao inicializar cÃ¢mera');
        },
      );

      if (Platform.isAndroid) {
        await _cameraController!.lockCaptureOrientation(
            DeviceOrientation.portraitUp);
      }

      if (mounted) {
        setState(() {
          _isCameraInitialized = true;
        });
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text('Erro ao inicializar cÃ¢mera: $e'),
        ));
      }
    }
  }

  Future<void> _disposeCamera() async {
    if (_cameraController != null) {
      try {
        print("Liberando recursos da cÃ¢mera");

        // Desativar o flash primeiro (se estiver ativo)
        if (_cameraController!.value.isInitialized &&
            _cameraController!.value.flashMode != FlashMode.off) {
          await _cameraController!.setFlashMode(FlashMode.off);
        }

        // Adicionar um pequeno delay pode ajudar a evitar problemas de sincronizaÃ§Ã£o
        await Future.delayed(Duration(milliseconds: 100));

        // Certificar-se que o controlador estÃ¡ inicializado antes de tentar fechÃ¡-lo
        if (_cameraController!.value.isInitialized) {
          await _cameraController!.dispose();
        }

        if (mounted) {
          setState(() {
            _isCameraInitialized = false;
          });
        }

        _cameraController = null;
        print("CÃ¢mera liberada com sucesso");
      } catch (e) {
        print('Erro ao liberar recursos da cÃ¢mera: $e');
      }
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);

    _timer?.cancel();
    _timer = null;

    _framePageController.dispose();
    _disposeCamera();
    super.dispose();
  }

  // Widget para o popup de seleÃ§Ã£o de molduras
  Widget _buildFrameSelectorPopup() {
    final screenSize = MediaQuery.of(context).size;
    final screenWidth = screenSize.width;
    final screenHeight = screenSize.height;
    final totalPages = (_availableFrames.length / 2).ceil();
    final showNavigationControls = _availableFrames.length > 2; // Mostrar controles apenas se há mais de 2 itens

    return AnimatedPositioned(
      duration: Duration(milliseconds: 300),
      curve: Curves.easeInOut,
      bottom: _showFrameSelector ? 0 : -screenHeight,
      left: 0,
      right: 0,
      height: screenHeight,
      child: Container(
        decoration: BoxDecoration(
          color: Colors.white,
        ),
        child: Column(
          children: [
            // Header com tÃ­tulo e botÃ£o X
            Container(
              padding: EdgeInsets.only(
                top: MediaQuery.of(context).padding.top + 16,
                left: 16,
                right: 16,
                bottom: 16,
              ),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text(
                    'Selecione a moldura desejada',
                    style: TextStyle(
                      color: AppConfig.instance.corPrimaria,
                      fontSize: 30,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                  IconButton(
                    onPressed: () {
                      setState(() {
                        _showFrameSelector = false;
                      });
                    },
                    icon: Icon(
                      Icons.close,
                      color: Colors.black,
                      size: 28,
                    ),
                  ),
                ],
              ),
            ),
            // Carrousel de molduras com 2 imagens por vez e botões de navegação
            Expanded(
              child: Stack(
                children: [
                  PageView.builder(
                    controller: _framePageController,
                    onPageChanged: (index) {
                      setState(() {
                        _currentFramePage = index;
                      });
                    },
                    itemCount: totalPages,
                    itemBuilder: (context, pageIndex) {
                      return Container(
                        padding: EdgeInsets.symmetric(
                          horizontal: showNavigationControls ? 60 : 20, // Menos padding se não há botões
                        ),
                        child: Row(
                          children: [
                            // Primeira imagem da pÃ¡gina
                            Expanded(
                              child: _buildFrameItem(pageIndex * 2),
                            ),
                            SizedBox(width: 20),
                            // Segunda imagem da pÃ¡gina (se existir)
                            Expanded(
                              child: pageIndex * 2 + 1 < _availableFrames.length
                                  ? _buildFrameItem(pageIndex * 2 + 1)
                                  : Container(), // Container vazio se nÃ£o houver segunda imagem
                            ),
                          ],
                        ),
                      );
                    },
                  ),
                  // Botão de navegação esquerda - só aparece se há mais de 2 itens
                  if (showNavigationControls)
                    Positioned(
                      left: 20,
                      top: 0,
                      bottom: 0,
                      child: Center(
                        child: Container(
                          width: 80,
                          height: 80,
                          child: ElevatedButton(
                            onPressed: _currentFramePage > 0 ? _goToPreviousPage : null,
                            style: ElevatedButton.styleFrom(
                              backgroundColor: _currentFramePage > 0
                                  ? AppConfig.instance.corPrimaria
                                  : Colors.grey.withOpacity(0.3),
                              shape: CircleBorder(),
                              padding: EdgeInsets.zero,
                              elevation: _currentFramePage > 0 ? 4 : 0,
                            ),
                            child: Icon(
                              Icons.chevron_left,
                              color: _currentFramePage > 0 ? Colors.white : Colors.grey,
                              size: 60,
                            ),
                          ),
                        ),
                      ),
                    ),
                  // Botão de navegação direita - só aparece se há mais de 2 itens
                  if (showNavigationControls)
                    Positioned(
                      right: 20,
                      top: 0,
                      bottom: 0,
                      child: Center(
                        child: Container(
                          width: 80,
                          height: 80,
                          child: ElevatedButton(
                            onPressed: _currentFramePage < totalPages - 1 ? _goToNextPage : null,
                            style: ElevatedButton.styleFrom(
                              backgroundColor: _currentFramePage < totalPages - 1
                                  ? AppConfig.instance.corPrimaria
                                  : Colors.grey.withOpacity(0.3),
                              shape: CircleBorder(),
                              padding: EdgeInsets.zero,
                              elevation: _currentFramePage < totalPages - 1 ? 4 : 0,
                            ),
                            child: Icon(
                              Icons.chevron_right,
                              color: _currentFramePage < totalPages - 1 ? Colors.white : Colors.grey,
                              size: 60,
                            ),
                          ),
                        ),
                      ),
                    ),
                ],
              ),
            ),
            // Indicadores de pÃ¡gina melhorados - só aparecem se há mais de 2 itens
            if (showNavigationControls)
              Container(
                padding: EdgeInsets.all(20),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: List.generate(totalPages, (index) {
                    final isCurrentPage = index == _currentFramePage;
                    return GestureDetector(
                      onTap: () {
                        _framePageController.animateToPage(
                          index,
                          duration: Duration(milliseconds: 300),
                          curve: Curves.easeInOut,
                        );
                      },
                      child: AnimatedContainer(
                        duration: Duration(milliseconds: 200),
                        margin: EdgeInsets.symmetric(horizontal: 6),
                        width: isCurrentPage ? 20 : 16, // Maior para página atual
                        height: isCurrentPage ? 20 : 16, // Maior para página atual
                        decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          color: isCurrentPage
                              ? AppConfig.instance.corPrimaria // Cor destacada para página atual
                              : Colors.grey.withOpacity(0.4), // Cor mais suave para outras páginas
                          border: isCurrentPage
                              ? Border.all(color: Colors.white, width: 2)
                              : null,
                          boxShadow: isCurrentPage
                              ? [
                            BoxShadow(
                              color: AppConfig.instance.corPrimaria.withOpacity(0.3),
                              spreadRadius: 2,
                              blurRadius: 4,
                              offset: Offset(0, 2),
                            ),
                          ]
                              : null,
                        ),
                      ),
                    );
                  }),
                ),
              ),
          ],
        ),
      ),
    );
  }

  // Widget para cada item de moldura
  Widget _buildFrameItem(int index) {
    final screenWidth = MediaQuery.of(context).size.width;

    return GestureDetector(
      onTap: () {
        setState(() {
          _selectedFrame = _availableFrames[index];
          _showFrameSelector = false;
        });
      },
      child: Container(
        margin: EdgeInsets.symmetric(vertical: 10),
        decoration: BoxDecoration(
          border: Border.all(
            color: _selectedFrame == _availableFrames[index]
                ? Colors.blue
                : Colors.grey.withOpacity(0.3),
            width: 3,
          ),
          borderRadius: BorderRadius.circular(15),
          boxShadow: [
            BoxShadow(
              color: Colors.grey.withOpacity(0.2),
              spreadRadius: 2,
              blurRadius: 5,
              offset: Offset(0, 3),
            ),
          ],
        ),
        child: ClipRRect(
          borderRadius: BorderRadius.circular(12),
          child: Container(
            color: Colors.white,
            child: Image(
              image: Midia.provider(_availableFrames[index]),
              fit: BoxFit.contain,
            ),
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (_cameraController == null || !_cameraController!.value.isInitialized) {
      return Center(child: CircularProgressIndicator(color: Colors.white,));
    }

    final screenSize = MediaQuery.of(context).size;
    final screenWidth = screenSize.width;
    final screenHeight = screenSize.height;

    return Scaffold(
      body: GestureDetector(
        onTap: _onScreenTap,
        child: Stack(
          children: [
            Container(color: Colors.black),
            if (!_isPhotoTaken)
              Center(
                child: SizedBox(
                  width: screenWidth,
                  height: screenHeight,
                  child: Transform(
                    alignment: Alignment.center,
                    transform: Matrix4.identity()..scale(-1.0, 1.0),
                    child: CameraPreview(_cameraController!),
                  ),
                ),
              ),

            if (_isPhotoTaken && imageFile != null)
              Image.file(
                File(imageFile!.path),
                width: double.infinity,
                height: double.infinity,
                fit: BoxFit.cover,
              ),

            // Moldura selecionada dinamicamente
            Image(
              image: Midia.provider(_selectedFrame),
              fit: BoxFit.fill,
              width: double.infinity,
              height: double.infinity,
            ),

            // Botões e balão: imagem, posição e tamanho vêm do editor do painel
            if (!_isCountdown && !_isLoading && !_isCapturing) ...[
              ElementoTela(
                elemento: 'balao',
                retangulo: AppConfig.instance.retanguloCamera('balao', comMoldura: _availableFrames.length > 1),
                child: Image(
                  image: AppConfig.instance.imagem('balao'),
                  fit: BoxFit.contain,
                ),
              ),

              // Sem o botão Moldura, Tirar foto e Início fecham o espaço dele
              BotaoTela(
                elemento: 'tirar_foto',
                retangulo: AppConfig.instance.retanguloCamera('tirar_foto', comMoldura: _availableFrames.length > 1),
                texto: 'Tirar foto',
                icone: Icons.photo_camera,
                onPressed: () {
                  _takePicture(context);
                },
              ),

              // Botão para selecionar moldura - só aparece se houver mais de uma moldura
              if (_availableFrames.length > 1)
                BotaoTela(
                  elemento: 'trocar_moldura',
                  texto: 'Moldura',
                  icone: Icons.photo_library,
                  onPressed: () {
                    setState(() {
                      _showFrameSelector = true;
                    });
                  },
                ),

              BotaoTela(
                elemento: 'camera_inicio',
                retangulo: AppConfig.instance.retanguloCamera('camera_inicio', comMoldura: _availableFrames.length > 1),
                texto: 'Início',
                icone: Icons.home_rounded,
                onPressed: () {
                  _disposeCamera();
                  _showCountdown = false;
                  _timer?.cancel();
                  _timer = null;

                  Navigator.of(context).push(
                    PageRouteBuilder(
                      pageBuilder: (context, animation, secondaryAnimation) => Tela1(),
                      transitionDuration: Duration.zero,
                      reverseTransitionDuration: Duration.zero,
                    ),
                  );
                },
              ),
            ],

            if (_isCountdown)
              Center(
                child: OutlinedText(
                  text: Text(
                    _countdownValue,
                    style: TextStyle(
                        fontSize: screenWidth * 0.2,
                        color: Colors.white,
                        fontWeight: FontWeight.bold
                    ),
                  ),
                  strokes: [
                    OutlinedTextStroke(
                        color: Colors.black,
                        width: screenWidth * 0.015
                    ),
                  ],
                ),
              ),

            if (_isLoading)
              Center(
                child: Container(
                  width: screenWidth * 0.15,
                  height: screenWidth * 0.15,
                  decoration: BoxDecoration(
                    color: Colors.black.withAlpha(120),
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: Center(
                    child: SizedBox(
                      width: screenWidth * 0.08,
                      height: screenWidth * 0.08,
                      child: CircularProgressIndicator(
                        color: Colors.white,
                        strokeWidth: screenWidth * 0.01,
                      ),
                    ),
                  ),
                ),
              ),

            // Contador regressivo (sÃ³ aparece quando faltam 30 segundos)
            if (_showCountdown)
              Positioned(
                bottom: screenHeight * 0.03,
                right: screenWidth * 0.03,
                child: Container(
                  padding: EdgeInsets.all(screenWidth * 0.02),
                  decoration: BoxDecoration(
                    color: Colors.red.withAlpha(200),
                    borderRadius: BorderRadius.circular(screenWidth * 0.02),
                  ),
                  child: Text(
                    "$_secondsRemaining",
                    style: TextStyle(
                      color: Colors.white,
                      fontWeight: FontWeight.bold,
                      fontSize: screenWidth * 0.045,
                    ),
                  ),
                ),
              ),

            // Popup de seleÃ§Ã£o de molduras - sÃ³ aparece se houver mais de uma moldura
            if (_availableFrames.length > 1)
              _buildFrameSelectorPopup(),
          ],
        ),
      ),
    );
  }

  void _takePicture(BuildContext context) async {
    if (_cameraController == null || !_cameraController!.value.isInitialized) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
        content: Text('Camera nÃ£o inicializada'),
      ));
      return;
    }

    // Evitar mÃºltiplas capturas simultÃ¢neas
    if (_isCapturing) {
      print('JÃ¡ estÃ¡ capturando uma imagem');
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
        content: Text('JÃ¡ estÃ¡ capturando uma imagem'),
      ));
      return;
    }

    if (_cameraController!.value.isTakingPicture) {
      print('Camera ocupada');
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
        content: Text('Camera ocupada'),
      ));
      return;
    }

    setState(() {
      _isCountdown = true;
      _countdownValue = '5';
    });

    for (int i = 5; i > 0; i--) {
      if (!mounted) return;
      setState(() {
        _countdownValue = i.toString();
      });
      await Future.delayed(const Duration(seconds: 1));
    }

    setState(() {
      _isLoading = true;
      _isCountdown = false;
      _isCapturing = true;
    });

    // Lockar o autofoco antes de capturar
    await _cameraController!.setFocusMode(FocusMode.locked);
    await Future.delayed(Duration(milliseconds: 300));

    final XFile image = await _cameraController!.takePicture().timeout(
      const Duration(seconds: 10),
      onTimeout: () {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('Timeout ao capturar imagem'),
        ));
        throw TimeoutException('Timeout ao capturar foto');
      },
    );

    // Esperar um momento para garantir que a captura foi concluÃ­da
    await Future.delayed(Duration(milliseconds: 500));

    setState(() {
      imageFile = image;
      _isPhotoTaken = true;
    });

    // Desbloquear o foco manualmente
    try {
      if (_cameraController != null &&
          _cameraController!.value.isInitialized) {
        await _cameraController!.setFocusMode(FocusMode.auto);
      }
    } catch (f) {
      print('Erro ao desbloquear foco, provavelmente cÃ¢mera jÃ¡ fechada: $f');
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
        content: Text(
            'Erro ao desbloquear foco, provavelmente cÃ¢mera jÃ¡ fechada'),
      ));
    }

    // Certificar-se de liberar a cÃ¢mera antes de navegar
    await _disposeCamera();

    if (mounted) {
      setState(() {
        _isCapturing = false;
        _isLoading = false;
      });

      _showCountdown = false;

      _timer?.cancel();
      _timer = null;

      await Navigator.of(context).push(
          PageRouteBuilder(
            pageBuilder: (context, animation, secondaryAnimation) =>
                Tela3(
                  selectedFrame: _selectedFrame,
                  imagePath: image.path,
                ),
            transitionDuration: Duration.zero,
            reverseTransitionDuration: Duration.zero,
          ));

      // Ao retornar, reinicializar a cÃ¢mera se necessÃ¡rio
      if (mounted && _cameraController == null) {
        await _initializeCamera();
      }
    }
  }
}

class BubblePainter extends CustomPainter {
  final Color color;
  BubblePainter({required this.color});

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = color
      ..style = PaintingStyle.fill;

    final path = Path();
    final radius = 20.0; // Bordas bem arredondadas como na imagem

    // Corpo do balão
    path.addRRect(RRect.fromRectAndRadius(
      Rect.fromLTWH(0, 0, size.width, size.height),
      Radius.circular(radius),
    ));

    // Pontinha lateral (triângulo)
    final trianglePath = Path();
    trianglePath.moveTo(size.width, size.height * 0.4);
    trianglePath.lineTo(size.width + 12, size.height * 0.2);
    trianglePath.lineTo(size.width, size.height * 0.15);
    trianglePath.close();

    canvas.drawPath(path, paint);
    canvas.drawPath(trianglePath, paint);
  }

  @override
  bool shouldRepaint(CustomPainter oldDelegate) => false;
}
