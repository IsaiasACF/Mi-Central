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

Tabla minima para autenticacion local de Mi Central.

Columnas principales:

- `id`;
- `username`;
- `password_hash`;
- `is_active`;
- `last_login_at`;
- `created_at`;
- `updated_at`.

`username` es unico. `password_hash` almacena hashes seguros generados por PHP, no contrasenas reversibles.

### login_attempts

Tabla para limitar intentos repetidos de login.

Columnas principales:

- `id`;
- `username`;
- `ip_address`;
- `attempted_at`.

La politica inicial limita 5 intentos fallidos durante 15 minutos por username e IP.

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
- `priority`;
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

Prioridades iniciales:

- `low`;
- `normal`;
- `high`.

Reglas:

- `space_id NULL` indica que la tarea pertenece a la Bandeja rapida;
- `project_id` relaciona una tarea con un proyecto;
- `parent_task_id` permite subtareas;
- `starts_at` y `ends_at` describen el periodo opcional en que ocurre o se realiza una tarea;
- `due_at` representa la fecha limite opcional;
- no existe una tabla redundante `project_tasks`.

El progreso de un proyecto se calcula a partir de sus tareas asociadas (`completed / total`) y no se guarda como columna persistente. Las subtareas no completan automaticamente a su tarea padre; esa decision queda en manos del usuario.

Las tareas de proyecto heredan el `space_id` del proyecto. Si el espacio de un proyecto cambia, las tareas asociadas se sincronizan al nuevo espacio para mantener coherencia.

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

Jobs e historial de transcripcion local para videos.

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

`user_id` pertenece al usuario autenticado y nunca se recibe desde el navegador. La transcripcion tiene una fuente explicita: original (`source_type = video`, `video_id` poblado) o exportacion (`source_type = export`, `export_job_id` poblado al crear el job). `source_video_id` referencia el video original propietario para listar el historial completo bajo el mismo video, incluyendo transcripciones hechas desde MP4 exportados. `source_display_name` conserva el nombre visible de la fuente usada.

`video_id` referencia `video_files.id` para fuentes originales. `export_job_id` referencia `video_export_jobs.id` con `ON DELETE SET NULL`, para que una transcripcion completada de una exportacion pueda conservarse si luego se elimina el MP4 exportado/job asociado. La regla "exactamente una fuente al crear/reprocesar" se aplica en repositorio/servicio junto con las validaciones de ownership y estado de la exportacion, porque MariaDB no permite expresar con CHECK la variante nullable necesaria para conservar historial de exportaciones eliminadas.

Las lecturas, creaciones y eliminaciones HTTP validan ownership mediante `video_transcriptions.user_id` y, al iniciar trabajos nuevos, mediante `TranscriptionSourceResolver`: originales deben resolverse dentro de `storage/video/uploads`; exportaciones deben pertenecer al usuario, estar `completed`, existir fisicamente y resolverse dentro de `storage/video/exports`.

Estados:

- `pending`: job creado y aun no tomado por worker.
- `processing`: job reclamado por `process-video-transcriptions.php`.
- `completed`: Whisper genero segmentos validos y se guardo `full_text`.
- `failed`: FFmpeg, Whisper o la validacion fallaron.

`requested_language` esta limitado a:

- `auto`;
- `es`;
- `en`.

El navegador no puede enviar rutas, nombres fisicos de modelo, binarios ni argumentos CLI. `model` registra el modelo configurado usado al crear/procesar el job, por ejemplo `base`. `detected_language` se guarda solo cuando el motor lo entrega de forma estructurada y confiable; si no existe, queda `NULL`.

`progress_percent` parte en `0`, pasa a `1` al reclamar el job y llega a `100` solo en `completed`. Si un job falla, conserva el ultimo porcentaje real conocido y no se fuerza a 100.

Solo se permite un job `pending` o `processing` por fuente al mismo tiempo. Las transcripciones `completed` anteriores se conservan; volver a transcribir crea una nueva fila, no sobrescribe silenciosamente una anterior.

### video_transcription_segments

Segmentos temporales normalizados producidos por `whisper.cpp`.

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
- `created_at`;
- `updated_at`.

Las notas permanecen separadas de tareas y proyectos. No tienen estado, prioridad, fechas, vencimiento ni completado. `space_id NULL` representa "Sin espacio".

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

Notificaciones internas generadas por procesos del sistema.

Columnas principales:

- `id`;
- `user_id`;
- `reminder_id` nullable;
- `type`;
- `title`;
- `message` nullable;
- `scheduled_at`;
- `read_at` nullable;
- `created_at`.

Reglas:

- las notificaciones pertenecen siempre a un `user_id`;
- `reminder_id` apunta al recordatorio que origino la notificacion cuando corresponde;
- `type` identifica la clase de notificacion, actualmente `reminder_due`;
- `scheduled_at` guarda la ocurrencia programada que disparo la notificacion;
- `read_at` queda reservado para la futura interfaz de notificaciones.

La idempotencia de recordatorios vencidos se garantiza con una restriccion unica sobre `reminder_id`, `type` y `scheduled_at`. Esto impide que la misma ocurrencia de un mismo recordatorio cree dos notificaciones aunque el worker se ejecute varias veces o haya dos pasadas concurrentes.

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

## Amigos en la U

El modelo de Amigos en la U registra horarios academicos ingresados manualmente. No almacena ubicacion real, GPS ni trazas de movimiento; las fases futuras estimaran presencia solo desde los bloques de horario.

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

El horario efectivo de Amigos en la U se obtiene aplicando:

```text
horario recurrente
-> vigencia
-> excepcion para una fecha
-> horario efectivo
```

`cancelled` se muestra como bloque cancelado y no cuenta como clase activa. `absent` indica que la persona no asistira y tampoco cuenta como presencia. `modified` reemplaza solo para esa fecha los campos indicados sin alterar el horario recurrente.

## Relaciones de Organizacion

Esquema textual:

```text
users
-> organization_spaces
-> organization_categories
-> organization_projects
-> organization_tasks
-> organization_notes
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
-> organization_reminders

organization_tasks
-> organization_tasks parent_task_id
-> organization_reminders

organization_reminders
-> notifications
```

Las tablas de Organizacion tienen `user_id` y FK hacia `users`.

Las relaciones opcionales (`space_id`, `category_id`, `project_id`, `parent_task_id`) usan `SET NULL` cuando corresponde para preservar registros. `organization_projects.space_id` es obligatorio y restringe la eliminacion del espacio si existen proyectos asociados.

Los servicios futuros deben validar que `space_id`, `category_id`, `project_id` y `parent_task_id` pertenezcan al mismo `user_id` del registro creado o actualizado. Esta regla evita asociar datos de un usuario con registros de otro usuario. La validacion completa corresponde a la capa de servicios del modulo.

## Relaciones de Amigos en la U

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

## Politica temporal

Los campos de negocio con fecha y hora, como `organization_tasks.starts_at`, `organization_tasks.ends_at`, `organization_tasks.due_at`, `organization_tasks.completed_at`, `organization_reminders.remind_at`, `organization_reminders.next_remind_at`, `organization_reminders.recurrence_until`, `notifications.scheduled_at`, `notifications.read_at`, `organization_events.starts_at` y `organization_events.ends_at`, se almacenan en UTC por convencion de aplicacion y se presentan al usuario usando la zona horaria configurada, actualmente `America/Santiago`.

Los formularios reciben fechas y horas en `America/Santiago`. La aplicacion convierte esos valores a UTC antes de persistirlos y convierte desde UTC a `America/Santiago` antes de renderizar vistas, respuestas JSON o valores para `datetime-local`. Esta logica debe pasar por `App\Support\DateTimeHelper` para evitar conversiones dobles entre Organizacion, Dashboard, Calendario y worker.

Los campos `DATE` puros, como `organization_projects.starts_on` y `organization_projects.due_on`, no se convierten de zona horaria.

Los horarios semanales de Amigos en la U son una excepcion intencional al flujo UTC porque representan horas academicas locales recurrentes. `friend_schedule_entries.starts_at`, `friend_schedule_entries.ends_at`, `friend_schedule_exceptions.starts_at`, `friend_schedule_exceptions.ends_at`, `user_schedule_entries.starts_at`, `user_schedule_entries.ends_at`, `user_schedule_exceptions.starts_at` y `user_schedule_exceptions.ends_at` se almacenan como `TIME` local en `America/Santiago`, sin conversion a UTC. `valid_from`, `valid_until` y `exception_date` son `DATE` puros y tampoco se convierten.

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
- `(video_transcriptions.user_id, video_transcriptions.video_id, video_transcriptions.created_at)` para compatibilidad con historial de transcripciones del original.
- `(video_transcriptions.user_id, video_transcriptions.export_job_id, video_transcriptions.created_at)` para ubicar trabajos asociados a una exportacion.
- `(video_transcriptions.user_id, video_transcriptions.source_video_id, video_transcriptions.created_at)` para listar el historial completo bajo el video original, incluyendo fuentes exportadas.
- `(video_transcriptions.status, video_transcriptions.created_at)` para reclamar jobs pendientes y detectar jobs `processing`.
- `(video_transcription_segments.transcription_id, video_transcription_segments.segment_index)` unico para conservar orden estable.
- `(video_transcription_segments.transcription_id, video_transcription_segments.start_seconds)` para cargar segmentos por tiempo.

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

Existen tablas del modelo de Organizacion, CRUD de tareas/proyectos/notas, calendario funcional sobre tareas/proyectos, gestion de recordatorios con recurrencia basica y worker Cron para generar notificaciones internas pendientes. Eventos se conserva sin uso por ahora. El modulo Video cuenta con `video_files` para registrar subidas seguras en storage privado, metadata tecnica mediante FFprobe ejecutado por worker, streaming autenticado con soporte HTTP Range, `video_cut_points` para persistir puntos de corte, `video_edit_segments` para gestionar segmentos virtuales incluidos/excluidos y reordenados, `video_export_jobs` / `video_export_segments` para exportar MP4 mediante worker FFmpeg con progreso, descarga autenticada y retencion/cleanup, y `video_transcriptions` / `video_transcription_segments` para ejecutar transcripcion local con whisper.cpp y guardar segmentos con timestamps. Aun no existen thumbnails derivados ni visor sincronizado de transcripciones.
