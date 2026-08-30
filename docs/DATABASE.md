# Base de Datos

## Motor

Mi Central usara **MariaDB 11.8** como base de datos principal.

La aplicacion tendra una base de datos principal compartida por sus modulos, manteniendo separacion logica a nivel de responsabilidades y estructura.

## Acceso desde PHP

PHP accedera a MariaDB mediante **PDO** y la extension `pdo_mysql`.

La conexion se centraliza en `app/Database/Connection.php`, que obtiene sus valores desde `config/database.php`. Los modulos no deben crear conexiones alternativas ni guardar credenciales en codigo.

La configuracion de base de datos se obtiene desde variables de entorno:

- `DB_HOST`
- `DB_PORT`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`
- `DB_CHARSET`

Dentro de Docker Compose, los servicios `web` y `worker` reciben estas variables. El servicio `db` usa las mismas variables `DB_*` como fuente para las variables requeridas por la imagen oficial de MariaDB, evitando duplicar credenciales.

`DB_ROOT_PASSWORD` existe solo para inicializar el usuario root de MariaDB en desarrollo local. La aplicacion no debe usar esa credencial para conectarse.

Las conexiones deben usar:

- `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION`
- `PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC`
- consultas preparadas reales
- `utf8mb4` como charset de conexion

Los errores de conexion no deben imprimir contrasenas ni secretos.

## Migraciones

Los cambios de esquema deben realizarse mediante migraciones.

Las migraciones deben ser versionadas, revisables y reproducibles en entornos Docker.

No se deben aplicar cambios manuales no documentados como fuente permanente de verdad.

Las migraciones se ejecutan con:

```sh
docker compose exec web php bin/migrate.php
```

El sistema registra migraciones aplicadas en `schema_migrations` y no vuelve a ejecutarlas.

Los seeds idempotentes se ejecutan con:

```sh
docker compose exec web php bin/seed.php
```

Los seeds pueden repetirse sin duplicar datos iniciales.

## Integridad referencial

Cuando existan relaciones entre entidades, se deben usar claves foraneas segun corresponda.

Las relaciones deben preservar consistencia entre modulos y evitar datos huerfanos.

## Indices

Los indices deben definirse segun necesidades reales de consulta, integridad y rendimiento.

No se deben agregar indices prematuramente sin una razon clara.

## Codificacion

La base de datos debe usar UTF-8 de forma consistente.

Las tablas, columnas y conexiones deben configurarse para soportar correctamente texto en espanol y otros caracteres necesarios.

La conexion inicial usa `utf8mb4`. MariaDB se inicia con `utf8mb4` como charset de servidor y `utf8mb4_unicode_ci` como collation base.

## Prueba de conectividad

La conectividad entre PHP y MariaDB se comprueba desde CLI, sin exponer diagnosticos por HTTP:

```sh
docker compose exec web php tests/integration/database_connection.php
```

La prueba carga el bootstrap, obtiene la conexion centralizada, ejecuta `SELECT 1` y verifica informacion basica no sensible como base conectada, version del servidor y charset de conexion.

## Tablas actuales

### schema_migrations

Tabla interna para registrar migraciones ejecutadas.

Columnas principales:

- `migration`;
- `executed_at`.

### users

Tabla de autenticacion local y administracion minima de Mi Central.

Columnas principales:

- `id`;
- `username`;
- `password_hash`;
- `approval_status`;
- `is_active`;
- `is_admin`;
- `last_login_at`;
- `approved_at`;
- `approved_by_user_id`;
- `rejected_at`;
- `created_at`;
- `updated_at`.

`username` es unico. `password_hash` almacena hashes seguros generados por PHP, no contrasenas reversibles.

`is_active` controla si una cuenta puede iniciar sesion despues de validar username y contrasena. Un usuario nuevo se crea con `is_active = 1`; una cuenta desactivada queda con `is_active = 0` y no puede obtener ni conservar una sesion valida.

`approval_status`, `approved_at`, `approved_by_user_id` y `rejected_at` pueden existir si se aplico la migracion historica de aprobacion manual. Desde `202608080022_deprecate_user_approval_status.php` quedan como campos legacy: no controlan el acceso y las cuentas pendientes se normalizan a `approved + is_active = 1`. Las cuentas previamente rechazadas se normalizan a `approval_status = approved` pero conservan `is_active = 0`.

`is_admin` habilita la administracion de usuarios en Configuracion / Usuarios para desactivar o reactivar cuentas. `approved_by_user_id` referencia a `users.id` con `ON DELETE SET NULL` por compatibilidad historica.

### login_attempts

Tabla para limitar intentos repetidos de login y registro.

Columnas principales:

- `id`;
- `username`;
- `ip_address`;
- `attempted_at`.

La politica inicial limita 5 intentos fallidos durante 15 minutos por username e IP. El registro publico reutiliza esta tabla con username interno `_register` y limita solicitudes por IP para evitar creacion masiva de cuentas.

### organization_spaces

Espacios principales de Organizacion por usuario.

Columnas principales:

- `id`;
- `user_id`;
- `name`;
- `slug`;
- `created_at`;
- `updated_at`.

Cada usuario puede tener un mismo conjunto base de espacios, con `UNIQUE (user_id, slug)`.

El seed inicial crea de forma idempotente:

- Universidad;
- Amigos;
- Personal;
- Trabajo.

No existe espacio Bandeja. En tareas, `space_id NULL` representa elementos de la Bandeja rapida.

### organization_categories

Clasificacion opcional dentro de Organizacion.

Columnas principales:

- `id`;
- `user_id`;
- `space_id` nullable;
- `name`;
- `created_at`;
- `updated_at`.

Si se elimina un espacio, las categorias asociadas conservan el registro con `space_id NULL`.

### organization_projects

Proyectos de Organizacion asociados a un espacio.

Columnas principales:

- `id`;
- `user_id`;
- `space_id`;
- `title`;
- `description` nullable;
- `status`;
- `starts_on` nullable;
- `due_on` nullable;
- `created_at`;
- `updated_at`.

Estados iniciales:

- `active`;
- `completed`;
- `archived`.

### organization_tasks

Tareas de Organizacion, Bandeja rapida y subtareas.

Columnas principales:

- `id`;
- `user_id`;
- `space_id` nullable;
- `category_id` nullable;
- `project_id` nullable;
- `parent_task_id` nullable;
- `title`;
- `description` nullable;
- `status`;
- `priority` legacy/deprecated;
- `starts_at` nullable;
- `ends_at` nullable;
- `due_at` nullable;
- `completed_at` nullable;
- `position`;
- `created_at`;
- `updated_at`.

Estados iniciales:

- `pending`;
- `completed`.

`priority` se conserva temporalmente como campo legacy para compatibilidad de esquema, pero la interfaz y los servicios de Organizacion ya no lo usan como concepto de producto.

Reglas:

- `space_id NULL` indica que la tarea pertenece a la Bandeja rapida;
- `project_id` relaciona una tarea con un proyecto;
- `parent_task_id` permite subtareas;
- `starts_at` y `ends_at` describen el periodo opcional en que ocurre o se realiza una tarea;
- `due_at` representa la fecha limite opcional;
- no existe una tabla redundante `project_tasks`.

El progreso de un proyecto se calcula a partir de sus tareas asociadas (`completed / total`) y no se guarda como columna persistente. Las subtareas no completan automaticamente a su tarea padre; esa decision queda en manos del usuario.

Las tareas de proyecto heredan el `space_id` del proyecto. Si el espacio de un proyecto cambia, las tareas asociadas se sincronizan al nuevo espacio para mantener coherencia.

### organization_labels

Etiquetas personalizables de Organizacion, pertenecientes a un usuario.

Columnas principales:

- `id`;
- `user_id`;
- `name`;
- `normalized_name`;
- `color`;
- `created_at`;
- `updated_at`.

`color` se guarda normalizado como `#RRGGBB`. `normalized_name` permite `UNIQUE (user_id, normalized_name)` para evitar duplicados por usuario con diferencias de mayusculas o espacios.

Relaciones many-to-many:

- `organization_task_labels`: `user_id`, `task_id`, `label_id`, `created_at`;
- `organization_project_labels`: `user_id`, `project_id`, `label_id`, `created_at`;
- `organization_note_labels`: `user_id`, `note_id`, `label_id`, `created_at`.

Cada tabla de relacion usa clave primaria compuesta por la entidad y la etiqueta. Al eliminar una etiqueta se eliminan sus relaciones por FK en cascada, pero no se eliminan tareas, proyectos ni notas.

### discount_benefit_programs

Catalogo global de beneficios/productos que pueden habilitar promociones.

Columnas principales:

- `id`;
- `provider_name`;
- `normalized_provider_name`;
- `name`;
- `normalized_name`;
- `benefit_type`;
- `product_name` nullable;
- `normalized_product_name`;
- `active`;
- `created_at`;
- `updated_at`.

`benefit_type` usa allowlist relacional mediante `CHECK`: `bank_card`, `bank_account`, `mobile`, `wallet`, `membership` y `other`. Los nombres visibles no se transforman destructivamente; las columnas normalizadas permiten evitar duplicados evidentes mediante `UNIQUE (normalized_provider_name, normalized_name, benefit_type, normalized_product_name)`.

### user_discount_benefits

Beneficios que posee cada usuario.

Columnas principales:

- `id`;
- `user_id`;
- `benefit_program_id`;
- `nickname` nullable;
- `notes` nullable;
- `active`;
- `created_at`;
- `updated_at`.

`user_id` referencia `users.id` con `ON DELETE CASCADE`. `benefit_program_id` referencia `discount_benefit_programs.id` con `ON DELETE RESTRICT` para impedir eliminar silenciosamente un programa usado. `UNIQUE (user_id, benefit_program_id)` evita duplicados por usuario. Las operaciones limitan siempre por `user_id` de sesion.

La seleccion de esta relacion vive en `Configuracion -> Mis tarjetas y beneficios`. El usuario solo marca/desmarca programas existentes del catalogo canonico; no crea BenefitPrograms desde la UI normal y no se guardan numeros de tarjeta, CVV, RUT, claves, vencimientos ni datos bancarios sensibles.

### discount_merchants

Catalogo simple de comercios para promociones.

Columnas principales:

- `id`;
- `name`;
- `normalized_name`;
- `category` nullable;
- `website_url` nullable;
- `active`;
- `created_at`;
- `updated_at`.

No existen sucursales, geolocalizacion ni tabla de categorias en esta fase. `normalized_name` evita comercios duplicados evidentes.

### discount_promotions

Promociones/descuentos importados desde collectors. El soporte historico para registros `manual` puede permanecer en el esquema por compatibilidad, pero la experiencia normal de Descuentos ya no ofrece crear, editar, desactivar ni borrar promociones manualmente.

Columnas principales:

- `id`;
- `merchant_id` nullable;
- `created_by_user_id` nullable;
- `category` nullable;
- `title`;
- `description` nullable;
- `discount_type`;
- `discount_value` nullable;
- `max_discount_clp` nullable;
- `promo_code` nullable;
- `channel`;
- `starts_on` nullable;
- `ends_on` nullable;
- `terms` nullable;
- `source_type`;
- `collector_key` nullable;
- `source_key` nullable;
- `source_url` nullable;
- `last_seen_at` nullable;
- `dedupe_fingerprint` nullable;
- `is_active`;
- `created_at`;
- `updated_at`.

`merchant_id` usa `ON DELETE SET NULL` para conservar promociones historicas si un comercio se elimina del catalogo. `created_by_user_id` referencia `users.id` con `ON DELETE SET NULL`: en promociones manuales identifica al usuario que la creo; en promociones importadas por collectors queda `NULL`. `category` es una categoria controlada de la promocion y no del comercio; puede quedar `NULL` cuando no hay informacion suficiente. `discount_type` permite `percentage`, `fixed_amount`, `special_price` y `other`. `channel` permite `in_store`, `online` y `both`. `source_type` permite `manual` y `collector`.

La migracion `202608080026_add_discount_promotion_category.php` agrega `discount_promotions.category`, crea su indice y rellena promociones existentes de forma conservadora desde categoria de fuente, comercio, titulo, descripcion y terminos. No intenta inferencias agresivas: si no hay senales claras, deja `NULL` u `other`.

`starts_on` y `ends_on` son `DATE` comerciales, no instantes horarios, y pueden ser `NULL` si la vigencia no se conoce. No se convierten a UTC. El modelo no guarda `is_current` ni `expired`; la vigencia se deriva con `is_active`, fechas y weekdays usando `America/Santiago` en servicios de aplicacion. Una promocion queda `Vencida` cuando `ends_on < hoy`.

Para promociones recolectadas, `source_type = collector`, `collector_key` identifica el recolector y `source_key` identifica la promocion dentro de esa fuente. La identidad fuerte de una promocion recolectada es `collector_key + source_key`; `source_key` por si sola no es global. `source_url` permite abrir la promocion oficial cuando contiene una URL `http`/`https` valida. `dedupe_fingerprint` guarda un SHA-256 determinista del contenido normalizado relevante para deduplicacion secundaria, sin ser unico globalmente.

La migracion `202608080025_clean_discount_test_benefit_programs.php` limpia contaminacion de fixtures de Banco de Chile (`Visa Test Card <hash>` y promociones `banco-test-*`). Si alguna referencia sobrevive, primero se migra a un programa canonico `Visa Banco de Chile` y luego se eliminan los BenefitPrograms de test.

`last_seen_at` se actualiza cada vez que un collector vuelve a observar la promocion. La ausencia de una promocion en una ejecucion no la desactiva automaticamente.

La escritura manual de promociones de Fase 7.3 queda deprecada para la experiencia normal. La incorporacion automatica de descuentos la realiza el pipeline de collectors de Fase 8.

### discount_collector_schedules

Estado actual del scheduler de recolectores de descuentos. No es un log historico; el historial vive separado en `discount_collector_runs`.

Columnas principales:

- `id`;
- `collector_key`;
- `enabled`;
- `interval_minutes`;
- `next_run_at` nullable;
- `last_started_at` nullable;
- `last_completed_at` nullable;
- `last_status`;
- `created_at`;
- `updated_at`.

`collector_key` es unico y corresponde a keys registradas en `DiscountCollectorRegistry`. `enabled = 0` conserva la configuracion pero impide ejecucion. `interval_minutes` define cada cuanto se vuelve a programar una ejecucion exitosa; la aplicacion valida un minimo configurable, por defecto 60 minutos.

`next_run_at`, `last_started_at` y `last_completed_at` se guardan en UTC porque son datos tecnicos de backend. `last_status` permite `never`, `running`, `success`, `partial`, `failed` y `not_registered`. `not_registered` se usa para reportar filas heredadas o configuraciones de collectors que ya no existen en el registry, sin borrarlas automaticamente.

El scheduler usa `next_run_at` para decidir ejecuciones pendientes. Tras `success` o `partial` programa `next_run_at = fin + interval_minutes`; tras `failed` programa el siguiente intento con `COLLECTOR_RETRY_MINUTES`. Una ejecucion que queda `running` puede recuperarse si `last_started_at` supera `COLLECTOR_STALE_AFTER_MINUTES`.

### discount_collector_runs

Historial estructurado de ejecuciones de collectors. Cada fila representa una ejecucion iniciada por Cron/scheduler, por CLI manual o por dry-run. No guarda HTML, JSON externo completo, cookies, tokens, headers sensibles ni stack traces.

Columnas principales:

- `id`;
- `collector_key`;
- `trigger_type`;
- `status`;
- `started_at`;
- `completed_at` nullable;
- `duration_ms` nullable;
- `collected_count`;
- `normalized_count`;
- `created_count`;
- `updated_count`;
- `duplicate_count`;
- `skipped_count`;
- `warning_count`;
- `error_count`;
- `error_type` nullable;
- `error_message` nullable;
- `warning_summary` nullable;
- `error_summary` nullable;
- `created_at`;
- `updated_at`.

`trigger_type` permite `scheduled`, `manual` y `dry_run`. `dry_run` registra trazabilidad tecnica pero no persiste promociones. `status` permite:

- `running`: ejecucion iniciada y aun no finalizada;
- `success`: collector y pipeline terminaron correctamente, incluso con 0 promociones;
- `partial`: el collector respondio y el pipeline proceso el lote, pero hubo errores de items;
- `failed`: la ejecucion completa no pudo completarse.

Las metricas se toman de `PromotionImportResult`: recolectadas, normalizadas, creadas, actualizadas, deduplicadas, omitidas, warnings y errores. `duration_ms` se calcula en proceso con reloj monotono cuando esta disponible y se persiste al finalizar.

`error_type` usa categorias controladas: `configuration`, `http`, `timeout`, `parse`, `normalization`, `validation`, `persistence`, `interrupted` y `unknown`. Los mensajes y resumenes se sanitizan y limitan antes de persistir. `warning_summary` y `error_summary` guardan solo las primeras lineas utiles, con conteo de adicionales si hay mas.

### discount_promotion_benefits

Relaciona promociones con beneficios que las habilitan.

Columnas principales:

- `promotion_id`;
- `benefit_program_id`;
- `created_at`.

La clave primaria compuesta `(promotion_id, benefit_program_id)` evita relaciones duplicadas. Al eliminar una promocion se eliminan sus relaciones. Al eliminar un benefit program usado se restringe la eliminacion.

Semantica inicial:

- promocion sin filas en `discount_promotion_benefits` = sin requisito especifico conocido o promocion general;
- promocion con varias filas = OR, cualquiera de esos beneficios puede habilitarla;
- no hay condiciones AND complejas; reglas especiales se conservan en `terms`.

### discount_promotion_days

Dias de semana en que aplica una promocion.

Columnas principales:

- `promotion_id`;
- `weekday`;
- `created_at`.

Convencion unica:

- `1` lunes;
- `2` martes;
- `3` miercoles;
- `4` jueves;
- `5` viernes;
- `6` sabado;
- `7` domingo.

La clave primaria compuesta `(promotion_id, weekday)` evita duplicados. Si una promocion no tiene filas en esta tabla, se considera valida cualquier dia dentro de su vigencia. Si tiene filas, aplica solo esos dias. La determinacion futura de "hoy" debe usar `America/Santiago`.

### user_discount_favorites

Favoritos de descuentos por usuario.

Columnas principales:

- `user_id`;
- `promotion_id`;
- `created_at`.

`UNIQUE`/clave primaria `(user_id, promotion_id)` impide duplicados. Al eliminar usuario o promocion, las filas se eliminan en cascada. No se guarda `is_favorite` dentro de `discount_promotions`; se deriva desde esta tabla para el usuario autenticado.

### video_files

Archivos de video subidos por el usuario para el modulo Video.

Columnas principales:

- `id`;
- `user_id`;
- `original_name`;
- `stored_name`;
- `storage_path`;
- `extension`;
- `mime_type`;
- `size_bytes`;
- `status`;
- `duration_seconds` nullable;
- `width` nullable;
- `height` nullable;
- `fps` nullable;
- `video_codec` nullable;
- `audio_codec` nullable;
- `container_format` nullable;
- `bitrate` nullable;
- `metadata_status`;
- `metadata_error` nullable;
- `analyzed_at` nullable;
- `created_at`;
- `updated_at`.

`user_id` pertenece al usuario autenticado y nunca se recibe desde el navegador. Todas las consultas de listado, consulta y eliminacion deben filtrar por `video_files.user_id`.

`original_name` conserva el nombre mostrado al usuario, saneado y escapado al renderizar. No se usa como ruta fisica. `stored_name` se genera de forma aleatoria por la aplicacion y es unico. `storage_path` apunta a `storage/video/uploads`, que permanece fuera de `public/` y no se sirve directamente por Apache.

Estados iniciales:

- `pending_metadata`: archivo subido y pendiente de analisis tecnico futuro.
- `uploaded`: estado reservado para compatibilidad operativa del modulo.

Estados de metadata:

- `pending`: archivo pendiente de analisis tecnico por worker.
- `processing`: archivo reservado por una ejecucion del worker.
- `ready`: metadata tecnica extraida correctamente.
- `failed`: FFprobe no pudo analizar el archivo original.

`duration_seconds` guarda segundos numericos. `width`, `height`, `fps`, codecs, formato/contenedor y bitrate provienen de FFprobe sobre el archivo original. El worker no modifica, recomprime ni reemplaza el archivo subido.

Los videos existentes antes de esta migracion quedan con `metadata_status = 'pending'` para que puedan analizarse sin volver a subirlos.

El modulo no almacena todavia thumbnails complejos ni descarga publica de exportaciones.

### video_cut_points

Puntos de corte definidos por el usuario sobre un video original.

Columnas principales:

- `id`;
- `video_id`;
- `position_seconds`;
- `created_at`;
- `updated_at`.

`video_id` referencia `video_files.id` con eliminacion en cascada. El ownership se obtiene siempre mediante `video_files.user_id`; el navegador nunca envia `user_id`.

`position_seconds` usa `DECIMAL(12,3)` para conservar precision de milisegundos aproximada. No se guardan strings `HH:MM:SS` como valor principal.

Reglas de aplicacion:

- un corte debe ser mayor a `0`;
- un corte debe ser menor a `video_files.duration_seconds`;
- solo se permite crear, mover o eliminar cortes de videos propios;
- se rechazan cortes practicamente duplicados dentro de una tolerancia pequena;
- agregar, mover o eliminar cortes no modifica el archivo original ni ejecuta FFmpeg.

Los puntos de corte son limites para segmentos virtuales. Por ejemplo, un video de `300` segundos con cortes `60`, `150` y `240` produce los rangos `0-60`, `60-150`, `150-240` y `240-300`, persistidos en `video_edit_segments`.

### video_edit_segments

Segmentos virtuales derivados de los puntos de corte de un video original.

Columnas principales:

- `id`;
- `video_id`;
- `source_start_seconds`;
- `source_end_seconds`;
- `sort_order`;
- `is_included`;
- `created_at`;
- `updated_at`.

`video_id` referencia `video_files.id` con eliminacion en cascada. El ownership se obtiene siempre mediante `video_files.user_id`; el navegador nunca envia `user_id`.

`source_start_seconds` y `source_end_seconds` usan `DECIMAL(12,3)` y representan posiciones dentro del archivo original. No representan el orden del resultado editado y no se modifican para reordenar. El orden de salida se guarda en `sort_order`; un segmento excluido conserva su rango fuente pero no participa en la secuencia final.

Reglas de aplicacion:

- `source_start_seconds >= 0`;
- `source_start_seconds < source_end_seconds`;
- `source_end_seconds <= video_files.duration_seconds`;
- los limites salen exclusivamente de `video_cut_points`;
- excluir/restaurar/reordenar no modifica el archivo original;
- estas operaciones no ejecutan FFmpeg ni generan derivados.

`VideoEditorService` sincroniza esta tabla de forma idempotente desde los cortes. Si no hay cortes, existe conceptualmente un unico segmento `0 -> duration_seconds`. Si hay cortes, los segmentos cubren todo el rango del video sin huecos ni solapamientos. Cuando un corte divide un rango, los nuevos segmentos heredan la decision de inclusion del rango anterior. Cuando se fusionan rangos con decisiones mixtas, el segmento fusionado queda incluido solo si todos los rangos originales estaban incluidos.

`getExportSequence()` devuelve solo segmentos con `is_included = 1`, ordenados por `sort_order`, y queda como contrato de entrada para la Fase 6.6 FFmpeg.

### video_export_jobs

Jobs de exportacion MP4 creados desde el editor de video.

Columnas principales:

- `id`;
- `user_id`;
- `video_id`;
- `status`;
- `output_name`;
- `output_stored_name`;
- `output_path` nullable;
- `estimated_duration_seconds`;
- `output_size_bytes` nullable;
- `error_message` nullable;
- `progress_percent`;
- `processed_seconds` nullable;
- `speed` nullable;
- `output_duration_seconds` nullable;
- `expires_at` nullable;
- `created_at`;
- `started_at` nullable;
- `completed_at` nullable;
- `updated_at`.

`user_id` pertenece al usuario autenticado y nunca se recibe desde el navegador. `video_id` referencia `video_files.id`. El worker opera internamente sin sesion web, pero las operaciones HTTP limitan siempre por `video_export_jobs.user_id`.

Estados:

- `pending`: job creado y aun no tomado por worker;
- `processing`: job reclamado por una ejecucion de `process-video-exports.php`;
- `completed`: archivo MP4 final verificado y movido a `storage/video/exports`;
- `failed`: FFmpeg, FFprobe o la validacion fallaron.

`output_name` es el nombre visible de la exportacion. `output_stored_name` es el filename fisico generado internamente, con forma `export_<random>.mp4`. `output_path`, cuando existe, apunta a `storage/video/exports`, fuera de `public/`.

`progress_percent` parte en `0`, se actualiza desde la salida real de FFmpeg y queda en `100` solo cuando el job termina `completed`. `processed_seconds` y `speed` guardan el ultimo dato conocido de FFmpeg. `output_duration_seconds` se guarda tras verificar la salida con FFprobe.

`expires_at` se calcula al completar el job usando `VIDEO_EXPORT_RETENTION_DAYS`, con default de 30 dias. La limpieza automatica elimina el MP4 vencido y luego borra el job para mantener BD y filesystem consistentes. Los jobs `failed` conservan el error controlado, pero no son descargables.

### video_export_segments

Snapshot inmutable de los segmentos incluidos en una exportacion.

Columnas principales:

- `id`;
- `export_job_id`;
- `source_start_seconds`;
- `source_end_seconds`;
- `sort_order`;
- `created_at`.

Al crear un job, `VideoExportService` copia `getExportSequence()` a esta tabla dentro de la misma transaccion. Si luego el usuario cambia cortes, inclusion o reordenamiento en `video_edit_segments`, el job ya creado mantiene su snapshot original.

El worker usa exclusivamente `video_export_segments` para procesar el job. No depende de `video_edit_segments` durante la exportacion.

### video_transcriptions

Tabla historica de jobs e historial de transcripcion local para videos. La funcionalidad Whisper/transcripcion fue retirada en la fase 11.2; esta tabla se conserva por compatibilidad de migraciones y datos existentes, sin UI, API, workers ni notificaciones activas.

Columnas principales:

- `id`;
- `user_id`;
- `video_id` nullable;
- `export_job_id` nullable;
- `source_type`;
- `source_video_id`;
- `source_display_name`;
- `status`;
- `requested_language`;
- `detected_language` nullable;
- `model`;
- `progress_percent`;
- `full_text` nullable;
- `error_message` nullable;
- `created_at`;
- `started_at` nullable;
- `completed_at` nullable;
- `updated_at`.

`user_id` pertenecia al usuario autenticado y nunca se recibia desde el navegador. La transcripcion tenia una fuente explicita: original (`source_type = video`, `video_id` poblado) o exportacion (`source_type = export`, `export_job_id` poblado al crear el job). `source_video_id` referencia el video original propietario para listar el historial completo bajo el mismo video, incluyendo transcripciones hechas desde MP4 exportados. `source_display_name` conserva el nombre visible de la fuente usada.

`video_id` referencia `video_files.id` para fuentes originales. `export_job_id` referencia `video_export_jobs.id` con `ON DELETE SET NULL`, para que una transcripcion completada de una exportacion pueda conservarse si luego se elimina el MP4 exportado/job asociado.

Estados:

- `pending`: job creado y aun no tomado por worker.
- `processing`: job reclamado por el worker historico.
- `completed`: se generaron segmentos validos y se guardo `full_text`.
- `failed`: la generacion o validacion fallo.

`requested_language` esta limitado a:

- `auto`;
- `es`;
- `en`.

La aplicacion actual ya no crea ni procesa nuevas filas de transcripcion. `model` conserva el valor historico usado al crear/procesar el job, por ejemplo `small`. `detected_language` se guarda solo cuando el motor lo entrego de forma estructurada y confiable; si no existe, queda `NULL`.

`progress_percent` parte en `0`, pasa a `1` al reclamar el job y llega a `100` solo en `completed`. Si un job falla, conserva el ultimo porcentaje real conocido y no se fuerza a 100.

Las filas `completed` anteriores se conservan. La fase 11.2 no elimina datos ni aplica migraciones destructivas sobre esta tabla.

### video_transcription_segments

Segmentos historicos normalizados de transcripciones retiradas en la fase 11.2.

Columnas principales:

- `id`;
- `transcription_id`;
- `segment_index`;
- `start_seconds`;
- `end_seconds`;
- `text`;
- `created_at`.

`transcription_id` referencia `video_transcriptions.id` con eliminacion en cascada. `segment_index` es unico por transcripcion para mantener un orden estable. `start_seconds` y `end_seconds` usan `DECIMAL(12,3)` y nunca se guardan como strings `HH:MM:SS`.

Reglas:

- `start_seconds >= 0`;
- `end_seconds > start_seconds`;
- `text` debe ser UTF-8 valido y no vacio;
- los segmentos se ordenan por tiempo antes de persistirse;
- `full_text` en `video_transcriptions` se genera concatenando los textos normalizados de estos segmentos.

### organization_notes

Notas simples de Organizacion.

Columnas principales:

- `id`;
- `user_id`;
- `space_id` nullable;
- `category_id` nullable;
- `title`;
- `content`;
- `status`;
- `completed_at` nullable;
- `created_at`;
- `updated_at`.

Estados iniciales:

- `active`;
- `completed`.

Las notas permanecen separadas de tareas y proyectos. No tienen prioridad, fechas ni vencimiento. `space_id NULL` representa "Sin espacio". Pueden tener 0..N etiquetas mediante `organization_note_labels`. Las notas activas generan una notificacion interna diaria deduplicada.

### organization_reminders

Recordatorios simples de Organizacion.

Columnas principales:

- `id`;
- `user_id`;
- `task_id` nullable;
- `project_id` nullable;
- `title`;
- `description` nullable;
- `remind_at`;
- `status`;
- `recurrence_type`;
- `recurrence_interval`;
- `recurrence_until` nullable;
- `next_remind_at` nullable;
- `created_at`;
- `updated_at`.

Estados iniciales:

- `pending`;
- `dismissed`;
- `completed`.

Reglas:

- un recordatorio puede pertenecer a una tarea;
- un recordatorio puede pertenecer a un proyecto;
- un recordatorio puede ser independiente;
- un recordatorio no puede pertenecer simultaneamente a tarea y proyecto;
- `task_id` y `project_id`, cuando existan, deben pertenecer al mismo `user_id` validado en la capa de servicio;
- `remind_at` es obligatorio.
- `recurrence_type` puede ser `none`, `daily`, `weekly`, `monthly` o `yearly`;
- `recurrence_interval` debe ser mayor o igual a 1;
- `next_remind_at` representa la proxima ocurrencia activa;
- no se crea una fila nueva por cada repeticion futura.

`remind_at` se conserva como fecha base de la definicion. Para recordatorios no recurrentes, `next_remind_at` equivale inicialmente a `remind_at`. Para recurrentes, completar o descartar una ocurrencia actual debe avanzar `next_remind_at` cuando exista una siguiente ocurrencia; la definicion recurrente no se marca permanentemente como completada solo por completar una ocurrencia.

`recurrence_until`, cuando existe, se almacena como fecha/hora UTC correspondiente al limite local configurado. La implementacion no genera `next_remind_at` posterior a ese limite.

El worker de recordatorios procesa solo la ocurrencia efectiva vencida (`COALESCE(next_remind_at, remind_at)`). Si estuvo detenido, crea como maximo una notificacion atrasada por recordatorio y avanza la recurrencia hasta la siguiente ocurrencia futura.

No se implementa envio automatico externo, Push, PWA ni centro completo de notificaciones en esta fase.

### notifications

Notificaciones internas generadas por procesos del sistema. La tabla se usa como centro general de actividad para recordatorios, Organizacion, Video y futuras fuentes; no existe un segundo sistema paralelo.

Columnas principales:

- `id`;
- `user_id`;
- `reminder_id` nullable;
- `type`;
- `source_module`;
- `entity_type` nullable;
- `entity_id` nullable;
- `dedupe_key` nullable;
- `title`;
- `message` nullable;
- `scheduled_at`;
- `read_at` nullable;
- `created_at`.

Reglas:

- las notificaciones pertenecen siempre a un `user_id`;
- `reminder_id` apunta al recordatorio que origino la notificacion cuando corresponde;
- `type` identifica la clase de notificacion, por ejemplo `reminder_due`, `daily_agenda`, `task_due_soon`, `task_due_today`, `task_starts_today`, `task_overdue`, `note_daily_reminder`, `project_summary`, `video_export_completed`, `video_export_failed`, `expense_due_soon`, `expense_due_today`, `expense_overdue`, `expense_missing_amount` o `expense_weekly_summary`;
- `source_module` agrupa visual y funcionalmente la fuente (`reminders`, `organization`, `video`, etc.);
- `entity_type` y `entity_id` permiten resolver acciones internas hacia tarea, proyecto o exportacion sin guardar URLs arbitrarias;
- `dedupe_key` evita que workers frecuentes repitan la misma actividad;
- `scheduled_at` guarda la ocurrencia programada que disparo la notificacion;
- `read_at` indica si ya fue leida.

La idempotencia de recordatorios vencidos se mantiene con la restriccion historica sobre `reminder_id`, `type` y `scheduled_at`. La actividad general usa ademas una restriccion unica por `(user_id, dedupe_key)`: una agenda diaria, deadline de tarea o evento terminal de video se crea una sola vez aunque Cron se ejecute cada minuto. Los workers obtienen `user_id` desde las entidades propietarias, nunca desde parametros enviados por navegador.

### organization_events

Eventos de Organizacion. Esta tabla se conserva sin uso por ahora para una posible necesidad futura.

Columnas principales:

- `id`;
- `user_id`;
- `space_id` nullable;
- `category_id` nullable;
- `title`;
- `description` nullable;
- `starts_at`;
- `ends_at` nullable;
- `all_day`;
- `location` nullable;
- `created_at`;
- `updated_at`.

La Fase 2 no implementa Eventos como entidad funcional. Las actividades con horario, duracion o vencimiento se representan mediante `organization_tasks.starts_at`, `organization_tasks.ends_at` y `organization_tasks.due_at`.

## Horarios

El modelo de Horarios registra horarios academicos ingresados manualmente. No almacena ubicacion real, GPS ni trazas de movimiento; las fases futuras estimaran presencia solo desde los bloques de horario.

### friends

Amigos del usuario autenticado. No son usuarios de la aplicacion ni tienen login propio.

Columnas principales:

- `id`;
- `user_id`;
- `name`;
- `university` nullable;
- `default_campus` nullable;
- `notes` nullable;
- `is_active`;
- `created_at`;
- `updated_at`.

Reglas:

- cada amigo pertenece a un unico `user_id`;
- eliminar el usuario elimina sus amigos;
- `is_active` permite ocultar o desactivar amigos sin borrar historico.

### friend_schedule_entries

Bloques recurrentes semanales de un amigo.

Columnas principales:

- `id`;
- `friend_id`;
- `weekday`;
- `starts_at`;
- `ends_at`;
- `course_name`;
- `course_code` nullable;
- `room` nullable;
- `campus` nullable;
- `valid_from` nullable;
- `valid_until` nullable;
- `created_at`;
- `updated_at`.

Reglas:

- `weekday` usa lunes = 1 y domingo = 7;
- `starts_at` y `ends_at` son `TIME`;
- `ends_at` debe ser posterior a `starts_at`;
- `campus` de la entrada puede reemplazar `friends.default_campus`;
- `valid_from` y `valid_until` limitan el bloque a un periodo academico;
- la base no prohibe solapamientos entre bloques.

### friend_schedule_exceptions

Excepciones puntuales de amigos para una fecha concreta. No modifican el bloque recurrente original.

Columnas principales:

- `id`;
- `friend_id`;
- `schedule_entry_id` nullable;
- `exception_date`;
- `type`;
- `starts_at` nullable;
- `ends_at` nullable;
- `course_name` nullable;
- `course_code` nullable;
- `room` nullable;
- `campus` nullable;
- `notes` nullable;
- `created_at`;
- `updated_at`.

Tipos iniciales:

- `cancelled`;
- `absent`;
- `modified`.

Si `schedule_entry_id` existe, debe apuntar a una entrada del mismo `friend_id`. `cancelled` representa clase cancelada, `absent` ausencia del amigo y `modified` un cambio puntual de hora, sala, ramo o campus.

### user_schedule_entries

Horario universitario propio del usuario. Se mantiene separado de `friends` para evitar crear al propietario como amigo ficticio.

Columnas principales:

- `id`;
- `user_id`;
- `weekday`;
- `starts_at`;
- `ends_at`;
- `course_name`;
- `course_code` nullable;
- `room` nullable;
- `campus` nullable;
- `valid_from` nullable;
- `valid_until` nullable;
- `created_at`;
- `updated_at`.

La estructura temporal es compatible con `friend_schedule_entries` y queda preparada para calcular coincidencias futuras entre mi horario y el horario de amigos.

### user_schedule_exceptions

Excepciones puntuales del horario propio. Se mantienen separadas de `friend_schedule_exceptions` para no representar al usuario como un amigo ficticio.

Columnas principales:

- `id`;
- `user_id`;
- `schedule_entry_id` nullable;
- `exception_date`;
- `type`;
- `starts_at` nullable;
- `ends_at` nullable;
- `course_name` nullable;
- `course_code` nullable;
- `room` nullable;
- `campus` nullable;
- `notes` nullable;
- `created_at`;
- `updated_at`.

Si `schedule_entry_id` existe, debe apuntar a una entrada de `user_schedule_entries` del mismo `user_id`. Los tipos son los mismos que en amigos: `cancelled`, `absent` y `modified`.

El horario efectivo de Horarios se obtiene aplicando:

```text
horario recurrente
-> vigencia
-> excepcion para una fecha
-> horario efectivo
```

`cancelled` se muestra como bloque cancelado y no cuenta como clase activa. `absent` indica que la persona no asistira y tampoco cuenta como presencia. `modified` reemplaza solo para esa fecha los campos indicados sin alterar el horario recurrente.

## Gastos

El modulo Gastos modela una planilla mensual personal sin crear datos globales compartidos. Un usuario nuevo parte con 0 categorias, 0 medios de pago, 0 servicios configurables y 0 gastos.

Flujo conceptual:

```text
Category
-> Expense Service
<-> Payment Methods
-> Recurring Rule
-> Recurring Adjustment
-> Monthly Expense
```

### expense_categories

Categorias personales para clasificar gastos.

Columnas principales:

- `id`;
- `user_id`;
- `name`;
- `normalized_name`;
- `color` nullable;
- `active`;
- `created_at`;
- `updated_at`.

Cada categoria pertenece a un unico usuario. `normalized_name` permite `UNIQUE (user_id, normalized_name)` para evitar duplicados evidentes por diferencias de mayusculas o espacios. No existen categorias globales. `color` queda disponible para distinguir visualmente categorias en fases futuras y debe ser `#RRGGBB` cuando existe.

### expense_payment_methods

Medios de pago conceptuales del usuario.

Columnas principales:

- `id`;
- `user_id`;
- `name`;
- `normalized_name`;
- `type`;
- `institution_name` nullable;
- `notes` nullable;
- `active`;
- `created_at`;
- `updated_at`.

`type` usa una allowlist relacional: `card`, `bank_account`, `automatic_payment`, `wallet`, `webpay`, `transfer`, `cash` y `other`.

Ejemplos validos:

- Visa Santander, `card`, Santander;
- PAT Santander, `automatic_payment`, Santander;
- Mercado Pago, `wallet`;
- WebPay, `webpay`;
- efectivo, `cash`.

La tabla no debe guardar numero completo de tarjeta, CVV, PIN, claves, numeros bancarios sensibles ni secretos. Solo identifica conceptualmente el medio de pago.

### expense_services

Servicios o gastos configurables que el usuario suele pagar.

Columnas principales:

- `id`;
- `user_id`;
- `category_id` nullable;
- `name`;
- `normalized_name`;
- `default_amount_clp` nullable;
- `notes` nullable;
- `active`;
- `created_at`;
- `updated_at`.

Ejemplos: Aguas Andinas, Gas, Spotify, YouTube, WOM, Entel, Fibra Internet, Vespucio Sur o Dividendo.

`ExpenseService` es una configuracion habitual, no el gasto mensual concreto. `default_amount_clp` es opcional y se usa solamente como monto sugerido para servicios relativamente estables. Agua o gas pueden quedar con `NULL`.

`category_id` usa `ON DELETE SET NULL` para no destruir el servicio si una categoria se elimina. La operacion normal esperada es desactivar categorias y servicios antes que borrar historial.

Un servicio desactivado puede conservar su regla recurrente, pero el worker no genera nuevos expenses mientras `expense_services.active = 0`.

### expense_service_payment_methods

Relacion many-to-many entre servicios configurables y medios de pago permitidos.

Columnas principales:

- `service_id`;
- `payment_method_id`;
- `is_default`;
- `created_at`.

La clave primaria compuesta `(service_id, payment_method_id)` evita duplicados. La relacion permite que Aguas Andinas se pague por PAT Santander, WebPay o Cuenta Santander, con uno de ellos marcado como default en la capa de dominio.

Esta tabla es auxiliar: al eliminar un servicio o medio de pago, sus relaciones se eliminan en cascada. No elimina gastos historicos.

### expense_recurring_rules

Reglas personales de recurrencia para generar gastos mensuales desde servicios configurables.

Columnas principales:

- `id`;
- `user_id`;
- `service_id`;
- `frequency`;
- `interval_value`;
- `day_of_month` nullable;
- `default_amount_clp` nullable;
- `default_category_id` nullable;
- `default_payment_method_id` nullable;
- `starts_on`;
- `ends_on` nullable;
- `next_generation_on` nullable;
- `active`;
- `created_at`;
- `updated_at`.

Cada regla pertenece a un usuario y debe apuntar a un `expense_services.id` del mismo usuario. La recurrencia no se permite para gastos ad-hoc sin servicio. En la version inicial `frequency` soporta `monthly`; `interval_value >= 1` deja lista la base para cada 1, 2 o mas meses.

`day_of_month` acepta 1..31. Al generar un expense mensual se calcula `due_on` con `ExpenseDateHelper::resolveDayOfMonth(year, month, requestedDay)`: si el dia solicitado no existe, se usa el ultimo dia valido del mes.

`starts_on` impide generar periodos anteriores al inicio. `ends_on`, si existe, impide generar periodos posteriores, pero no borra expenses ya creados. Para evitar backfill historico masivo, una regla creada desde la UI con inicio antiguo inicializa `next_generation_on` en el mes local actual; el worker hace catch-up desde ese puntero, no desde anos antiguos.

La relacion normal es una regla por servicio y usuario mediante `UNIQUE (user_id, service_id)`. Desactivar una regla usa `active = 0` y conserva historico. Eliminar una regla fisicamente no es la operacion normal; si ocurre, los expenses generados conservan el gasto y `recurring_rule_id` queda `NULL` por FK.

Defaults de generacion:

- monto: regla, luego servicio, luego `NULL`;
- categoria: regla, luego servicio, luego `NULL`;
- medio de pago: regla si esta activo, luego default activo del servicio, luego `NULL`.

Las categorias inactivas pueden seguir usandose como clasificacion si estan asociadas a la regla o al servicio. Los medios de pago inactivos no se usan para nuevas generaciones.

### expense_recurring_adjustments

Ajustes personales por mes para una regla recurrente.

Columnas principales:

- `id`;
- `user_id`;
- `recurring_rule_id`;
- `period_month`;
- `action`;
- `description` nullable;
- `amount_override`;
- `amount_clp` nullable;
- `due_on` nullable;
- `category_id` nullable;
- `payment_method_id` nullable;
- `notes` nullable;
- `active`;
- `created_at`;
- `updated_at`.

Cada ajuste pertenece a una regla recurrente del mismo usuario y a un unico periodo mensual (`period_month` siempre es el primer dia del mes). La restriccion `UNIQUE (user_id, recurring_rule_id, period_month)` evita dos instrucciones distintas para la misma regla y mes.

`action = skip` indica que el worker no debe generar el gasto de ese periodo. Sirve para congelar, suspender, cancelar temporalmente o dejar de pagar un servicio durante un mes puntual sin desactivar toda la recurrencia ni borrar historial.

`action = generate` mantiene la generacion, pero permite sobrescribir solo ese mes: descripcion, monto, fecha limite, categoria, medio de pago y notas. `amount_override = 1` distingue entre "usar monto normal" y "este mes el monto fue informado como `NULL` o `0`"; por eso `amount_clp = 0` es valido para ofertas, descuentos completos o meses sin cobro. `due_on` puede caer en otro mes para representar pagos aplazados.

Un ajuste inactivo queda registrado pero el worker lo ignora. Los ajustes solo afectan gastos que todavia no fueron generados; no reescriben expenses historicos ya creados.

### expenses

Gastos mensuales concretos.

Columnas principales:

- `id`;
- `user_id`;
- `service_id` nullable;
- `recurring_rule_id` nullable;
- `category_id` nullable;
- `period_month`;
- `description`;
- `amount_clp`;
- `installment_current` nullable;
- `installment_total` nullable;
- `due_on` nullable;
- `paid_on` nullable;
- `payment_method_id` nullable;
- `status`;
- `notes` nullable;
- `created_at`;
- `updated_at`.

`Expense` es el gasto real de un mes. Puede apuntar a un servicio configurable o ser ad-hoc con `service_id NULL`. `description` conserva un nombre visible historico aun cuando exista `service_id`, por ejemplo Spotify o Dividendo.

`period_month` es un `DATE` comercial/local y siempre usa el primer dia del mes, por ejemplo `2026-08-01` para agosto 2026. No es timestamp UTC.

`recurring_rule_id` identifica el origen automatico cuando un expense fue generado por una regla. Los gastos creados manualmente usan `NULL`.

`amount_clp` se guarda como entero nullable en pesos chilenos, por ejemplo `19994` para `$19.994`. No se usa `float` ni se modelan otras monedas en esta fase. `NULL` significa monto pendiente/desconocido, no `$0`.

Las cuotas se representan con `installment_current` e `installment_total`. Ambos son `NULL` si no hay cuotas. Si existen, ambos deben estar informados y cumplir `current >= 1`, `total >= 1` y `current <= total`. No se guarda `3/12` como texto.

`due_on` es la fecha limite local/comercial y `paid_on` es la fecha real local/comercial de pago. No se convierten a UTC.

`status` persiste solo estados base: `pending`, `paid` y `cancelled`. `overdue` no se guarda; se deriva con `status = pending` y `due_on < hoy` usando `America/Santiago`.

`category_id` se guarda en cada gasto como clasificacion historica. Al crear un gasto desde un servicio, si no se envia categoria explicita, el servicio de dominio usa la categoria actual del servicio como snapshot. Cambios posteriores en la categoria del servicio no deben reescribir automaticamente gastos historicos.

`payment_method_id` representa el medio realmente usado para ese mes. Aunque un servicio permita varios medios, cada gasto mensual registra a lo mas uno. Los pagos divididos entre varios medios no estan implementados.

La restriccion unica `UNIQUE (recurring_rule_id, period_month)` evita que una misma regla genere dos expenses para el mismo periodo. MariaDB permite multiples `NULL` en indices unicos, por lo que los gastos manuales con `recurring_rule_id NULL` no quedan limitados por esta regla.

Las FK opcionales a regla recurrente, categoria, servicio y medio de pago usan `ON DELETE SET NULL` para preservar el gasto financiero historico. La eliminacion del usuario si elimina sus datos personales por cascada.

## Relaciones de Organizacion

Esquema textual:

```text
users
-> organization_spaces
-> organization_categories
-> organization_projects
-> organization_tasks
-> organization_notes
-> organization_labels
-> organization_reminders
-> notifications
-> organization_events

organization_spaces
-> organization_categories
-> organization_projects
-> organization_tasks
-> organization_notes
-> organization_events

organization_projects
-> organization_tasks
-> organization_project_labels
-> organization_reminders

organization_tasks
-> organization_tasks parent_task_id
-> organization_task_labels
-> organization_reminders

organization_notes
-> organization_note_labels

organization_labels
-> organization_task_labels
-> organization_project_labels
-> organization_note_labels

organization_reminders
-> notifications
```

Las tablas de Organizacion tienen `user_id` y FK hacia `users`.

Las relaciones opcionales (`space_id`, `category_id`, `project_id`, `parent_task_id`) usan `SET NULL` cuando corresponde para preservar registros. `organization_projects.space_id` es obligatorio y restringe la eliminacion del espacio si existen proyectos asociados. Las relaciones de etiquetas se eliminan en cascada al borrar la entidad o la etiqueta.

Los servicios deben validar que `space_id`, `category_id`, `project_id`, `parent_task_id` y `label_id` pertenezcan al mismo `user_id` del registro creado o actualizado. Esta regla evita asociar datos de un usuario con registros de otro usuario. La validacion completa corresponde a la capa de servicios del modulo.

## Relaciones de Horarios

Esquema textual:

```text
users
-> friends
-> user_schedule_entries
-> user_schedule_exceptions

friends
-> friend_schedule_entries
-> friend_schedule_exceptions

friend_schedule_entries
-> friend_schedule_exceptions

user_schedule_entries
-> user_schedule_exceptions
```

`friend_schedule_entries` y `friend_schedule_exceptions` no tienen `user_id` directo. El ownership se valida siempre mediante `friends.user_id`; una consulta de horarios o excepciones debe unir con `friends` y limitar por el usuario autenticado. `user_schedule_entries` y `user_schedule_exceptions` pertenecen directamente a `users`.

## Relaciones de Gastos

Esquema textual:

```text
users
-> expense_categories
-> expense_payment_methods
-> expense_services
-> expenses

expense_categories
-> expense_services
-> expenses

expense_services
-> expense_service_payment_methods
-> expenses

expense_payment_methods
-> expense_service_payment_methods
-> expenses
```

Todas las entidades principales tienen `user_id`. Las relaciones opcionales de historial financiero (`expenses.service_id`, `expenses.category_id`, `expenses.payment_method_id`) usan `SET NULL` para no destruir gastos ya registrados si se borra un catalogo. La relacion auxiliar `expense_service_payment_methods` usa cascada porque no es historial financiero, sino configuracion actual de medios permitidos.

Los servicios del modulo validan que `category_id`, `service_id` y `payment_method_id` pertenezcan al mismo `user_id` del usuario autenticado antes de crear o actualizar registros. Un usuario no puede listar, leer ni asociar datos de otro usuario.

## Politica temporal

Los campos de negocio con fecha y hora, como `organization_tasks.starts_at`, `organization_tasks.ends_at`, `organization_tasks.due_at`, `organization_tasks.completed_at`, `organization_notes.completed_at`, `organization_reminders.remind_at`, `organization_reminders.next_remind_at`, `organization_reminders.recurrence_until`, `notifications.scheduled_at`, `notifications.read_at`, `organization_events.starts_at` y `organization_events.ends_at`, se almacenan en UTC por convencion de aplicacion y se presentan al usuario usando la zona horaria configurada, actualmente `America/Santiago`.

Los formularios reciben fechas y horas en `America/Santiago`. La aplicacion convierte esos valores a UTC antes de persistirlos y convierte desde UTC a `America/Santiago` antes de renderizar vistas, respuestas JSON o valores para `datetime-local`. Esta logica debe pasar por `App\Support\DateTimeHelper` para evitar conversiones dobles entre Organizacion, Dashboard, Calendario y worker.

Los campos `DATE` puros, como `organization_projects.starts_on` y `organization_projects.due_on`, no se convierten de zona horaria.

Los campos de Gastos `expenses.period_month`, `expenses.due_on` y `expenses.paid_on` tambien son `DATE` comerciales/locales y no se convierten a UTC. `period_month` siempre usa el primer dia del mes. El estado derivado `overdue` se calcula con `America/Santiago`.

Los horarios semanales de Horarios son una excepcion intencional al flujo UTC porque representan horas academicas locales recurrentes. `friend_schedule_entries.starts_at`, `friend_schedule_entries.ends_at`, `friend_schedule_exceptions.starts_at`, `friend_schedule_exceptions.ends_at`, `user_schedule_entries.starts_at`, `user_schedule_entries.ends_at`, `user_schedule_exceptions.starts_at` y `user_schedule_exceptions.ends_at` se almacenan como `TIME` local en `America/Santiago`, sin conversion a UTC. `valid_from`, `valid_until` y `exception_date` son `DATE` puros y tampoco se convierten.

Significado temporal de tareas:

- `starts_at`: inicio opcional del periodo de la tarea;
- `ends_at`: fin opcional del periodo de la tarea;
- `due_at`: fecha limite opcional.

Significado temporal de recordatorios:

- `remind_at`: fecha y hora obligatoria en que el usuario quiere ser recordado.
- `next_remind_at`: proxima ocurrencia activa que usa la interfaz y el Dashboard.
- `recurrence_until`: limite opcional para generar nuevas ocurrencias.

La politica para recurrencias mensuales y anuales usa el ultimo dia valido del mes cuando el dia original no existe. Por ejemplo, 31 de enero avanza al ultimo dia de febrero, y 29 de febrero avanza al 28 de febrero en anos no bisiestos.

`ends_at` no debe ser anterior a `starts_at`. La capa de servicio valida esta regla.

El Calendario utiliza:

- tareas: `starts_at`, `ends_at`, `due_at`;
- proyectos: `starts_on`, `due_on`.

El Calendario es una vista derivada de esos datos existentes. No crea registros especiales, no duplica fechas y no usa `organization_events` en la implementacion actual.

La urgencia visual de Organizacion se calcula dinamicamente con `DeadlineUrgencyService` al cargar tareas y proyectos. Usa `organization_tasks.due_at` para tareas y `organization_projects.due_on` para proyectos. No existe una columna `urgency`, porque el resultado depende del tiempo actual en `America/Santiago`. Las tareas completadas se muestran como completadas y no como urgentes.

Los campos `created_at` y `updated_at` son timestamps tecnicos administrados por MariaDB para trazabilidad.

## Indices de Organizacion

Indices principales:

- `user_id` para filtrar datos del usuario autenticado;
- `space_id` para filtrar por espacio;
- `project_id` en tareas;
- `status` en proyectos y tareas;
- `starts_at` y `ends_at` en tareas;
- `due_at` en tareas;
- `remind_at` en recordatorios;
- `next_remind_at` en recordatorios;
- `scheduled_at` en notificaciones;
- `starts_at` en eventos.
- `friends.user_id` para filtrar amigos propios;
- `(friend_id, weekday)` en horarios de amigos;
- `(friend_id, exception_date)` en excepciones;
- `(user_id, weekday)` en horario propio.
- `video_files.user_id` para listar videos propios;
- `(video_files.user_id, video_files.status)` para filtros futuros por estado;
- `video_files.metadata_status` para reservar trabajo pendiente de FFprobe;
- `video_files.stored_name` unico para evitar colisiones fisicas.
- `(video_cut_points.video_id, video_cut_points.position_seconds)` para listar cortes ordenados por video.
- `(video_edit_segments.video_id, video_edit_segments.source_start_seconds, video_edit_segments.source_end_seconds)` para sincronizar segmentos por rango fuente.
- `(video_edit_segments.video_id, video_edit_segments.is_included, video_edit_segments.sort_order)` para listar la secuencia incluida.
- `(video_export_jobs.user_id, video_export_jobs.video_id, video_export_jobs.created_at)` para listar exportaciones propias por video.
- `(video_export_jobs.status, video_export_jobs.created_at)` para reclamar jobs pendientes.
- `(video_export_jobs.status, video_export_jobs.expires_at)` para limpiar exports vencidos.
- `(video_export_segments.export_job_id, video_export_segments.sort_order)` para cargar el snapshot en orden de salida.
- Indices historicos de `video_transcriptions` y `video_transcription_segments` se conservan mientras existan las tablas heredadas.
- `(notifications.user_id, notifications.source_module, notifications.entity_type, notifications.entity_id)` para ubicar actividad relacionada por usuario y entidad.
- `(notifications.user_id, notifications.dedupe_key)` unico para evitar duplicados de actividad generada por workers.
- `(discount_benefit_programs.normalized_provider_name, normalized_name, benefit_type, normalized_product_name)` unico para evitar programas duplicados evidentes.
- `(user_discount_benefits.user_id, user_discount_benefits.benefit_program_id)` unico para evitar beneficios duplicados por usuario.
- `discount_merchants.normalized_name` unico para evitar comercios duplicados evidentes.
- `(discount_promotions.is_active, discount_promotions.starts_on, discount_promotions.ends_on)` para futuras consultas de vigencia.
- `discount_promotions.category` para filtrar descuentos por tipo de producto o rubro.
- `(discount_promotions.source_type, discount_promotions.source_key)` para compatibilidad de consultas sobre promociones importadas.
- `(discount_promotions.created_by_user_id, source_type, created_at)` para listar y proteger promociones manuales por usuario.
- `(discount_promotions.source_type, discount_promotions.collector_key, discount_promotions.source_key)` unico para impedir duplicados de la misma promocion externa dentro de un collector.
- `(discount_promotions.source_type, discount_promotions.collector_key, discount_promotions.dedupe_fingerprint)` para deduplicacion secundaria por contenido dentro de la misma fuente.
- `discount_promotions.dedupe_fingerprint` para detectar posibles duplicados manuales o cross-source sin fusionarlos automaticamente.
- `discount_collector_schedules.collector_key` unico para un solo estado actual por collector.
- `(discount_collector_schedules.enabled, discount_collector_schedules.next_run_at, discount_collector_schedules.last_status)` para buscar collectors pendientes.
- `(discount_collector_runs.collector_key, discount_collector_runs.started_at)` para listar historial por collector.
- `(discount_collector_runs.status, discount_collector_runs.started_at)` para ubicar ejecuciones running/fallidas recientes.
- `(discount_promotion_benefits.promotion_id, discount_promotion_benefits.benefit_program_id)` unico para relaciones OR sin duplicados.
- `(discount_promotion_days.promotion_id, discount_promotion_days.weekday)` unico para dias de promocion sin duplicados.
- `(user_discount_favorites.user_id, user_discount_favorites.promotion_id)` unico para favoritos por usuario.
- `(expense_categories.user_id, expense_categories.normalized_name)` unico para evitar categorias duplicadas por usuario.
- `(expense_payment_methods.user_id, expense_payment_methods.normalized_name)` unico para evitar medios de pago duplicados por usuario.
- `(expense_services.user_id, expense_services.normalized_name)` unico para evitar servicios configurables duplicados por usuario.
- `(expense_service_payment_methods.service_id, expense_service_payment_methods.payment_method_id)` unico para evitar relaciones duplicadas.
- `(expenses.user_id, expenses.period_month)` para listar gastos de un mes.
- `(expenses.user_id, expenses.due_on)` para consultas generales por vencimiento.
- `(expenses.user_id, expenses.status, expenses.due_on)` para que el worker de notificaciones de gastos encuentre vencimientos pendientes sin escanear todo el historial.
- `(expenses.user_id, expenses.status)` para filtros por estado base.
- `expenses.service_id` para consultar gastos por servicio configurable.
- `expenses.recurring_rule_id` y `UNIQUE (expenses.recurring_rule_id, expenses.period_month)` para generacion recurrente idempotente.
- `expenses.category_id` para consultar gastos por categoria historica.
- `expenses.payment_method_id` para consultar gastos por medio usado.
- `(expense_recurring_rules.active, expense_recurring_rules.next_generation_on)` para que el worker encuentre reglas que debe considerar.

## Timestamps

Las tablas persistentes deben considerar timestamps cuando aporten trazabilidad.

Convenciones esperadas:

- Fecha de creacion.
- Fecha de actualizacion cuando corresponda.
- Fecha de eliminacion logica solo si una funcionalidad lo justifica.

## No duplicar informacion

El diseno debe evitar duplicar informacion entre modulos.

Si varios modulos necesitan usar el mismo dato, se debe evaluar una relacion compartida o una abstraccion comun antes de copiar datos.

## Alcance actual

Este documento no define todavia todas las tablas definitivas.

El modelo de datos se disenara por fases, segun las funcionalidades solicitadas.

Existen tablas del modelo de Organizacion, CRUD de tareas/proyectos/notas, calendario funcional sobre tareas/proyectos, gestion de recordatorios con recurrencia basica y worker Cron para generar notificaciones internas pendientes. Eventos se conserva sin uso por ahora. El modulo Video cuenta con `video_files` para registrar subidas seguras en storage privado, metadata tecnica mediante FFprobe ejecutado por worker, streaming autenticado con soporte HTTP Range, `video_cut_points` para persistir puntos de corte, `video_edit_segments` para gestionar segmentos virtuales incluidos/excluidos y reordenados, `video_export_jobs` / `video_export_segments` para exportar MP4 mediante worker FFmpeg con progreso, descarga autenticada y retencion/cleanup. `video_transcriptions` / `video_transcription_segments` son tablas historicas conservadas sin funcionalidad activa desde 11.2. El modulo Descuentos ya tiene modelo relacional backend, seleccion personal de tarjetas/beneficios en Configuracion, compatibilidad, vistas de descubrimiento `Para mi`/`Favoritos`/`Todos` e infraestructura de collectors con pipeline de normalizacion/deduplicacion/persistencia, scheduler persistido en BD, historial estructurado de ejecuciones y fuentes reales `banco_chile`, `santander_chile` y `bancoestado`. El modulo Gastos tiene modelo relacional backend para categorias personales, medios de pago conceptuales, servicios configurables, medios permitidos por servicio y gastos mensuales con totales derivados por mes; aun no tiene interfaz completa, recurrencia automatica ni notificaciones propias. Aun no existen thumbnails derivados de video ni transcripcion activa.
