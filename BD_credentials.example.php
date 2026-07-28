<?php
declare(strict_types=1);

define('DB_HOST', 'localhost');
define('DB_NAME', 'Bd_Nordictechsv');
define('DB_USER', 'usuario_mysql');
define('DB_PASSWORD', 'password_mysql');
define('DB_CHARSET', 'utf8');
define('DB_COLLATE', '');

// Si el archivo ya contiene AUTH_KEY de WordPress, la API puede usarla.
define('AUTH_KEY', 'CAMBIA_ESTA_LLAVE_POR_UNA_ALEATORIA_DE_64_CARACTERES');

// JWT_SECRET es opcional. Si existe, tiene prioridad sobre AUTH_KEY.
define('JWT_SECRET', 'OTRA_LLAVE_ALEATORIA_DE_64_CARACTERES_PARA_LA_API');
define('JWT_ISSUER', 'Nordictech.Asistencia.Api');
define('JWT_AUDIENCE', 'Nordictech.Asistencia.Client');
define('JWT_EXPIRATION_MINUTES', 60);

// Ruta absoluta recomendada, fuera de public_html.
// define('FCM_SERVICE_ACCOUNT_PATH', '/home/usuario/firebase-service-account.json');
