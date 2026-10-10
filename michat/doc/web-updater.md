# Actualizador web de MiChat

## Alcance
La pantalla **Acerca de MiChat** permite consultar origin/main y solicitar actualización desde un usuario con `Users.system_role='superadmin'`. Reutiliza `Users.password`, `ChatIdentity`, `AuthorizationService`, `CsrfGuard` y `AccessControl` de la base de datos **compartida con ArcadeCloud S3**. No crea ni migra tablas.

## Preparación de EC2 (intervención manual autorizada)
Primero instalar la rama nueva normalmente en `/var/www/michat`; identificar el usuario real del pool PHP-FPM correspondiente a MiChat. Ejecutar:

```bash
sudo bash /var/www/michat/michat/bin/install_michat_updater.sh USUARIO_REAL_PHP_FPM
```

El instalador genera `/etc/michat/updater.json`, copia el helper a `/usr/local/sbin/michat-updater` y añade sudoers limitado a `probe/check/apply/apply-stash`. Nunca usar el usuario PHP de S3 sin verificar que es también el de MiChat.

## Contrato de seguridad
- El backend exige sesión activa, rol superadmin, CSRF y contraseña actual verificada con `password_verify` antes de aplicar.
- El helper instalado está fuera de Git y sólo acepta `origin` del repositorio `jimmybackend/michat`, rama `main`, fast-forward y checkout `/var/www/michat`.
- No permite reset hard, forzar push, comandos arbitrarios ni seleccionar repositorio desde HTTP.
- Los cambios locales pueden guardarse opcionalmente en un stash que **no se restaura automáticamente**; los commits locales divergentes bloquean el proceso.
- El código actualizado no reinicia PHP-FPM, Nginx ni el task worker y no ejecuta SQL. El mensaje de éxito exige revisar dependencias y reiniciar el worker de forma controlada.
- La verificación remota `fetch` puede tardar; el administrador debe revisar límites de tiempo de PHP/nginx.

## Verificación manual posterior
```bash
cd /var/www/michat
git status --short
git rev-parse HEAD
sudo systemctl is-active michat-task-worker.service
```

Si el código cambió y el worker necesita reinicio, verificar primero si ejecuta tareas activas. No reiniciar a ciegas. Si hay migraciones de MiChat, inspeccionarlas individualmente antes de ejecutar cualquier cambio en MySQL compartido.

**Importante:** Fusionar esta funcionalidad en GitHub no instala el helper ni actualiza la EC2 por sí solo.
