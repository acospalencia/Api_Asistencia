# API de Asistencia para Bluehost

Versión PHP 8.2+ diseñada para Bluehost Shared Hosting. No necesita Composer,
terminal ni un proceso permanente.

## Instalación con File Manager

1. En Bluehost selecciona PHP 8.2 o superior.
2. Dentro de `public_html`, crea la carpeta `api-asistencia`.
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

Si la carpeta se llama `api-asistencia`, las URLs serán:

```text
https://tudominio.com/api-asistencia/api/v1/auth/login
https://tudominio.com/api-asistencia/api/v1/account/me
```

Las contraseñas de `Usuarios.password_hash` deben estar creadas mediante
`password_hash($password, PASSWORD_BCRYPT)`.
