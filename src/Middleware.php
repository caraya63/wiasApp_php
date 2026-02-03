<?php

declare(strict_types=1);

require __DIR__ . "/utils/simpleJWT.php";


final class Middleware
{

    /** Señal básica de “viene desde la app” */
    public static function requireAppSignature(): void
    {
        try {
            $clientId = Http::header('X-Client-Id');
            $ts = Http::header('X-Client-Timestamp');
            $signature = Http::header('X-Client-Signature');
            $payloadIn = Http::header('X-Client-Payload');

            if (!$clientId || !$ts || !$signature) {
                Http::forbidden('missing_app_headers');
            }

            if ($clientId !== Config::APP_CLIENT_ID) {
                Http::forbidden('invalid_client_id');
            }

            if (!ctype_digit($ts)) {
                Http::forbidden('invalid_timestamp');
            }

            $tsInt = (int)$ts;
            $now = time();
            if (abs($now - (int)floor($tsInt / 1000)) > Config::APP_SIGNATURE_MAX_SKEW_SECONDS) {
                // App envía ms en el ejemplo RN (Date.now())
                Http::forbidden('timestamp_out_of_range');
            }

            $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

            // path relativo sin query string
            $uri = $_SERVER['REQUEST_URI'] ?? '/';
            //$pathOnly = strtok($uri, '?') ?: '/';
            $pathOnly = getPathAfterIndex();

            // Debe coincidir con RN: `${clientId}.${ts}.${method}.${urlPath}`
            // RN usa config.url que es el path relativo: "/auth/login"
            $payload = Config::APP_CLIENT_ID . '.' . $ts . '.' . $method . '.' . $pathOnly;

            // RN firma sha256(`${payload}.${key}`)
            $expected = hash('sha256', $payload . '.' . Config::APP_SIGNATURE_KEY);
            mylog("en Middleware\nexpected: " . $expected . "\n recibida: " . $signature . "\nPayload:   " . $payload . "\nPayloadIn: " . $payloadIn ."\n");
            if (!hash_equals($expected, $signature)) {
                Http::forbidden('invalid_signature');
            }
        }
        catch(Exception $e){
            error_log("Error en Middleware.requireAppSignature ".$e->getMessage());
            Http::unauthorized('bad_request');
        }
    }

    /** Requiere JWT Bearer */
    public static function requireAuthUserId(): int
    {
        try {
            mylog('en Middleware.requireAuthUserId' );
            $auth = Http::header('Authorization') ?? '';
            mylog('en Middleware.requireAuthUserId: '.$auth );

            if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
                Http::unauthorized('missing_bearer_token');
            }

            $token = $m[1];
            mylog("en Middleware.requireAuthUserId, token: ".$token);

            try {
                $decoded = SimpleJWT::decode($token, Config::JWT_SECRET, 30, true);
                $uid = isset($decoded['sub']) ? (int)$decoded['sub'] : 0;
                mylog('en Middleware... data: ' . json_encode($decoded) . " uid: " . $uid);
                if ($uid <= 0) {
                    Http::unauthorized('invalid_token'); // Esto hace exit;
                }
                return $uid;
            } catch (\Throwable $e) {
                mylog("Error en Middleware.requireAuthUserId ".$e->getMessage());
                Http::unauthorized('invalid_token');
            }
        }
        catch(Exception $e){
            error_log("Error en Middleware.requireAuthUserId ".$e->getMessage());
            Http::unauthorized('bad_request_user ');
        }
        return 0;
    }

    public static function issueJwt(int $userId): string
    {
        try {
            $now = time();
            $payload = [
                'iss' => Config::JWT_ISSUER,
                'iat' => $now,
                'exp' => $now + Config::JWT_TTL_SECONDS,
                'sub' => (string)$userId,
            ];
            return SimpleJWT::encode($payload, Config::JWT_SECRET);
        }
        catch(Exception $e){
            error_log("Error en Middleware.issueJwt ".$e->getMessage());
            Http::unauthorized('bad_request_issueJwt');
        }
        return "invalid";
    }
}