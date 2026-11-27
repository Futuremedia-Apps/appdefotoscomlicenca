import 'package:flutter/material.dart';
import 'package:package_info_plus/package_info_plus.dart';

import 'app_config.dart';
import 'evento.dart';
import 'licenca.dart';
import 'robo_info.dart';
import 'tela_licenca.dart';

// Tela de admin: sincroniza as molduras, fundos e vídeo do painel web. Abre com
// 5 toques no canto superior direito da tela inicial + PIN (ver Tela1).
// Quem vê esta tela é o cliente, então nada de endereços, IDs ou números
// internos: só o que ajuda a conferir o robô antes de um evento.
class AdminScreen extends StatefulWidget {
  const AdminScreen({super.key});

  @override
  State<AdminScreen> createState() => _AdminScreenState();
}

class _AdminScreenState extends State<AdminScreen> {
  final AppConfig _config = AppConfig.instance;
  final Licenca _licenca = Licenca.instance;
  final EventoAtual _evento = EventoAtual.instance;
  String _status = '';
  bool _erro = false;
  bool _mostrarResumo = false;
  String _versaoApp = '';

  // Teste de conexão: null enquanto testa
  bool _testando = false;
  bool? _internet;
  bool? _envio;

  static const Map<String, String> _telas = {
    'tela_inicio': 'Início',
    'tela_termos': 'Termos de uso',
    'tela_recusa': 'Aviso "Não aceito"',
    'tela_final': 'Final',
  };

  @override
  void initState() {
    super.initState();
    _config.addListener(_aoMudarConfig);
    _licenca.addListener(_aoMudarConfig);
    _evento.addListener(_aoMudarConfig);
    _evento.conferir();
    _testarConexao();
    PackageInfo.fromPlatform().then((info) {
      if (mounted) setState(() => _versaoApp = '${info.version} (${info.buildNumber})');
    });
  }

  void _aoMudarConfig() {
    if (mounted) setState(() {});
  }

  @override
  void dispose() {
    _config.removeListener(_aoMudarConfig);
    _licenca.removeListener(_aoMudarConfig);
    _evento.removeListener(_aoMudarConfig);
    super.dispose();
  }

  Future<void> _sincronizar() async {
    setState(() {
      _status = 'Iniciando...';
      _erro = false;
      _mostrarResumo = false;
    });

    final erro = await _config.sincronizar(
      progresso: (mensagem) {
        if (mounted) setState(() => _status = mensagem);
      },
    );

    if (!mounted) return;
    setState(() {
      _erro = erro != null;
      _mostrarResumo = erro == null;
      _status = erro ?? 'Sincronizado com sucesso!';
    });
    _evento.conferir();
    _testarConexao();
  }

  Future<void> _testarConexao() async {
    if (_testando) return;
    setState(() {
      _testando = true;
      _internet = null;
      _envio = null;
    });
    final resultado = await _config.testarConexao();
    if (!mounted) return;
    setState(() {
      _testando = false;
      _internet = resultado.internet;
      _envio = resultado.envio;
    });
  }

  String _formatarData(String iso) {
    final data = DateTime.tryParse(iso)?.toLocal();
    if (data == null) return '-';
    String dois(int n) => n.toString().padLeft(2, '0');
    return '${dois(data.day)}/${dois(data.month)}/${data.year} ${dois(data.hour)}:${dois(data.minute)}';
  }

  String _fotosDeHoje() {
    final enviadas = _config.fotosHoje;
    final falhas = _config.falhasHoje;
    final texto = enviadas == 1 ? '1 enviada' : '$enviadas enviadas';
    if (falhas == 0) return texto;
    return '$texto (${falhas == 1 ? '1 falha' : '$falhas falhas'} no envio)';
  }

  Widget _linhaInfo(String rotulo, String valor, {Color? cor}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(width: 200, child: Text(rotulo, style: const TextStyle(fontSize: 20, color: Colors.black54))),
          Expanded(child: Text(valor, style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold, color: cor))),
        ],
      ),
    );
  }

  // Evento para onde vão as fotos (escolhido no painel, vale na hora)
  Widget _linhaEvento() {
    final e = _evento;
    if (e.ativo == true) return _linhaInfo('Evento atual', e.nome.isEmpty ? 'Ativo' : e.nome);
    if (e.ativo == false) {
      return _linhaInfo('Evento atual', 'Nenhum aberto. Crie ou reabra um evento no painel: sem evento, o robô não tira fotos.', cor: Colors.red.shade700);
    }
    return _linhaInfo('Evento atual', e.conferindo ? 'Conferindo...' : 'Não deu para conferir agora');
  }

  // Validade da licença, com aviso quando ela está para travar
  Widget _linhaValidade() {
    final l = _licenca;
    // Bloqueada (suspensa, vencida, desvinculada): não há data para mostrar
    if (!l.valida) {
      return _linhaInfo('Validade', l.motivoBloqueio.isNotEmpty ? 'Bloqueada. ${l.motivoBloqueio}' : 'Bloqueada.', cor: Colors.red.shade700);
    }
    final validoAte = _formatarData(l.validoAte.toIso8601String()).split(' ').first;
    if (l.situacao == 'vencida') {
      return _linhaInfo('Validade', 'Venceu. O app trava em $validoAte. Fale com a Futuremedia.', cor: Colors.red.shade700);
    }
    // Muito tempo sem falar com a central (sem internet)
    if (l.validoAte.difference(DateTime.now()) < const Duration(days: 3)) {
      return _linhaInfo('Validade', 'Conecte o robô à internet até $validoAte para a licença não travar.', cor: Colors.red.shade700);
    }
    if (l.vence.isEmpty) return _linhaInfo('Validade', 'Sem vencimento');
    final partes = l.vence.split('-');
    return _linhaInfo('Validade', partes.length == 3 ? 'Até ${partes[2]}/${partes[1]}/${partes[0]}' : l.vence);
  }

  Future<void> _desvincular() async {
    final confirmou = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Desvincular este robô?', style: TextStyle(fontSize: 24, fontWeight: FontWeight.bold)),
        content: const SizedBox(
          width: 520,
          child: Text(
            'O app fica travado na tela de ativação e a vaga da licença fica livre '
            'para outro robô.\n\n'
            'Para usar este robô de novo, é preciso digitar o código da licença.',
            style: TextStyle(fontSize: 18, height: 1.4),
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Cancelar', style: TextStyle(fontSize: 18)),
          ),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: Colors.red.shade700),
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Desvincular', style: TextStyle(fontSize: 18)),
          ),
        ],
      ),
    );
    if (confirmou != true || !mounted) return;

    setState(() {
      _status = 'Desvinculando...';
      _erro = false;
      _mostrarResumo = false;
    });
    // Trava sempre (mesmo sem internet): a tela inicial vira a de ativação
    await _licenca.desvincular();
    if (mounted) Navigator.of(context).pop();
  }

  Future<void> _trocarLicenca() async {
    final trocou = await Navigator.of(context).push<bool>(
      MaterialPageRoute(builder: (context) => const TelaLicenca(troca: true)),
    );
    if (trocou == true && mounted) {
      setState(() {
        _status = 'Licença trocada. Este robô agora usa o painel de ${_licenca.cliente}.';
        _erro = false;
        _mostrarResumo = false;
      });
      _testarConexao();
    }
  }

  Widget _linhaConexao(String rotulo, bool? ok, String textoOk, String textoFalha) {
    final Widget icone;
    final String texto;
    final Color? cor;
    if (ok == null) {
      icone = const SizedBox(width: 24, height: 24, child: CircularProgressIndicator(strokeWidth: 3));
      texto = 'Testando...';
      cor = Colors.black54;
    } else if (ok) {
      icone = Icon(Icons.check_circle, color: Colors.green.shade700, size: 28);
      texto = textoOk;
      cor = Colors.green.shade800;
    } else {
      icone = Icon(Icons.error, color: Colors.red.shade700, size: 28);
      texto = textoFalha;
      cor = Colors.red.shade700;
    }
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        children: [
          SizedBox(width: 200, child: Text(rotulo, style: const TextStyle(fontSize: 20, color: Colors.black54))),
          SizedBox(width: 28, child: Center(child: icone)),
          const SizedBox(width: 10),
          Expanded(child: Text(texto, style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold, color: cor))),
        ],
      ),
    );
  }

  // Miniaturas (caminho, legenda), 4 por linha na largura que houver
  Widget _grade(List<(String, String)> itens, {required bool moldura}) {
    const espaco = 12.0;
    return LayoutBuilder(
      builder: (context, limites) {
        final largura = (limites.maxWidth - 3 * espaco) / 4;
        return Wrap(
          spacing: espaco,
          runSpacing: espaco,
          children: [
            for (final (caminho, legenda) in itens) _miniatura(caminho, legenda, largura: largura, moldura: moldura),
          ],
        );
      },
    );
  }

  Widget _miniatura(String caminho, String legenda, {required double largura, required bool moldura}) {
    final padrao = Midia.ehAsset(caminho);
    return SizedBox(
      width: largura,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          ClipRRect(
            borderRadius: BorderRadius.circular(8),
            child: AspectRatio(
              aspectRatio: 16 / 9,
              child: Container(
                // A moldura é transparente no meio: o fundo escuro faz ela aparecer
                color: moldura ? const Color(0xFF3A3F47) : Colors.black12,
                child: Image(
                  image: ResizeImage(Midia.provider(caminho), width: 260),
                  fit: BoxFit.cover,
                  errorBuilder: (context, erro, pilha) => const Center(child: Icon(Icons.broken_image, color: Colors.black38)),
                ),
              ),
            ),
          ),
          const SizedBox(height: 6),
          Text(legenda, style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w600), maxLines: 1, overflow: TextOverflow.ellipsis),
          Text(
            padrao ? 'Padrão do app' : 'Do painel',
            style: TextStyle(fontSize: 14, color: padrao ? Colors.black45 : Colors.green.shade800),
          ),
        ],
      ),
    );
  }

  Widget _cartao(String titulo, List<Widget> filhos) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(titulo, style: const TextStyle(fontSize: 24, fontWeight: FontWeight.bold)),
            const SizedBox(height: 12),
            ...filhos,
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final cor = _config.corPrimaria;
    final ocupado = _config.sincronizando;
    final molduras = _config.molduras;

    return Scaffold(
      backgroundColor: const Color(0xFFF2F4F7),
      appBar: AppBar(
        backgroundColor: cor,
        foregroundColor: Colors.white,
        title: const Text('Administração do app', style: TextStyle(fontSize: 26, fontWeight: FontWeight.bold)),
      ),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        // Sincronização
                        _cartao('Sincronizar com o painel', [
                          const Text(
                            'Baixa as molduras, fundos e o vídeo enviados no painel web. '
                            'O app só busca as mudanças do painel quando você toca aqui.',
                            style: TextStyle(fontSize: 18, color: Colors.black54),
                          ),
                          const SizedBox(height: 24),
                          SizedBox(
                            height: 80,
                            child: ElevatedButton.icon(
                              style: ElevatedButton.styleFrom(backgroundColor: cor, foregroundColor: Colors.white),
                              onPressed: ocupado ? null : _sincronizar,
                              icon: ocupado
                                  ? const SizedBox(
                                      width: 28,
                                      height: 28,
                                      child: CircularProgressIndicator(color: Colors.white, strokeWidth: 3),
                                    )
                                  : const Icon(Icons.sync, size: 36),
                              label: const Text('Sincronizar', style: TextStyle(fontSize: 26, fontWeight: FontWeight.bold)),
                            ),
                          ),
                          if (_status.isNotEmpty) ...[
                            const SizedBox(height: 16),
                            Text(
                              _status,
                              style: TextStyle(
                                fontSize: 18,
                                fontWeight: FontWeight.bold,
                                color: _erro ? Colors.red.shade700 : Colors.green.shade800,
                              ),
                            ),
                          ],
                          // O que mudou nesta sincronização
                          if (_mostrarResumo)
                            for (final linha in _config.ultimoResumo)
                              Padding(
                                padding: const EdgeInsets.only(top: 6),
                                child: Text('•  $linha', style: const TextStyle(fontSize: 18)),
                              ),
                        ]),
                        const SizedBox(height: 16),
                        // Conexão
                        _cartao('Conexão', [
                          _linhaConexao('Internet', _internet, 'Conectada', 'Sem internet'),
                          _linhaConexao('Envio de fotos', _envio, 'Funcionando', 'Não responde'),
                          if (_internet == false || _envio == false)
                            Padding(
                              padding: const EdgeInsets.only(top: 6),
                              child: Text(
                                _internet == false
                                    ? 'Sem internet, as fotos não são enviadas e o QR Code não funciona. Confira o Wi-Fi do robô.'
                                    : 'O robô tem internet, mas o servidor das fotos não respondeu. Tente de novo ou fale com o suporte.',
                                style: TextStyle(fontSize: 16, color: Colors.red.shade700),
                              ),
                            ),
                          const SizedBox(height: 8),
                          Align(
                            alignment: Alignment.centerLeft,
                            child: TextButton.icon(
                              onPressed: _testando ? null : _testarConexao,
                              icon: const Icon(Icons.refresh, size: 24),
                              label: const Text('Testar de novo', style: TextStyle(fontSize: 18)),
                            ),
                          ),
                        ]),
                      ],
                    ),
                  ),
                  const SizedBox(width: 24),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        // Situação atual
                        _cartao('Situação', [
                          _linhaInfo('Painel', _config.sincronizado ? _config.nomeCliente : 'Nunca sincronizado (usando o padrão do app)'),
                          _linhaEvento(),
                          _linhaInfo('Sincronizado em', _formatarData(_config.sincronizadoEm)),
                          _linhaInfo('Fotos de hoje', _fotosDeHoje(), cor: _config.falhasHoje > 0 ? Colors.red.shade700 : null),
                          _linhaInfo('Versão do app', _versaoApp.isEmpty ? '-' : _versaoApp),
                          _linhaInfo('Licença', _licenca.codigoTexto.isEmpty ? '-' : _licenca.codigoTexto),
                          _linhaInfo('Serial do robô', RoboInfo.serial.isEmpty ? 'Não informado' : RoboInfo.serial),
                          _linhaValidade(),
                          const SizedBox(height: 20),
                          Wrap(
                            spacing: 12,
                            runSpacing: 12,
                            children: [
                              OutlinedButton.icon(
                                onPressed: () => Navigator.of(context).pop(),
                                icon: const Icon(Icons.arrow_back),
                                label: const Text('Voltar ao app', style: TextStyle(fontSize: 18)),
                              ),
                              OutlinedButton.icon(
                                onPressed: ocupado ? null : _trocarLicenca,
                                icon: const Icon(Icons.key_outlined),
                                label: const Text('Trocar licença', style: TextStyle(fontSize: 18)),
                              ),
                            ],
                          ),
                          // Discreto, de propósito: quase nunca é usado
                          if (_licenca.codigo.isNotEmpty)
                            Align(
                              alignment: Alignment.centerRight,
                              child: TextButton.icon(
                                style: TextButton.styleFrom(foregroundColor: Colors.black38),
                                onPressed: ocupado || _licenca.renovando ? null : _desvincular,
                                icon: const Icon(Icons.link_off, size: 18),
                                label: const Text('Desvincular este robô', style: TextStyle(fontSize: 14)),
                              ),
                            ),
                        ]),
                        const SizedBox(height: 16),
                        // O que está no robô agora
                        _cartao('O que está no robô', [
                          const Text('Telas', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: Colors.black54)),
                          const SizedBox(height: 8),
                          _grade([
                            for (final tela in _telas.entries) (_config.caminhoImagem(tela.key), tela.value),
                          ], moldura: false),
                          const SizedBox(height: 16),
                          Text(
                            molduras.length == 1 ? 'Moldura (1)' : 'Molduras (${molduras.length})',
                            style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: Colors.black54),
                          ),
                          const SizedBox(height: 8),
                          _grade([
                            for (var i = 0; i < molduras.length; i++) (molduras[i], 'Moldura ${i + 1}'),
                          ], moldura: true),
                        ]),
                      ],
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}
