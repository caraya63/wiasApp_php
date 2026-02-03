<?php
declare(strict_types=1);

final class SimpleJWT
{
    // Encode: crea un JWT (HS256)
    public static function encode(array $payload, string $secret, array $header = []): string
    {
        $header = array_merge([
            'typ' => 'JWT',
            'alg' => 'HS256',
        ], $header);

        $headerB64  = self::base64urlEncode(self::jsonEncode($header));
        $payloadB64 = self::base64urlEncode(self::jsonEncode($payload));

        $signingInput = $headerB64 . '.' . $payloadB64;
        $signature = hash_hmac('sha256', $signingInput, $secret, true);
        $sigB64 = self::base64urlEncode($signature);

        return $signingInput . '.' . $sigB64;
    }

    /**
     * Decode: valida firma y devuelve payload.
     * $leeway: tolerancia de segundos para reloj (clock skew).
     * $validateClaims: si true, valida exp/nbf/iat (si existen).
     */
    public static function decode(
        string $jwt,
        string $secret,
        int $leeway = 0,
        bool $validateClaims = true
    ): array {
        try {
            mylog("en simpleJWT.decode token: ".$jwt);
            $parts = explode('.', $jwt);
            if (count($parts) !== 3) {
                mylog('JWT inválido: formato incorrecto');
                throw new Exception('JWT inválido: formato incorrecto');
            }

            list($headerB64, $payloadB64, $sigB64) = $parts;

            $headerJson = self::base64urlDecode($headerB64);
            $payloadJson = self::base64urlDecode($payloadB64);
            $signature = self::base64urlDecode($sigB64);

            $header = self::jsonDecode($headerJson);
            $payload = self::jsonDecode($payloadJson);

            // Solo permitir HS256 (evita "alg:none" y confusiones)
            if (!isset($header['alg']) || $header['alg'] !== 'HS256') {
                mylog('JWT inválido: alg no permitido');
                throw new Exception('JWT inválido: alg no permitido');
            }

            // Validar firma (constant-time)
            $signingInput = $headerB64 . '.' . $payloadB64;
            $expected = hash_hmac('sha256', $signingInput, $secret, true);

            if (!hash_equals($expected, $signature)) {
                mylog('JWT inválido: firma incorrecta');
                throw new Exception('JWT inválido: firma incorrecta');
            }

            if ($validateClaims) {
                $now = time();

                // nbf: not before
                if (isset($payload['nbf']) && is_numeric($payload['nbf'])) {
                    if ($now + $leeway < (int)$payload['nbf']) {
                        mylog('JWT inválido: token aún no es válido (nbf)');
                        throw new Exception('JWT inválido: token aún no es válido (nbf)');
                    }
                }

                // iat: issued at (opcional validar que no venga del futuro)
                if (isset($payload['iat']) && is_numeric($payload['iat'])) {
                    if ($now + $leeway < (int)$payload['iat']) {
                        mylog('JWT inválido: iat en el futuro');
                        throw new Exception('JWT inválido: iat en el futuro');
                    }
                }

                // exp: expiration
                if (isset($payload['exp']) && is_numeric($payload['exp'])) {
                    if ($now - $leeway >= (int)$payload['exp']) {
                        mylog('JWT expirado');
                        throw new Exception('JWT expirado');
                    }
                }
            }

            return $payload;
        }
        catch(Exception $e){
            mylog("Error en simpleJWT decode: " . $e->getMessage());
            return [];
        }
    }

    // --- Helpers ---

    private static function jsonEncode(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new Exception('JSON encode falló');
        }
        return $json;
    }

    private static function jsonDecode(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new Exception('JSON decode falló');
        }
        return $data;
    }

    private static function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64urlDecode(string $data): string
    {
        $b64 = strtr($data, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad > 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($b64, true);
        if ($decoded === false) {
            throw new Exception('Base64URL decode falló');
        }
        return $decoded;
    }
}
