import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'evento.dart';
import 'licenca.dart';

// Aparece no lugar da tela inicial quando o painel do cliente não tem evento:
// as fotos só podem ser tiradas com um evento. Sai sozinha quando o evento é
// criado no painel (o app pergunta a cada 10 segundos, ver evento.dart).
// O canto superior direito continua abrindo a tela de admin (5 toques + PIN).
class TelaSemEvento extends StatelessWidget {
  const TelaSemEvento({super.key, required this.aoToqueSecreto});

  final VoidCallback aoToqueSecreto;

  // Cores da Futuremedia, as mesmas da tela de ativação (tela_licenca.dart)
  static const Color _azul = Color(0xFF174786);
  static const Color _ciano = Color(0xFF008BCC);
  static const Color _fundo = Color(0xFFF1EFEB);
  static const Color _texto = Color(0xFF2E2F35);
  static const Color _alerta = Color(0xFF8A5A00);

  @override
  Widget build(BuildContext context) {
    final tela = MediaQuery.of(context).size;
    final altura = tela.height;
    final evento = EventoAtual.instance;
    final cliente = Licenca.instance.cliente;

    return Scaffold(
      backgroundColor: _fundo,
      body: Stack(
        children: [
          SafeArea(
            child: Center(
              child: ConstrainedBox(
                constraints: BoxConstraints(maxWidth: altura * 1.5),
                child: Padding(
                  padding: EdgeInsets.all(altura * 0.06),
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      // Faixa com o degradê da Futuremedia
                      Container(
                        height: altura * 0.01,
                        width: altura * 0.13,
                        margin: EdgeInsets.only(bottom: altura * 0.03),
                        decoration: BoxDecoration(
                          gradient: const LinearGradient(colors: [_azul, _ciano]),
                          borderRadius: BorderRadius.circular(altura * 0.005),
                        ),
                      ),
                      Row(
                        children: [
                          Icon(Icons.event_busy, color: _alerta, size: altura * 0.08),
                          SizedBox(width: altura * 0.02),
                          Expanded(
                            child: FittedBox(
                              fit: BoxFit.scaleDown,
                              alignment: Alignment.centerLeft,
                              child: Text(
                                'Nenhum evento ativo',
                                style: TextStyle(fontSize: altura * 0.075, fontWeight: FontWeight.w900, color: _azul),
                              ),
                            ),
                          ),
                        ],
                      ),
                      SizedBox(height: altura * 0.03),
                      Text(
                        'Este robô só tira fotos com um evento aberto. Para liberar, abra o painel do cliente, '
                        'entre em "Configurar o app" e crie ou reabra o evento no cartão "Evento atual".',
                        style: TextStyle(fontSize: altura * 0.036, color: _texto, height: 1.35),
                      ),
                      SizedBox(height: altura * 0.02),
                      Text(
                        'Esta tela sai sozinha poucos segundos depois que o evento for criado ou reaberto.',
                        style: TextStyle(fontSize: altura * 0.03, color: _texto.withAlpha(170), height: 1.3),
                      ),
                      if (cliente.isNotEmpty) ...[
                        SizedBox(height: altura * 0.012),
                        Text(
                          'Painel: $cliente',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(fontSize: altura * 0.026, color: _texto.withAlpha(150), fontWeight: FontWeight.w600),
                        ),
                      ],
                      SizedBox(height: altura * 0.05),
                      Row(
                        children: [
                          if (evento.conferindo) ...[
                            SizedBox(
                              width: altura * 0.03,
                              height: altura * 0.03,
                              child: const CircularProgressIndicator(strokeWidth: 3, color: _azul),
                            ),
                            SizedBox(width: altura * 0.015),
                            Text('Conferindo...', style: TextStyle(fontSize: altura * 0.028, color: _azul, fontWeight: FontWeight.bold)),
                          ],
                          const Spacer(),
                          _botao('Tentar de novo', Icons.refresh, altura, evento.conferindo ? null : evento.conferir),
                          SizedBox(width: altura * 0.015),
                          _botao('Fechar app', Icons.close, altura, () => SystemNavigator.pop()),
                        ],
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),

          // Área invisível do acesso à tela de admin (a mesma da tela inicial)
          Positioned(
            top: 0,
            right: 0,
            width: tela.width * 0.12,
            height: altura * 0.15,
            child: GestureDetector(
              behavior: HitTestBehavior.opaque,
              onTap: aoToqueSecreto,
            ),
          ),
        ],
      ),
    );
  }

  Widget _botao(String rotulo, IconData icone, double altura, VoidCallback? aoTocar) {
    return SizedBox(
      height: altura * 0.085,
      child: OutlinedButton.icon(
        style: OutlinedButton.styleFrom(
          foregroundColor: _texto,
          side: const BorderSide(color: Colors.black26, width: 2),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(altura * 0.02)),
          padding: EdgeInsets.symmetric(horizontal: altura * 0.03),
        ),
        onPressed: aoTocar,
        icon: Icon(icone, size: altura * 0.036),
        label: Text(rotulo, style: TextStyle(fontSize: altura * 0.03)),
      ),
    );
  }
}
