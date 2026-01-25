<?php
declare(strict_types=1);

function generarOtp(int $digits = 6): string {
    $min = (int) pow(10, $digits - 1);
    $max = (int) pow(10, $digits) - 1;
    return (string) random_int($min, $max);
}



/**
 * Construye registro seguro para BD (hash + expiración).
 */
function construirRegistroOtp(string $otp, int $ttlMinutos = 10): array {
    $expiresAt = time() + ($ttlMinutos * 60);
    $otpHash = password_hash($otp, PASSWORD_DEFAULT);

    return [
        'otp_hash' => $otpHash,
        'expires_at' => $ttlMinutos
    ];
}

function validarOtp(string $otpIngresado, string $otpHashGuardado, int $expiresAt): bool {
    try {
        $time = time();
        if ($time > $expiresAt) {
            mylog("token expirado time: " . $time . " vs " . $expiresAt);
            return false;
        }
        $booleano = password_verify($otpIngresado, $otpHashGuardado);
        mylog("validarOtp:\notp Ingresado: " . $otpIngresado . "\nhash guardado  : " . $otpHashGuardado);
        mylog("resultado de password verify: " . $booleano);

        return $booleano;
    }
    catch(Exception $e){
        error_log("Error en validarOTP : ".$e->getMessage());
        return false;
    }
}
