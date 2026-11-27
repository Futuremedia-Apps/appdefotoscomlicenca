# Documentacao - ajuste automatico da cabeca do robo Orion/Buddy

## Objetivo

Adicionar ao app de fotos a mesma funcao usada no projeto Buddy para levantar a cabeca do robo quando o APK abre.

Quando o app inicia no robo, ele deve:

1. Conectar no SDK do robo Orion via `RobotApi`.
2. Aguardar aproximadamente `1200ms` apos a conexao.
3. Enviar um comando absoluto para a cabeca:

```kotlin
RobotApi.getInstance().moveHead(
    reqId,
    "absolute",
    "absolute",
    0,
    14,
    listener
)
```

O valor importante e o angulo vertical `14`, que foi copiado do Buddy.

## Comportamento esperado

Ao abrir o APK de fotos no robo:

- o app conecta no servico `RobotApi`;
- a cabeca vai para o angulo vertical fixo `14`;
- o movimento acontece apenas uma vez por abertura/conexao do app;
- o app continua funcionando normalmente com a interface Flutter.

Esse ajuste e absoluto, nao relativo. Isso significa que o robo vai para o angulo `14`, e nao "sobe mais 14 graus" a cada abertura.

## Arquivos alterados neste projeto

Projeto:

```text
C:\Users\Futuremedia\Desktop\Estudos\camerashotAtualizado2026
```

Arquivos:

```text
android/app/src/main/kotlin/com/bynexti/camerashot/MainActivity.kt
android/app/build.gradle.kts
android/app/src/main/AndroidManifest.xml
android/app/libs/robotservice.jar
```

## 1. MainActivity.kt

Arquivo:

```text
android/app/src/main/kotlin/com/bynexti/camerashot/MainActivity.kt
```

Codigo atual esperado:

```kotlin
package com.bynexti.camerashot

import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.util.Log
import com.ainirobot.coreservice.client.ApiListener
import com.ainirobot.coreservice.client.RobotApi
import com.ainirobot.coreservice.client.listener.CommandListener
import io.flutter.embedding.android.FlutterActivity

class MainActivity : FlutterActivity() {

    companion object {
        private const val TAG = "PhotoHeadAdjust"
        private const val PHOTO_HEAD_START_ANGLE = 14
    }

    private var robotConnected = false
    private var robotControlGranted = false
    private var reqIdCounter = 100
    private var startupHeadApplied = false
    private val mainHandler = Handler(Looper.getMainLooper())

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        connectRobotForPhotoHead()
    }

    override fun onDestroy() {
        mainHandler.removeCallbacksAndMessages(null)
        super.onDestroy()
    }

    private fun connectRobotForPhotoHead() {
        try {
            RobotApi.getInstance().connectServer(this, object : ApiListener {
                override fun handleApiConnected() {
                    Log.i(TAG, "RobotApi connected")
                    robotConnected = true
                    robotControlGranted = true

                    mainHandler.postDelayed({
                        applyPhotoHeadAngle(PHOTO_HEAD_START_ANGLE)
                    }, 1200L)
                }

                override fun handleApiDisconnected() {
                    Log.w(TAG, "RobotApi disconnected")
                    robotConnected = false
                    robotControlGranted = false
                    startupHeadApplied = false
                }

                override fun handleApiDisabled() {
                    Log.w(TAG, "RobotApi disabled")
                    robotConnected = false
                    robotControlGranted = false
                    startupHeadApplied = false
                }
            })
        } catch (e: Exception) {
            Log.e(TAG, "connectRobotForPhotoHead error: ${e.message}")
        }
    }

    private fun canUseRobotApi(): Boolean = robotConnected && robotControlGranted

    private fun applyPhotoHeadAngle(angle: Int) {
        if (!canUseRobotApi()) {
            Log.w(TAG, "Head angle ignored: RobotApi unavailable")
            return
        }
        if (startupHeadApplied) return

        startupHeadApplied = true
        try {
            RobotApi.getInstance().moveHead(
                reqIdCounter++,
                "absolute",
                "absolute",
                0,
                angle,
                object : CommandListener() {
                    override fun onResult(status: Int, responseString: String?) {
                        Log.i(TAG, "Photo head angle $angle applied: status=$status response=$responseString")
                    }

                    override fun onError(errorCode: Int, errorString: String?) {
                        startupHeadApplied = false
                        Log.e(TAG, "Photo head angle failed: code=$errorCode msg=$errorString")
                    }
                }
            )
        } catch (e: Exception) {
            startupHeadApplied = false
            Log.e(TAG, "applyPhotoHeadAngle error: ${e.message}")
        }
    }
}
```

### Onde trocar o angulo

Para ajustar a posicao da cabeca, altere somente esta constante:

```kotlin
private const val PHOTO_HEAD_START_ANGLE = 14
```

Sugestoes de teste:

```text
0  = neutro
5  = levemente levantado
10 = moderado
14 = mesmo angulo usado no Buddy
18 = um pouco mais alto
20 = alto
25 = testar com cuidado
```

## 2. Dependencia do SDK do robo

Arquivo:

```text
android/app/build.gradle.kts
```

No final do arquivo, deve existir:

```kotlin
dependencies {
    implementation(fileTree(mapOf("dir" to "libs", "include" to listOf("*.jar"))))
}
```

Essa linha faz o Android incluir qualquer `.jar` dentro de:

```text
android/app/libs
```

## 3. Arquivo robotservice.jar

Arquivo necessario:

```text
android/app/libs/robotservice.jar
```

Esse arquivo e o SDK do robo Orion usado para acessar:

```kotlin
RobotApi
ApiListener
CommandListener
```

Sem esse `.jar`, o projeto nao compila porque os imports `com.ainirobot...` nao existem.

## 4. Permissao no AndroidManifest.xml

Arquivo:

```text
android/app/src/main/AndroidManifest.xml
```

Adicionar junto das outras permissoes:

```xml
<uses-permission android:name="com.ainirobot.coreservice.robotSettingProvider" />
```

No projeto atual, essa permissao esta antes dos `uses-feature`.

## 5. Como o fluxo funciona

### onCreate

Quando a `MainActivity` abre, chama:

```kotlin
connectRobotForPhotoHead()
```

### connectServer

Essa funcao tenta conectar ao servico do robo:

```kotlin
RobotApi.getInstance().connectServer(this, object : ApiListener { ... })
```

Quando conecta, o SDK chama:

```kotlin
handleApiConnected()
```

### Delay de seguranca

Depois de conectado, o app espera `1200ms`:

```kotlin
mainHandler.postDelayed({
    applyPhotoHeadAngle(PHOTO_HEAD_START_ANGLE)
}, 1200L)
```

Esse delay evita mandar o comando cedo demais, antes do controle do robo estar pronto.

### moveHead

A funcao que mexe a cabeca e:

```kotlin
RobotApi.getInstance().moveHead(
    reqIdCounter++,
    "absolute",
    "absolute",
    0,
    angle,
    listener
)
```

Parametros:

```text
reqIdCounter++ = ID incremental da chamada
"absolute"     = modo horizontal absoluto
"absolute"     = modo vertical absoluto
0              = angulo horizontal
angle          = angulo vertical da cabeca
listener       = callbacks de sucesso/erro
```

## 6. Por que usar "absolute"

Usar `absolute` e importante para evitar acumulacao.

Correto:

```kotlin
moveHead(..., "absolute", "absolute", 0, 14, ...)
```

Isso significa:

```text
Coloque a cabeca no angulo vertical 14.
```

Evitar movimento relativo nesse caso, porque ele poderia fazer a cabeca subir mais um pouco a cada abertura do app.

## 7. Logs para validar no robo

Filtrar no Logcat pela tag:

```text
PhotoHeadAdjust
```

Logs esperados:

```text
RobotApi connected
Photo head angle 14 applied: status=...
```

Se der erro:

```text
Head angle ignored: RobotApi unavailable
Photo head angle failed: code=... msg=...
connectRobotForPhotoHead error: ...
```

## 8. Como buildar

O projeto e Flutter, entao precisa do Flutter SDK instalado e configurado.

No arquivo:

```text
android/local.properties
```

O caminho precisa apontar para uma instalacao real do Flutter:

```properties
flutter.sdk=C:\\caminho\\para\\flutter
```

No ambiente atual, havia este valor:

```properties
flutter.sdk=C:\\dev\\flutter
```

Mas essa pasta nao existia nesta maquina, por isso o build local falhou com:

```text
Included build 'C:\dev\flutter\packages\flutter_tools\gradle' does not exist.
```

Para buildar, corrija o `flutter.sdk` ou coloque o Flutter nesse caminho.

Com o Flutter configurado, rode na raiz do projeto:

```powershell
flutter build apk --debug
```

Ou:

```powershell
flutter build apk --release
```

Tambem pode buildar pela pasta Android:

```powershell
cd android
.\gradlew.bat assembleDebug
```

Porem, por ser Flutter, o Gradle tambem depende do caminho correto do Flutter SDK.

## 9. Como testar no robo

1. Gerar o APK.
2. Instalar no robo.
3. Abrir o app pelo menu/Application Center do robo, se possivel.
4. Observar se a cabeca sobe para o angulo configurado.
5. Abrir Logcat e filtrar por `PhotoHeadAdjust`.
6. Tirar uma foto teste e conferir o enquadramento.
7. Se precisar, ajustar `PHOTO_HEAD_START_ANGLE`.
8. Rebuildar e repetir.

## 10. Problemas comuns

### O projeto nao compila com imports com.ainirobot

Conferir se existe:

```text
android/app/libs/robotservice.jar
```

E se o Gradle inclui:

```kotlin
implementation(fileTree(mapOf("dir" to "libs", "include" to listOf("*.jar"))))
```

### O build falha dizendo que nao encontra o Flutter

Conferir:

```text
android/local.properties
```

O valor `flutter.sdk` precisa apontar para a pasta real do Flutter.

### O APK abre, mas a cabeca nao mexe

Verificar:

- se esta rodando no robo Orion/Buddy real;
- se o app foi aberto pelo sistema do robo e nao apenas por um modo que bloqueia controle;
- se o Logcat mostra `RobotApi connected`;
- se o Logcat mostra erro na tag `PhotoHeadAdjust`;
- se a permissao `com.ainirobot.coreservice.robotSettingProvider` esta no manifest.

### A cabeca mexe para uma posicao ruim

Trocar:

```kotlin
private const val PHOTO_HEAD_START_ANGLE = 14
```

Testar primeiro:

```text
10 -> 14 -> 18 -> 20 -> 5 -> 0
```

## 11. Resumo para quem vai buildar

Antes de buildar, confirmar:

- `MainActivity.kt` tem a conexao com `RobotApi`;
- `PHOTO_HEAD_START_ANGLE` esta em `14`;
- `android/app/libs/robotservice.jar` existe;
- `android/app/build.gradle.kts` inclui os `.jar` da pasta `libs`;
- `AndroidManifest.xml` tem a permissao do SDK Orion;
- `android/local.properties` aponta para um Flutter SDK existente;
- o teste final deve ser feito no robo fisico.

