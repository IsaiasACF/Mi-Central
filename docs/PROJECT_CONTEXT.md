# Mi Central - Contexto del Proyecto

## Identidad del proyecto

- Nombre: **Mi Central**
- Proposito: aplicacion web privada y personal para agrupar pequenas herramientas y modulos de organizacion.
- Tipo de arquitectura: aplicacion monolitica modular.
- Estado inicial: proyecto nuevo, independiente y aislado.

Este repositorio es la unica fuente de verdad para Mi Central.

## Independencia total

Mi Central no esta relacionado con otros proyectos, repositorios o aplicaciones.

No se deben reutilizar arquitectura, nombres, configuraciones, bases de datos, variables, rutas, convenciones ni codigo de otros proyectos salvo que aparezcan explicitamente dentro de este repositorio.

Todo contexto valido del proyecto debe estar documentado dentro de este repositorio.

## Entorno de desarrollo

- El equipo anfitrion utiliza Windows 11.
- La terminal principal de trabajo sera **WSL**.
- Todas las instrucciones de terminal, comandos, rutas y ejemplos deben priorizar Linux/WSL.
- Docker Desktop estara disponible desde WSL.
- Todo debe poder ejecutarse mediante Docker Compose.

Comandos preferidos para documentacion y ejemplos:

- `pwd`
- `ls`
- `cd`
- `mkdir`
- `cp`
- `mv`
- `rm`
- `grep`
- `find`
- `chmod`
- `docker`
- `docker compose`
- `git`

Evitar comandos especificos de PowerShell salvo que una tarea realmente lo requiera.

## Stack decidido

- PHP 8.5
- Apache
- MariaDB 11.8
- HTML5
- CSS3
- JavaScript nativo
- FFmpeg
- Docker Compose
- PHP CLI
- Cron

## Principios del proyecto

- Mantener una aplicacion monolitica modular.
- Priorizar soluciones autocontenidas.
- Mantener pocas dependencias.
- Evitar APIs externas cuando una solucion local sencilla sea suficiente.
- Permitir una API externa solo cuando reduzca considerablemente la complejidad y exista una justificacion tecnica clara.
- No usar React inicialmente.
- No usar Vue inicialmente.
- No usar Node.js como backend.
- No usar Laravel inicialmente.
- No usar microservicios.
- No usar Redis salvo necesidad futura demostrable.

## Arquitectura conceptual

Flujo principal:

```text
Navegador
-> Apache
-> PHP
-> MariaDB
```

Procesos auxiliares:

```text
PHP CLI / Cron
-> workers
-> FFmpeg / recolectores / recordatorios
```

## Modulos previstos

### Dashboard principal

Vista inicial privada para acceder y resumir los modulos principales.

### Organizacion personal

- Bandeja rapida.
- Tareas.
- Eventos.
- Notas.
- Proyectos.
- Espacios:
  - Universidad.
  - Amigos.
  - Personal.
  - Trabajo.

### Recordatorios

- Fechas.
- Horas.
- Anticipaciones.
- Recurrencias.
- Centro de notificaciones.

### Amigos en la U

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

### Coincidencias

- Comparar horarios.
- Identificar bloques academicos simultaneos con amigos.
- Considerar campus.
- Relacionar coincidencias con actividades organizadas con amigos.

### Editor basico de video

- Subir video.
- Previsualizar.
- Cortar.
- Dividir en segmentos.
- Eliminar segmentos.
- Reordenar segmentos.
- Unir segmentos.
- Exportar MP4.
- Descargar resultado.
- Eliminar archivos temporales automaticamente.

El procesamiento se realizara localmente mediante FFmpeg.

Flujo final de la Fase 6 de Video:

```text
upload
-> FFprobe
-> editor
-> cut points
-> segmentos
-> export job
-> FFmpeg
-> progreso
-> MP4
-> descarga autenticada
-> retencion/cleanup
```

### Descuentos

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
- Mostrar promociones compatibles con los beneficios configurados.

### Recoleccion automatica de descuentos

- Fuentes independientes.
- Recolectores propios cuando sea razonable.
- API oficial cuando resulte tecnicamente mas conveniente.
- Normalizacion.
- Deduplicacion.
- Logs.
- Ejecucion programada.

### PWA y notificaciones

- Manifest.
- Service worker.
- Instalacion en PC y telefono.
- Notificaciones cuando resulte viable.

## Roles definidos

- `architect`
- `backend`
- `frontend`
- `database`
- `qa-security`
- `devops`

Estos roles representan responsabilidades de trabajo y revision. No es necesario ejecutarlos todos simultaneamente.
