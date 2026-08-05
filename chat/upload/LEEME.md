# Módulo de Subidas (S3v2) — LEEME.md

Este módulo unifica **todas las subidas de archivos** (desde PC, desde URL remota/Drive, por Dropzone/Dropbox y por chunks) usando una **API común** (`api/upload.php`) y drivers polimórficos en `upload/drivers/`.

---

## Estructura de carpetas

Dentro de `public_html/s3v2/`:

```
api/
  upload.php                ← API unificada (router)

upload/
  UploadFactory.php         ← Factory de drivers según mode
  core/
    UploaderInterface.php   ← Interfaz común init/part/complete
    UploadResponse.php      ← Respuestas JSON uniformes
  drivers/
    LocalPresignedPutUploader.php   ← Subida local con URL firmada (PUT directo a S3)
    RemoteUrlUploader.php           ← Subida por URL remota (Drive/Directo) desde servidor
    DropboxUploader.php             ← Subida vía Dropzone (multipart/form-data) desde servidor
    Chunked15MBUploader.php         ← Subida por partes (presigned uploadPart)
  repositories/
    FileS3Repository.php     ← Inserta/actualiza registros en tabla FileS3
  storage/
    UploadStateStore.php     ← Guarda estado JSON del multipart (chunked)
    state/                   ← Carpeta donde se guardan JSON de estado (debe ser escribible)
```

Además, en la raíz de `s3v2/`:
- `app_bootstrap.php` (carga `vendor/autoload.php` + incluye `Config-s3.php` y `db.php` desde fuera del webroot)
- `vendor/` (Composer, AWS SDK)

---

## Bootstrap y seguridad de credenciales

El proyecto usa `app_bootstrap.php` para **mantener `Config-s3.php` y `db.php` fuera de `public_html`** (carpeta protegida), evitando exposición de credenciales.

`app_bootstrap.php` debe cargar:
1) `s3v2/vendor/autoload.php` (AWS SDK)
2) `Config-s3.php` y `db.php` desde la ruta privada (fuera del webroot)

Si AWS no carga, aparecerá el error: `Class "Aws\S3\S3Client" not found`.

---

## API Unificada: `api/upload.php`

### Parámetros base
- `mode`: tipo de subida
- `action`: fase del flujo

`mode` soportados:
- `local_put`  → archivo desde el navegador (PC) usando URL firmada
- `remote_url` → descarga desde URL (Drive/Directo) y sube a S3 desde servidor
- `dropbox`    → subida multipart/form-data (Dropzone)
- `chunked`    → subida por partes (15MB) con multipart presigned

`action` soportados:
- `init`      → iniciar operación (firmar URL / iniciar multipart / ejecutar remote upload / etc.)
- `part`      → operaciones de partes (solo `chunked`)
- `complete`  → finalizar y/o actualizar metadatos (ej. tamaño final)

---

## Flujos por modo

### 1) `local_put` (Subida desde PC, PUT directo a S3)
**Paso 1: pedir URL firmada**
```
GET /s3v2/api/upload.php?mode=local_put&action=init&nombre=archivo.pdf
```

Respuesta (JSON):
- `url` (presigned PUT)
- `key`, `carpeta`, `nombreEncriptado`, etc.

**Paso 2: el navegador hace PUT a `json.url`**  
**Paso 3 (opcional recomendado): avisar tamaño real**
```
POST /s3v2/api/upload.php?mode=local_put&action=complete
  nombreEncriptado=<...>
  tamano=<bytes>
```

> Este modo inserta primero el registro en FileS3 y luego actualiza el tamaño (si `complete` se llama).

---

### 2) `remote_url` (Subida desde URL remota / Google Drive)
Se envía una URL remota y el servidor:
1) descarga por streaming
2) sube a S3 (multipart si es grande)
3) registra en DB (FileS3)

**POST recomendado**
```
POST /s3v2/api/upload.php?mode=remote_url&action=init
  url=<url>
  u64=<base64 utf8 de url> (opcional)
```

**Fallback GET (útil si WAF/hosting bloquea POST)**
```
GET /s3v2/api/upload.php?mode=remote_url&action=init&u64=<base64>
```

Respuesta (JSON) típica:
- `ok`, `key`, `bytes`, `nombreOriginal`, `nombreEncriptado`

---

### 3) `dropbox` (Dropzone)
Dropzone sube archivos como `multipart/form-data` con field `file`.

Ejemplo:
```
POST /s3v2/api/upload.php?mode=dropbox&action=init
  file=<archivo>
```

Respuesta (JSON):
- `estado`, `resultados[]` con `key` y `file_id`

> Si Dropzone sigue apuntando a `upload.php` (legacy), funciona igual pero NO está unificado. Para unificar, cambia el `url` del Dropzone a `api/upload.php?mode=dropbox&action=init`.

---

### 4) `chunked` (subida por partes de 15MB)
Este modo está pensado para archivos grandes (ej. > 1GB) y usa multipart con presigned UploadPart.

**init** crea MultipartUpload:
```
POST /s3v2/api/upload.php?mode=chunked&action=init
  filename=<nombre>
  filesize=<bytes>
  mime=<mime>
```

**part** firma una parte:
```
POST /s3v2/api/upload.php?mode=chunked&action=part
  uploadId=<id>
  key=<key>
  partNumber=<n>
  contentLength=<bytes>
  step=sign
```

**resume** lista partes subidas (reanudar):
```
POST /s3v2/api/upload.php?mode=chunked&action=part
  step=resume
  stateId=<id> (si se usa)
  uploadId=<id>
  key=<key>
```

**complete** finaliza multipart:
```
POST /s3v2/api/upload.php?mode=chunked&action=complete
  stateId=<id> (si se usa store local)
  uploadId=<id>
  key=<key>
  etags=<json de {partNumber: etag}>
```

> El estado de multipart se guarda en `upload/storage/state/` como JSON. La carpeta debe ser escribible.

---

## Comportamiento de duplicados en `chunked` (15MB)

En el modo **`chunked`** (subida por partes), el sistema está diseñado para **reanudar** subidas interrumpidas.
Por eso, si intentas subir **el mismo archivo** (mismo `filename` y mismo `filesize`) más de una vez:

- El backend calcula un identificador estable (**`stateId`**) a partir de `filename|filesize`.
- El frontend guarda y reutiliza esa sesión en `localStorage` para poder reanudar.
- Al repetir la subida del mismo archivo, se reutiliza la misma sesión y el mismo `key` → **se sobrescribe** (o se completa) el mismo objeto en S3, en vez de crear un duplicado.

✅ Esto es **intencional** y es lo que permite:
- reintentos automáticos por chunk,
- reanudación tras cortes de internet,
- reanudación tras recargar la página (seleccionando el mismo archivo).

> Si en algún momento se necesitara subir “como nuevo” (crear duplicado), se puede añadir un parámetro `forceNew=1` en `init` para generar una key diferente, pero por defecto el comportamiento actual es **sobrescribir / reanudar**.

---

## Registro en base de datos

La tabla usada es `FileS3`.  
Se registran (mínimo):
- `Nombre` (original)
- `Encriptado` (nombre final/encriptado)
- `Tamano`
- `Metadatos` (JSON)
- `Ruta` (prefijo/carpeta, NOT NULL)
- `user_id_`

El repositorio central es:
- `upload/repositories/FileS3Repository.php`

---

## Archivos legacy (compatibilidad)

En el sistema previo existían endpoints:
- `firmado.php` (URL firmada para subida local)
- `firmadowww.php` (subida desde URL remota)
- `upload.php` / `upload_publico.php` (Dropzone)

Ahora la lógica equivalente está en drivers:
- `LocalPresignedPutUploader.php` (reemplaza firmado.php)
- `RemoteUrlUploader.php` (reemplaza firmadowww.php)
- `DropboxUploader.php` (reemplaza upload.php)

### Si decides mantener `firmadowww.php`
Puedes dejarlo por compatibilidad mientras migras el frontend.
Recomendación: que `firmadowww.php` use `app_bootstrap.php` y tenga `vendor/autoload.php` cargado.

---

## Frontend (resumen)

El JS del panel debe apuntar a:
- `window.UPLOAD_API = "api/upload.php";`

Y llamar:
- Local: `?mode=local_put&action=init`
- URL:   `?mode=remote_url&action=init`
- Dropzone: `?mode=dropbox&action=init` (si se unifica)
- Chunked: `?mode=chunked&action=init|part|complete`

---

## Notas y troubleshooting

### Error: `Class "Aws\S3\S3Client" not found`
- Falta cargar `vendor/autoload.php`.  
Solución: asegurar que `app_bootstrap.php` incluya:
`require_once __DIR__ . '/vendor/autoload.php';`

### HTTP 500 en `api/upload.php`
- Revisar logs de PHP.
- Verificar que existan los drivers en `upload/drivers/` con nombres exactos (Linux distingue mayúsculas).
- Confirmar que `app_bootstrap.php` se está cargando desde `api/upload.php`.

### Permisos
- `upload/storage/state/` debe ser escribible para `chunked`.

---

## Versiones
- PHP: 7.x compatible
- AWS SDK: cargado por Composer (`vendor/`)
