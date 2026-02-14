<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

final class Mailer
{
    public static function send(string $to, string $subject, string $html, string $text = ''): bool
    {
        $mail = new PHPMailer(true);

        try {
            // SMTP
            $mail->isSMTP();
            $mail->Host = Config::SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = Config::SMTP_USER;
            $mail->Password = Config::SMTP_PASS;
            $mail->SMTPSecure = Config::SMTP_SECURE; // 'tls' o 'ssl'
            $mail->Port = Config::SMTP_PORT;

            $mail->setFrom(Config::MAIL_FROM, Config::MAIL_FROM_NAME);
            $mail->addAddress($to);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $html;
            $mail->AltBody = $text !== '' ? $text : strip_tags($html);
            mylog("todo lista para enviar email a: ".$to);
            return $mail->send();
        } catch (Exception $e) {
            error_log("MAIL ERROR: " . $e->getMessage());
            return false;
        }
    }
}
