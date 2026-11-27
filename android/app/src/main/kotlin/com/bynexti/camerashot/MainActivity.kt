package com.bynexti.camerashot

import android.media.AudioManager
import android.os.Build
import android.os.Bundle
import com.ainirobot.coreservice.client.RobotSettings
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel
import android.os.Handler
import android.os.Looper
import android.util.Log
import com.ainirobot.coreservice.client.ApiListener
import com.ainirobot.coreservice.client.RobotApi
import com.ainirobot.coreservice.client.Definition
import com.ainirobot.coreservice.client.listener.CommandListener
import com.ainirobot.coreservice.client.module.ModuleCallbackApi
import io.flutter.embedding.android.FlutterActivity
import org.json.JSONObject

class MainActivity : FlutterActivity() {

    companion object {
        private const val TAG = "PhotoHeadAdjust"
        // Range 0-90. 0 is usually full UP. 
        // Let's try 90 to force it to the maximum downward position.
       // private const val PHOTO_HEAD_START_ANGLE = 80

        //Mudar o ângulo da tela do Buddy aqui
        private const val PHOTO_HEAD_START_ANGLE = 60
        //private const val PHOTO_HEAD_START_ANGLE = 75
        // private const val PHOTO_HEAD_START_ANGLE = 85 // -> Levemente levantado
       //  private const val PHOTO_HEAD_START_ANGLE = 90 // -> Baixo
        private const val MAX_RETRY_ACTIVE = 30
    }

    private var robotConnected = false
    private var activeCheckCount = 0
    private var startupHeadApplied = false
    private var reqIdCounter = 100
    private val mainHandler = Handler(Looper.getMainLooper())
    private val audioManager by lazy { getSystemService(AudioManager::class.java) }
    private var mutedMediaForPhotos = false

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        connectRobotForPhotoHead()
    }

    override fun onStart() {
        super.onStart()
        val audio = audioManager ?: return
        // Os anúncios do OrionStar continuam tocando como mídia em segundo plano.
        if (!audio.isStreamMute(AudioManager.STREAM_MUSIC)) {
            audio.adjustStreamVolume(AudioManager.STREAM_MUSIC, AudioManager.ADJUST_MUTE, 0)
            mutedMediaForPhotos = audio.isStreamMute(AudioManager.STREAM_MUSIC)
        }
    }

    override fun onStop() {
        // Restaura apenas se o app silenciou a mídia nesta abertura.
        if (mutedMediaForPhotos) {
            audioManager?.adjustStreamVolume(AudioManager.STREAM_MUSIC, AudioManager.ADJUST_UNMUTE, 0)
            mutedMediaForPhotos = false
        }
        super.onStop()
    }

    // Identificação do robô para a licença (lib/robo_info.dart):
    // serial de fábrica e modelo
    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, "futuremedia/robo")
            .setMethodCallHandler { call, result ->
                when (call.method) {
                    "info" -> result.success(mapOf("serial" to serialDoRobo(), "modelo" to (Build.MODEL ?: "")))
                    else -> result.notImplemented()
                }
            }
    }

    // Serial de fábrica: o SDK do robô (ro.robot.serialno), senão as
    // propriedades padrão do Android. "" se nada estiver disponível.
    private fun serialDoRobo(): String {
        val candidatos = mutableListOf<String?>()
        try { candidatos.add(RobotSettings.getSystemSn()) } catch (e: Throwable) { Log.w(TAG, "getSystemSn: ${e.message}") }
        for (nome in listOf("ro.robot.serialno", "ro.serialno", "ro.boot.serialno")) {
            try {
                val classe = Class.forName("android.os.SystemProperties")
                candidatos.add(classe.getMethod("get", String::class.java).invoke(null, nome) as String?)
            } catch (e: Throwable) { }
        }
        for (valor in candidatos) {
            val limpo = valor?.trim() ?: continue
            if (limpo.isNotEmpty() && !limpo.equals("unknown", true) && !limpo.startsWith("Error")) return limpo
        }
        return ""
    }

    override fun onDestroy() {
        mainHandler.removeCallbacksAndMessages(null)
        super.onDestroy()
    }

    private fun connectRobotForPhotoHead() {
        try {
            Log.i(TAG, "Connecting to RobotApi...")
            RobotApi.getInstance().connectServer(this, object : ApiListener {
                override fun handleApiConnected() {
                    Log.i(TAG, "RobotApi connected. Starting active polling...")
                    robotConnected = true
                    
                    // Set callback but don't wait for onRecovery to trigger movement
                    RobotApi.getInstance().setCallback(object : ModuleCallbackApi() {
                        override fun onRecovery() { Log.i(TAG, "Control recovered") }
                        override fun onSuspend() { Log.w(TAG, "Control suspended") }
                    })

                    activeCheckCount = 0
                    checkRobotActive()
                }

                override fun handleApiDisconnected() {
                    robotConnected = false
                    startupHeadApplied = false
                }

                override fun handleApiDisabled() {
                    robotConnected = false
                    startupHeadApplied = false
                }
            })
        } catch (e: Exception) {
            Log.e(TAG, "connectRobotForPhotoHead error: ${e.message}")
        }
    }

    private fun checkRobotActive() {
        if (!robotConnected) return
        
        val isActive = RobotApi.getInstance().isActive
        Log.i(TAG, "Active state: $isActive (Attempt $activeCheckCount)")

        if (isActive) {
            Log.i(TAG, "Robot is active! Setting head angle in 2s...")
            mainHandler.postDelayed({
                applyPhotoHeadAngle(PHOTO_HEAD_START_ANGLE)
            }, 2000L)
        } else {
            if (activeCheckCount < MAX_RETRY_ACTIVE) {
                activeCheckCount++
                mainHandler.postDelayed({ checkRobotActive() }, 1000L)
            }
        }
    }

    private fun applyPhotoHeadAngle(angle: Int) {
        if (!RobotApi.getInstance().isApiConnectedService || !RobotApi.getInstance().isActive) {
            Log.w(TAG, "Robot not ready for movement")
            return
        }
        
        if (startupHeadApplied) return

        try {
            // Stop any follow action that might be holding the head up
            RobotApi.getInstance().stopBodyFollowAction(reqIdCounter++)
            
            Log.i(TAG, "Sending moveHead: angle=$angle")
            RobotApi.getInstance().moveHead(
                reqIdCounter++,
                "absolute",
                "absolute",
                0,
                angle,
                object : CommandListener() {
                    override fun onResult(status: Int, responseString: String?) {
                        Log.i(TAG, "Result: $responseString")
                        startupHeadApplied = true
                    }

                    override fun onError(errorCode: Int, errorString: String?) {
                        Log.e(TAG, "Error: $errorString")
                    }
                }
            )
        } catch (e: Exception) {
            Log.e(TAG, "Exception: ${e.message}")
        }
    }
}
