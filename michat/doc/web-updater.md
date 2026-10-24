# Actualizador web de MiChat

## Alcance
La pantalla **Acerca de MiChat** permite consultar origin/main y solicitar actualización desde un usuario con `Users.system_role='superadmin'`. Reutiliza `Users.password`, `ChatIdentity`, `AuthorizationService`, `CsrfGuard` y `AccessControl` de la base de datos **compartida con ArcadeCloud S3**. Después de actualizar, verifica y crea automáticamente las dos tablas aditivas de procesamiento de archivos (fase14). No ejecuta las migraciones históricas ni altera tablas compartidas existentes.

## Preparación de EC2 (intervención manual autorizada)
Primero instalar la rama nueva normalmente en `/var/www/michat`; identificar el usuario real del pool PHP-FPM correspondiente a MiChat. Ejecutar:

```bash
sudo bash /var/www/michat/michat/bin/install_michat_updater.sh USUARIO_REAL_PHP_FPM
```

El instalador genera `/etc/michat/updater.json`, copia el helper a `/usr/local/sbin/michat-updater` y añade sudoers limitado a `probe/check/apply`. Nunca usar el usuario PHP de S3 sin verificar que es también el de MiChat.

## Contrato de seguridad
- El backend exige sesión activa, rol superadmin, CSRF y contraseña actual verificada con `password_verify` antes de aplicar.
- El helper instalado está fuera de Git y sólo acepta `origin` del repositorio `jimmybackend/michat`, rama `main`, fast-forward y checkout `/var/www/michat`.
- No permite reset hard, forzar push, comandos arbitrarios ni seleccionar repositorio desde HTTP.
- No se utiliza stash. Los cambios locales incompatibles, dependencias cambiadas y los commits divergentes bloquean el proceso.
- El helper privilegiado solo actualiza Git; no ejecuta SQL ni reinicia PHP-FPM, Nginx o workers. La aplicación, con su propia cuenta MySQL y sin root, prepara exclusivamente AttachmentProcessingJobs y AttachmentProcessingCosts. El endpoint de actualización verifica el resultado antes de comunicar éxito.
- La verificación remota `fetch` puede tardar; el administrador debe revisar límites de tiempo de PHP/nginx.

## Verificación manual posterior
```bash
cd /var/www/michat
git status --short
git rev-parse HEAD
sudo systemctl is-active michat-task-worker.service
```

Si el código cambió y el worker necesita reinicio, verificar primero si ejecuta tareas activas. No reiniciar a ciegas. Después de actualizar, al abrir MiChat con una sesión activa (o arrancar su worker) se preparan las tablas fase14 si faltan. Esto también funciona con la copia anterior del helper, sin reinstalarla. Las migraciones históricas siguen siendo explícitas; no se adopta una historia desconocida ni se reproducen scripts antiguos.

**Importante:** Fusionar esta funcionalidad en GitHub no instala el helper ni actualiza la EC2 por sí solo.

## Preparación automática de fase14

`AttachmentProcessingSchema` usa el mismo bloqueo por base de datos que el runner. Solo permite las dos definiciones CREATE TABLE del archivo fase14, sin modificar sus bytes ni checksums históricos. Valida columnas, tipos, valores predeterminados, índices, motor y cotejamiento de tablas presentes antes de crear las ausentes. Las tablas temporales de referencia se eliminan; las tablas existentes y sus registros no se borran ni alteran. Si se interrumpió una creación sin historial de éxito, puede continuar. Una estructura incompatible, un checksum diferente o una tabla desaparecida después de un éxito registrado bloquean la reparación automática.

La cuenta MySQL de la aplicación necesita CREATE y CREATE TEMPORARY TABLES para esa preparación. Si falla, el error queda en el log y la tarea no se marca como preparada; el resto de MiChat puede seguir abriendo. No se modifican concesiones de permisos automáticamente.

Si SchemaMigrations ya tiene historia, se registra el checksum real de fase14 después de verificar ambas tablas. Si no hay historia inicializada, no se inventa una línea base histórica. El runner CLI reconoce posteriormente esas dos estructuras exactas sin volver a crearlas.
