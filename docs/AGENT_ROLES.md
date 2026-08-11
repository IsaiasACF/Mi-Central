# Roles de Agentes

Los roles de este documento representan responsabilidades de trabajo y revision dentro de Mi Central.

No es necesario ejecutar todos los roles simultaneamente. Cada tarea debe activar solo las responsabilidades relevantes.

## architect

Responsable de:

- Arquitectura general.
- Separacion de modulos.
- Dependencias.
- Decisiones estructurales.
- Evitar sobreingenieria.

Limites:

- No debe introducir complejidad sin necesidad demostrable.
- No debe convertir el proyecto en microservicios.
- No debe proponer frameworks o dependencias nuevas sin justificacion tecnica clara.

## backend

Responsable de:

- PHP.
- Servicios internos.
- API interna.
- Autenticacion.
- Logica de aplicacion.
- Workers.

Limites:

- Debe priorizar PHP nativo.
- Debe usar PDO y consultas preparadas.
- No debe usar Node.js como backend.
- No debe implementar funcionalidades no solicitadas.

## frontend

Responsable de:

- HTML.
- CSS.
- JavaScript nativo.
- Responsive.
- Experiencia de usuario.

Limites:

- No debe introducir React ni Vue inicialmente.
- No debe disenar interfaces completas si la tarea solo pide documentacion o backend.
- Debe mantener JavaScript simple y acotado.

## database

Responsable de:

- MariaDB.
- Migraciones.
- Relaciones.
- Indices.
- Integridad de datos.
- Consultas.

Limites:

- No debe disenar todas las tablas definitivas antes de que las funcionalidades lo requieran.
- Debe evitar duplicacion de informacion entre modulos.
- Debe preservar integridad referencial.

## qa-security

Responsable de:

- Pruebas.
- Validaciones.
- Regresiones.
- Seguridad.
- Uploads.
- CSRF.
- Sesiones.
- SQL injection.

Limites:

- No debe asumir que una validacion frontend es suficiente.
- No debe permitir interpolacion directa de entradas de usuario en SQL.
- Debe revisar especialmente modulos con archivos, autenticacion o datos sensibles.

## devops

Responsable de:

- Docker.
- Docker Compose.
- Apache.
- PHP.
- FFmpeg.
- Cron.
- Configuracion.
- Entorno WSL.

Limites:

- Debe priorizar instrucciones Linux/WSL.
- No debe depender de comandos PowerShell salvo necesidad real.
- Debe mantener el proyecto ejecutable mediante Docker Compose.
- No debe guardar secretos reales en archivos versionados.
