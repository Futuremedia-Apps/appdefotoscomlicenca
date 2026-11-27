# Keep OrionStar SDK classes
-keep class com.ainirobot.** { *; }
-keep interface com.ainirobot.** { *; }

# Keep GSON
-keep class com.google.gson.** { *; }

# Flutter R8 rules are usually handled automatically, 
# but we add these to ensure the robot JAR works correctly.
-dontwarn com.ainirobot.**
-dontwarn androidx.annotation.**
-dontwarn android.support.annotation.**
