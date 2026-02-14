<?php

declare(strict_types=1);

require_once __DIR__ . '/utils/otp.php';


final class AuthController
{

    private static function userResponse(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'email' => $row['email'],
            'displayName' => $row['display_name'],
            'preferredLanguage' => $row['preferred_language'],
            'validated' => $row['validated']
        ];
    }

    private static function validateEmail(string $email): void
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Http::badRequest('invalid_email');
        }
    }

    private static function validatePassword(string $password): void
    {
        if (mb_strlen($password) < 8) {
            Http::badRequest('password_too_short', ['min' => 8]);
        }
    }

    private static function normalizeLang(string $lang): string
    {
        $lang = strtolower(trim($lang));
        $allowed = ['es', 'en', 'fr', 'pt'];
        return in_array($lang, $allowed, true) ? $lang : 'en';
    }

    private static function normalizeBirthVisibility(string $v): string
    {
        $v = trim($v);
        $allowed = ['private', 'friends', 'hidden_but_used'];
        if (!in_array($v, $allowed, true)) return 'friends';
        return $v;
    }

    private static function normalizeDate(?string $date): ?string
    {
        if ($date === null || trim($date) === '') return null;
        $date = trim($date);
        // YYYY-MM-DD
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            Http::badRequest('invalid_birth_date', ['expected' => 'YYYY-MM-DD']);
        }
        return $date;
    }

    /** POST /auth/register */
    public static function register(): void
    {
        Middleware::requireAppSignature();

        $b = Http::jsonBody();
        mylog("en register " . json_encode($b));

        $email = (string)($b['email'] ?? '');
        $password = (string)($b['password'] ?? '');
        $displayName = trim((string)($b['displayName'] ?? ''));
        $birthDate = self::normalizeDate($b['birthDate'] ?? null);
        $birthVis = self::normalizeBirthVisibility((string)($b['birthDateVisibility'] ?? 'friends'));
        $lang = self::normalizeLang((string)($b['preferredLanguage'] ?? 'en'));

        if ($displayName === '') Http::badRequest('missing_displayName');
        self::validateEmail($email);
        self::validatePassword($password);

        $pdo = Db::pdo();

        // Email único solo para activos: usamos email_active si aplicaste esa estrategia.
        // Si no, cambia a "WHERE email = :email AND deleted=0"
        $query = "SELECT id " .
                 " FROM users " .
                 " WHERE email = :email AND deleted=0 LIMIT 1";
        $stmt = $pdo->prepare($query);
        $stmt->execute([':email' => $email]);
        if ($stmt->fetch()) {
            Http::badRequest('email_already_registered');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);

        $pdo->beginTransaction();
        try {
            $query = "INSERT INTO users (email, display_name, birth_date, birth_date_visibility, preferred_language, created_at, updated_at) " .
                     " VALUES (:email, :display_name, :birth_date, :birth_vis, :lang, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3))";
            $stmt = $pdo->prepare($query);
            $stmt->execute([
                ':email' => $email,
                ':display_name' => $displayName,
                ':birth_date' => $birthDate,
                ':birth_vis' => $birthVis,
                ':lang' => $lang,
            ]);

            $userId = (int)$pdo->lastInsertId();
            $query = "INSERT INTO user_auth (user_id, password_hash, provider, created_at, updated_at) " .
                     " VALUES (:uid, :hash, 'local', CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3))";
            $stmt = $pdo->prepare($query);
            $stmt->execute([':uid' => $userId, ':hash' => $hash]);

            $pdo->commit();
            mylog("en register-issueJwt (inserted) " . json_encode($b));

            $token = Middleware::issueJwt($userId);
            mylog("en register, token= " . $token);
            // devolver user
            $query = "SELECT id,email,display_name,preferred_language,validated " .
                     " FROM users WHERE id=:id LIMIT 1";
            $stmt = $pdo->prepare($query);
            $stmt->execute([':id' => $userId]);
            $userRow = $stmt->fetch();

            $registro = sendMailOTP($email,$lang);
            mylog("en register, registro email: " . json_encode($registro));

            // Guardar $registro['otp_hash'] y $registro['expires_at'] en tu BD. o "error"
            $query = "UPDATE user_auth " .
                     " SET last_otp = :otp, otp_expires = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL " . $registro['expires_at'] ." MINUTE) " .
                     " WHERE user_id = :id;";
            mylog("en register, update: ".$query."\notp: ".$registro['otp_hash']."\nid: ".$userId);
            $stmt = $pdo->prepare($query);
            $stmt->execute([':otp' => $registro['otp_hash'], ':id' => $userId]);


            Http::json(201, ['token' => $token, 'user' => self::userResponse($userRow)]);
        } catch (Exception $e) {
            mylog("Error en register falla: " . $e->getMessage());
            $pdo->rollBack();
            Http::json(500, ['error' => 'server_error', 'message' => 'register_failed ' . $e->getMessage()]);
        }
    }

    /** POST /auth/login */
    public static function login(): void
    {
        try {
            Middleware::requireAppSignature();

            $b = Http::jsonBody();
            mylog("en login " . json_encode($b));
            $email = (string)($b['email'] ?? '');
            $password = (string)($b['password'] ?? '');

            self::validateEmail($email);
            self::validatePassword($password); // mismo mínimo

            $pdo = Db::pdo();

            $query = "SELECT u.id, u.email, u.display_name, u.preferred_language, ua.password_hash , u.validated" .
                " FROM users u JOIN user_auth ua ON ua.user_id = u.id AND ua.provider='local' AND ua.deleted=0 " .
                " WHERE u.email = :email AND u.deleted=0  LIMIT 1 ";
            $stmt = $pdo->prepare($query);
            $stmt->execute([':email' => $email]);

            $row = $stmt->fetch();

            if (!$row || !password_verify($password, (string)$row['password_hash'])) {
                Http::unauthorized('invalid_credentials, user or password incorrect');
            }

            $userId = (int)$row['id'];
            $token = Middleware::issueJwt($userId);

            $row['password_hash'] = "password";

            Http::json(200, [
                'token' => $token,
                'user' => self::userResponse($row),
            ]);
        }
        catch(Exception $e){
            Http::dbServerFail('not_login');
            error_log("Error en AuthController.login ".$e->getMessage());
        }

    }

    /** POST /auth/validateAccount */
    public static function validateAccount(): void
    {
        try {
            Middleware::requireAppSignature();
            $userId = Middleware::requireAuthUserId();

            $b = Http::jsonBody();
            mylog("en validateAccount,recibido: " . json_encode($b));
            $email = (string)($b['email'] ?? '');
            $otp = (string)($b['otp']);

            $pdo = Db::pdo();

            $query = "SELECT u.id, ua.last_otp, UNIX_TIMESTAMP(ua.otp_expires) expires" .
                " FROM userss u JOIN user_auth ua ON ua.user_id = u.id AND ua.provider='local' AND ua.deleted=0 " .
                " WHERE u.email = :email AND u.deleted=0  LIMIT 1 ";
            $stmt = $pdo->prepare($query);
            $stmt->execute([':email' => $email]);

            $row = $stmt->fetch();
            mylog("en validateAccount, leido: " . json_encode($row));

            if (!$row || !validarOtp($otp,$row['last_otp'],(int)$row['expires'])) {
                Http::unauthorized('invalid_credentials');
            }
            else {
                $query = "UPDATE users SET validated=1 where id=:id;";
                $stmt = $pdo->prepare($query);
                $stmt->execute([':id' => $row['id']]);

                Http::json(200, ['validated' => "yes"]);
            }

        }
        catch(Exception $e){
            error_log("Error en AuthController.validateAccount ".$e->getMessage());
            Http::dbServerFail('not_validated');

        }
    }

    /** GET /auth/me */
    public static function me(): void
    {
        Middleware::requireAppSignature();
        $userId = Middleware::requireAuthUserId();

        $pdo = Db::pdo();
        $query = "SELECT id,email,display_name,preferred_language, validated" .
                 " FROM users WHERE id=:id AND deleted=0 LIMIT 1";
        $stmt = $pdo->prepare($query);
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();

        if (!$row) Http::unauthorized('user_not_found');

        Http::json(200, ['user' => self::userResponse($row)]);
    }

    /** PATCH /users/me  (actualización perfil + idioma + cumpleaños + password opcional) */
    public static function updateMe(): void
    {
        Middleware::requireAppSignature();
        $userId = Middleware::requireAuthUserId();

        $b = Http::jsonBody();

        $displayName = array_key_exists('displayName', $b) ? trim((string)$b['displayName']) : null;
        $photoUrl = array_key_exists('photoUrl', $b) ? trim((string)$b['photoUrl']) : null;
        $birthDate = array_key_exists('birthDate', $b) ? self::normalizeDate($b['birthDate']) : null;
        $birthVis = array_key_exists('birthDateVisibility', $b) ? self::normalizeBirthVisibility((string)$b['birthDateVisibility']) : null;
        $lang = array_key_exists('preferredLanguage', $b) ? self::normalizeLang((string)$b['preferredLanguage']) : null;

        $newPassword = array_key_exists('newPassword', $b) ? (string)$b['newPassword'] : null;
        if ($newPassword !== null) self::validatePassword($newPassword);

        $fields = [];
        $params = [':id' => $userId];

        if ($displayName !== null) {
            if ($displayName === '') Http::badRequest('missing_displayName');
            $fields[] = "display_name = :display_name";
            $params[':display_name'] = $displayName;
        }
        if ($photoUrl !== null) {
            $fields[] = "photo_url = :photo_url";
            $params[':photo_url'] = $photoUrl === '' ? null : $photoUrl;
        }
        if (array_key_exists('birthDate', $b)) {
            $fields[] = "birth_date = :birth_date";
            $params[':birth_date'] = $birthDate;
        }
        if ($birthVis !== null) {
            $fields[] = "birth_date_visibility = :birth_vis";
            $params[':birth_vis'] = $birthVis;
        }
        if ($lang !== null) {
            $fields[] = "preferred_language = :lang";
            $params[':lang'] = $lang;
        }

        if (empty($fields) && $newPassword === null) {
            Http::badRequest('no_fields_to_update');
        }

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            if (!empty($fields)) {
                $sql = "UPDATE users " .
                            " SET " . implode(', ', $fields) . ", updated_at = CURRENT_TIMESTAMP(3) " .
                            " WHERE id = :id AND deleted=0";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                if ($stmt->rowCount() === 0) {
                    $pdo->rollBack();
                    Http::unauthorized('user_not_found');
                }
            }

            if ($newPassword !== null) {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE user_auth " .
                                              "SET password_hash = :hash, updated_at = CURRENT_TIMESTAMP(3) " .
                                              "WHERE user_id = :id AND provider='local' AND deleted=0");
                $stmt->execute([':hash' => $hash, ':id' => $userId]);
            }

            $pdo->commit();

            $stmt = $pdo->prepare("SELECT id,email,display_name,preferred_language " .
                                            "FROM users WHERE id=:id AND deleted=0 LIMIT 1");
            $stmt->execute([':id' => $userId]);
            $row = $stmt->fetch();

            Http::json(200, ['user' => self::userResponse($row)]);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            Http::json(500, ['error' => 'server_error', 'message' => 'update_failed']);
        }
    }
}