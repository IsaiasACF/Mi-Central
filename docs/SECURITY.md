# Seguridad

## Principios iniciales

Mi Central sera una aplicacion privada y personal, pero debe tratarse como una aplicacion web real expuesta a riesgos comunes.

La seguridad debe considerarse desde el inicio, incluso cuando una funcionalidad todavia no este implementada.

## Autenticacion

La aplicacion requiere autenticacion para acceder a la pagina principal.

El formulario de login vive en `public/login.php`. El registro publico vive en `public/register.php` y crea cuentas activas inmediatamente. El flujo es:

```text
Registro publico
-> usuario activo
-> login
-> sesion
-> datos aislados por user_id
-> login permitido
```

`is_active` controla si una cuenta puede iniciar sesion despues de validar username y contrasena:

- `is_active = 1`: cuenta puede iniciar sesion;
- `is_active = 0`: cuenta desactivada, sin login.

Si existen columnas heredadas como `approval_status`, `approved_at`, `approved_by_user_id` o `rejected_at`, se conservan por compatibilidad de migraciones y trazabilidad historica, pero ya no controlan el acceso. Las cuentas nuevas se guardan con `approval_status = approved` cuando esa columna existe.

La administracion de usuarios esta dentro de `/index.php?section=settings&tab=users` y requiere `is_admin = 1` en una cuenta activa. No se hardcodean usernames. El comando CLI `make user-admin USERNAME=<username>` marca como administrador una cuenta existente; no crea cuentas nuevas. Esta administracion permite desactivar/reactivar usuarios, no aprobar registros.

Los mensajes de fallo de login siguen evitando revelar si el username existe o si la contrasena fue incorrecta. Solo despues de verificar una contrasena correcta se muestra que una cuenta esta desactivada.

## Sesiones

Las sesiones se centralizan en `app/Http/Session.php`.

Configuracion base:

- nombre de sesion especifico: `SESSION_NAME`;
- cookies `HttpOnly`;
- `SameSite=Lax`;
- `Secure` configurable mediante `SESSION_SECURE`;
- regeneracion del identificador despues del login;
- destruccion completa al cerrar sesion;
- timeout de inactividad mediante `SESSION_IDLE_TIMEOUT`.
- validacion de que la cuenta de sesion siga `is_active = 1` en la siguiente peticion.

En desarrollo local con `http://localhost:8080`, `SESSION_SECURE` puede permanecer en `false`. En produccion con HTTPS debera configurarse en `true`.

## Password hashing

Las contrasenas deben almacenarse usando `password_hash()` con `PASSWORD_ARGON2ID`.

Nunca se deben almacenar contrasenas en texto plano.

La verificacion debe realizarse con `password_verify()`. Cuando corresponda, la aplicacion debe usar `password_needs_rehash()` para actualizar hashes antiguos de forma segura durante un login valido.

Si `PASSWORD_ARGON2ID` no esta disponible en la imagen PHP, no se debe degradar silenciosamente a un algoritmo debil.

## CSRF

Los formularios y acciones sensibles deben protegerse contra CSRF.

Las APIs internas que modifiquen estado deben considerar tokens CSRF o una proteccion equivalente.

La proteccion CSRF se centraliza en `app/Http/Csrf.php`.

Los tokens se generan con `random_bytes()`, se almacenan en sesion y se comparan con `hash_equals()`.

Actualmente se aplica como minimo a:

- login;
- registro;
- logout;
- acciones administrativas de usuarios;
- APIs internas que modifican estado.

El logout solo debe aceptar `POST`.

## Intentos de login y registro

Los intentos fallidos se registran en `login_attempts`.

Politica inicial:

- maximo 5 intentos fallidos;
- ventana de 15 minutos;
- evaluacion por username normalizado e IP directa del servidor web.

El registro publico reutiliza `login_attempts` con la clave interna `_register` para limitar solicitudes por IP durante la misma ventana. No se usa CAPTCHA ni servicios externos.

## Consultas preparadas

Todo acceso a base de datos debe usar PDO y consultas preparadas.

No se deben interpolar entradas de usuario directamente en SQL.

La autenticacion se implementa en `app/Services/AuthService.php` usando prepared statements.

## Validacion de entrada

Toda entrada del usuario debe validarse en el servidor.

La validacion del frontend puede mejorar la experiencia, pero no reemplaza la validacion del backend.

## Uploads seguros

Los archivos subidos deben tratarse como datos no confiables.

Se debe validar:

- Tipo permitido.
- Tamano maximo.
- Nombre seguro generado por la aplicacion.
- Ubicacion fuera del acceso publico directo cuando corresponda.
- Limpieza de archivos temporales.

El modulo de video requerira controles estrictos por su uso de archivos y FFmpeg.

## Proteccion de archivos privados

Los archivos privados no deben quedar expuestos directamente desde el directorio publico.

Las descargas deben pasar por controles de autorizacion cuando corresponda.

## Secretos

Los secretos deben gestionarse mediante variables de entorno.

No se deben guardar secretos en Git.

No se deben crear credenciales reales dentro del repositorio.

Los archivos de ejemplo deben usar valores ficticios y claramente marcados como ejemplos.

Los logs no deben contener contrasenas, hashes ni secretos.

## Headers HTTP

La aplicacion envia headers basicos desde `app/Http/SecurityHeaders.php`:

- `X-Content-Type-Options: nosniff`;
- `X-Frame-Options: DENY`;
- `Referrer-Policy`;
- `Content-Security-Policy` simple para la aplicacion actual.

No se habilita HSTS en localhost por HTTP. HSTS queda reservado para produccion con HTTPS.

## IP del cliente

Para limitar intentos de login se usa inicialmente `REMOTE_ADDR`.

No se confia automaticamente en `X-Forwarded-For` ni `X-Real-IP`, porque todavia no existe una configuracion documentada de proxy confiable para Mi Central.

## Minimos privilegios

Los servicios, usuarios de base de datos y permisos de archivos deben configurarse con privilegios minimos suficientes.

## HTTPS

Produccion debe usar HTTPS.

Las cookies sensibles y cualquier flujo autenticado deben configurarse considerando HTTPS en produccion.
