# Importacion JSON de horarios

Este formato permite importar bloques semanales al modulo Horarios.

El JSON no decide el propietario del horario. El destino se selecciona en la interfaz de Mi Central:

- Mi horario;
- un amigo activo propio.

No incluir `user_id` ni `friend_id` en el JSON.

## Estructura

```json
{
  "version": 1,
  "schedule": [
    {
      "weekday": "monday",
      "starts_at": "08:30",
      "ends_at": "10:00",
      "course_name": "Bases de Datos",
      "course_code": "INF-210",
      "room": "B201",
      "campus": "San Joaquin",
      "valid_from": "2026-08-01",
      "valid_until": "2026-12-20"
    }
  ]
}
```

## Campos

Raiz:

- `version`: obligatorio. Actualmente debe ser `1`.
- `schedule`: obligatorio. Array de bloques. Maximo 100 bloques por importacion.

Campos obligatorios por bloque:

- `weekday`: dia semanal en ingles.
- `starts_at`: hora de inicio en formato `HH:MM`.
- `ends_at`: hora de termino en formato `HH:MM`.
- `course_name`: nombre del ramo o bloque.

Campos opcionales por bloque:

- `course_code`;
- `room`;
- `campus`;
- `valid_from`;
- `valid_until`.

## Dias Permitidos

`weekday` solo puede ser:

- `monday`;
- `tuesday`;
- `wednesday`;
- `thursday`;
- `friday`;
- `saturday`;
- `sunday`.

## Reglas

- `starts_at` y `ends_at` usan `HH:MM`.
- `ends_at` debe ser posterior a `starts_at`.
- `valid_from` y `valid_until`, cuando existan, usan `YYYY-MM-DD`.
- `valid_until` no puede ser anterior a `valid_from`.
- Los textos deben respetar longitudes razonables de Mi Central.
- Los horarios son datos locales academicos en `America/Santiago`; no se convierten a UTC.
- Mi Central valida todo antes de guardar y no inserta parcialmente si hay errores.
- Los bloques aparentemente duplicados se omiten por defecto al confirmar la importacion.

## Ejemplo Completo

```json
{
  "version": 1,
  "schedule": [
    {
      "weekday": "monday",
      "starts_at": "08:30",
      "ends_at": "10:00",
      "course_name": "Bases de Datos",
      "course_code": "INF-210",
      "room": "B201",
      "campus": "San Joaquin",
      "valid_from": "2026-08-01",
      "valid_until": "2026-12-20"
    },
    {
      "weekday": "wednesday",
      "starts_at": "12:00",
      "ends_at": "13:30",
      "course_name": "Fisica",
      "room": "A102"
    }
  ]
}
```
