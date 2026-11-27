# Documentação de Implementação - Robô Orion/Buddy (Final)

Este documento registra as configurações e lógicas que funcionaram com sucesso para o controle do robô e fechamento do app.

## 1. Ajuste da Cabeça (Startup)

Para que o robô olhe para frente/baixo ao iniciar o app, foram necessários os seguintes ajustes:

*   **Arquivo:** `android/app/src/main/kotlin/com/bynexti/camerashot/MainActivity.kt`
*   **Ângulo Vertical Final:** `90` (Limite inferior no range 0-90 da SDK).
*   **Lógica de Conexão:** 
    *   Uso de **Polling** para verificar `RobotApi.getInstance().isActive` a cada 1 segundo.
    *   Chamada obrigatória de `RobotApi.getInstance().stopBodyFollowAction()` antes de fixar o ângulo para evitar que o rastreamento automático de rostos sobrescreva o comando.
    *   Uso de `reqId` incremental para evitar descarte de comandos pelo sistema do robô.

## 2. Dependências e SDK

*   **SDK:** `robotservice.jar` localizado em `android/app/libs/`.
*   **GSON:** Adicionada dependência `com.google.code.gson:gson:2.10.1` no `build.gradle.kts`, necessária para o funcionamento interno da `RobotApi`.
*   **Minificação (R8):** Habilitada com `isMinifyEnabled = true` e configurada via `proguard-rules.pro`.

## 3. Regras de Proguard

Para evitar que o compilador remova classes essenciais em modo Release:
```proguard
-keep class com.ainirobot.** { *; }
-keep interface com.ainirobot.** { *; }
-keep class com.google.gson.** { *; }
-dontwarn com.ainirobot.**
```

## 4. Manifest (Permissões e Filtros)

Configurações essenciais no `AndroidManifest.xml`:
*   **Permissão:** `com.ainirobot.coreservice.robotSettingProvider`.
*   **Intent Filter:** Adicionado `action.orionstar.default.app` para que o sistema do robô reconheça o aplicativo como um controlador válido.

## 5. Comportamento de Saída

*   **Arquivo:** `lib/tela1.dart`
*   **Função:** `abrirApp()`
*   **Alteração:** A lógica de abrir o app Xiaobao foi comentada. Agora o app utiliza `SystemNavigator.pop()` para fechar a aplicação Flutter e retornar à Home do robô.
*   **Import Necessário:** `import 'package:flutter/services.dart';`

## 6. Observações de Teste

1.  O APK deve ser instalado e aberto **clicando no ícone na tela do robô**.
2.  Abrir via ADB ou comandos externos pode impedir que o robô conceda permissão de movimento (`isActive` retornará false).
