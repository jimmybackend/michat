# Búsqueda de metadatos de Drive y JSON de análisis

## Dashboard

`dashboard_stats.php` utiliza el bootstrap portable y una conexión mysqli explícita. El informe pertenece al usuario autenticado, incluyendo sesiones, modelos y lint de sus proyectos. La consulta de compresión elimina `ANY_VALUE()`, incompatible con MariaDB. Un fallo del detalle opcional de lint genera una advertencia y no borra los costos. Si falla la consulta principal se muestra un error; no se inventa un informe vacío.

El costo mostrado es la suma de `TokenUsage.estimated_cost_usd`, sin aplicar una tarifa genérica a modelos desconocidos ni reescribir registros. Son estimaciones históricas guardadas, no facturación de AWS ni una corrección retroactiva de precios. Las operaciones sin registro de uso no aparecen. Los embeddings centrales aún tienen su auditoría independiente, descrita en Fase 13. El gráfico/tabla incluyen todas las fases; un fallo de la CDN de Chart.js no oculta las tablas.

## Modal de ArcadeCloud

Desde 🧠 → **Ver en ArcadeCloud · Buscar archivos** se abre un modal. La búsqueda consulta exclusivamente `FileS3`: Nombre, Ruta, Tamano, Fecha y coincidencias de Metadatos. Solo devuelve datos visibles de archivos propios con Found=1 y AccessType distinto de secure. No lista S3 ni descarga objetos, ejecuta extractores, crea chunks/resúmenes o genera embeddings al buscar. No amplía el acceso a archivos compartidos de terceros.

- Búsqueda literal disponible incluso sin conversación seleccionada.
- IA opcional: interpreta una petición como «contratos PDF de septiembre de 2026» en términos, extensiones y fechas. Solo recibe la petición y el contrato de filtros, nunca registros del catálogo ni contenido de archivos.
- Se usa `drive_metadata_search` si existe; de lo contrario el `chat_main` efectivo y activo. No se inserta ni altera configuración de agentes. El modelo debe admitir Bedrock Converse.
- La búsqueda con IA requiere una conversación propia para atribuir su uso a TokenUsage, fase scout, y POST con CSRF. Se registran los tokens reportados incluso si la salida del planificador es inválida. Se conserva la política de estimación vigente del chat.
- Los filtros de la IA se validan y muestran. No se ejecuta SQL generado. Los campos manuales prevalecen sobre filtros inferidos.
- Lotes de 40 con cursor; el plan interpretado se conserva en la sesión PHP para paginar sin otra llamada al modelo. Una nueva búsqueda IA reemplaza ese plan. No es búsqueda semántica del contenido.

## Selección y análisis

**Seleccionar y analizar** vincula el FileS3 autorizado a la conversación; si no existe conversación se crea una con el procedimiento actual. Solo entonces se consulta S3. Primero se busca el JSON correspondiente a la revisión actual. Si ya existe, se importa su resumen generado sin descargar/extraer de nuevo el original ni volver a invocar el resumidor o embeddings. Si no existe, se reutiliza un resumen verificable ya guardado en ese chat o se llama al servicio semántico/visual existente y se guarda el resultado.

La cobertura del resumen mantiene el límite y las omisiones declarados por el extractor. Un resumen parcial no equivale a lectura íntegra. Los resúmenes reutilizados entran como bloques `file`: las citas los identifican como resúmenes generados, sin inventar páginas o líneas. Para recuperar citas literales y fragmentos originales siguen disponibles las acciones de indexación explícita del panel. Este cambio no añade OCR ni nuevos formatos.

## Objeto JSON auxiliar

La clave privada estable es:

```
<clave real del original>.michat-u<user_id>.analysis.v1.json
```

Está en la misma carpeta S3 que el original, con sufijo por usuario para evitar mezclar análisis cuando hay claves referenciadas por distintos propietarios. El documento conserva nombre visible, ID FileS3, propietario, clave, ETag, cobertura, modelo, resumen y revisiones. Cada revisión conserva sus interacciones. La revisión incluye ID, propietario, ubicación, ETag y nombre; si el original cambia, el análisis anterior no se reutiliza como vigente.

El JSON no se registra como un archivo independiente en FileS3 ni se indexa automáticamente. Así no crea una segunda vía de acceso que sobreviva al bloqueo o desvinculación del original. Puede descargarse desde el resultado del análisis; el endpoint vuelve a validar propietario, sesión, vínculo y bloqueo antes de leer S3. Nunca se publica una URL directa de bucket.

En los dos recorridos de respuestas (legacy y Task), los archivos realmente seleccionados por ContextBuilder que ya tengan JSON reciben la pregunta y respuesta persistidas, modelo y IDs de mensajes, sin otra llamada de IA. Se comprueba que la revisión usada para contestar siga vigente. No se copia toda la conversación ni se inicia un análisis implícito. Las respuestas que no usan el archivo no actualizan su JSON. Las respuestas generadas pueden combinar otras fuentes propias y se identifican como tales, no como texto literal del original. La presencia del JSON evita repetir el análisis; responder nuevas preguntas con un modelo sigue teniendo consumo.

Las escrituras usan un lock por usuario/archivo y condiciones S3 If-Match / If-None-Match, disponibles en la versión AWS SDK 3.344.6 del lockfile actual. Una actualización concurrente falla sin borrar cambios ajenos. El límite de 2 MiB falla explícitamente; no descarta revisiones ni interacciones para hacer espacio. Un error de S3 no se interpreta como cache inexistente. El original no se modifica.

## Operación y límites

No hay migraciones, despliegue, cambios IAM ni cambios en vendor/composer. Tras actualizar el checkout por el procedimiento operativo habitual, PHP debe tener la configuración DB/AWS actual, acceso al original y permiso de escritura sobre su objeto JSON auxiliar. La versión de chat.js depende de su hash y cambia automáticamente.

Los JSON ocupan S3 y producen operaciones de lectura/escritura. No hay sincronización automática del auxiliar al mover/eliminar archivos desde otro sistema: la búsqueda en la ubicación nueva puede necesitar un análisis nuevo; el objeto anterior se conserva. No se realizan limpiezas destructivas. Si falla el guardado de una interacción, la respuesta permanece en ChatMessages y se informa el fallo; no se afirma que el JSON quedó actualizado ni hay un nuevo worker de reparación automática. El usuario puede descargar el JSON para inspeccionarlo; no contiene embeddings ni el texto íntegro extraído.

## Validación

`drive_metadata_analysis_mysql_test.php` usa tablas temporales con el esquema real sobre MariaDB aislada y dobles S3/Bedrock. Comprueba filtros, paginación, costos/mes/propietario, consulta sin ANY_VALUE, contrato real del planificador y tokens, selección explícita, caché entre chats, interacciones idempotentes, revisión cambiada, escrituras condicionales, aislamiento, bloqueo/desvinculación y límites/fallos. Los tests JavaScript ejecutan el controlador real del modal y las funciones de render del dashboard con APIs/DOM controlados. No constituyen certificación AWS/EC2 en vivo.

Después de actualizar en un entorno autorizado:

1. Abrir dashboard_viewer.php, elegir un mes con TokenUsage y comparar el total con las filas del usuario. Probar un mes vacío y un fallo de Chart.js.
2. Abrir el modal y buscar por nombre/tipo/fechas. Verificar que no se crean bloques ni llamadas S3 durante la búsqueda.
3. Seleccionar un archivo propio pequeño, revisar resumen/cobertura y descargar el JSON. Comprobar que el original sigue idéntico.
4. Seleccionar ese archivo desde otro chat y confirmar «Análisis reutilizado». No debe producir una nueva llamada al resumidor.
5. Preguntar sobre el archivo, esperar la respuesta y descargar de nuevo el JSON: debe contener los IDs y el Q&A que usó su contexto.
6. Cambiar el original en un entorno de pruebas; comprobar análisis nuevo y conservación de la revisión anterior. Bloquear/desvincular y verificar acceso denegado, también al JSON.
