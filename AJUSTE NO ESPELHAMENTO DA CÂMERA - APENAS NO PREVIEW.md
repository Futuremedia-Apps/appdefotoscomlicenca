# Histórico de Alterações - Câmera

## 26 de Maio de 2026 - Espelhamento da Visualização da Câmera

### Descrição
Foi implementado o espelhamento horizontal da pré-visualização da câmera (preview) para melhorar a experiência do usuário, simulando um efeito de espelho antes da captura da foto.

### Arquivos Alterados
- `lib/tela2.dart`

### Detalhes Técnicos
A alteração foi realizada no widget `CameraPreview`, que estava envolvido por um `Transform`. Modificamos a matriz de transformação para aplicar uma escala negativa no eixo X.

**Código anterior:**
```dart
transform: Matrix4.identity()..scale(1.0, 1.0),
```

**Novo código:**
```dart
transform: Matrix4.identity()..scale(-1.0, 1.0),
```

### Impacto
- Afeta apenas a **visualização** (preview) na `Tela2`.
- Não altera o arquivo de imagem final salvo (que permanece com a orientação original do sensor da câmera).
