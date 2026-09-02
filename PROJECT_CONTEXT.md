# Mi Central - Contexto del Proyecto

Este archivo identifica inequívocamente este repositorio como el proyecto **Mi Central**.

La referencia principal de contexto operativo para agentes y tareas tecnicas esta en `docs/PROJECT_CONTEXT.md`. Este archivo raiz existe para que la identidad del proyecto sea visible desde el primer nivel del repositorio.

## Identidad

- Nombre: **Mi Central**
- Tipo: aplicacion web privada y personal.
- Naturaleza: proyecto independiente, nuevo y aislado.
- Objetivo: agrupar pequenas herramientas y modulos de organizacion personal en una aplicacion monolitica modular.

## Independencia

Mi Central no esta relacionado con otros proyectos, repositorios o aplicaciones.

No se deben reutilizar arquitectura, nombres, configuraciones, bases de datos, variables, rutas, convenciones ni codigo de otros proyectos salvo que aparezcan explicitamente dentro de este repositorio.

Todo contexto valido del proyecto debe quedar documentado dentro de este repositorio.

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

## Entorno

- Sistema anfitrion: Windows 11.
- Terminal principal: WSL.
- Docker Desktop disponible desde WSL.
- Las instrucciones de terminal deben priorizar Linux/WSL.

## Filosofia tecnica

- Monolito modular.
- Pocas dependencias.
- Soluciones autocontenidas.
- Sin frameworks frontend inicialmente.
- Sin Laravel inicialmente.
- Sin Node.js como backend.
- Sin microservicios.
- Sin Redis salvo necesidad futura demostrable.
- APIs externas solo con justificacion tecnica clara.
