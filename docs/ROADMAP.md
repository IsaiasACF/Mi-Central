# Roadmap

Este roadmap documenta fases previstas. No implica autorizacion para avanzar automaticamente ni para implementar funcionalidades sin una solicitud explicita.

## Fase 0 - Fundaciones

- Contexto del proyecto.
- Documentacion base.
- Reglas para agentes.
- Arquitectura inicial.
- Preparacion futura del entorno Docker.

## Fase 1 - Dashboard

- Dashboard principal privado.
- Resumen de modulos.
- Navegacion base.

## Fase 2 - Organizacion

- Vista unica de Organizacion con filtros por espacio, estado, prioridad y tiempo.
- Bandeja rapida como tareas sin espacio.
- Tareas CRUD.
- Proyectos.
- Notas CRUD, separadas de tareas/proyectos y con filtro simple por espacio.
- Calendario funcional mensual/semanal como vista de tareas/proyectos existentes.
- Eventos no se implementan por ahora como tipo separado: las tareas soportan inicio, fin y vencimiento mediante `starts_at`, `ends_at` y `due_at`.
- `organization_events` se conserva sin uso para posible necesidad futura.
- Espacios:
  - Universidad.
  - Amigos.
  - Personal.
  - Trabajo.

## Fase 3 - Recordatorios

- Modelo basico de recordatorios con fecha y hora.
- Gestion CRUD de recordatorios independientes o asociados a tareas/proyectos.
- Estados pendientes, completados y descartados.
- Integracion minima en Organizacion y Dashboard.
- Recurrencias diaria, semanal, mensual y anual con intervalo y fecha limite opcional.
- Worker Cron para generar notificaciones internas pendientes.
- Anticipaciones.
- Centro de notificaciones.

La fase actual no implementa aun envio automatico externo, Push, PWA ni centro completo de notificaciones.

## Fase 4 - Amigos en la U

- Amigos.
- Universidad.
- Campus.
- Horarios.
- Ramos.
- Salas.
- Vista Ahora.
- Vista Hoy.
- Vista semanal.
- Excepciones.

El sistema no realizara seguimiento GPS ni ubicacion real.

La ubicacion de un amigo sera unicamente inferida segun el horario ingresado manualmente.

## Fase 5 - Coincidencias

- Comparar horarios.
- Identificar bloques academicos simultaneos con amigos.
- Considerar campus.
- Relacionar coincidencias con actividades organizadas con amigos.

## Fase 6 - Editor de video

- Subir video.
- Previsualizar.
- Cortar.
- Dividir en segmentos.
- Eliminar segmentos.
- Reordenarlos.
- Unirlos.
- Exportar MP4.
- Descargar resultado.
- Eliminar archivos temporales automaticamente.

El procesamiento se realizara localmente mediante FFmpeg.

## Fase 7 - Descuentos manuales

- Promociones.
- Comercios.
- Categorias.
- Tarjetas.
- Bancos.
- Operadores.
- Beneficios personales.
- Fechas.
- Condiciones.
- Favoritos.
- Promociones compatibles con beneficios configurados.

## Fase 8 - Recolectores

- Fuentes independientes.
- Recolectores propios cuando sea razonable.
- API oficial cuando resulte tecnicamente mas conveniente.
- Normalizacion.
- Deduplicacion.
- Logs.
- Ejecucion programada.

## Fase 9 - PWA/notificaciones

- Manifest.
- Service worker.
- Instalacion en PC y telefono.
- Notificaciones cuando resulte viable.
