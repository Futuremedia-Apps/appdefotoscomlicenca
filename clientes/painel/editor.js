// Editor das telas do painel: mostra a tela do app em miniatura e deixa
// arrastar e redimensionar os botões. As posições são frações da tela (0 a 1),
// iguais às que o app usa (AppConfig.layoutPadrao), e vão no campo "layout" de
// todo formulário da tela, para nada se perder ao enviar uma imagem.
(function () {
    'use strict';

    // A tela do robô é 1920x1080: a proporção serve para manter o formato das imagens
    var PROPORCAO_TELA = 16 / 9;
    var MINIMO = 0.02;
    var IMA = 0.006; // distância para "grudar" no centro

    var ICONES = {
        camera: '<path d="M12 15.2a3.2 3.2 0 1 0 0-6.4 3.2 3.2 0 0 0 0 6.4zM9 2 7.17 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2h-3.17L15 2H9zm3 15c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5z"/>',
        molduras: '<path d="M22 16V4c0-1.1-.9-2-2-2H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2zm-11-4 2.03 2.71L16 11l4 5H8l3-4zM2 6v14c0 1.1.9 2 2 2h14v-2H4V6H2z"/>',
        inicio: '<path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/>',
        salvar: '<path fill="#2e9d4f" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>',
        refazer: '<path fill="#d32f2f" d="M12 2C6.47 2 2 6.47 2 12s4.47 10 10 10 10-4.47 10-10S17.53 2 12 2zm5 13.59L15.59 17 12 13.41 8.41 17 7 15.59 10.59 12 7 8.41 8.41 7 12 10.59 15.59 7 17 8.41 13.41 12 17 15.59z"/>'
    };

    // QR Code de exemplo (só para mostrar o lugar)
    var QR_EXEMPLO = (function () {
        var s = '<svg viewBox="0 0 21 21" shape-rendering="crispEdges"><rect width="21" height="21" fill="#fff"/>';
        function olho(x, y) {
            s += '<rect x="' + x + '" y="' + y + '" width="7" height="7"/><rect x="' + (x + 1) + '" y="' + (y + 1) + '" width="5" height="5" fill="#fff"/><rect x="' + (x + 2) + '" y="' + (y + 2) + '" width="3" height="3"/>';
        }
        olho(0, 0); olho(14, 0); olho(0, 14);
        var semente = 7;
        for (var y = 0; y < 21; y++) {
            for (var x = 0; x < 21; x++) {
                if ((x < 8 && y < 8) || (x > 12 && y < 8) || (x < 8 && y > 12)) continue;
                semente = (semente * 73 + 41) % 101;
                if (semente % 2) s += '<rect x="' + x + '" y="' + y + '" width="1" height="1"/>';
            }
        }
        return s + '</svg>';
    })();

    var editores = [];
    var enviando = false;

    function limitar(v, min, max) { return Math.min(max, Math.max(min, v)); }
    function arred(v) { return Math.round(v * 10000) / 10000; }
    function copiar(r) { return { x: r.x, y: r.y, w: r.w, h: r.h }; }

    // Altura (fração) que mantém o formato da imagem para uma largura (fração)
    function alturaPara(w, aspecto) { return w * PROPORCAO_TELA / aspecto; }

    function Editor(raiz) {
        var dados = JSON.parse(raiz.getAttribute('data-editor'));
        var secao = raiz.closest('[data-tela-secao]');
        var palco = raiz.querySelector('.palco');
        var medidas = raiz.querySelector('.medidas');
        var formSalvar = raiz.querySelector('.salvar-layout');
        var botaoSalvar = formSalvar.querySelector('button[type=submit]');
        var estado = raiz.querySelector('.estado-layout');
        var guiaV = document.createElement('div');
        var guiaH = document.createElement('div');
        var self = this;

        var elementos = {};
        var salvo = {};
        var selecionado = null;

        this.tela = dados.tela;
        this.secao = secao;

        // ---------- Montagem ----------

        palco.style.setProperty('--cor-app', dados.cor);
        if (dados.fundo) {
            palco.style.backgroundImage = "url('" + dados.fundo + "')";
        } else if (dados.camera) {
            palco.classList.add('palco-camera');
            palco.style.backgroundImage = "url('" + dados.camera + "'), linear-gradient(135deg, #6b768c, #2b3240)";
            var aviso = document.createElement('span');
            aviso.className = 'palco-aviso';
            aviso.textContent = 'Aqui aparece a câmera';
            palco.appendChild(aviso);
        }

        (dados.fixos || []).forEach(function (f) {
            var d = document.createElement('div');
            d.className = 'fixo';
            d.title = f.titulo + ': não coloque botões aqui';
            d.innerHTML = '<span>' + f.titulo + '</span>';
            posicionar(d, f);
            palco.appendChild(d);
        });

        guiaV.className = 'guia guia-v';
        guiaH.className = 'guia guia-h';
        palco.appendChild(guiaV);
        palco.appendChild(guiaH);

        dados.elementos.forEach(function (e) {
            var no = document.createElement('div');
            // "el-tipo-", e não "el-": .el-botao e .el-foto são o desenho de dentro
            // (com overflow: hidden), e na caixa de fora cortariam a alça ◢
            no.className = 'el el-tipo-' + e.tipo + (e.condicional ? ' el-condicional' : '');
            no.tabIndex = 0;
            no.setAttribute('role', 'button');
            no.setAttribute('aria-label', e.titulo + ': arraste para mover');
            no.innerHTML = conteudo(e) + '<span class="el-centro"></span><span class="el-nome">' + e.titulo + '</span><span class="el-alca" title="Puxe para mudar o tamanho"></span>';
            palco.appendChild(no);
            e.no = no;
            e.r = copiar(e.atual);
            elementos[e.id] = e;
            salvo[e.id] = copiar(e.atual);

            no.addEventListener('pointerdown', function (ev) { comecarArrasto(ev, e); });
            no.addEventListener('focus', function () { selecionar(e.id); });
            no.addEventListener('keydown', function (ev) { teclado(ev, e); });
        });

        function conteudo(e) {
            if ((e.tipo === 'botao' || e.tipo === 'imagem') && e.imagem) {
                return '<img src="' + e.imagem + '" alt="" draggable="false">';
            }
            if (e.tipo === 'botao') {
                var icone = ICONES[e.icone]
                    ? '<svg viewBox="0 0 24 24" class="el-icone">' + ICONES[e.icone] + '</svg>'
                    : '';
                return '<span class="el-botao' + (e.estilo === 'cheio' ? ' el-botao-cheio' : '') + (icone ? ' el-botao-icone' : '') + '">' + icone + '<span class="el-texto">' + e.texto + '</span></span>';
            }
            if (e.tipo === 'foto') {
                return '<span class="el-foto"><span>Foto tirada</span></span>';
            }
            if (e.tipo === 'qrcode') {
                return '<span class="el-qr">' + QR_EXEMPLO + '</span>';
            }
            return '';
        }

        function posicionar(no, r) {
            no.style.left = (r.x * 100) + '%';
            no.style.top = (r.y * 100) + '%';
            no.style.width = (r.w * 100) + '%';
            no.style.height = (r.h * 100) + '%';
        }

        // Texto dos botões padrão: metade da altura, como no app, sem passar da largura
        function ajustarTexto(e) {
            var texto = e.no.querySelector('.el-texto');
            if (!texto) return;
            var alturaPx = palco.clientHeight * e.r.h;
            var temIcone = e.no.querySelector('.el-botao-icone');
            var tamanho = temIcone ? alturaPx * 0.13 : alturaPx * 0.5;
            texto.style.fontSize = tamanho + 'px';
            var caixa = e.no.clientWidth * 0.9;
            if (texto.scrollWidth > caixa && texto.scrollWidth > 0) {
                texto.style.fontSize = (tamanho * caixa / texto.scrollWidth) + 'px';
            }
        }

        // ---------- Câmera com 1 moldura (sem o botão Moldura) ----------

        // Mesma conta do app (AppConfig.retanguloCamera): o botão de depois sobe
        // para o lugar da Moldura, os dois ficam centralizados no espaço do grupo
        // e o balão acompanha o Tirar foto. Só muda a prévia; o que se salva é
        // sempre a posição "com 2 ou mais molduras".
        var modoSemMoldura = false;

        function semMoldura(id) {
            var r = elementos[id].r;
            var foto = elementos.tirar_foto, moldura = elementos.trocar_moldura, inicio = elementos.camera_inicio;
            if (!foto || !moldura || !inicio || ['tirar_foto', 'camera_inicio', 'balao'].indexOf(id) < 0) return r;
            foto = foto.r; moldura = moldura.r; inicio = inicio.r;
            var referencia = id === 'balao' ? 'tirar_foto' : id;

            function meio(q, vert) { return vert ? q.y + q.h / 2 : q.x + q.w / 2; }
            // Os botões estão um embaixo do outro ou lado a lado?
            var vertical = Math.abs(meio(inicio, true) - meio(foto, true)) >= Math.abs(meio(inicio, false) - meio(foto, false));
            function comeco(q) { return vertical ? q.y : q.x; }

            var fotoAntes = meio(foto, vertical) <= meio(inicio, vertical);
            var primeiro = fotoAntes ? foto : inicio;
            var ultimo = fotoAntes ? inicio : foto;
            // Só fecha o buraco se a Moldura estiver mesmo entre os dois
            if (meio(moldura, vertical) <= meio(primeiro, vertical) || meio(moldura, vertical) >= meio(ultimo, vertical)) return r;
            var sobe = comeco(ultimo) - comeco(moldura);
            if (sobe <= 0) return r;
            var desloca = (referencia === 'tirar_foto') === fotoAntes ? sobe / 2 : -sobe / 2;
            var s = copiar(r);
            if (vertical) s.y += desloca; else s.x += desloca;
            return s;
        }

        function trocarModo(sem) {
            modoSemMoldura = sem;
            if (sem) selecionar(null);
            palco.classList.toggle('palco-previa', sem);
            raiz.querySelectorAll('[data-comando=modo-com], [data-comando=modo-sem]').forEach(function (b) {
                var ativo = (b.getAttribute('data-comando') === 'modo-sem') === sem;
                b.classList.toggle('ativo', ativo);
                b.setAttribute('aria-pressed', ativo ? 'true' : 'false');
            });
            desenharTudo();
        }

        function desenhar(e) {
            e.no.hidden = modoSemMoldura && e.id === 'trocar_moldura';
            posicionar(e.no, modoSemMoldura ? semMoldura(e.id) : e.r);
            ajustarTexto(e);
        }

        function desenharTudo() {
            Object.keys(elementos).forEach(function (id) { desenhar(elementos[id]); });
        }

        // ---------- Seleção e medidas ----------

        function selecionar(id) {
            // A prévia com 1 moldura é só para ver: escolher um elemento volta a editar
            if (modoSemMoldura && id) trocarModo(false);
            selecionado = id ? elementos[id] : null;
            Object.keys(elementos).forEach(function (k) {
                elementos[k].no.classList.toggle('el-selecionado', k === id);
            });
            raiz.querySelectorAll('.peca-elemento').forEach(function (p) {
                p.classList.toggle('peca-selecionada', p.getAttribute('data-elemento') === id);
            });
            medidas.hidden = !selecionado;
            atualizarMedidas();
        }

        // Os campos mostram o meio do elemento (cx, cy) e o tamanho; o app e o
        // servidor continuam guardando o canto superior esquerdo (x, y)
        function medida(r, chave) {
            if (chave === 'cx') return r.x + r.w / 2;
            if (chave === 'cy') return r.y + r.h / 2;
            return r[chave];
        }

        function atualizarMedidas() {
            if (!selecionado) return;
            medidas.querySelector('.medidas-nome').textContent = selecionado.titulo;
            medidas.querySelectorAll('[data-medida]').forEach(function (campo) {
                if (document.activeElement !== campo) {
                    campo.value = (medida(selecionado.r, campo.getAttribute('data-medida')) * 100).toFixed(1);
                }
            });
        }

        medidas.querySelectorAll('[data-medida]').forEach(function (campo) {
            campo.addEventListener('input', function () {
                if (!selecionado) return;
                var v = parseFloat(campo.value.replace(',', '.'));
                if (isNaN(v)) return;
                var e = selecionado;
                var r = copiar(e.r);
                var chave = campo.getAttribute('data-medida');
                v = v / 100;
                if (chave === 'cx') {
                    r.x = v - r.w / 2;
                    aplicar(e, r);
                } else if (chave === 'cy') {
                    r.y = v - r.h / 2;
                    aplicar(e, r);
                } else {
                    // Largura e altura crescem para os dois lados: o meio fica no lugar
                    r[chave] = v;
                    if (e.aspecto && chave === 'w') r.h = alturaPara(r.w, e.aspecto);
                    if (e.aspecto && chave === 'h') r.w = r.h * e.aspecto / PROPORCAO_TELA;
                    aplicar(e, r, { cx: medida(e.r, 'cx'), cy: medida(e.r, 'cy') });
                }
            });
            campo.addEventListener('change', atualizarMedidas);
            // Ao sair do campo, mostra o valor real (ex.: 95 vira 90 se encostou na borda)
            campo.addEventListener('blur', atualizarMedidas);
        });

        // Coloca o retângulo dentro da tela, mantendo o formato quando precisa.
        // Com "centro", o tamanho muda em volta desse ponto (o meio não sai do lugar).
        function aplicar(e, r, centro) {
            r.w = limitar(r.w, MINIMO, 1);
            r.h = limitar(r.h, MINIMO, 1);
            if (e.aspecto) {
                var h = alturaPara(r.w, e.aspecto);
                if (h > 1) { r.w = r.w / h; h = 1; }
                r.h = Math.max(h, MINIMO);
            }
            if (centro) {
                r.x = centro.cx - r.w / 2;
                r.y = centro.cy - r.h / 2;
            }
            r.x = limitar(r.x, 0, 1 - r.w);
            r.y = limitar(r.y, 0, 1 - r.h);
            e.r = { x: arred(r.x), y: arred(r.y), w: arred(r.w), h: arred(r.h) };
            desenhar(e);
            atualizarMedidas();
            marcarAlteracao();
        }

        raiz.addEventListener('click', function (ev) {
            var comando = ev.target.closest('[data-comando]');
            if (comando) {
                var c = comando.getAttribute('data-comando');
                if (c === 'modo-com' || c === 'modo-sem') {
                    trocarModo(c === 'modo-sem');
                } else if (c === 'centralizar' && selecionado) {
                    var r = copiar(selecionado.r);
                    r.x = (1 - r.w) / 2;
                    aplicar(selecionado, r);
                } else if (c === 'padrao-um' && selecionado) {
                    aplicar(selecionado, copiar(selecionado.padrao));
                } else if (c === 'padrao-todos') {
                    if (modoSemMoldura) trocarModo(false);
                    window.painelConfirmar({
                        titulo: 'Tudo no lugar padrão?',
                        texto: 'Todos os elementos desta tela voltam à posição e ao tamanho padrão. A mudança só vale depois de tocar em Salvar posições.',
                        botao: 'Voltar ao padrão'
                    }, function () {
                        Object.keys(elementos).forEach(function (k) { aplicar(elementos[k], copiar(elementos[k].padrao)); });
                    });
                }
                return;
            }
            var peca = ev.target.closest('.peca-elemento');
            if (peca && !ev.target.closest('form')) {
                selecionar(peca.getAttribute('data-elemento'));
            }
        });

        raiz.querySelectorAll('.peca-elemento').forEach(function (p) {
            p.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter' && ev.target === p) selecionar(p.getAttribute('data-elemento'));
            });
        });

        palco.addEventListener('pointerdown', function (ev) {
            if (ev.target === palco) selecionar(null);
        });

        // ---------- Arrastar e redimensionar ----------

        function comecarArrasto(ev, e) {
            if (ev.button !== undefined && ev.button !== 0) return;
            if (modoSemMoldura) return;
            ev.preventDefault();
            e.no.focus({ preventScroll: true });
            selecionar(e.id);

            var redimensionar = ev.target.classList.contains('el-alca');
            var inicio = { px: ev.clientX, py: ev.clientY, r: copiar(e.r) };
            var largura = palco.clientWidth;
            var altura = palco.clientHeight;
            e.no.setPointerCapture(ev.pointerId);
            e.no.classList.add('el-arrastando');

            function mover(m) {
                var dx = (m.clientX - inicio.px) / largura;
                var dy = (m.clientY - inicio.py) / altura;
                var r = copiar(inicio.r);
                var centroV = false;
                var centroH = false;
                if (redimensionar) {
                    // Cresce para os dois lados em volta do meio (o canto segue o
                    // mouse), sem passar da borda da tela
                    var cx = inicio.r.x + inicio.r.w / 2;
                    var cy = inicio.r.y + inicio.r.h / 2;
                    var maxW = 2 * Math.min(cx, 1 - cx);
                    var maxH = 2 * Math.min(cy, 1 - cy);
                    r.w = limitar(inicio.r.w + 2 * dx, MINIMO, maxW);
                    r.h = e.aspecto ? alturaPara(r.w, e.aspecto) : limitar(inicio.r.h + 2 * dy, MINIMO, maxH);
                    if (e.aspecto && r.h > maxH) {
                        r.h = maxH;
                        r.w = r.h * e.aspecto / PROPORCAO_TELA;
                    }
                    r.x = cx - r.w / 2;
                    r.y = cy - r.h / 2;
                } else {
                    r.x = limitar(inicio.r.x + dx, 0, 1 - r.w);
                    r.y = limitar(inicio.r.y + dy, 0, 1 - r.h);
                    if (Math.abs(r.x + r.w / 2 - 0.5) < IMA) { r.x = 0.5 - r.w / 2; centroV = true; }
                    if (Math.abs(r.y + r.h / 2 - 0.5) < IMA) { r.y = 0.5 - r.h / 2; centroH = true; }
                }
                guiaV.classList.toggle('guia-visivel', centroV);
                guiaH.classList.toggle('guia-visivel', centroH);
                aplicar(e, r);
            }

            function soltar() {
                e.no.removeEventListener('pointermove', mover);
                e.no.removeEventListener('pointerup', soltar);
                e.no.removeEventListener('pointercancel', soltar);
                e.no.classList.remove('el-arrastando');
                guiaV.classList.remove('guia-visivel');
                guiaH.classList.remove('guia-visivel');
            }

            e.no.addEventListener('pointermove', mover);
            e.no.addEventListener('pointerup', soltar);
            e.no.addEventListener('pointercancel', soltar);
        }

        function teclado(ev, e) {
            if (modoSemMoldura) return;
            var passo = ev.shiftKey ? 0.02 : 0.002;
            var r = copiar(e.r);
            if (ev.key === 'ArrowLeft') r.x -= passo;
            else if (ev.key === 'ArrowRight') r.x += passo;
            else if (ev.key === 'ArrowUp') r.y -= passo;
            else if (ev.key === 'ArrowDown') r.y += passo;
            else return;
            ev.preventDefault();
            aplicar(e, r);
        }

        // ---------- Salvar ----------

        this.layout = function () {
            var saida = {};
            Object.keys(elementos).forEach(function (id) { saida[id] = elementos[id].r; });
            return saida;
        };

        this.alterado = function () {
            return Object.keys(elementos).some(function (id) {
                var a = elementos[id].r;
                var b = salvo[id];
                return a.x !== b.x || a.y !== b.y || a.w !== b.w || a.h !== b.h;
            });
        };

        function marcarAlteracao() {
            var mudou = self.alterado();
            botaoSalvar.disabled = !mudou;
            estado.textContent = mudou ? 'Há mudanças ainda não salvas.' : '';
            estado.classList.toggle('estado-pendente', mudou);
        }

        window.addEventListener('resize', desenharTudo);
        // A imagem do botão muda o tamanho do texto padrão só depois de carregar
        if (document.readyState === 'complete') desenharTudo();
        else window.addEventListener('load', desenharTudo);
        desenharTudo();
    }

    document.querySelectorAll('.editor[data-editor]').forEach(function (raiz) {
        editores.push(new Editor(raiz));
    });

    // Todo formulário de uma tela leva as posições atuais do editor dela. Fica
    // no document para rodar depois da confirmação ("Remover?") do formulário.
    document.addEventListener('submit', function (ev) {
        if (ev.defaultPrevented) return;
        enviando = true;
        var form = ev.target;
        var secao = form.closest('[data-tela-secao]');
        var editor = null;
        editores.forEach(function (ed) { if (ed.secao === secao) editor = ed; });
        if (!editor) return;
        var campo = form.querySelector('input[name=layout]');
        if (!campo) {
            campo = document.createElement('input');
            campo.type = 'hidden';
            campo.name = 'layout';
            form.appendChild(campo);
        }
        campo.value = JSON.stringify(editor.layout());
    });

    window.addEventListener('beforeunload', function (ev) {
        if (enviando) return;
        if (editores.some(function (ed) { return ed.alterado(); })) {
            ev.preventDefault();
            ev.returnValue = '';
        }
    });
})();
