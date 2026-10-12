# MiChat: diagnóstico multimodal de producción

**Repositorio:** `jimmybackend/michat`. Estos pasos son verificaciones; no autorizan cambios de infraestructura.

## Imágenes: Titan v2 retirado

El error `amazon.titan-image-generator-v2:0` / `reached the end of its life` indica que el endpoint desplegado todavía está invocando Titan. En `main`, `michat/chat_gen_image.php` convierte las preferencias heredadas de Titan a `amazon.nova-canvas-v1:0`, y `ImageGenerationPolicy::allowedModels()` solo admite Nova Canvas. Comprueba que el archivo realmente servido por PHP-FPM coincide con el commit desplegado y reinicia/opcache solo mediante un procedimiento operativo aprobado. Verifica también la habilitación y permisos de Nova Canvas en la región configurada. No hace falta modificar MySQL para este fallback.

## Video: consulta larga de 120 segundos

Si DevTools solicita `chat_gen_video_status.php?...&wait_secs=120`, el `chat.js` desplegado **no coincide** con `main`: el código actual usa `SERVER_WAIT_SECS = 0`, llamadas cortas y polling en navegador. El endpoint limita `wait_secs` a 5 incluso si el cliente envía 120. Verifica caché del navegador/CDN y la ruta de despliegue de `michat/js/chat.js` y `michat/chat_gen_video_status.php`. El HTTP 504 HTML viene del proxy; no debe confundirse con el estado real del trabajo asíncrono de Bedrock. La falla concurrente de `chat_notify_poll.php` sugiere revisar saturación/latencia PHP-FPM y no atribuir todos los 504 a Nova Reel.

## Voz: error 502 de Nova 2 Sonic

`ScriptProcessorNode` está deprecado, pero esa advertencia no demuestra que cause el 502. El 502 lo devuelve `voice_turn.php` cuando el proceso Node/Sonic falla. Buscar `VOICE_SONIC_ERROR` en el log PHP y correlacionar con la hora; revisar disponibilidad del modelo, permisos Bedrock, región, dependencias instaladas en `michat/voice`, conectividad HTTP/2 y respuesta del bridge `sonic_turn.mjs`. No imprimir credenciales ni stderr completo en la interfaz. El endpoint limita ahora la espera del subprocess a 45 s, para intentar devolver JSON antes de que nginx expire. Esto no garantiza corregir un fallo de Bedrock: requiere inspeccionar el error real.

## Alcance y pruebas

Cambios de esta rama: `voice_turn.php` y `chat_gen_video_status.php`. La corrección de imagen y la de polling del navegador ya están en `main` antes de esta rama. No se cambió `s3`, MySQL, configuración nginx, PHP-FPM, AWS ni producción. Validar con `php -l` para ambos archivos y pruebas integradas en un despliegue de pruebas; confirmar el commit y assets efectivamente servidos antes de promover a producción.
