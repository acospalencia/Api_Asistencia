<?php
declare(strict_types=1);

namespace Nordictech\Api\Controllers;

use JsonException;
use Nordictech\Api\Data\Database;
use Nordictech\Api\Http\Response;
use Throwable;

final class DeviceController
{
    /** @param array<string, mixed> $claims */
    public function register(array $claims): never
    {
        $userId = (int) ($claims['sub'] ?? 0);
        $body = $this->requestBody();
        $token = trim((string) ($body['token'] ?? ''));
        $platform = strtolower(trim((string) ($body['platform'] ?? 'android')));

        if ($userId <= 0) {
            Response::error(
                401,
                'invalid_token',
                'La sesión no identifica al usuario.'
            );
        }

        if ($token === '' || strlen($token) > 512) {
            Response::error(
                422,
                'invalid_device_token',
                'El identificador del dispositivo no es válido.'
            );
        }

        if (!in_array($platform, ['android', 'ios'], true)) {
            Response::error(
                422,
                'invalid_platform',
                'La plataforma del dispositivo no es válida.'
            );
        }

        try {
            $connection = Database::connection();
            $statement = $connection->prepare(
                'INSERT INTO Dispositivos_Notificacion (
                    id_usuario,
                    token_dispositivo,
                    plataforma,
                    estado_activo,
                    fecha_registro,
                    fecha_actualizacion
                 ) VALUES (
                    :id_usuario,
                    :token_dispositivo,
                    :plataforma,
                    1,
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP
                 )
                 ON DUPLICATE KEY UPDATE
                    id_usuario = VALUES(id_usuario),
                    plataforma = VALUES(plataforma),
                    estado_activo = 1,
                    fecha_actualizacion = CURRENT_TIMESTAMP'
            );
            $statement->execute([
                'id_usuario' => $userId,
                'token_dispositivo' => $token,
                'plataforma' => $platform,
            ]);
        } catch (Throwable $exception) {
            error_log(
                '[API Asistencia][register_device] '
                . $exception->getMessage()
            );
            Response::error(
                503,
                'device_registration_unavailable',
                'No fue posible registrar el dispositivo para notificaciones.'
            );
        }

        Response::json([
            'message' => 'Dispositivo registrado para notificaciones.',
        ]);
    }

    /** @return array<string, mixed> */
    private function requestBody(): array
    {
        try {
            $body = json_decode(
                file_get_contents('php://input') ?: '',
                true,
                8,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            Response::error(
                400,
                'invalid_json',
                'El cuerpo JSON no es válido.'
            );
        }

        if (!is_array($body)) {
            Response::error(
                400,
                'invalid_request',
                'La solicitud no es válida.'
            );
        }

        return $body;
    }
}
