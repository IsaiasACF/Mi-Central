# Arquitectura

## Enfoque general

Mi Central sera una **aplicacion monolitica modular**.

Esto significa que todos los modulos viviran dentro de una misma aplicacion, compartiran una base de datos principal y se ejecutaran como una sola unidad desplegable mediante Docker Compose.

No se usaran microservicios inicialmente.

## Flujo web principal

```text
Navegador
-> Apache
-> PHP
-> MariaDB
```

## Procesos auxiliares

```text
PHP CLI / Cron
-> workers
-> FFmpeg / recolectores / recordatorios
```

## Modulos

Los modulos deben mantenerse independientes dentro de la misma aplicacion.

Cada modulo debe agrupar su propia logica, vistas, validaciones y operaciones relacionadas, evitando acoplarse innecesariamente con otros modulos.

Cuando exista funcionalidad compartida, debe ubicarse en componentes comunes claramente justificados y documentados.

## Base de datos

La aplicacion usara una base de datos principal en MariaDB 11.8.

Los modulos podran relacionarse mediante claves foraneas cuando corresponda, manteniendo integridad referencial y evitando duplicacion innecesaria de informacion.

## API interna

La aplicacion podra exponer una API interna en PHP que responda JSON para interacciones dinamicas del frontend o acciones asincronas.

Esta API interna no implica separar el backend en otro servicio.

## Frontend

El frontend inicial usara:

- HTML5.
- CSS3.
- JavaScript nativo.

No se usaran React ni Vue inicialmente.

## Layout autenticado

La interfaz autenticada usa componentes PHP nativos reutilizables en `app/Views/layout/`:

- `header.php`;
- `sidebar.php`;
- `footer.php`.

Las pantallas base viven en `app/Views/pages/`. `public/index.php` actua como entrada autenticada y mantiene una unica estructura principal de contenido.

Los componentes visuales reutilizables viven en `app/Views/components/`. Su responsabilidad es concentrar markup comun de la interfaz, escapar texto dinamico por defecto y evitar duplicacion en vistas como el Dashboard.

El Dashboard obtiene sus datos desde `modules/Dashboard/DashboardSummaryService.php`. El flujo previsto es:

```text
modulos futuros / datos
-> DashboardSummaryService
-> vista Dashboard
-> widgets reutilizables
```

Mientras los modulos no existan, el servicio devuelve colecciones vacias, conteos coherentes y mensajes de estado vacio. La excepcion actual es Bandeja rapida, que obtiene tareas pendientes sin espacio (`organization_tasks.space_id IS NULL`) para mostrar cantidad y algunos elementos recientes. Hoy y Proximamente integran tareas con fecha, vencimientos y recordatorios pendientes; para recordatorios recurrentes solo consideran `next_remind_at`, no todas las repeticiones futuras. Los recordatorios se rotulan explicitamente como `Recordatorio:` para no confundirlos con el vencimiento propio de una tarea. Horarios aporta un resumen de estado actual y un widget pequeno de proxima coincidencia academica usando `CoincidenceService`, sin duplicar la logica de horarios efectivos.

## Backend de tareas de Organizacion

El backend interno de Organizacion mantiene el flujo:

```text
HTTP JSON autenticado
-> servicio del modulo
-> repositorio del modulo
-> PDO / MariaDB
```

Los endpoints internos principales viven en:

```text
/api/organization/tasks.php
/api/organization/projects.php
/api/organization/notes.php
/api/organization/labels.php
/api/organization/reminders.php
```

Operaciones de tareas disponibles:

- `GET /api/organization/tasks.php` lista tareas del usuario autenticado con filtros basicos.
- `GET /api/organization/tasks.php?id=ID` obtiene una tarea propia.
- `POST /api/organization/tasks.php` crea una tarea.
- `PATCH /api/organization/tasks.php?id=ID` o `PUT ...` actualiza campos editables.
- `POST /api/organization/tasks.php?id=ID&action=complete` completa una tarea.
- `POST /api/organization/tasks.php?id=ID&action=reopen` reabre una tarea.
- `DELETE /api/organization/tasks.php?id=ID` elimina una tarea propia.

Operaciones de proyectos disponibles:

- `GET /api/organization/projects.php` lista proyectos propios con progreso calculado.
- `GET /api/organization/projects.php?id=ID` obtiene un proyecto propio.
- `POST /api/organization/projects.php` crea un proyecto.
- `PATCH /api/organization/projects.php?id=ID` o `PUT ...` actualiza campos editables.
- `POST /api/organization/projects.php?id=ID&action=complete` marca un proyecto como completado.
- `POST /api/organization/projects.php?id=ID&action=archive` archiva un proyecto.
- `DELETE /api/organization/projects.php?id=ID` elimina un proyecto propio.

Operaciones de notas disponibles:

- `GET /api/organization/notes.php` lista notas propias con filtro simple de espacio.
- `GET /api/organization/notes.php?id=ID` obtiene una nota propia.
- `POST /api/organization/notes.php` crea una nota.
- `PATCH /api/organization/notes.php?id=ID` o `PUT ...` actualiza titulo, contenido y espacio.
- `DELETE /api/organization/notes.php?id=ID` elimina una nota propia.

Operaciones de etiquetas disponibles:

- `GET /api/organization/labels.php` lista etiquetas propias.
- `GET /api/organization/labels.php?id=ID` obtiene una etiqueta propia.
- `POST /api/organization/labels.php` crea una etiqueta.
- `PATCH /api/organization/labels.php?id=ID` o `PUT ...` actualiza nombre y color.
- `DELETE /api/organization/labels.php?id=ID` elimina una etiqueta propia y sus relaciones.

Operaciones de recordatorios disponibles:

- `GET /api/organization/reminders.php` lista recordatorios propios con filtros basicos.
- `GET /api/organization/reminders.php?id=ID` obtiene un recordatorio propio.
- `POST /api/organization/reminders.php` crea un recordatorio.
- `PATCH /api/organization/reminders.php?id=ID` o `PUT ...` actualiza campos editables.
- `POST /api/organization/reminders.php?id=ID&action=complete` marca un recordatorio como completado.
- `POST /api/organization/reminders.php?id=ID&action=dismiss` descarta un recordatorio.
- `POST /api/organization/reminders.php?id=ID&action=stop` detiene una recurrencia.
- `DELETE /api/organization/reminders.php?id=ID` elimina un recordatorio propio.

Las escrituras requieren sesion autenticada y token CSRF. El navegador no envia ni controla `user_id`; siempre se toma desde la sesion. `TaskService` valida entrada, estados, fechas y ownership de `space_id`, `category_id`, `project_id`, `parent_task_id` y etiquetas. `TaskRepository` ejecuta consultas preparadas y limita todas las operaciones por `user_id`. `priority` queda como columna legacy/deprecated del esquema de tareas, sin control visible ni logica de ordenamiento.

`ProjectService` valida que cada proyecto pertenezca a un espacio propio del usuario. El progreso de proyectos no se almacena en base de datos: `ProjectRepository` lo calcula con las tareas asociadas como `tasks_completed`, `tasks_total` y `progress_percent`. Al eliminar un proyecto, las tareas se conservan y `project_id` queda `NULL` segun la FK existente.

`LabelService` gestiona etiquetas propias del usuario con nombre y color HEX `#RRGGBB`. Las relaciones con tareas, proyectos y notas son many-to-many y se sincronizan desde los servicios correspondientes despues de validar ownership de ambos lados. El navegador puede enviar `label_ids`, pero no rutas, `user_id` ni informacion de propiedad.

Las tareas pueden asociarse a proyectos mediante `project_id`. Cuando se crea una tarea desde un proyecto, `TaskService` asigna o exige un `space_id` coherente con el espacio del proyecto. La interfaz no vuelve a pedir espacio en ese contexto y el backend rechaza un espacio distinto. Si cambia el espacio del proyecto, `ProjectService` sincroniza las tareas asociadas para mantener la herencia.

Las subtareas usan `parent_task_id`; la capa de servicio impide autorreferencias, ciclos evidentes y relaciones de otro usuario. La interfaz muestra un nivel visual de subtareas dentro del detalle del proyecto/tarea correspondiente, aunque el modelo puede almacenar jerarquias mas profundas para uso futuro.

Las tareas tienen tres campos temporales:

- `starts_at`: inicio opcional del periodo en que ocurre o se realiza la tarea;
- `ends_at`: fin opcional del periodo;
- `due_at`: fecha limite opcional.

`TaskService` valida fechas y rechaza `ends_at` anterior a `starts_at`. Los filtros rapidos actuales de Tareas siguen usando `due_at`. El Calendario combina `starts_at`, `ends_at` y `due_at`.

`DeadlineUrgencyService` calcula la urgencia al cargar datos, sin persistirla. Para tareas usa `due_at`; para proyectos usa `due_on`. La salida contiene texto entendible como `Faltan 7 dias`, `Menos de 24 h` o `Vencida hace 1 dia`, mas un nivel visual progresivo. Las tareas completadas se presentan como `Completada` y dejan de mostrarse como urgentes.

La politica temporal del proyecto es: el usuario introduce y visualiza `DATETIME` en `America/Santiago`, y la base almacena esos valores en UTC. `App\Support\DateTimeHelper` centraliza la conversion de entrada local a UTC, la conversion de UTC a local, el valor de "ahora" y el formato para controles `datetime-local`. Los campos `DATE` puros de proyectos, como `starts_on` y `due_on`, no pasan por conversion de zona horaria.

No hay SQL en vistas ni en el endpoint HTTP. La API es interna de la aplicacion y devuelve JSON con mensajes seguros, sin trazas ni detalles SQL.

`NoteService` mantiene notas como registros simples separados de tareas y proyectos. Una nota tiene titulo, contenido, espacio opcional, etiquetas opcionales y estado `active` o `completed`. Las notas activas generan un recordatorio interno diario deduplicado. `NoteRepository` limita todas sus consultas por `user_id`.

`ReminderService` mantiene recordatorios dentro de Organizacion. Un recordatorio puede estar asociado a una tarea, a un proyecto o ser independiente, pero no puede apuntar simultaneamente a tarea y proyecto. La capa de servicio valida que `task_id` y `project_id` pertenezcan al mismo `user_id` autenticado. `remind_at` es obligatorio, se guarda en UTC y se presenta usando `America/Santiago`. Los estados disponibles son `pending`, `completed` y `dismissed`.

Los recordatorios pueden ser no recurrentes o recurrentes con frecuencia diaria, semanal, mensual o anual e intervalo entero mayor o igual a 1. La tabla guarda una sola definicion recurrente, no una fila por cada ocurrencia futura. `remind_at` representa la fecha base de la definicion y `next_remind_at` representa la ocurrencia activa que deben mostrar Organizacion y Dashboard. `recurrence_until`, cuando existe, limita la serie y no permite una siguiente ocurrencia posterior a esa fecha.

Completar o descartar una ocurrencia recurrente no marca permanentemente la definicion como completada si existe una siguiente ocurrencia; en ese caso `next_remind_at` avanza y el recordatorio sigue `pending`. Si ya no hay siguiente ocurrencia por `recurrence_until`, se marca con el estado terminal correspondiente. Detener una recurrencia es una accion explicita que desactiva la serie. Esta fase no implementa envios, Push, PWA ni centro completo de notificaciones.

La politica para recurrencias mensuales y anuales con fechas especiales es usar el ultimo dia valido del mes cuando el dia original no existe. Por ejemplo, una recurrencia mensual del 31 de enero avanza al 28 o 29 de febrero segun corresponda; una recurrencia anual del 29 de febrero avanza al 28 de febrero en anos no bisiestos.

`workers/process-reminders.php` procesa automaticamente recordatorios vencidos desde CLI. Busca recordatorios `pending` cuya ocurrencia efectiva (`COALESCE(next_remind_at, remind_at)`) sea menor o igual a la hora UTC actual obtenida mediante `DateTimeHelper`. Por cada ocurrencia vencida crea una notificacion interna `reminder_due` en `notifications` y luego cierra o avanza el recordatorio segun corresponda.

La idempotencia se basa en una restriccion unica de `notifications` sobre `reminder_id`, `type` y `scheduled_at`, donde `scheduled_at` es la ocurrencia programada. El worker ademas procesa cada recordatorio dentro de una transaccion y usa `SELECT ... FOR UPDATE` para evitar duplicados ante ejecuciones simultaneas.

Si el worker estuvo detenido, no genera una notificacion por cada ocurrencia perdida. Crea como maximo una notificacion atrasada para la ocurrencia vencida actual y avanza `next_remind_at` hasta la siguiente ocurrencia futura, o finaliza la recurrencia si `recurrence_until` ya no permite mas ejecuciones.

El centro visual de notificaciones internas es un centro general de actividad. Reutiliza la tabla `notifications` para recordatorios, organizacion, video y futuras fuentes, sin crear un sistema paralelo:

```text
Reminders / Organization / Video / futuras fuentes
-> workers
-> notifications
-> NotificationService
-> campana / Dashboard / vista de notificaciones
```

`workers/process-notification-activity.php` crea actividad diaria deduplicada para cada usuario activo. Usa `America/Santiago` para el dia funcional y genera como maximo un resumen diario por usuario y fecha (`daily_agenda:<user_id>:YYYY-MM-DD`). Tambien crea notificaciones deduplicadas para tareas que vencen pronto, tareas que vencen hoy, tareas que comienzan hoy, tareas vencidas pendientes, notas activas, resumenes relevantes de proyectos activos y cambios terminales de exportaciones de video.

La tabla `notifications` conserva `reminder_id` por compatibilidad, pero las nuevas notificaciones usan `source_module`, `entity_type`, `entity_id` y `dedupe_key`. La idempotencia de actividad se basa en `dedupe_key`; por ejemplo, una tarea que vence hoy crea una sola notificacion por dia aunque Cron corra cada minuto. Los workers obtienen siempre el propietario desde las entidades de dominio y nunca desde el navegador.

`NotificationService` lista notificaciones propias, cuenta no leidas, marca una o todas como leidas y resuelve enlaces internos seguros segun entidad: tareas/proyectos/notas van a Organizacion, recordatorios mantienen su destino actual y video va a `/index.php?section=video&tab=processings`. La campana del header muestra las recientes, el contador de no leidas y una etiqueta discreta del modulo; la vista `/index.php?section=notifications` permite filtrar Todas, No leidas y Leidas sin agregar una entrada nueva al sidebar. Las escrituras pasan por la API interna `/api/notifications.php` con sesion autenticada, CSRF y restricciones por `user_id`. Esta fase no implementa Push, email, PWA ni WebSockets.

`CalendarService` no crea ni duplica datos. Construye una vista de calendario desde tareas y proyectos existentes:

- tareas programadas mediante `starts_at` y `ends_at`;
- vencimientos mediante `due_at`;
- proyectos mediante `starts_on` y `due_on`;
- tareas internas de proyectos cuando tienen fechas.

Los elementos del calendario enlazan al flujo existente de edicion/detalle de tareas o proyectos. No existe un editor especifico de calendario.

El calendario marca el dia actual usando `America/Santiago` tanto en la vista mensual como semanal. El dia seleccionado por el usuario se representa como un estado visual independiente para que pueda coexistir con el indicador fijo de hoy.

`organization_events` se conserva en base de datos pero no se usa por ahora. La Fase 2 no implementa Eventos como tipo separado porque las tareas ya soportan temporalidad suficiente mediante `starts_at`, `ends_at` y `due_at`.

## Horarios

El modulo Horarios modela amigos y horarios ingresados manualmente. No rastrea ubicacion real, no usa GPS, no consulta mapas y no crea cuentas para amigos. Las vistas futuras estimaran donde deberia estar una persona segun su horario academico registrado.

El modelo inicial usa:

- `friends`: amigos pertenecientes al usuario autenticado;
- `friend_schedule_entries`: bloques recurrentes semanales de cada amigo;
- `friend_schedule_exceptions`: excepciones puntuales de amigos para cambios, ausencias o cancelaciones;
- `user_schedule_entries`: horario universitario propio del usuario, separado de `friends` para no crear una persona ficticia;
- `user_schedule_exceptions`: excepciones puntuales del horario propio.

`FriendScheduleService` y `FriendScheduleRepository` entregan operaciones minimas de consulta/creacion para pruebas y uso futuro. El ownership de horarios de amigos se deriva siempre por la cadena `users -> friends -> friend_schedule_entries`; aunque las entradas de horario no tienen `user_id` directo, toda consulta debe unir con `friends.user_id`. El horario propio usa `user_schedule_entries.user_id` directamente.

Los horarios semanales usan `weekday` con lunes = 1 y domingo = 7. `starts_at` y `ends_at` son campos `TIME` locales academicos, interpretados en `America/Santiago`, sin conversion a UTC. `valid_from`, `valid_until` y `exception_date` son `DATE` puros y tampoco se convierten. Esta politica es distinta a los `DATETIME` de tareas, recordatorios y notificaciones.

La base no prohibe solapamientos entre clases. Una fase posterior podra advertir conflictos al usuario sin bloquear necesariamente el guardado.

La gestion de amigos usa el flujo:

```text
interfaz/API autenticada
-> FriendService
-> FriendRepository
-> PDO / MariaDB
```

La resolucion de horario efectivo se centraliza en `FriendScheduleResolver`:

```text
horario recurrente semanal
-> filtro por weekday
-> vigencia valid_from / valid_until
-> excepcion puntual por fecha
-> horario efectivo
```

`cancelled` muestra la clase atenuada/cancelada pero no cuenta como clase activa. `absent` muestra que la persona no asistira y tampoco cuenta como presencia. `modified` reemplaza solo para esa fecha los campos indicados, como hora, sala, ramo, codigo o campus, sin sobrescribir el bloque recurrente. Esta salida efectiva es la fuente para Semana, Ahora, Hoy, Dashboard y queda preparada para coincidencias futuras.

`CoincidenceService` calcula coincidencias de actividad academica simultanea reutilizando exclusivamente el horario efectivo:

```text
ScheduleResolver
-> bloques academicos efectivos
-> interseccion temporal Mi horario / amigos activos
-> coincidencias individuales y grupales
```

Una coincidencia existe cuando un bloque academico efectivo propio se solapa temporalmente con un bloque academico efectivo de un amigo activo. Los huecos libres comunes, como recreos entre clases, no forman parte de esta vista. `cancelled` y `absent` no generan coincidencias; `modified` usa la hora y datos modificados para esa fecha. "Mismo campus" significa que ambos bloques tienen campus conocido y coincidente de forma normalizada, sin afirmar ubicacion fisica real.

La pestana `Coincidencias` consume `CoincidenceService` desde `public/index.php` y renderiza resultados server-side, sin recalcular disponibilidad en JavaScript. Permite consultar una fecha local, amigo especifico y filtro de mismo campus. Los resultados separan coincidencias individuales y grupales; los intervalos de hoy que ya terminaron se muestran atenuados y la proxima coincidencia se calcula usando `America/Santiago`.

La integracion manual con Organizacion usa el flujo:

```text
ScheduleResolver
-> CoincidenceService
-> vista Coincidencias
-> usuario selecciona Crear tarea
-> CoincidenceTaskPrefillService valida/recalcula la coincidencia
-> formulario existente de tareas
-> OrganizationTaskService
-> Organizacion / Calendario
```

Una coincidencia es informacion inferida desde horarios academicos. Una tarea es una decision explicita del usuario. Por eso las coincidencias no aparecen automaticamente en el Calendario de Organizacion ni crean recordatorios; solo se convierten en tareas si el usuario revisa y guarda el formulario de Organizacion. El espacio sugerido se busca por el `slug` `amigos` entre los espacios propios del usuario, sin depender de IDs fijos.

La pantalla vive en `/index.php?section=friends` y mantiene una sola entrada principal en el sidebar: Horarios. Dentro de la pantalla existe navegacion interna simple:

- Ahora;
- Hoy;
- Semana;
- Coincidencias;
- Amigos;
- Horarios.

El endpoint interno `/api/friends/friends.php` permite listar, crear, editar, activar, desactivar y eliminar amigos propios. El endpoint `/api/friends/schedule.php` gestiona bloques semanales tanto para `friend_schedule_entries` como para `user_schedule_entries` usando `target_type=user|friend`; con `action=exceptions` gestiona excepciones del mismo destino. Las escrituras requieren CSRF y nunca aceptan `user_id` desde el navegador.

El editor semanal representa bloques entre 08:00 y 21:00 sin librerias externas de calendario. En desktop muestra una grilla de lunes a domingo; en movil muestra selector de dia y el detalle vertical del dia seleccionado. Los bloques de amigos usan `effective_campus`: si la entrada no tiene campus propio, se muestra `friends.default_campus` sin duplicarlo en la tabla de horario.

Los solapamientos de bloques del mismo horario se permiten. `FriendScheduleService` agrega una advertencia `Este bloque se superpone con otra clase del horario.` cuando detecta cruce de dia/hora y periodos de vigencia compatibles, pero no impide guardar. El filtro por defecto muestra bloques vigentes para la fecha local actual; la opcion "Ver todos los bloques" permite editar horarios fuera de vigencia.

`FriendPresenceService` calcula vistas Ahora y Hoy usando `America/Santiago`, horarios ingresados manualmente y el horario efectivo resuelto. Ahora clasifica amigos activos como En clase, Libre, No asiste, Cancelada, Sin clases hoy o Fuera del periodo de vigencia, siempre con textos como "Segun horario" para no afirmar ubicacion real. Hoy lista los bloques efectivos del dia y los marca como Finalizada, Ahora, Proxima, Cancelada, No asiste o Modificada. El Dashboard consume un resumen pequeno desde este mismo servicio.

La importacion JSON de horarios se realiza desde la pestana Horarios. El usuario selecciona destino en la interfaz y luego pega o carga un JSON; el JSON no puede incluir `user_id` ni `friend_id`. La API valida todo, muestra previsualizacion, advierte duplicados/solapamientos y solo guarda al confirmar. La referencia del formato esta en `docs/SCHEDULE_IMPORT.md`.

## Interfaz de Organizacion

La pantalla autenticada de Organizacion vive en:

```text
/index.php?section=organization
```

`public/index.php` prepara datos iniciales mediante `SpaceRepository`, `TaskService` y `ProjectService`, y renderiza la vista PHP nativa `app/Views/pages/organization-tasks.php`. La vista muestra filtros, formularios y listas iniciales sin consultar la base de datos directamente.

Las acciones de crear, editar, completar, reabrir y eliminar usan JavaScript nativo en `public/assets/js/app.js` contra las APIs internas de tareas y proyectos. La lista se actualiza con `fetch` y creacion de nodos DOM mediante `textContent`, evitando insertar HTML de usuario. Los filtros principales se conservan como query string para mantener URLs simples y revisables.

Organizacion se maneja con pestanas internas, sin nuevas entradas en el sidebar:

- Tareas;
- Proyectos;
- Notas;
- Recordatorios;
- Calendario;
- Etiquetas.

Tareas es la pestana inicial. Muestra tareas independientes por defecto y excluye tareas asociadas a proyectos y subtareas como elementos principales. Sus filtros combinables son espacio, estado, etiqueta y tiempo, con opcion de ordenar por vencimiento. Bandeja sigue representando `space_id IS NULL`.

Proyectos tiene sus propios filtros de espacio, estado y etiqueta, con opcion de ordenar por vencimiento. Puede abrirse un detalle de proyecto dentro de la misma pantalla para ver informacion del proyecto, rango de fechas, progreso, urgencia derivada, etiquetas y tareas asociadas.

Notas muestra solo notas y permite crear, editar, eliminar y filtrar por espacio o etiqueta. No mezcla notas con tareas ni proyectos.

Etiquetas administra los labels del usuario dentro de Organizacion. Cada etiqueta tiene nombre y color configurable, se muestra como chip compacto y puede asignarse a tareas, proyectos y notas desde sus formularios.

Recordatorios muestra recordatorios pendientes, todos o completados/descartados. Pueden crearse desde la pestana interna, desde una tarea o desde un proyecto. Cuando se crean desde tarea/proyecto, la relacion queda fijada por contexto y la interfaz no vuelve a pedir el elemento recordado.

Calendario es una vista funcional de lectura sobre tareas y proyectos existentes. La vista mensual y semanal se renderiza con PHP, CSS y JavaScript nativo, sin librerias externas de calendario ni registros especiales. Las tareas sin ninguna fecha no aparecen en Calendario.

La Bandeja no tiene pagina separada ni espacio propio en base de datos. Una tarea pertenece a Bandeja cuando `space_id` es `NULL`. Organizar una tarea consiste en editarla y asignarle un espacio existente del usuario; al hacerlo deja de aparecer bajo el filtro Bandeja. El Dashboard incluye una captura rapida que crea tareas pendientes normales, sin espacio ni vencimiento, usando la API interna de tareas.

Los assets publicos del layout viven en:

- `public/assets/css/app.css`;
- `public/assets/js/app.js`.

No se usa motor de templates, framework frontend, bundler ni CDN.

## Autenticacion y administracion de usuarios

La autenticacion local se centraliza en `App\Services\AuthService`, con `password_hash()`/`password_verify()` usando `PASSWORD_ARGON2ID`, normalizacion de username y rate limiting basado en `login_attempts`.

El registro publico vive en `/register.php`. Un formulario valido crea un usuario activo con:

```text
is_active = 1
```

No se inicia sesion automaticamente; se redirige al login con el mensaje "Cuenta creada correctamente. Ya puedes iniciar sesion.". Despues de un login valido se guarda en sesion solo el `user_id` autenticado y el username.

El acceso ya no requiere aprobacion manual. Si existen columnas heredadas de una version anterior (`approval_status`, `approved_at`, `approved_by_user_id`, `rejected_at`), se conservan por compatibilidad de migraciones y trazabilidad, pero no controlan el login. Las cuentas nuevas quedan con `approval_status = approved` cuando esa columna existe, y la migracion `202608080022_deprecate_user_approval_status.php` normaliza cuentas pendientes a activas.

`is_active` permite desactivar o reactivar cuentas. `Session::isAuthenticated()` vuelve a consultar que la cuenta siga activa, de modo que una cuenta desactivada deja de pasar validaciones en la siguiente peticion.

El rol administrativo minimo se almacena en `users.is_admin`. No hay usernames hardcodeados ni cuentas admin creadas automaticamente. Para convertir un usuario existente en administrador se usa:

```sh
make user-admin USERNAME=<username>
```

La vista de Configuracion / Usuarios no muestra `password_hash` ni contrasenas. Todas las acciones usan POST y CSRF, y un administrador autenticado no puede desactivar accidentalmente su propia cuenta.

## Navegacion interna

La navegacion autenticada se resuelve mediante una lista blanca explicita en `app/Support/Navigation.php`.

La URL inicial es:

```text
/index.php
```

Las secciones internas usan:

```text
/index.php?section=clave-conocida
```

Una seccion desconocida no carga archivos dinamicamente y responde con una pantalla segura de seccion no encontrada. No se usa `include` basado directamente en entrada del usuario.

## Workers

Los procesos en segundo plano se implementaran con PHP CLI y se ejecutaran mediante Cron cuando sea necesario.

Casos previstos:

- Recordatorios.
- Recolectores de descuentos.
- Limpieza de archivos temporales.
- Tareas relacionadas con FFmpeg.

El contenedor `worker` ejecuta Cron en primer plano. La frecuencia inicial para recordatorios, video y el scheduler de collectors es cada minuto:

```text
* * * * * . /tmp/mi-central-worker-env.sh; cd /var/www/html && /usr/local/bin/php workers/process-reminders.php
17 * * * * . /tmp/mi-central-worker-env.sh; cd /var/www/html && /usr/local/bin/php workers/process-expense-notifications.php
* * * * * . /tmp/mi-central-worker-env.sh; cd /var/www/html && /usr/local/bin/php workers/process-notification-activity.php
* * * * * . /tmp/mi-central-worker-env.sh; cd /var/www/html && /usr/local/bin/php workers/process-video-metadata.php
* * * * * . /tmp/mi-central-worker-env.sh; cd /var/www/html && /usr/local/bin/php workers/process-video-exports.php
* * * * * . /tmp/mi-central-worker-env.sh; cd /var/www/html && /usr/local/bin/php workers/process-discount-collectors.php
```

Que Cron despierte cada minuto no significa que cada collector consulte sitios externos cada minuto. `process-discount-collectors.php` revisa `discount_collector_schedules.next_run_at`, reclama como maximo un collector pendiente y termina inmediatamente si no hay nada por ejecutar.

Cada worker escribe en su propio log:

- `storage/logs/reminders-worker.log`;
- `storage/logs/expense-notifications-worker.log`;
- `storage/logs/notification-activity-worker.log`;
- `storage/logs/video-metadata-worker.log`;
- `storage/logs/video-exports-worker.log`;
- `storage/logs/discount-collectors-worker.log`.

Los comandos manuales disponibles son:

```sh
make process-reminders
make process-expense-notifications
make process-notification-activity
make process-video-metadata
make process-video-exports
make process-discount-collectors
```

Los logs se pueden seguir con:

```sh
make worker-logs
make expense-notification-logs
make video-metadata-logs
```

## Descuentos

Descuentos funciona como modulo de descubrimiento automatico. Las promociones comerciales provienen de collectors globales y el usuario solo selecciona en su perfil que tarjetas, cuentas, membresias o beneficios posee. La Fase 8 registra fuentes reales publicas como Banco de Chile Beneficios, Santander Chile Beneficios y BancoEstado Beneficios. El flujo de dominio vigente es:

```text
Collectors
-> PromotionImportPipeline
-> discount_promotions globales
-> discount_promotion_benefits
-> discount_benefit_programs canonicos

Usuario
-> Configuracion / Perfil
-> Mis tarjetas y beneficios
-> user_discount_benefits
-> DiscountCompatibilityService
-> Para mi

Usuario
-> user_discount_favorites
-> discount_promotions
```

`discount_benefit_programs` es un catalogo global de beneficios o productos que pueden habilitar promociones, como tarjetas bancarias, cuentas, beneficios moviles, wallets, membresias u otros. Los nombres visibles se conservan; la normalizacion se guarda aparte solo para evitar duplicados evidentes.

`user_discount_benefits` guarda que beneficios posee cada usuario. El navegador no envia `user_id`; las operaciones toman el usuario desde la sesion y limitan siempre por ownership. Un usuario no puede repetir el mismo `benefit_program_id`.

La seleccion vive en `Configuracion -> Mis tarjetas y beneficios`, no dentro de Descuentos:

```text
Usuario
-> Configuracion / Perfil
-> Mis tarjetas y beneficios
-> user_discount_benefits
-> discount_benefit_programs
```

Desde esa UI el usuario marca o desmarca programas existentes del catalogo directamente con un checkbox. El cambio se guarda sin recargar mediante JavaScript nativo y `POST` JSON a `/api/discounts/user-benefits.php`, con CSRF y usuario resuelto desde sesion. El navegador envia solo `benefit_program_id` y el estado deseado; el backend valida que el programa exista y este activo, y aplica la relacion en `user_discount_benefits` con ownership del usuario autenticado.

El usuario no puede crear libremente BenefitPrograms, renombrarlos, modificar tipo ni eliminar globalmente un registro de `discount_benefit_programs`. Si falta un beneficio real, se corrige el collector/resolver o un catalogo controlado del sistema; no se piden ni almacenan numeros de tarjeta, CVV, RUT, claves, vencimientos ni datos bancarios sensibles.

`discount_merchants` guarda comercios como catalogo simple. No hay sucursales ni GPS. La categoria del comercio puede existir como dato auxiliar, pero la busqueda de descuentos usa principalmente la categoria de la promocion porque un mismo comercio puede vender productos de rubros distintos.

`discount_promotions` guarda promociones importadas por collectors. `starts_on` y `ends_on` son `DATE` comerciales, no instantes UTC. Una promocion sin fechas conocidas puede dejar ambos campos en `NULL`. `category` guarda una categoria controlada de la promocion (`restaurants`, `perfumes`, `technology`, etc.) normalizada por la capa comun. `source_type`, `collector_key`, `source_key`, `source_url` y `last_seen_at` identifican el origen oficial y permiten actualizar una promocion existente sin duplicarla. El CRUD manual de promociones queda deprecado para la experiencia normal; la UI no ofrece crear, editar, desactivar ni borrar promociones manualmente.

La Fase 7.4 centraliza compatibilidad en `DiscountCompatibilityService`:

```text
user_discount_benefits activos
-> DiscountCompatibilityService
-> discount_promotion_benefits
-> discount_promotions
```

Reglas:

- una promocion sin `discount_promotion_benefits` es `general`;
- una promocion con requirements compara solo por `benefit_program_id`;
- varios requirements usan semantica OR;
- si el usuario posee varios requirements, se reportan todas las coincidencias;
- `user_discount_benefits.active = 0` no cuenta;
- `discount_benefit_programs.active = 0` no produce compatibilidad activa;
- no hay fuzzy matching, IA, scraping ni interpretacion de `terms`;
- la compatibilidad no se persiste en BD porque cambia dinamicamente cuando el usuario agrega o quita beneficios.

El resultado estructurado incluye `status` (`general`, `compatible`, `incompatible`), `required_benefits`, `matched_benefits` y `reason` amigable. Las consultas de listado cargan los beneficios activos del usuario una vez y precargan requirements de promociones en grupo para evitar N+1 evidente.

La interfaz principal de Descuentos usa una sola entrada de sidebar y navegacion interna:

```text
Descuentos
-> Para mi
-> Favoritos
-> Todos
```

`/index.php?section=discounts` abre `Para mi`. Esa vista consume `DiscountCompatibilityService` para mostrar promociones generales y promociones que coinciden con los beneficios activos del usuario. No muestra promociones incompatibles, inactivas ni vencidas; las vigentes aparecen antes que las proximas.

`Para mi` incluye un enlace discreto a `Configuracion -> Mis tarjetas y beneficios` cuando el usuario necesita ajustar sus productos. No duplica CRUD de beneficios dentro de Descuentos.

`Todos` es una vista de consulta del catalogo de promociones visibles. Muestra indicadores derivados de compatibilidad (`Te sirve`, `Para todos`, `Requiere ...`) sin acciones administrativas. Permite filtros de busqueda, comercio, categoria, canal, fuente/collector, beneficio requerido y estado (`Vigentes`, `Proximas`, `Vencidas`, `Todas`). No reemplaza la antigua tab `Hoy`; las oportunidades actuales se priorizan desde `Para mi`.

La busqueda combina texto libre con categorias. `DiscountPromotionCategory` convierte alias deterministas como `perfumes`, `comida`, `ropa` o `tecnologia` en categorias internas, sin modificar el texto original ni usar IA. El filtro del selector usa codigos canonicos (`perfumes`, `fashion`, `technology`, etc.) y puede expandir categorias paraguas de forma centralizada: `food` incluye `food`, `restaurants` y `cafes`; `beauty` incluye `beauty` y `perfumes`. Las consultas SQL usan placeholders con nombres unicos por campo (`title`, `description`, `terms`, comercio y categoria) para funcionar con prepares nativos de PDO y evitar errores por placeholders repetidos.

`Favoritos` deriva su estado desde `user_discount_favorites`. Los favoritos se agregan o quitan con POST autenticado, CSRF y `user_id` de sesion; una promocion favorita puede seguir apareciendo aunque luego quede vencida o inactiva para que el usuario pueda quitarla manualmente.

Las vistas de descubrimiento reutilizan el mismo componente de card de promocion y el servicio `DiscountDiscoveryService`, que agrupa promociones, merchants, weekdays, requirements, favoritos y compatibilidad en consultas/preloads por lote. Las cards muestran comercio, descuento/titulo, fuente humana del collector, categoria amigable, beneficio requerido, dias, vigencia, canal, favorito y `Ver promocion oficial` cuando `source_url` existe y usa esquema `http` o `https`. La descripcion se muestra compacta con truncado visual; los terminos completos se abren solo desde `Ver condiciones`. En desktop las cards usan layout flexible para alinear las acciones al fondo de cada card.

Compatibilidad y disponibilidad temporal son conceptos separados. `DiscountCompatibilityService` responde si el usuario tiene el beneficio necesario; `DiscountPromotionAvailabilityService` responde si una promocion esta activa, vigente y aplica al weekday de hoy. `isApplicableToday()` usa `America/Santiago`; si una promocion no tiene weekdays, aplica cualquier dia dentro de su vigencia. Una promocion futura o vencida puede ser compatible estructuralmente aunque no sea aplicable hoy. `Vencida` se deriva dinamicamente cuando `ends_on < hoy`; no se persiste un campo `expired`.

`discount_promotion_benefits` define que beneficios habilitan una promocion. Si una promocion no tiene registros ahi, se interpreta como promocion general o sin requisito especifico conocido. Si tiene varios beneficios, la semantica inicial es OR: cualquiera de ellos puede habilitarla. No existe soporte AND ni motor generico de reglas; reglas mas especificas se conservan en `terms`.

`discount_promotion_days` usa lunes = 1 y domingo = 7. Si una promocion no tiene dias asociados, puede aplicar cualquier dia dentro de su vigencia. Si tiene dias, solo aplica esos weekdays. La determinacion futura de "hoy" debe usar `America/Santiago`.

`user_discount_favorites` relaciona usuarios con promociones favoritas y no duplica favoritos por `UNIQUE (user_id, promotion_id)`.

No se persisten datos derivados como `compatible_with_user`, `is_today`, `days_remaining`, `is_favorite` dentro de promociones ni labels generados. La compatibilidad y vigencia se calcularan en fases posteriores desde:

```text
is_active
AND (starts_on IS NULL OR starts_on <= today)
AND (ends_on IS NULL OR ends_on >= today)
AND (sin weekdays asociados OR weekday actual asociado)
```

### Recolectores automaticos de descuentos

La Fase 8 agrega infraestructura comun para recolectores, un pipeline de normalizacion/deduplicacion/persistencia, un scheduler persistido en BD y collectors reales publicos. No envia notificaciones y no implementa scraping masivo. El flujo de ejecucion programada es:

```text
Cron del worker
-> workers/process-discount-collectors.php
-> DiscountCollectorScheduler
-> discount_collector_schedules
-> DiscountCollectorRunner
-> DiscountCollectorInterface / CollectorHttpClient
-> CollectorResult
-> DiscountPromotionImportPipeline
-> discount_promotions
```

Cada collector especifico tiene una key estable (`getKey()`), un nombre humano (`getName()`) y un metodo `collect(CollectorContext)` que devuelve `CollectedPromotion[]`. Un collector solo debe acceder a su fuente, extraer datos y devolver registros raw. No debe conocer PDO, `user_id`, favoritos, compatibilidad, deduplicacion, notificaciones ni escritura en `discount_promotions`.

`CollectedPromotion` representa una promocion encontrada antes de normalizar. Requiere `source_key` y `title`; `source_url`, cuando existe, debe ser URL valida. Campos como `discount_text`, `max_discount_clp`, `starts_on_raw`, `weekdays_raw`, `benefit_names_raw`, `channel_raw` y `category_raw` pueden conservar texto parcial de la fuente. Cada collector especifico sigue limitado a extraer informacion y devolver registros raw.

`PromotionNormalizer` transforma cada item raw en `NormalizedPromotion` usando reglas deterministas. Reconoce descuentos simples (`percentage`, `fixed_amount`, `special_price`, `other`), categorias controladas desde `category_raw` o fallback conservador, canales (`in_store`, `online`, `both`), fechas comerciales `DATE` en formatos no ambiguos y weekdays con lunes = 1 y domingo = 7. No usa IA, fuzzy matching, HTTP ni persistencia. Cuando una fecha/canal/descuento/categoria no puede interpretarse con seguridad, emite advertencias o deja el campo como `NULL` segun corresponda; no inventa numeros ni fechas.

`DiscountMerchantResolver` resuelve `merchant_name` contra `discount_merchants` mediante texto comparable conservador y crea un comercio nuevo solo si no existe uno equivalente. La normalizacion de comparacion tolera mayusculas, espacios, acentos y puntuacion menor, pero no fusiona conceptos distintos como `Uber` y `Uber Eats`.

`DiscountBenefitProgramResolver` resuelve beneficios contra `discount_benefit_programs`. Primero intenta coincidencias deterministas con el catalogo activo; si un collector futuro entrega un descriptor estructurado confiable puede crear un programa nuevo de catalogo. Si solo hay texto ambiguo no crea asociaciones falsas. Una promocion con benefits raw que no logran resolverse no se importa como promocion general, para evitar recomendaciones incorrectas.

`PromotionFingerprintService` genera un SHA-256 desde datos normalizados relevantes: merchant, titulo, tipo/valor de descuento, vigencia, weekdays, benefits y canal. No incluye `collected_at`, `last_seen_at` ni IDs de BD.

`PromotionDeduplicator` aplica estas reglas:

- misma fuente exacta (`collector_key + source_key`) actualiza la promocion existente;
- mismo collector + mismo fingerprint puede actualizar aunque cambie `source_key`, dejando advertencia;
- mismo fingerprint entre collectors distintos se reporta como posible duplicado, pero no se fusiona automaticamente;
- una promocion collector nunca sobrescribe una promocion manual, aunque el fingerprint coincida.

`DiscountPromotionImportPipeline` conecta `CollectorResult -> normalizacion -> deduplicacion -> resolucion de merchant/benefits -> persistencia`. Cada promocion se persiste en una transaccion que cubre `discount_promotions`, merchant nuevo si corresponde, `discount_promotion_days` y `discount_promotion_benefits`. Si un item falla, se contabiliza y el pipeline continua con los siguientes.

`DiscountCollectorRegistry` registra collectors por key y rechaza keys invalidas o duplicadas. En produccion registra `banco_chile`, `santander_chile` y `bancoestado`; los registries vacios en pruebas se crean explicitamente con `new DiscountCollectorRegistry([])`. Los collectors fake usados en pruebas viven solo dentro del test y no se registran en produccion.

`DiscountCollectorRunner` resuelve un collector desde el registry, crea `CollectorContext`, ejecuta `collect()` y devuelve `CollectorResult`. Un resultado con cero items sigue siendo exitoso. Los errores de configuracion, HTTP o parseo se capturan como resultado fallido con mensaje controlado, sin stack traces ni HTML externo completo.

`DiscountCollectorScheduler` consulta collectors registrados, crea schedules faltantes de forma idempotente y procesa como maximo un collector por invocacion. La primera sincronizacion crea `next_run_at = UTC_TIMESTAMP()` para que el siguiente ciclo pueda ejecutarlo, pero la sincronizacion por si sola no ejecuta collectors. Si el registry esta vacio, el worker termina limpiamente con `No collectors registered.`.

La periodicidad vive en `discount_collector_schedules.interval_minutes`. Los valores por defecto se configuran con:

```text
COLLECTOR_DEFAULT_INTERVAL_MINUTES=1440
COLLECTOR_MIN_INTERVAL_MINUTES=60
COLLECTOR_RETRY_MINUTES=60
COLLECTOR_STALE_AFTER_MINUTES=180
```

El scheduler usa `next_run_at` como fuente de verdad. Tras una ejecucion `success` o `partial` programa `next_run_at = fin + interval_minutes`; si falla el runner o el pipeline, marca `last_status = failed` y reprograma con `COLLECTOR_RETRY_MINUTES`. Cero promociones recolectadas cuenta como `success` si runner y pipeline terminan correctamente.

La concurrencia se controla en MariaDB: el scheduler reclama un collector due dentro de una transaccion con `FOR UPDATE SKIP LOCKED`, marca `last_status = running` y ejecuta fuera de la transaccion. Otra instancia simultanea no puede reclamar el mismo collector mientras siga `running`. Si un worker queda interrumpido, una fila `running` cuyo `last_started_at` supere `COLLECTOR_STALE_AFTER_MINUTES` se considera stale, puede reclamarse de nuevo y el run anterior se marca `failed` con `error_type = interrupted`.

Desde 8.4 cada ejecucion crea una fila en `discount_collector_runs`:

```text
Cron
-> Scheduler
-> Collector Run = running
-> DiscountCollectorRunner
-> DiscountPromotionImportPipeline
-> Collector Run = success / partial / failed
-> Schedule actualizado
```

`discount_collector_schedules` sigue representando solo el estado actual. `discount_collector_runs` representa historial. Los estados de runs son:

- `running`: ejecucion iniciada;
- `success`: collector y pipeline terminaron sin errores de items;
- `partial`: pipeline termino pero uno o mas items fallaron;
- `failed`: collector o pipeline no pudieron completar la ejecucion.

Las metricas de runs se copian desde `PromotionImportResult`: recolectadas, normalizadas, creadas, actualizadas, deduplicadas, omitidas, warnings y errores. La duracion se mide con reloj monotono del proceso y se guarda como `duration_ms`.

`DiscountCollectorErrorSanitizer` centraliza categorias y mensajes seguros. Mapea errores hacia `configuration`, `http`, `timeout`, `parse`, `normalization`, `validation`, `persistence`, `interrupted` o `unknown`. Antes de guardar o escribir logs elimina caracteres de control y redacta patrones comunes de secretos como `Authorization`, cookies, tokens y passwords. No se persiste HTML/JSON raw, headers completos, cookies ni stack traces.

`DiscountCollectorLogger` centraliza el log tecnico `storage/logs/discount-collectors-worker.log`. Escribe una linea compacta por run con collector, trigger, status, metricas y duracion. Si el archivo no puede escribirse, no debe abortar una importacion valida; la fuente estructurada principal es la BD.

Un schedule cuyo `collector_key` ya no esta registrado no se ejecuta ni se borra automaticamente; se reporta como `not_registered`. Deshabilitar un collector (`enabled = 0`) conserva su configuracion y evita ejecuciones. No existe endpoint web para disparar collectors: la ejecucion es solo CLI/worker.

`CollectorHttpClient` centraliza timeout, connect timeout, redirects limitados, User-Agent identificable, tamano maximo de respuesta y validacion de status. Los collectors futuros deben usar este cliente en lugar de crear llamadas `curl_init()` propias. Antes de descargar valida que la URL sea HTTP/HTTPS, que el host este en la allowlist del collector y que no apunte a destinos locales, loopback, redes privadas o reservadas. Los redirects pasan por la misma validacion y no pueden saltar a hosts no permitidos.

#### Fuente 1: Banco de Chile Beneficios

`BancoChileDiscountCollector` implementa `DiscountCollectorInterface` con:

- `collector_key`: `banco_chile`;
- nombre: `Banco de Chile - Beneficios`;
- pagina publica inicial: `https://sitiospublicos.bancochile.cl/personas/beneficios`;
- endpoint publico observado en la propia pagina: `https://sitiospublicos.bancochile.cl/api/content/spaces/personas/types/beneficios/entries?per_page=100&page=N`;
- host permitido: `sitiospublicos.bancochile.cl`;
- frecuencia inicial: `COLLECTOR_DEFAULT_INTERVAL_MINUTES=1440`, una vez al dia salvo que el schedule se cambie por CLI.

La pagina publica de beneficios carga un catalogo JSON paginado bajo el mismo dominio oficial. Cada entrada contiene `meta.slug`, `meta.tags` y `fields` como `Titulo`, `Extracto`, `Vigencia`, `Descripcion`, `Tipo Beneficio`, `Tarjetas Permitidas` y `Condiciones Comerciales`. El collector consulta ese JSON publico con `CollectorHttpClient`, una pagina a la vez, y no ejecuta JavaScript externo. No visita ni scrapea enlaces externos de comercios; si una promocion incluye un link de comercio, solo puede conservarse como dato raw cuando corresponda.

`BancoChileBenefitsParser` separa el parseo del acceso HTTP. Usa `json_decode()` para el catalogo publico y `DOMDocument`/`DOMXPath` mediante `CollectorHtmlHelper` para limpiar fragmentos HTML de descripcion/terminos. No usa regex como parser HTML principal.

La identidad estable de cada promocion es el `slug` oficial de `meta.slug`. `source_url` apunta al detalle oficial `https://sitiospublicos.bancochile.cl/personas/beneficios/detalle/{slug}`. Esto deja la identidad final como `banco_chile + source_key`.

Campos que el parser intenta extraer de forma conservadora:

- comercio/titulo desde `fields.Titulo`;
- descripcion limpia desde `fields.Descripcion`;
- descuento raw y hints de porcentaje desde `Tipo Beneficio`;
- tope de descuento CLP solo cuando aparece como tope maximo de descuento;
- vigencia raw desde `Vigencia` o `Condiciones Comerciales`;
- dias raw desde tags y textos visibles;
- canal raw desde extracto, descripcion y condiciones;
- beneficios requeridos desde `Tarjetas Permitidas`, usando descriptores estructurados para `DiscountBenefitProgramResolver`;
- terminos desde `Condiciones Comerciales`.

Si el endpoint responde correctamente y trae cero entradas con una estructura valida, la ejecucion puede ser `success` con cero promociones. Si desaparece la estructura fundamental (`entries` o metadatos incompatibles) el parser lanza `CollectorParseException`; el scheduler/run queda `failed`, las promociones existentes no se borran y no se interpreta como eliminacion masiva de beneficios. La ausencia de una promocion en un run tampoco desactiva automaticamente registros previos.

El collector respeta `COLLECTOR_BANCO_CHILE_MAX_ITEMS` para pruebas manuales acotadas y `COLLECTOR_BANCO_CHILE_REQUEST_DELAY_MS` para pausas entre paginas. No usa concurrencia paralela.

#### Fuente 2: Santander Chile Beneficios

`SantanderChileDiscountCollector` implementa `DiscountCollectorInterface` con:

- `collector_key`: `santander_chile`;
- nombre: `Santander Chile - Beneficios`;
- pagina publica inicial: `https://banco.santander.cl/beneficios`;
- endpoint publico usado por el widget oficial: `https://banco.santander.cl/beneficios/promociones.json?per_page=500&tags=home-disfrutadores&custom_fields=true&order_by=updated_at&desc=true&hash=1`;
- host permitido: `banco.santander.cl`;
- frecuencia inicial: `COLLECTOR_DEFAULT_INTERVAL_MINUTES=1440`, una vez al dia salvo que el schedule se cambie por CLI.

La pagina publica visible carga una aplicacion Modyo/Vue y el HTML inicial puede mostrar solo `Cargando el sitio`. El widget oficial obtiene el catalogo mediante `$modyo.getPromotions()` contra el JSON publico anterior. Los filtros visibles (`Todos`, `Multiplica millas`, `Sabores`, `Cuotas sin interes`, `Verdes`, `Descuentos`, region, comuna, dia y tarjeta) se aplican en cliente sobre ese mismo catalogo mediante tags y custom fields, por lo que el collector no ejecuta una request por cada filtro.

Cada promocion del JSON contiene campos como `slug`, `url`, `title`, `description`, `conditions`, `tags`, `start_date`, `end_date`, `discount` y `custom_fields` (`Bajada externa`, `Bajada interna`, `Vigencia`, `Region cobertura`, `Comuna cobertura`, `Sitio web beneficio`). El collector hace una sola request al catalogo por ejecucion, no visita detalles individuales porque el JSON ya trae descripcion, condiciones, vigencia, tags y URL oficial, y no scrapea comercios externos.

`SantanderChileBenefitsParser` separa el parseo del acceso HTTP. Usa `json_decode()` para validar la estructura del catalogo y `DOMDocument`/`DOMXPath` mediante `CollectorHtmlHelper` para limpiar fragmentos HTML de descripcion/terminos. Si el JSON no contiene la estructura fundamental `promociones`, lanza `CollectorParseException`. Una respuesta valida con `promociones = []` y `total_entries = 0` puede ser `success` con cero items; una respuesta vacia que declara `total_entries > 0` se considera parse error.

La identidad estable de cada promocion es el `slug` oficial. Si no existe, el parser puede usar `uuid` o `id` publicos como fallback determinista. `source_url` conserva la URL oficial de Santander solo si apunta a `banco.santander.cl`. La identidad final es `santander_chile + source_key`, por lo que una promocion parecida de Banco de Chile no se fusiona automaticamente con Santander.

Campos que el parser intenta extraer de forma conservadora:

- comercio/titulo desde `title`;
- descripcion limpia desde `description`;
- descuento raw desde `Bajada externa`, `Bajada interna` o textos de landing;
- hints de porcentaje solo cuando el porcentaje es exacto; textos como `Hasta 20%` quedan como `other`;
- cuotas sin interes, millas u otros beneficios no numericos como `other`;
- codigo promocional solo cuando aparece una frase explicita como `Codigo promocional: ...`;
- tope CLP solo cuando aparece como tope o descuento maximo;
- vigencia raw desde `custom_fields.Vigencia`;
- dias raw desde tags y textos visibles;
- canal raw desde bajadas y descripcion;
- beneficios requeridos desde tags/textos, con descriptores estructurados para `Tarjetas Credito Santander`, `Tarjetas Debito Santander`, `American Express Santander` y `WorldMember Limited Santander`;
- terminos desde descripcion y `conditions`, sin scripts, estilos, navegacion ni raw HTML completo.

El collector omite entradas marcadas como `empresas` o `no-home` y evita importar contenido institucional que no represente un beneficio concreto. Si aparece un requisito Santander nuevo que `DiscountBenefitProgramResolver` no logra resolver, el pipeline registra warning y no transforma esa promocion en general.

Santander respeta `COLLECTOR_SANTANDER_MAX_ITEMS` para pruebas manuales acotadas. `COLLECTOR_SANTANDER_REQUEST_DELAY_MS` queda disponible si en el futuro se agregan multiples requests, pero la fuente actual usa una sola request por ejecucion y no requiere pausa entre paginas. El endpoint acepta un User-Agent libcurl estandar; el collector envia un identificador transparente `curl/{version} MiCentral-DiscountCollector/1.0`, sin simular navegador. No usa navegador automatizado, Playwright, Selenium ni ejecucion de JavaScript externo.

#### Fuente 3: BancoEstado Beneficios

`BancoEstadoDiscountCollector` implementa `DiscountCollectorInterface` con:

- `collector_key`: `bancoestado`;
- nombre: `BancoEstado - Beneficios`;
- pagina publica inicial observada: `https://investor.bancoestado.cl/content/bancoestado-public/cl/es/home/home/todosuma---bancoestado-personas/todos-beneficios.html`;
- paginas de detalle bajo `/content/bancoestado-public/cl/es/home/home/todosuma---bancoestado-personas/todos-beneficios/*.html`;
- hosts permitidos: `www.bancoestado.cl` e `investor.bancoestado.cl`;
- frecuencia inicial: `COLLECTOR_DEFAULT_INTERVAL_MINUTES=1440`, una vez al dia salvo que el schedule se cambie por CLI.

La fuente observable es HTML publico. El catalogo muestra categorias como sabores, viajes, bienestar, hogar, vestuario, cuotas sin interes y otros servicios, ademas de filtros visuales por medio de pago. No se encontro un endpoint JSON publico estable equivalente a Santander; el collector usa HTML con `DOMDocument`/`DOMXPath`. No ejecuta JavaScript externo, no usa navegador automatizado y no visita comercios externos.

El flujo implementado es:

```text
catalogo HTML BancoEstado
-> enlaces internos de detalle
-> detalle HTML BancoEstado
-> CollectedPromotion[]
-> pipeline comun
```

Cada detalle oficial suele tener secciones como `Detalle`, `Donde`, `Medios de Pago` y `Vigencia`. `BancoEstadoBenefitsParser` extrae lineas utiles, elimina scripts/estilos/navegacion/footer y valida senales minimas de estructura. Si recibe `Access Denied`, pagina no encontrada o una estructura sin senales de beneficios BancoEstado, lanza `CollectorParseException`; el run queda fallido y no se borran promociones anteriores.

La identidad estable usa el slug de la pagina individual. Por ejemplo, `papa-john-s---beneficios-bancoestado.html` produce `source_key = papa-john-s`. Cuando una misma pagina contiene mas de un beneficio, el parser agrega un sufijo deterministico derivado del texto de descuento, vigencia o cupon, no de la posicion temporal del listado. La identidad final es `bancoestado + source_key`.

Campos que el parser intenta extraer de forma conservadora:

- comercio desde el `<title>` o el contenido principal del detalle;
- descripcion desde `Detalle`, `Donde` y `Vigencia`;
- porcentaje exacto como `percentage`;
- monto fijo solo cuando el texto dice explicitamente `dto`, `dcto` o `descuento`;
- cuotas sin interes y precios preferenciales como `other`;
- tope CLP solo cuando aparece como tope o descuento maximo;
- compra minima queda en `terms` y no se guarda como tope;
- codigo promocional solo si es un cupon fijo publico;
- primeros digitos de tarjeta, RUT, BIN u otros datos sensibles no se guardan como `promo_code`;
- fechas comerciales raw desde `Vigencia`;
- dias raw compactos como `martes`, `domingo` o `todos los dias`;
- canal raw desde `Donde` y detalle;
- beneficios requeridos como descriptores estructurados para `Tarjetas Debito BancoEstado`, `Tarjetas Credito BancoEstado`, `Tarjetas Credito Visa BancoEstado`, `Tarjetas Credito Mastercard BancoEstado` y `CuentaRUT BancoEstado`.

La deteccion de productos es conservadora. Si el detalle dice `Tarjetas de Debito o Credito BancoEstado`, se entregan ambos requirements con semantica OR. Si dice `Visa` o `Mastercard`, se conserva esa especificidad. Si el texto dice `excluye CuentaRUT`, no se asocia CuentaRUT aunque aparezca mencionada en condiciones.

BancoEstado respeta `COLLECTOR_BANCOESTADO_MAX_ITEMS` para pruebas manuales acotadas y `COLLECTOR_BANCOESTADO_REQUEST_DELAY_MS` para pausar entre detalles. La implementacion realiza una request al catalogo y una por cada detalle unico hasta alcanzar el limite configurado. El acceso directo con `curl` desde el entorno actual recibe bloqueo Akamai (`403`) en `investor.bancoestado.cl` o pagina no encontrada en algunas rutas `www.bancoestado.cl`; por diseno el collector falla de forma controlada en ese caso y registra el error mediante 8.4, sin intentar evadir la proteccion.

Las promociones importadas usan `source_type = collector`, `collector_key`, `source_key`, `source_url`, `last_seen_at` y `dedupe_fingerprint`. `last_seen_at` se actualiza cada vez que la fuente vuelve a observar una promocion. La ausencia en un run no desactiva automaticamente promociones, porque una fuente puede fallar parcialmente o cambiar paginacion. Si una promocion collector existente fue desactivada explicitamente, el pipeline no la reactiva automaticamente.

Los collectors son globales, no por usuario:

```text
Collector
-> promociones globales
-> DiscountPromotionImportPipeline
-> discount_promotions source_type=collector

Usuario
-> Configuracion / Mis tarjetas y beneficios
-> DiscountCompatibilityService
-> Para mi
```

La compatibilidad seguira calculandose dinamicamente desde `user_discount_benefits`; no se recolectan descuentos distintos por cada usuario.

La ejecucion manual vive en `workers/run-discount-collector.php` y puede invocarse mediante `make run-discount-collector COLLECTOR=<key>`. Para probar sin escribir en BD existe `make run-discount-collector COLLECTOR=<key> DRY_RUN=1`, que ejecuta collector, normalizacion, validacion y deduplicacion, pero no persiste cambios. Esta ejecucion manual no altera `discount_collector_schedules.next_run_at`, para no romper el calendario automatico.

Los comandos operativos simples del scheduler son:

```sh
make process-discount-collectors
make discount-scheduler-status
make discount-collector-runs [COLLECTOR=<key>]
make discount-collector-run RUN_ID=<id>
make discount-collector-enable COLLECTOR=<key>
make discount-collector-disable COLLECTOR=<key>
make discount-collector-interval COLLECTOR=<key> MINUTES=1440
```

El scheduler no depende del navegador ni de Cloudflare Tunnel. Si Docker y el contenedor `worker` estan activos, Cron continua evaluando `next_run_at` persistido en MariaDB aunque nadie visite Mi Central.

## Video y FFmpeg

Video usa una sola entrada principal en el sidebar. Al abrir `/index.php?section=video` se muestra la pestaña interna `Editor`; `/index.php?section=video&tab=processings` muestra `Procesamientos`. Los enlaces historicos `section=video-editor` y `section=video-processing` redirigen de forma segura a la nueva ruta para no romper deep links, pero el sidebar ya no renderiza submenu, flecha ni entradas separadas para Editor/Procesamientos.

```text
Sidebar
-> Video
   -> Editor
   -> Procesamientos
```

Mientras el usuario este en Editor o Procesamientos, la entrada lateral `Video` permanece activa y las pestañas internas indican la subseccion actual.

El editor basico de video procesara archivos localmente mediante FFmpeg.

FFmpeg y FFprobe viven dentro del contenedor `worker`; no se instalan en el host WSL ni se exponen como servicio externo. Las rutas se centralizan en `config/video.php` y pueden configurarse con `FFMPEG_BIN` y `FFPROBE_BIN`. En la imagen actual las rutas esperadas son:

- `FFMPEG_BIN=/usr/bin/ffmpeg`
- `FFPROBE_BIN=/usr/bin/ffprobe`

El flujo actual de metadata y preview es:

```text
Upload
-> video_files
-> metadata pending
-> worker
-> FFprobe
-> metadata ready
-> reproductor
-> endpoint Range autenticado
-> archivo original fuera de public
```

`storage/video` esta fuera de `public/` y se comparte entre `web` y `worker`. En desarrollo local se usa el bind mount del proyecto completo en `/var/www/html`; en produccion `compose.prod.yaml` copia el codigo dentro de la imagen y monta volumenes persistentes separados para storage. Los archivos subidos, temporales, trabajos y exportaciones no se sirven directamente por Apache. La estructura preparada es:

- `storage/video/uploads`
- `storage/video/jobs`
- `storage/video/exports`
- `storage/video/temp`

La subida segura de videos sigue este flujo:

```text
Browser
-> formulario multipart autenticado con CSRF
-> VideoService
-> validacion de extension, MIME Fileinfo y tamano
-> storage/video/uploads
-> video_files
```

`VideoService` centraliza las reglas de subida. Solo acepta extensiones de video permitidas, verifica el MIME real mediante Fileinfo, limita el tamano con `VIDEO_MAX_UPLOAD_MB`, genera un nombre fisico aleatorio y conserva el nombre original solo como metadata escapada al renderizar. El navegador nunca envia rutas internas ni `user_id`; las consultas pasan siempre por `video_files.user_id`.

Si el archivo se mueve correctamente pero falla la insercion en base de datos, la aplicacion elimina el archivo recien creado para evitar huerfanos. Si falla el movimiento del upload, no se crea registro. La eliminacion recibe solamente un ID, comprueba ownership, obtiene la ruta desde base de datos y elimina el archivo dentro de `storage/video/uploads`.

`workers/process-video-metadata.php` reserva videos con `metadata_status = 'pending'`, los marca `processing` y ejecuta FFprobe dentro del contenedor `worker` usando `FFPROBE_BIN`. Extrae duracion, resolucion, FPS, codecs, contenedor y bitrate cuando FFprobe los entrega. Un video `ready` no se reprocesa de forma repetida; si FFprobe falla se marca `failed` con un mensaje tecnico acotado y el original queda intacto.

La vista de Video muestra metadata compacta cuando esta `ready`, "Analizando video..." mientras esta pendiente o procesando, y permite reintentar analisis solo para videos propios en estado `failed` mediante API con CSRF. El reintento solo vuelve la metadata a `pending`; FFprobe no se ejecuta desde HTTP.

El reproductor usa HTML5 `<video controls>` para originales probablemente compatibles con navegador. Si el contenedor o codecs no son adecuados para preview directo, la aplicacion informa que el archivo se subio correctamente y queda para una fase futura de conversion/exportacion. No se transcodifica ni se generan derivados en esta fase.

El streaming seguro se sirve desde `/video/stream.php?id=ID`. El endpoint exige sesion, busca `video_files.id + user_id`, resuelve la ruta fisica desde la base de datos, comprueba que permanezca dentro de `storage/video/uploads` y transmite por chunks. Soporta un solo `Range: bytes=...` para seek con respuestas `200`, `206` o `416`; no acepta rutas ni `storage_path` desde el navegador.

El primer editor de video usa el reproductor y streaming existentes. El flujo actual es:

```text
video_files
-> reproductor
-> timeline
-> video.currentTime
-> puntos de corte
-> video_cut_points
```

La timeline es proporcional a `video_files.duration_seconds`, que proviene de FFprobe. El playhead se sincroniza con `video.currentTime` mediante eventos HTML5 del reproductor (`timeupdate`, `seeking`, `loadedmetadata`, `durationchange`). Hacer clic o tap en la timeline asigna `video.currentTime` y deja que el streaming Range existente resuelva el seek.

Los puntos de corte se gestionan por API interna autenticada en `/api/video/cuts.php`. Las lecturas usan `GET`; crear, mover y eliminar usan `POST` con CSRF. `VideoEditorService` valida ownership mediante `video_files.user_id`, exige duracion tecnica disponible, rechaza posiciones fuera de `(0, duration_seconds)` y evita duplicados cercanos. Estas operaciones modifican `video_cut_points` y sincronizan segmentos virtuales persistidos: no ejecutan FFmpeg, no generan derivados y no alteran el archivo original.

El editor mantiene segmentos virtuales persistidos con este flujo:

```text
video_cut_points
-> segmentos fuente
-> video_edit_segments
-> incluir/excluir
-> sort_order
-> getExportSequence()
-> exportacion FFmpeg
```

Por ejemplo, un video de `300` segundos con cortes `60`, `150` y `240` se interpreta como rangos fuente `0-60`, `60-150`, `150-240` y `240-300`. Cada rango se guarda en `video_edit_segments` con `source_start_seconds` y `source_end_seconds`; el reordenamiento solo cambia `sort_order`, nunca los tiempos fuente. Excluir un segmento marca `is_included = 0`, no borra el video original ni los puntos de corte.

`VideoEditorService` centraliza la sincronizacion entre cortes y segmentos. Al agregar un corte divide el segmento fuente afectado; al mover un corte actualiza los limites adyacentes; al eliminarlo fusiona los rangos correspondientes. Cuando un rango fuente permanece igual, conserva `is_included` y `sort_order`. Los rangos nuevos heredan decisiones cuando nacen de un rango anterior; si una fusion combina decisiones incompatibles, el resultado queda incluido solo si todos los rangos fusionados estaban incluidos.

La gestion de segmentos usa `/api/video/segments.php`. Las lecturas usan `GET`; excluir, restaurar y reordenar usan `POST` con CSRF. El backend verifica que todos los IDs pertenezcan al mismo video propio, rechaza duplicados y no acepta `user_id`, rutas ni comandos desde el navegador.

`getExportSequence(videoId, userId)` devuelve solo segmentos incluidos, ordenados por `sort_order`, con pares `source_start_seconds` / `source_end_seconds`. Ese contrato alimenta los jobs de exportacion.

La exportacion real de video usa este flujo:

```text
video_edit_segments
-> getExportSequence()
-> snapshot video_export_segments
-> video_export_jobs
-> worker
-> FFmpeg
-> progreso
-> FFprobe
-> storage/video/exports
-> descarga autenticada
-> retencion/cleanup
```

El editor crea un job mediante `/api/video/exports.php` con sesion, ownership y CSRF. En la misma transaccion se copia la secuencia incluida actual a `video_export_segments`; por eso una exportacion ya creada no cambia si el usuario modifica cortes o reordena segmentos despues. El navegador nunca envia `user_id`, rutas, filtros FFmpeg, codecs ni parametros arbitrarios.

`workers/process-video-exports.php` procesa como maximo un job por ejecucion. Primero evita iniciar otro job si existe uno `processing`, luego reclama atomicamente un `pending`, lo marca `processing`, carga el original desde `video_files`, usa el snapshot del job y genera un MP4 temporal en `storage/video/temp`. Solo si FFmpeg termina correctamente y FFprobe verifica el resultado, mueve el archivo final a `storage/video/exports` y marca el job como `completed`.

La estrategia FFmpeg usa el archivo original como input y genera filtros internos `trim/setpts` para video, `atrim/asetpts` cuando existe audio, y `concat` segun el orden del snapshot. La salida actual es MP4 con H.264 (`libx264`, CRF razonable, `yuv420p`, `+faststart`) y AAC cuando hay pista de audio. No usa `-c copy`, porque los cortes precisos y el reordenamiento no dependen de keyframes.

El progreso real se obtiene desde FFmpeg con `-progress pipe:1` y `-nostats`. El worker parsea `out_time_us` / `out_time_ms`, `out_time`, `speed` y `progress`, calcula `progress_percent` contra `estimated_duration_seconds` y limita el valor a `0..100`. La base se actualiza solo cuando el avance cambia al menos 1% o aproximadamente cada segundo. `completed` queda en 100%; `failed` conserva el ultimo progreso alcanzado.

La interfaz consulta `/api/video/exports.php?video_id=ID` cada 2-3 segundos solo mientras existan jobs `pending` o `processing`; al quedar todos en `completed` o `failed`, detiene el polling. No se usan WebSockets.

La descarga segura se sirve desde `/video/export/download.php?id=ID`. El endpoint exige sesion, busca `video_export_jobs.id + user_id`, requiere `status = completed`, resuelve `output_path` desde BD, valida que el `realpath` permanezca dentro de `storage/video/exports` y transmite el MP4 por chunks con `Content-Disposition: attachment`. No acepta rutas, filenames fisicos ni `user_id` desde el navegador.

Las exportaciones terminadas pueden eliminarse manualmente desde la UI mediante `POST` con CSRF a `/api/video/exports.php?action=delete&id=ID`. El backend valida ownership, borra solo el MP4 exportado y elimina el job con su snapshot en cascada. Nunca borra el original ni `video_cut_points` / `video_edit_segments`.

Si FFmpeg o FFprobe fallan, el job queda `failed`, se guarda un error controlado y se eliminan temporales incompletos. La exportacion no reemplaza, modifica ni borra el archivo original, ni modifica `video_edit_segments`.

`workers/cleanup-video-files.php` corre diariamente por Cron. Elimina temporales antiguos de `storage/video/temp` con patron controlado, sin tocar temporales de jobs `processing`, y elimina exports `completed` cuyo `expires_at` ya vencio. La retencion se configura con `VIDEO_EXPORT_RETENTION_DAYS` y por defecto es 30 dias. La limpieza nunca aplica a `storage/video/uploads`; los originales solo se eliminan por accion explicita del usuario.

El worker conserva Cron para recordatorios, metadata de video, exportaciones y limpieza sin Redis, colas externas ni servicios nuevos. `workers/check-video-environment.php` valida desde CLI que FFmpeg, FFprobe y los directorios de video esten disponibles y sean escribibles.

La ejecucion de FFprobe/FFmpeg no debe construir comandos concatenando valores recibidos del usuario. Las operaciones usan argumentos controlados, rutas generadas internamente y allowlists de acciones permitidas. El navegador nunca debe poder enviar comandos FFmpeg arbitrarios ni rutas del filesystem.

## Video y transcripcion local

La transcripcion local con Whisper fue retirada en la fase 11.2 para preparar el despliegue ARM64 en Oracle Cloud. Ya no hay UI, rutas HTTP, API, workers, cron, comandos Make, variables de entorno ni notificaciones activas para transcribir videos.

Las tablas historicas `video_transcriptions` y `video_transcription_segments` se conservan en base de datos por trazabilidad y compatibilidad de migraciones. La fase 11.2 no borra tablas ni datos de transcripcion, y tampoco agrega migraciones destructivas.

## Gastos

Gastos es un modulo de organizacion financiera personal. La Fase 10.1 implementa modelo de datos, repositorios, servicios de dominio y pruebas. La Fase 10.2 agrega la pantalla de Configuracion para administrar categorias, medios de pago, servicios habituales y los medios permitidos por servicio. La Fase 10.3 agrega el CRUD mensual para registrar gastos concretos por periodo. La Fase 10.4 genera gastos recurrentes. La Fase 10.5 muestra el resumen mensual. La Fase 10.6 integra Gastos con el centro general de Notificaciones. Aun no muestra graficos historicos, no importa movimientos bancarios, no define presupuestos y no envia email, Push, SMS ni WhatsApp.

El flujo conceptual es:

```text
Category
-> Expense Service
<-> Payment Methods
-> Recurring Rule
-> Recurring Adjustment
-> Monthly Expense
```

`ExpenseService` en el modelo de datos representa algo configurable que el usuario suele pagar, como Aguas Andinas, Spotify, WOM, Entel, Vespucio Sur o Dividendo. No es el pago mensual concreto. Puede tener categoria opcional, monto CLP sugerido opcional, notas y estado activo/inactivo. Una recurrencia vive en `expense_recurring_rules`, siempre asociada a un servicio del mismo usuario, y genera `expenses`: no existe un sistema paralelo de gastos.

La navegacion autenticada muestra una unica entrada principal `Gastos`, sin submenu. La ruta real actual es:

```text
/index.php?section=expenses
```

Esa entrada abre el mes actual. La pantalla usa navegacion interna:

```text
Gastos
-> Mes actual
-> Configuracion
```

El mes se selecciona con `month=YYYY-MM`, por ejemplo:

```text
/index.php?section=expenses&month=2026-08
```

Si el mes es invalido, se vuelve al mes local actual usando `America/Santiago`. La configuracion conserva las rutas equivalentes de 10.2 mediante tabs internas:

```text
Configuracion
-> Categorias
-> Medios de pago
-> Servicios
```

Las operaciones de configuracion usan la API interna autenticada:

```text
/api/expenses/configuration.php
```

`GET` devuelve solo la configuracion del usuario autenticado. Las escrituras usan `POST` con CSRF y acciones controladas para crear, actualizar, desactivar y reactivar categorias, medios de pago y servicios. El navegador nunca envia ni controla `user_id`.

Desde la pestana Servicios se configura la recurrencia del servicio. El flujo es:

```text
Expense Service
-> Regla recurrente
-> Ajuste mensual opcional
-> Worker
-> Expense mensual pendiente
```

La version inicial soporta `monthly` con `interval_value >= 1`, lo que permite cada 1, 2 o mas meses sin agregar frecuencias nuevas. `day_of_month` define el vencimiento local del gasto generado. Si el mes no tiene ese dia, `ExpenseDateHelper::resolveDayOfMonth()` usa el ultimo dia valido: 31 de febrero pasa a 28 o 29, y 31 de abril pasa a 30.

Las operaciones mensuales usan la API interna autenticada:

```text
/api/expenses/expenses.php
```

Las escrituras son `POST` con CSRF. Las acciones permitidas son crear, actualizar, marcar pagado, volver a pendiente, cancelar y reactivar. No hay eliminacion fisica normal en 10.3 para conservar historial financiero.

`Expense` representa el gasto real de un periodo mensual. Puede venir de un `expense_services.id` o ser ad-hoc con `service_id NULL`, como Reparacion notebook. Conserva `description` como nombre historico visible, `amount_clp` como entero CLP nullable y `category_id` del momento de creacion cuando corresponde, para que cambiar luego la categoria del servicio no destruya el significado historico de gastos ya creados. Si el gasto nace desde un servicio, la UI sugiere descripcion, categoria, monto habitual, medios permitidos y medio predeterminado, pero el registro mensual guarda su propio snapshot editable.

`amount_clp = NULL` significa monto pendiente, no cero. Esto permite generar gastos recurrentes variables como agua, gas, electricidad o autopistas antes de conocer la boleta. Los KPIs monetarios excluyen esos montos y muestran la cantidad pendiente de completar.

Los medios de pago se modelan como identificadores conceptuales del usuario: Visa Santander, Cuenta Santander, PAT Santander, Mercado Pago, WebPay, efectivo o transferencia. El modulo no guarda numero completo de tarjeta, CVV, PIN, claves ni numeros bancarios sensibles. `ExpensePaymentMethodService` rechaza campos sensibles explicitos y textos con senales basicas de datos secretos.

La prioridad de defaults al generar un gasto recurrente es:

- monto de la regla;
- monto del servicio;
- `NULL` como monto pendiente.

Para categoria se usa categoria de la regla, luego categoria actual del servicio y finalmente `NULL`. La categoria queda como snapshot del expense y cambios posteriores no reescriben historico. Para medio de pago se usa el medio de la regla solo si esta activo; si no, se intenta el default activo del servicio; si no hay, queda `NULL`.

Los ajustes mensuales de recurrencia viven en `expense_recurring_adjustments` y permiten flexibilidad sin crear otro sistema de pagos. Para un servicio recurrente se puede saltar un periodo puntual, por ejemplo congelar o suspender un cobro, o generar ese periodo con cambios especificos: monto especial, descuento, monto `0`, fecha limite aplazada a otro mes, categoria, medio de pago, descripcion y notas. El ajuste se aplica solo si el expense de ese periodo aun no fue generado; no modifica gastos historicos ya existentes.

Para dejar de pagar un servicio hacia adelante se debe desactivar la regla recurrente o definir `ends_on`; para meses puntuales se usa un ajuste `skip`. Si el usuario cambia de proveedor, el modelo esperado es desactivar o finalizar el servicio/regla anterior y crear un servicio nuevo para el reemplazo, preservando ambos historiales.

Un servicio configurable puede tener varios medios de pago permitidos mediante `expense_service_payment_methods`:

```text
Expense Service
<-> Payment Methods
   -> is_default opcional
```

Editar un servicio sincroniza la relacion completa en una transaccion: elimina relaciones quitadas, conserva las existentes y agrega las nuevas. Solo puede existir un medio predeterminado por servicio y debe estar dentro de los medios seleccionados. Si se quita el medio que era predeterminado y no se elige otro, el servicio queda sin default. El gasto mensual registra a lo mas un `payment_method_id` como medio realmente usado ese mes. Al elegir un servicio, la UI muestra primero sus medios permitidos y preselecciona el default cuando existe, pero puede usarse otro medio activo propio como excepcion mensual. No se implementan pagos divididos.

La UI de Servicios permite seleccionar 0..N medios mediante checkboxes/chips y un selector de predeterminado. Los medios desactivados no se ofrecen para nuevas asociaciones; si un servicio ya los tenia asociados, pueden mostrarse como inactivos para no borrarlos silenciosamente. Las categorias desactivadas no se ofrecen normalmente para nuevas asignaciones, pero un servicio existente puede conservar su categoria actual.

Las fechas de Gastos son fechas comerciales locales:

- `period_month` es `DATE` y siempre usa el primer dia del mes, por ejemplo `2026-08-01` para agosto 2026.
- `due_on` es la fecha limite local/comercial.
- `paid_on` es la fecha real local/comercial de pago.

Estos campos no se convierten a UTC porque no son instantes horarios. El calculo de vencido usa "hoy" en `America/Santiago`.

`workers/process-recurring-expenses.php` corre desde Cron una vez al dia y tambien puede ejecutarse manualmente. Genera el gasto del mes al comenzar el mes, pero si el equipo estuvo apagado hace catch-up cuando vuelva a correr. Al crear una regla a mitad de mes desde la UI, la API ejecuta una pasada acotada para esa regla y crea el gasto del mes actual si corresponde. Para evitar historia masiva, una regla nueva con `starts_on` antiguo inicializa `next_generation_on` en el mes local actual; el worker solo hace catch-up desde ese puntero de la regla.

La idempotencia no depende solo de un `SELECT` previo: `expenses.recurring_rule_id` apunta a `expense_recurring_rules.id` y existe `UNIQUE (recurring_rule_id, period_month)`. Por eso ejecutar el worker varias veces puede saltar existentes, pero no duplica Spotify septiembre.

Los gastos recurrentes siempre nacen como `pending` y `paid_on = NULL`, incluso si el servicio usa PAT. Mi Central no asume que el cobro ocurrio. Editar un expense generado no modifica la regla. Editar la regla no modifica expenses historicos ni el expense del mes si ya fue generado; aplica a generaciones futuras.

El estado persistido de un gasto mensual usa solo estados base: `pending`, `paid` y `cancelled`. `overdue` no se persiste; se deriva cuando `status = pending` y `due_on < hoy` en `America/Santiago`. Esto evita estados que puedan quedar desactualizados.

El dashboard mensual de Gastos no persiste totales. Usa el flujo:

```text
expenses
-> ExpenseMonthlySummaryService
-> Monthly Expense Dashboard
```

`ExpenseMonthlySummaryService` recibe `user_id` y `period_month`, y calcula con consultas preparadas acotadas al usuario:

- total conocido de expenses no cancelados con `amount_clp IS NOT NULL`;
- pagado conocido;
- pendiente conocido;
- vencido conocido;
- cantidad de expenses no cancelados con monto pendiente;
- porcentaje pagado sobre montos conocidos;
- breakdown derivado por categoria;
- breakdown derivado por medio de pago real del expense;
- proximos vencimientos pendientes;
- vencidos pendientes.

`amount_clp = NULL` nunca se presenta como `$0`: se muestra como `Monto pendiente` y queda fuera de sumas monetarias. Esos expenses si cuentan como compromisos del mes y aparecen en categoria, medio de pago, proximos vencimientos o vencidos cuando corresponda.

Los breakdowns por categoria y por medio de pago son derivados para el mes seleccionado. Las categorias o medios inactivos siguen apareciendo si existen gastos historicos asociados. Los expenses sin categoria aparecen como `Sin categoria`; los expenses sin payment method aparecen como `Sin medio definido`.

Los KPIs principales siempre representan todo el mes seleccionado. Los filtros de lista por estado, categoria, servicio, medio de pago y busqueda solo afectan el listado y el bloque `Resultados`; no reemplazan el resumen mensual superior.

El historial de Gastos tampoco persiste agregados. Usa el flujo:

```text
Expenses
-> ExpenseHistoryService
-> Historical Analysis
```

`ExpenseHistoryService` recibe `user_id`, un rango predefinido y filtros opcionales por categoria, servicio o medio de pago. Los rangos soportados son ultimos 3 meses, ultimos 6 meses, ultimos 12 meses, ano actual y ano anterior. Ultimos 12 meses es una ventana movil; ano actual usa enero hasta el mes local actual, sin meses futuros; ano anterior usa enero-diciembre completo.

El analisis se deriva exclusivamente de `expenses`, no de montos default de servicios. Los expenses recurrentes y manuales se analizan igual porque ambos son gastos mensuales concretos. Los cancelados quedan fuera de totales, graficos y breakdowns. `amount_clp = NULL` significa monto desconocido: no suma como cero, pero incrementa indicadores de datos parciales por mes y por periodo.

La serie mensual incluye todos los meses del rango aunque no tengan gastos. Esto permite que el promedio mensual divida por el rango completo seleccionado y que los graficos no oculten meses en cero. La comparacion con el mes anterior se calcula dinamicamente sobre montos conocidos; si el mes anterior es cero no se muestra porcentaje infinito, solo una comparacion absoluta o estado sin variacion.

Los breakdowns historicos se calculan con consultas agregadas acotadas por usuario:

- total registrado conocido y total pagado;
- promedio mensual;
- mes con mayor y menor gasto conocido;
- evolucion mensual;
- gastos por categoria, incluyendo `Sin categoria` y categorias inactivas con historial;
- servicios con mayor gasto, solo para expenses con `service_id`;
- uso por medio de pago real del expense, incluyendo `Sin medio definido` y medios inactivos con historial;
- pagado, pendiente, vencido y montos pendientes segun el estado actual del expense.

Los filtros historicos se validan contra ownership en backend. Si el navegador envia una categoria, servicio o medio de otro usuario, ese filtro no se aplica ni revela datos ajenos. La URL conserva el estado del analisis con parametros como `tab=history&range=6m&category_id=ID`.

La integracion con Notificaciones reutiliza exclusivamente la tabla `notifications` y el centro general:

```text
Expenses
-> ExpenseNotificationService / workers/process-expense-notifications.php
-> notifications
-> Notification Center
```

`ExpenseNotificationService` usa `America/Santiago` para calcular hoy, manana, fechas vencidas y la semana funcional. Los timestamps tecnicos de la notificacion siguen guardandose como UTC mediante la politica existente.

Las reglas generadas son:

- `expense_due_soon`: gastos `pending` que vencen manana;
- `expense_due_today`: gastos `pending` que vencen hoy;
- `expense_overdue`: gastos `pending` con `due_on < hoy`;
- `expense_missing_amount`: gastos `pending`, con `amount_clp NULL`, que vencen en 3 dias;
- `expense_weekly_summary`: resumen compacto los lunes para gastos `pending` que vencen entre hoy y hoy+6.

No se generan avisos para gastos `paid`, `cancelled` ni gastos sin `due_on`. Crear o editar un gasto no dispara notificaciones inmediatas; el worker solo crea avisos cuando aparece una situacion temporal relevante. Si un gasto se paga despues de que exista una notificacion, la notificacion queda como historial y al abrirla se navega al expense actual.

La idempotencia usa `notifications.dedupe_key`, sin contaminar `expenses` con flags. Las claves son:

- `expense_due_tomorrow:<expense_id>:<date>`;
- `expense_due_today:<expense_id>:<date>`;
- `expense_overdue:<expense_id>:<due_on>`;
- `expense_missing_amount:<expense_id>:<period>`;
- `expense_weekly_summary:<user_id>:YYYY-Www`.

Las notificaciones puntuales guardan `source_module = expenses`, `entity_type = expense` y `entity_id = expenses.id`. `NotificationService` resuelve esas entidades a `/index.php?section=expenses&month=YYYY-MM&expense=ID#expense-ID`, sin guardar URLs arbitrarias desde el frontend. El resumen semanal no apunta a una entidad individual, pero aparece mezclado cronologicamente con recordatorios, organizacion, proyectos y video.

Las cuotas se guardan como datos estructurados con `installment_current` e `installment_total`, no como texto `3/12`. Ambos son `NULL` cuando no hay cuotas; si hay cuotas, ambos deben existir y cumplir `current >= 1`, `total >= 1` y `current <= total`.

El backend vive en `modules/Expenses/` y mantiene el mismo estilo modular del resto de Mi Central:

```text
servicio de dominio
-> repositorio del modulo
-> PDO / MariaDB
```

El flujo mensual de 10.3 es:

```text
Gastos / Mes actual
-> ExpenseService
-> ExpenseRepository
-> expenses
```

El flujo recurrente de 10.4 es:

```text
ExpenseService
-> ExpenseRecurringRuleService
-> RecurringExpenseWorker
-> ExpenseRepository / expenses
```

`ExpenseRepository` lista gastos mensuales con `LEFT JOIN` a servicios, categorias y medios de pago para evitar N+1 y conservar nombres visibles aunque esas entidades hayan sido desactivadas despues.

Las entidades personales de Gastos pertenecen siempre al usuario autenticado: categorias, medios de pago, servicios configurables y gastos mensuales. La capa de servicio valida ownership antes de asociar `category_id`, `service_id` o `payment_method_id`; el navegador no debe enviar ni controlar `user_id`.

## Docker Compose

Todo el proyecto debe poder ejecutarse mediante Docker Compose.

El entorno previsto incluye:

- Apache con PHP.
- MariaDB.
- FFmpeg disponible para procesos PHP/CLI.
- Cron para workers programados.

`compose.yaml` conserva el flujo de desarrollo local con bind mount del repositorio. `compose.prod.yaml` queda preparado para Oracle Cloud ARM64 sin bind mount del repo, con `restart: unless-stopped`, healthchecks para `web`, `db` y `worker`, y volumenes persistentes separados para MariaDB, logs/cache/temp global, uploads, jobs, exports y temporales de video. HTTPS, dominio real, proxy inverso y despliegue GitHub quedan fuera de la fase 11.2.

## Dependencias

Se deben evitar dependencias nuevas salvo que exista una justificacion tecnica clara.

La preferencia inicial es resolver con PHP nativo, JavaScript nativo, MariaDB, FFmpeg y herramientas locales.

## Convenciones iniciales de estructura

- `public/` sera el unico directorio web publico.
- `app/` contendra codigo compartido del nucleo de la aplicacion.
- `app/Views/` contendra componentes visuales PHP reutilizables.
- `modules/` contendra funcionalidades de negocio separadas por modulo.
- `api/` contendra endpoints internos PHP/JSON cuando se implementen.
- `workers/` contendra procesos PHP CLI.
- `database/` contendra migraciones y seeds.
- `storage/` nunca debe exponerse directamente desde Apache.
- `config/` contendra configuracion de aplicacion.
- `docker/` contendra configuracion especifica de contenedores.
