# AGENTS.md

## Proyecto

Este repositorio corresponde exclusivamente al proyecto **Mi Central**.

Mi Central es una aplicacion web privada y personal, monolitica modular, destinada a agrupar herramientas pequenas de organizacion personal.

Este proyecto es independiente de cualquier otro repositorio, aplicacion o contexto previo. No se deben reutilizar arquitecturas, nombres, configuraciones, bases de datos, rutas, convenciones, variables ni codigo de otros proyectos salvo que aparezcan explicitamente dentro de este repositorio.

## Reglas antes de modificar codigo

Antes de modificar codigo, configuracion o documentacion tecnica, Codex debe:

1. Leer `docs/PROJECT_CONTEXT.md`.
2. Leer la documentacion relacionada con la tarea concreta.
3. Comprobar que esta trabajando dentro del repositorio de Mi Central.
4. Usar solo la documentacion y los archivos de este repositorio como fuente de verdad del proyecto.
5. No utilizar conocimiento, configuraciones ni decisiones de otros proyectos como base para cambios en Mi Central.

## Entorno de trabajo

- El equipo anfitrion usa Windows 11.
- La terminal principal de trabajo sera WSL.
- Las instrucciones de terminal deben priorizar Linux/WSL.
- Docker Desktop estara disponible desde WSL.
- El proyecto debe mantener compatibilidad con Docker Compose.

Comandos preferidos en ejemplos e instrucciones:

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

Evitar comandos especificos de PowerShell salvo necesidad real.

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

## Principios de implementacion

- Realizar cambios pequenos y acotados.
- No implementar funcionalidades no solicitadas.
- No avanzar automaticamente hacia el siguiente punto del roadmap.
- No modificar modulos ajenos innecesariamente.
- Evitar dependencias nuevas salvo justificacion tecnica clara.
- Priorizar PHP nativo y JavaScript nativo.
- No usar React inicialmente.
- No usar Vue inicialmente.
- No usar Node.js como backend.
- No usar Laravel inicialmente.
- No usar microservicios.
- No usar Redis salvo necesidad futura demostrable.
- Evitar APIs externas cuando una solucion local sencilla sea suficiente.
- Usar APIs externas solo cuando reduzcan considerablemente la complejidad y exista una justificacion tecnica clara.
- Priorizar soluciones autocontenidas.
- Separar responsabilidades.
- Documentar decisiones arquitectonicas relevantes.

## Reglas tecnicas

- Usar PDO para acceso a base de datos.
- Usar consultas preparadas.
- Mantener integridad referencial en MariaDB.
- Mantener compatibilidad con Docker Compose.
- Ejecutar las pruebas aplicables despues de cada cambio.
- No guardar secretos en Git.
- No crear credenciales reales dentro del repositorio.
- Gestionar secretos mediante variables de entorno.

## Seguridad minima esperada

- Validar entrada en servidor.
- Proteger formularios sensibles con CSRF.
- Usar hashing seguro para contrasenas cuando se implemente autenticacion.
- Proteger sesiones.
- Tratar uploads como datos no confiables.
- Evitar SQL injection mediante PDO y consultas preparadas.
- Aplicar minimos privilegios.

## Roles de trabajo y revision

Los roles definidos para el proyecto son:

- `architect`
- `backend`
- `frontend`
- `database`
- `qa-security`
- `devops`

Estos roles representan responsabilidades de trabajo y revision. No es necesario ejecutarlos todos simultaneamente.

Ver `docs/AGENT_ROLES.md` para el detalle de responsabilidades y limites.
