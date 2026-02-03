<?php
declare(strict_types=1);

final class FriendsController
{
    public static function listFriends(): void
    {
        mylog("en  listFriends");
        Middleware::requireAppSignature();
        $userId = Middleware::requireAuthUserId();
        mylog("en listFriend -> userid: $userId");
        $pdo = DB::pdo();

        $sql = "SELECT f.id,
                       CASE
                         WHEN f.requester_user_id = :uid THEN f.addressee_user_id
                         ELSE f.requester_user_id
                       END AS friend_user_id,
                       u.display_name, u.email, u.birth_date, u.preferred_language,
                       f.created_at, f.updated_at
                FROM friends f
                JOIN users u ON u.id = CASE
                                        WHEN f.requester_user_id = :uid THEN f.addressee_user_id
                                        ELSE f.requester_user_id
                                      END
                WHERE f.deleted_at IS NULL
                  AND f.status = 'accepted'
                  AND (:uid IN (f.requester_user_id, f.addressee_user_id))
                ORDER BY u.display_name ASC";

        $st = $pdo->prepare($sql);
        $st->execute([':uid' => $userId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        Http::json(200, ['friends' => $rows]);
    }

    public static function listRequests(): void
    {
        mylog("en listsRequest (friends)");
        Middleware::requireAppSignature();
        $userId = Middleware::requireAuthUserId();
        $pdo = DB::pdo();

        // Recibidas (yo soy addressee)
        $inSql = "SELECT f.id,
                         f.requester_user_id AS from_user_id,
                         u.display_name, u.email,
                         f.status, f.created_at, f.updated_at
                  FROM friends f
                  JOIN users u ON u.id = f.requester_user_id
                  WHERE f.deleted_at IS NULL
                    AND f.status = 'pending'
                    AND f.addressee_user_id = :uid
                  ORDER BY f.created_at DESC";

        $stIn = $pdo->prepare($inSql);
        $stIn->execute([':uid' => $userId]);
        $incoming = $stIn->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Enviadas (yo soy requester)
        $outSql = "SELECT f.id,
                          f.addressee_user_id AS to_user_id,
                          u.display_name, u.email,
                          f.status, f.created_at, f.updated_at
                   FROM friends f
                   JOIN users u ON u.id = f.addressee_user_id
                   WHERE f.deleted_at IS NULL
                     AND f.status = 'pending'
                     AND f.requester_user_id = :uid
                   ORDER BY f.created_at DESC";

        $stOut = $pdo->prepare($outSql);
        $stOut->execute([':uid' => $userId]);
        $outgoing = $stOut->fetchAll(PDO::FETCH_ASSOC) ?: [];

        Http::json(200, ['incoming' => $incoming, 'outgoing' => $outgoing]);
    }

    public static function createRequest(array $body): void
    {
        try {
            mylog("en create request");
            mylog("data in: " . (string)$body);
            Middleware::requireAppSignature();
            $userId = Middleware::requireAuthUserId();

            $toUserId = isset($body['toUserId']) ? (int)$body['toUserId'] : 0;

            // Compatibilidad: acepta "toEmail" o "email"
            $toEmail = '';
            if (isset($body['toEmail'])) {
                $toEmail = trim((string)$body['toEmail']);
            } elseif (isset($body['email'])) {
                $toEmail = trim((string)$body['email']);
            }

            if ($toUserId <= 0 && $toEmail === '') {
                Http::json(400, ['error' => 'bad_request', 'message' => 'toUserId or toEmail required']);
                return;
            }

            $pdo = DB::pdo();

            if ($toUserId <= 0 && $toEmail !== '') {
                $st = $pdo->prepare("SELECT id FROM users WHERE email = :email AND deleted_at IS NULL LIMIT 1");
                $st->execute([':email' => $toEmail]);
                $row = $st->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    Http::json(404, ['error' => 'not_found', 'message' => 'user_not_found']);
                    return;
                }
                $toUserId = (int)$row['id'];
            }

            if ($toUserId === $userId) {
                Http::json(400, ['error' => 'bad_request', 'message' => 'cannot_friend_self']);
                return;
            }

            $check = $pdo->prepare(
                "SELECT * 
                         FROM friends
                         WHERE deleted_at IS NULL
                           AND ((requester_user_id = :a AND addressee_user_id = :b)
                             OR (requester_user_id = :b AND addressee_user_id = :a))
                         LIMIT 1"
            );
            $check->execute([':a' => $userId, ':b' => $toUserId]);
            $existing = $check->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                if (($existing['status'] ?? '') === 'accepted') {
                    Http::json(409, ['error' => 'conflict', 'message' => 'already_friends']);
                    return;
                }
                Http::json(409, ['error' => 'conflict', 'message' => 'request_already_exists', 'request' => $existing]);
                return;
            }

            $ins = $pdo->prepare(
                "INSERT " . " INTO friends (requester_user_id, addressee_user_id, status, created_at, updated_at)
             VALUES (:req, :add, 'pending', NOW(), NOW())"
            );
            $ins->execute([':req' => $userId, ':add' => $toUserId]);

            Http::json(201, ['requestId' => (int)$pdo->lastInsertId(), 'status' => 'pending']);
        }
        catch(Exception $e){
            mylog("CreateRequest error : " . $e->getMessage());
            Http::json(201, ['requestId' => 0, 'status' => 'not inserted']);
        }
    }

    public static function acceptRequest(int $requestId): void
    {
        mylog("en create acceptRequest");
        Middleware::requireAppSignature();
        $userId = Middleware::requireAuthUserId();
        $pdo = DB::pdo();

        $st = $pdo->prepare("SELECT * FROM friends WHERE id = :id AND deleted_at IS NULL LIMIT 1");
        $st->execute([':id' => $requestId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            Http::json(404, ['error' => 'not_found', 'message' => 'request_not_found']);
            return;
        }

        if (($row['status'] ?? '') !== 'pending') {
            Http::json(409, ['error' => 'conflict', 'message' => 'request_not_pending']);
            return;
        }

        if ((int)$row['addressee_user_id'] !== $userId) {
            Http::json(403, ['error' => 'forbidden', 'message' => 'not_request_recipient']);
            return;
        }

        $upd = $pdo->prepare("UPDATE friends SET status = 'accepted', updated_at = NOW() WHERE id = :id");
        $upd->execute([':id' => $requestId]);

        Http::json(200, ['requestId' => $requestId, 'status' => 'accepted']);
    }

    public static function rejectRequest(int $requestId): void
    {
        mylog("en create rejectRequest");
        Middleware::requireAppSignature();
        $userId = Middleware::requireAuthUserId();
        $pdo = DB::pdo();

        $st = $pdo->prepare("SELECT * FROM friends WHERE id = :id AND deleted_at IS NULL LIMIT 1");
        $st->execute([':id' => $requestId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            Http::json(404, ['error' => 'not_found', 'message' => 'request_not_found']);
            return;
        }

        if (($row['status'] ?? '') !== 'pending') {
            Http::json(409, ['error' => 'conflict', 'message' => 'request_not_pending']);
            return;
        }

        if ((int)$row['addressee_user_id'] !== $userId) {
            Http::json(403, ['error' => 'forbidden', 'message' => 'not_request_recipient']);
            return;
        }

        $upd = $pdo->prepare("UPDATE friends SET status = 'rejected', updated_at = NOW() WHERE id = :id");
        $upd->execute([':id' => $requestId]);

        Http::json(200, ['requestId' => $requestId, 'status' => 'rejected']);
    }

    public static function deleteFriend(int $friendRowId): void
    {
        mylog("en deleteRequest");
        Middleware::requireAppSignature();
        $userId = Middleware::requireAuthUserId();
        $pdo = DB::pdo();

        $st = $pdo->prepare("SELECT * FROM friends WHERE id = :id AND deleted_at IS NULL LIMIT 1");
        $st->execute([':id' => $friendRowId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            Http::json(404, ['error' => 'not_found', 'message' => 'friend_not_found']);
            return;
        }

        if (($row['status'] ?? '') !== 'accepted') {
            Http::json(409, ['error' => 'conflict', 'message' => 'not_friends']);
            return;
        }

        $isParticipant = ((int)$row['requester_user_id'] === $userId) || ((int)$row['addressee_user_id'] === $userId);
        if (!$isParticipant) {
            Http::json(403, ['error' => 'forbidden', 'message' => 'not_allowed']);
            return;
        }

        $upd = $pdo->prepare("UPDATE friends SET deleted_at = NOW(), updated_at = NOW() WHERE id = :id");
        $upd->execute([':id' => $friendRowId]);

        Http::json(200, ['id' => $friendRowId, 'deleted' => true]);
    }
}
