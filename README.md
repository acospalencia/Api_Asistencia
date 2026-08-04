# API de Asistencia para Bluehost

Versión PHP 8.2+ diseñada para Bluehost Shared Hosting. No necesita Composer,
terminal ni un proceso permanente.

Versión actual de la API: `2026.08.04.2`.

Producción:

```text
https://api.nordictech-corp.com/
https://api.nordictech-corp.com/api/v1/health
```

## Funcionalidades principales

- Autenticación JWT y contraseñas protegidas con `password_hash`.
- Gestión administrativa de usuarios, roles, asignaciones y ubicaciones.
- Rol `Administración` con panel administrativo y reportes globales de todos
  los técnicos, sin marcación de entrada o salida.
- Entradas y salidas con validación geográfica y reglas de horario.
- Días sin marcación administrables y validados también en el servidor.
- Misiones de jornada para supervisores y técnicos.
- Reportes por técnico o por todos los técnicos asignados.
- Horas extra por jornada y total individual por técnico.
- Notificaciones push y recordatorios ejecutados mediante cron.
- Rate limiting por dirección IP y límite de 1 MB por solicitud.

## Instalación con File Manager

1. En Bluehost selecciona PHP 8.2 o superior.
2. Dentro de `public_html`, crea la carpeta que utilizará la API, por ejemplo
   `api`.
3. Sube dentro de ella todo el contenido de esta carpeta, incluida `.htaccess`.
4. Copia `BD_credentials.example.php` como `BD_credentials.php`.
5. Completa las credenciales y cambia `JWT_SECRET` por una llave aleatoria de
   al menos 32 caracteres.
6. No publiques ni compartas `BD_credentials.php`.

También puedes colocar `BD_credentials.php` en `public_html`, un nivel arriba
de la API. La API busca ambas ubicaciones. La opción más segura es colocarlo
fuera de `public_html` y configurar `BD_CREDENTIALS_PATH` si el plan lo permite.

`BD_credentials.php` puede tener la estructura completa de `wp-config.php`.
La API lee las constantes `define()` como texto y nunca ejecuta el archivo ni
carga WordPress. Reconoce:

- `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` y `DB_CHARSET`.
- `JWT_SECRET` si la agregas.
- `AUTH_KEY` o `SECURE_AUTH_KEY` como respaldo para firmar los tokens.
- `JWT_ISSUER`, `JWT_AUDIENCE` y `JWT_EXPIRATION_MINUTES` si existen.

Si reutilizas el archivo completo proporcionado por WordPress, no necesitas
modificar su sección `ABSPATH` ni el `require_once` final, porque la API no los
ejecutará.

## Endpoints

- `POST /api/v1/auth/login`: recibe `username` y `password`.
- `POST /api/v1/auth/register`: crea los datos personales y el usuario,
  asigna un rol operativo y devuelve una sesión JWT.
- `GET /api/v1/account/me`: requiere `Authorization: Bearer TOKEN`.
- `GET /api/v1/locations`: devuelve las ubicaciones activas autorizadas.
- `POST /api/v1/attendance/check-in`: registra la entrada del usuario
  autenticado. Valida un radio máximo de 50 metros y evita entradas duplicadas
  durante la misma jornada. Después de las 8:00 a. m. exige un comentario que
  justifique la entrada tardía.
- `POST /api/v1/attendance/check-out`: registra la salida del usuario
  autenticado. Exige una entrada previa, valida un radio máximo de 50 metros,
  evita salidas duplicadas y solicita justificación antes de las 5:00 p. m.
- `GET /api/v1/attendance/availability/today`: indica si el técnico
  autenticado puede marcar asistencia durante la fecha actual.
- `GET /api/v1/attendance/overtime-report`: genera exclusivamente para el
  técnico autenticado su reporte quincenal de horas extra. Solo está
  disponible los días 10 y 25. El día 10 incluye del 25 del mes anterior al
  9 actual; el día 25 incluye del 10 al 23 actual.
- `GET /api/v1/admin/dashboard`: devuelve métricas, usuarios, roles,
  supervisores, técnicos y sus asignaciones. Requiere rol Administrador.
- `PUT /api/v1/admin/users/{id}/role`: cambia el rol de un usuario.
- `PATCH /api/v1/admin/users/{id}/status`: activa o desactiva una cuenta.
- `POST /api/v1/admin/supervisor-assignments`: asigna varios técnicos a un
  supervisor. Cada técnico conserva como máximo un supervisor activo.
- `DELETE /api/v1/admin/supervisor-assignments/{technicianId}`: retira la
  asignación activa de un técnico.
- `POST /api/v1/admin/locations`: crea una ubicación autorizada.
- `PUT /api/v1/admin/locations/{id}`: actualiza los datos, coordenadas y
  estado de una ubicación.
- `DELETE /api/v1/admin/locations/{id}`: elimina una ubicación sin
  marcaciones. Si ya posee historial de asistencia, la archiva para conservar
  la integridad de los registros.
- `GET /api/v1/admin/non-working-days`: consulta los días sin marcación.
- `POST /api/v1/admin/non-working-days`: registra o actualiza una fecha y su
  motivo. Solo admite la fecha actual o fechas futuras.
- `DELETE /api/v1/admin/non-working-days/{id}`: vuelve a habilitar una fecha
  para marcar asistencia.
- `GET /api/v1/supervisor/dashboard?date=YYYY-MM-DD`: devuelve únicamente los
  técnicos asignados al supervisor autenticado, junto con el estado y detalle
  de sus entradas y salidas. Considera tarde una entrada posterior a las
  8:00 a. m. y temprana una salida anterior a las 5:00 p. m.
- `GET /api/v1/supervisor/report?days=15&technicianId=123`: genera el
  historial independiente de supervisión. `days` admite de 1 a 365 y
  `technicianId` es opcional; al omitirlo incluye a todos los técnicos
  asignados. Devuelve entradas, salidas, indicadores de horario, ubicaciones,
  comentarios y las misiones del período con su estado y observaciones.
  También calcula por jornada y por técnico las horas extra acumuladas como
  el tiempo marcado antes de las 8:00 a. m. más el tiempo marcado después de
  las 5:00 p. m. No genera un total combinado entre técnicos: devuelve el
  total individual de cada técnico y el detalle de cada día.

Para asegurar que existan los roles `Administrador`, `Supervisor` y `Técnico`,
ejecuta una vez `database/admin_roles_setup.sql` desde phpMyAdmin. El mismo
archivo contiene una instrucción comentada para promover la primera cuenta
administrativa.

Antes de publicar la administración de días sin marcación, ejecuta una vez
`database/non_working_days_setup.sql` desde phpMyAdmin. La API comprueba esta
tabla tanto al consultar la disponibilidad como al intentar registrar una
entrada o salida, de modo que una versión antigua de la aplicación tampoco
puede omitir el bloqueo.

Si la carpeta se llama `api`, las URLs serán:

```text
https://tudominio.com/api/v1/auth/login
https://tudominio.com/api/v1/account/me
```

Las contraseñas de `Usuarios.password_hash` deben estar creadas mediante
`password_hash($password, PASSWORD_BCRYPT)`.

## Rate limiting

Todas las solicitudes HTTP se limitan por dirección IP antes de ejecutar los
controladores. Los contadores usan archivos temporales con bloqueo exclusivo,
por lo que funcionan entre los distintos procesos PHP de Bluehost sin
necesitar Redis.

- Límite global: 300 solicitudes por minuto.
- Lecturas: 180 por minuto.
- Escrituras: 60 por minuto.
- Inicio de sesión: 20 intentos cada 5 minutos.
- Registro: 10 solicitudes por hora.
- Ejecución HTTP del cron: 30 solicitudes por minuto.

Al superar un límite, la API responde con HTTP `429`, código
`rate_limit_exceeded` y encabezado `Retry-After`. El comando CLI del cron no
consume estos límites. Además, Apache rechaza cuerpos mayores de 1 MB antes de
iniciar PHP.

Este control reduce fuerza bruta y abuso por cliente, pero no reemplaza un
WAF o CDN para ataques distribuidos de gran volumen.

## Eventos de la jornada

Antes de publicar el módulo, ejecuta una vez
`database/workday_events_setup.sql`. El script agrega los mensajes del
supervisor, el índice de estados y la tabla de dispositivos.

Endpoints agregados:

- `GET|POST /api/v1/supervisor/workday-events`
- `PATCH /api/v1/supervisor/workday-events/{id}/cancel`
- `POST /api/v1/supervisor/workday-events/{id}/request-cancellation`
- `GET /api/v1/workday-events/today`
- `PATCH /api/v1/workday-events/{id}/comment`
- `POST /api/v1/workday-events/{id}/start`
- `POST /api/v1/workday-events/{id}/complete`
- `POST /api/v1/workday-events/{id}/cancel`
- `POST /api/v1/devices/register`
- `POST /api/v1/cron/attendance-reminders`

La salida de asistencia queda bloqueada mientras exista una misión
`EN_TRAYECTO`.

## Notificaciones push

El aviso dentro de la aplicación funciona aunque Firebase no esté
configurado. Para activar el push:

1. Crea una aplicación Android en Firebase con el mismo `ApplicationId`.
2. Coloca `google-services.json` dentro de `Interfaz_Usuario` antes de
   compilar Android.
3. Guarda la cuenta de servicio de Firebase fuera de `public_html`.
4. Define `FCM_SERVICE_ACCOUNT_PATH` en `BD_credentials.php`.
5. Activa Firebase Cloud Messaging API (HTTP v1).

Nunca publiques la cuenta de servicio. Si Firebase falla, la misión permanece
guardada y el técnico la verá al abrir la aplicación.

Cuando un técnico registra una entrada o salida, la API también envía una
notificación silenciosa al supervisor de su asignación activa. El supervisor
debe haber iniciado sesión al menos una vez en el dispositivo para registrar
su token. Android muestra estos avisos en el canal `Asistencia de técnicos`,
sin sonido ni vibración.

## Recordatorios automáticos de asistencia

Ejecuta una sola vez `database/attendance_reminders_setup.sql` desde
phpMyAdmin. Después agrega una llave aleatoria de al menos 32 caracteres en
`BD_credentials.php`:

```php
define(
    'CRON_SECRET',
    'REEMPLAZA_ESTO_POR_UNA_LLAVE_ALEATORIA_DE_AL_MENOS_32_CARACTERES'
);
```

Sube `cron_recordatorios_asistencia.php` en la carpeta raíz de la API y
configura en el panel del hosting un cron que lo ejecute cada cinco minutos.
Si la API se encuentra en `/home/oicnjgmy/public_html/api`, el comando es:

```text
php -q /home/oicnjgmy/public_html/api/cron_recordatorios_asistencia.php >/dev/null 2>&1
```

El endpoint usa la zona horaria `America/El_Salvador` y solamente procesa:

- De 7:55 a 7:59 a. m.: técnicos activos que todavía no registraron entrada.
- De 5:00 a 5:04 p. m.: técnicos con entrada registrada y sin salida.

La tabla `Recordatorios_Asistencia` impide repetir el mismo aviso para un
técnico durante el mismo día después de una entrega exitosa. Si Firebase no
entrega ningún mensaje, vuelve a intentarlo en la siguiente ejecución dentro
de la ventana de cinco minutos. Los días registrados en
`Dias_No_Laborales` no generan recordatorios.

La configuración más segura cuando no se conoce la zona horaria del servidor
de Bluehost es ejecutar el proceso cada cinco minutos:

```text
*/5 * * * 1-5
```

PHP comprueba internamente la hora de El Salvador y sale inmediatamente fuera
de las ventanas. Si cPanel confirma que el cron también usa
`America/El_Salvador` y permite intervalos por minuto, se pueden usar
`55-59 7 * * 1-5` y `0-4 17 * * 1-5` para aprovechar los reintentos.
Ejecutar el comando normal fuera de esas ventanas no envía notificaciones.

Para probar inmediatamente el envío a un solo técnico, sin esperar la hora
programada, ejecuta el comando sin redirigir su salida:

```text
php -q /home/oicnjgmy/public_html/api/cron_recordatorios_asistencia.php --type=CHECK_IN --test-user=USUARIO_DEL_TECNICO
```

El resultado JSON debe mostrar `sentMessages: 1` o más. Si muestra cero,
`deliveryResults` indicará si falta `FCM_SERVICE_ACCOUNT_PATH`, si el técnico
no tiene un token registrado o si Firebase rechazó la solicitud. La prueba no
crea un bloqueo diario y se puede repetir.

Como alternativa, se puede invocar el endpoint protegido por HTTP:

```text
/usr/bin/curl -fsS -X POST -H "Content-Type: application/json" -H "X-Cron-Secret: TU_CRON_SECRET" --data "{}" https://api.nordictech-corp.com/api/v1/cron/attendance-reminders >/dev/null 2>&1
```

## Paquete de despliegue

El archivo `src.zip` contiene los archivos necesarios para actualizar la API
desde File Manager. Está generado sin `BD_credentials.php`, sin `.git` y sin
la cuenta de servicio de Firebase.

Al desplegar:

1. Conserva el `BD_credentials.php` existente en el servidor.
2. Sube `src.zip` a la raíz de la API.
3. Extrae y sobrescribe los archivos del código.
4. Comprueba `/api/v1/health`.
5. Confirma que `apiVersion` sea `2026.08.04.2`.

## Seguridad

- Nunca publiques `BD_credentials.php`, `JWT_SECRET`, `CRON_SECRET` ni la
  cuenta de servicio de Firebase.
- Mantén HTTPS habilitado.
- Conserva `.htaccess`; bloquea listados, limita el cuerpo y redirige las
  rutas hacia `index.php`.
- Las validaciones de rol, distancia, horario y días sin marcación se
  ejecutan en el servidor aunque el cliente haya sido modificado.
