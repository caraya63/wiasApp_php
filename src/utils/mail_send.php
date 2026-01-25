<?php



function i18nOtpStrings(string $lang, string $otp, int $ttlMinutos): array {
    $lang = strtolower($lang);
    if (!in_array($lang, ['es', 'en', 'pt', 'fr'], true)) {
        $lang = 'es'; // fallback
    }

    $dict = [
        'es' => [
            'subject' => 'Tu código de verificación (OTP)',
            'title'   => 'Código de verificación',
            'line1'   => 'Tu código OTP es:',
            'line2'   => 'Este código vence en <b>%d minutos</b>.',
            'line3'   => 'Si no fuiste tú, puedes ignorar este correo.',
        ],
        'en' => [
            'subject' => 'Your verification code (OTP)',
            'title'   => 'Verification code',
            'line1'   => 'Your OTP code is:',
            'line2'   => 'This code expires in <b>%d minutes</b>.',
            'line3'   => 'If this wasn’t you, you can ignore this email.',
        ],
        'pt' => [
            'subject' => 'Seu código de verificação (OTP)',
            'title'   => 'Código de verificação',
            'line1'   => 'Seu código OTP é:',
            'line2'   => 'Este código expira em <b>%d minutos</b>.',
            'line3'   => 'Se não foi você, pode ignorar este e-mail.',
        ],
        'fr' => [
            'subject' => 'Votre code de vérification (OTP)',
            'title'   => 'Code de vérification',
            'line1'   => 'Votre code OTP est :',
            'line2'   => 'Ce code expire dans <b>%d minutes</b>.',
            'line3'   => 'Si ce n’était pas vous, vous pouvez ignorer cet e-mail.',
        ],
    ];

    $t = $dict[$lang];

    // Construir HTML (escapando OTP)
    $otpEsc = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');

    $html = '
    <div style="font-family: Arial, sans-serif; line-height: 1.4">
      <h2>' . $t['title'] . '</h2>
      <p>' . $t['line1'] . '</p>
      <div style="font-size: 28px; font-weight: bold; letter-spacing: 4px; margin: 12px 0">' . $otpEsc . '</div>
      <p>' . sprintf($t['line2'], (int)$ttlMinutos) . '</p>
      <p>' . $t['line3'] . '</p>
    </div>';

    return [
        'subject' => $t['subject'],
        'html' => $html,
        'lang' => $lang
    ];
}

/**
 * Envía OTP por email con i18n.
 */
function enviarOtpEmailI18n(string $to, string $otp, int $ttlMinutos = 10, string $lang = 'es'): bool {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $fromEmail = 'no-reply@todoit.cl';
    $from = 'TodoIT <' . $fromEmail . '>';

    // Preparar textos i18n
    $msg = i18nOtpStrings($lang, $otp, $ttlMinutos);

    // Evitar inyección en headers
    $toSafe = str_replace(["\r", "\n"], '', $to);
    $subjectSafe = str_replace(["\r", "\n"], '', $msg['subject']);
    $fromSafe = str_replace(["\r", "\n"], '', $from);

    $headers = [];
    $headers[] = "From: {$fromSafe}";
    $headers[] = "Reply-To: {$fromEmail}";
    $headers[] = "MIME-Version: 1.0";
    $headers[] = "Content-Type: text/html; charset=UTF-8";
    $headers[] = "Content-Language: " . $msg['lang'];

    return mail($toSafe, $subjectSafe, $msg['html'], implode("\r\n", $headers));
}

function sendMailOTP($email,$lang) : array
{
    try {
        $ttl = 10;

        $otp = generarOtp(6);
        $registro = construirRegistroOtp($otp, $ttl);


        $ok = enviarOtpEmailI18n($email, $otp, $ttl, $lang);
        if (!$ok) {
            throw new Exception('No se pudo enviar el OTP');
        }
        return $registro;
    }
    catch(Exception $e){
        error_log("Error en mail_send.sendMail ".$e->getMessage());
        return ["error"=>"error"];
    }
}