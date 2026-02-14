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

function i18InvitacionStrings(string $lang, string $inviteLink,bool $existe): array {
    $lang = strtolower($lang);
    if (!in_array($lang, ['es', 'en', 'pt', 'fr'], true)) {
        $lang = 'es'; // fallback
    }
    $dict = [];
    if (!$existe) { //cuando no existe en la app un usuario invitado
        $dict = [
            'es' => [
                'subject' => 'PROBANDO Te invitaron a WISH_APP',
                'title' => 'Invitacion a Wish_app<BR><BR>',
                'line1' => '<p>ES UNA PRUEBA, Hola,</p>',
                'line2' => '<p><b>alguien te invitó a unirte a <b>Wish_app</b>.</p>',
                'line3' => '<p>Descárgala o entra aquí: <a href=' . $inviteLink . '>{$inviteLink}</a></p>',

            ],
            'en' => [
                'subject' => 'TESTING You were invited to WISH_APP',
                'title' => 'Invitation to Wish_app<BR><BR>',
                'line1' => '<p>It is a test, Hi,</p>',
                'line2' => '<p><b>Someone invited you to join <b>Wish_app</b>.</p>',
                'line3' => '<p>Download it or enter here: <a href=' . $inviteLink . '>{$inviteLink}</a></p>',
            ],
            'pt' => [
                'subject' => 'TESTANDO Você foi convidado para o WISH_APP',
                'title' => 'Convite para o aplicativo<BR><BR>',
                'line1' => '<p>É um teste, olá.</p>',
                'line2' => '<p><b>Alguém te convidou para participar <b>Wish_app</b>.</p>',
                'line3' => '<p>Faça o download ou acesse aqui: <a href=' . $inviteLink . '>{$inviteLink}</a></p>',
            ],
            'fr' => [
                'subject' => 'TEST Vous avez été invité à WISH_APP',
                'title' => 'Invitation à Wish_app<BR><BR>',
                'line1' => '<p>C\'EST UN TEST, Bonjour,</p>',
                'line2' => '<p><b>Quelqu\'un vous a invité à rejoindre <b>Wish_app</b>.</p>',
                'line3' => '<p>Téléchargez-le ou saisissez-le ici : <a href=' . $inviteLink . '>{$inviteLink}</a></p>',
            ],
        ];
    }
    else { //el usuario invitado existe en la app
        $dict = [
            'es' => [
                'subject' => 'Nueva solicitud de contacto en WISH_APP',
                'title' => 'Solicitud de contacto a Wish_app<BR><BR>',
                'line1' => '<p>Hola,</p>',
                'line2' => '<p><b>{:fulano} ({:correo}) te invitó a contactarse en <b>Wish_app</b>.</p>',
                'line3' => '<p>Entra a la app y acepta el contacto <a href=' . $inviteLink . '>{$inviteLink}</a></p>',

            ],
            'en' => [
                'subject' => 'TESTING You were invited to WISH_APP',
                'title' => 'Contact request to Wish_app<BR><BR>',
                'line1' => '<p>Hi,</p>',
                'line2' => '<p><b>{:fulano} ({:correo}) invited you to connect on <b>Wish_app</b>.</p>',
                'line3' => '<p>Open the app and accept the contact <a href=' . $inviteLink . '>{$inviteLink}</a></p>',
            ],
            'pt' => [
                'subject' => 'TESTANDO Você foi convidado para o WISH_APP',
                'title' => 'Solicitação de contato para o aplicativo Wish<BR><BR>',
                'line1' => '<p>Olá.</p>',
                'line2' => '<p><b>{:fulano} ({:correo}) te convidou para se conectar em <b>Wish_app</b>.</p>',
                'line3' => '<p>Abra o aplicativo e aceite o contato. <a href=' . $inviteLink . '>{$inviteLink}</a></p>',
            ],
            'fr' => [
                'subject' => 'TEST Vous avez été invité à WISH_APP',
                'title' => 'Demande de contact à Wish_app<BR><BR>',
                'line1' => '<p>Bonjour,</p>',
                'line2' => '<p><b>vous a invité à prendre contact à <b>Wish_app</b>.</p>',
                'line3' => '<p>Ouvrez l\'application et acceptez le contact <a href=' . $inviteLink . '>{$inviteLink}</a></p>',
            ],
        ];
    }

    $t = $dict[$lang];


    $html = '
    <div style="font-family: Arial, sans-serif; line-height: 1.4">
      <h2>' . $t['title'] . '</h2>
      <p>' . $t['line1'] . '</p>
      <p>' . $t['line2'] . '</p>
      <p>' . $t['line3'] . '</p>
    </div>';

    return [
        'subject' => $t['subject'],
        'html' => $html,
        'lang' => $lang
    ];
}

/**
 * Envía email invitacion con i18n.
 */
function enviarEmailInvitacion(string $to, string $lang = 'es',string $inviteLink,bool $existe = false): bool {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $fromEmail = 'no-reply@todoit.cl';
    $from = 'TodoIT <' . $fromEmail . '>';

    // Preparar textos i18n
    $msg = i18InvitacionStrings($lang, $inviteLink,$existe);

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

    mylog("to: ".$toSafe);
    mylog("Subject: ".$subjectSafe);
    mylog("from: ".$fromSafe);
    mylog("msg : ".$msg['html']);
    mylog("Headers : ".var_export($headers,true));

    return mail($toSafe, $subjectSafe, $msg['html'], implode("\r\n", $headers));
}