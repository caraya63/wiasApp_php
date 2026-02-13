<?php

class WishlistsController
{
    // =========================================================
    // Helpers
    // =========================================================

    private static function nowExpr(): string {
        return "CURRENT_TIMESTAMP(3)";
    }

    private static function getWishlistRow(int $wishlistId): ?array
    {
        $pdo = DB::pdo();
        $st = $pdo->prepare("SELECT " .
            "* FROM wishlist WHERE id = :id AND deleted=0 LIMIT 1");
        $st->execute([':id' => $wishlistId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function hasPermission(int $userId, int $wishlistId, array $roles): bool
    {
        $pdo = DB::pdo();
        $in = implode(",", array_fill(0, count($roles), "?"));
        $params = array_merge([$wishlistId, $userId], $roles);

        $st = $pdo->prepare("SELECT 1 " .
                                      "FROM wishlist_permission
                                      WHERE deleted=0
                                        AND wishlist_id = ?
                                        AND user_id = ?
                                        AND role IN ($in)
                                      LIMIT 1");
        $st->execute($params);
        return (bool)$st->fetchColumn();
    }

    private static function getRole(int $userId, int $wishlistId, int $ownerId): ?string
    {
        if ($userId === $ownerId) return 'owner';

        $pdo = DB::pdo();
        $st = $pdo->prepare("SELECT role " .
                                      "FROM wishlist_permission
                                      WHERE deleted=0 AND wishlist_id = :wid AND user_id = :uid
                                      LIMIT 1");
        $st->execute([':wid' => $wishlistId, ':uid' => $userId]);
        $role = $st->fetchColumn();
        return $role ? (string)$role : null;
    }

    private static function areFriends(int $a, int $b): bool
    {
        $pdo = DB::pdo();
        $st = $pdo->prepare("SELECT 1 " .
                                     "FROM friends
                                      WHERE deleted=0
                                        AND status = 'accepted'
                                        AND (
                                          (requester_user_id = :a AND addressee_user_id = :b)
                                          OR
                                          (requester_user_id = :b AND addressee_user_id = :a)
                                        )
                                      LIMIT 1");
        $st->execute([':a' => $a, ':b' => $b]);
        return (bool)$st->fetchColumn();
    }

    /**
     * Regla de acceso a la wishlist (contenedor):
     * - owner => sí
     * - public => cualquiera autenticado => sí
     * - friends => amigos aceptados => sí
     * - private => solo por permiso explícito (wishlist_permission) => sí
     */
    private static function canViewWishlist(int $viewerId, array $wl): bool
    {

        $ownerId = (int)$wl['owner_id'];
        if ($ownerId === $viewerId) return true;

        $vis = (string)($wl['visibility'] ?? 'private');

        if ($vis === 'public') return true;

        // Permisos explícitos siempre aplican (reader/editor/owner)
        if (self::hasPermission($viewerId, (int)$wl['id'], ['owner','editor','reader'])) return true;

        if ($vis === 'friends') {
            return self::areFriends($viewerId, $ownerId);
        }

        return false;
    }

    private static function canEditWishlist(int $userId, array $wl): bool
    {
        $ownerId = (int)$wl['owner_id'];
        if ($ownerId === $userId) return true;
        return self::hasPermission($userId, (int)$wl['id'], ['editor','owner']);
    }

    private static function canManageShares(int $userId, array $wl): bool
    {
        return (int)$wl['owner_id'] === $userId;
    }

    private static function randomToken64Hex(): string
    {
        try {
            return bin2hex(random_bytes(32));
        } catch (Exception $e) {
            error_log("error en randomToken64Hex ".$e->getMessage());
            return "";
        }
    }

    private static function isShareLinkValid(array $row): bool
    {
        if (!empty($row['deleted_at'])) return false;
        if (!empty($row['revoked_at'])) return false;

        if (!empty($row['expires_at'])) {
            $expires = strtotime($row['expires_at']);
            if ($expires !== false && $expires <= time()) return false;
        }
        return true;
    }

    // =========================================================
    // Endpoints: Wishlists
    // =========================================================

    public static function listWishlists(): void
    {
        $userId = 0;
        try {
            Middleware::requireAppSignature();
            $userId = Middleware::requireAuthUserId();
            $pdo = DB::pdo();

            // 1) Mis wishlists
            $stMine = $pdo->prepare("SELECT id, owner_id, title, description, visibility, created_at, updated_at " .
                                              "FROM wishlist
                                              WHERE deleted=0 AND owner_id = :uid
                                              ORDER BY updated_at DESC");
            $stMine->execute([':uid' => $userId]);
            $mine = $stMine->fetchAll(PDO::FETCH_ASSOC);
            //mylog("wishlists array mine: " . var_dump($mine));
            // 2) Wishlists con permiso explícito (reader/editor)
            $stPerm = $pdo->prepare("SELECT w.id, w.owner_id, w.title, w.description, w.visibility, w.created_at, w.updated_at,p.role " .
                                                "FROM wishlist_permission p 
                                                JOIN wishlist w ON w.id = p.wishlist_id AND NOT(w.owner_id = :uid)
                                                  WHERE p.deleted=0 AND w.deleted=0 AND p.user_id = :uid 
                                                  ORDER BY w.updated_at DESC");
            $stPerm->execute([':uid' => $userId]);
            $shared = $stPerm->fetchAll(PDO::FETCH_ASSOC);

            // 3) Wishlists visibles por "friends" (owner = mis amigos) + public
            // Nota: para no duplicar (mías o con permiso) usamos NOT EXISTS.
            $stVisible = $pdo->prepare("SELECT w.id, w.owner_id, w.title, w.description, w.visibility, w.created_at, w.updated_at " .
                                                  "FROM wishlist w
                                                  WHERE w.deleted=0 AND w.owner_id <> :uid AND (w.visibility = 'public' 
                                                      OR (w.visibility = 'friends' AND 
                                                        EXISTS (SELECT 1 FROM friends f
                                                            WHERE f.deleted=0 AND f.status = 'accepted' AND (
                                                              (f.requester_user_id = :uid AND f.addressee_user_id = w.owner_id) OR
                                                              (f.requester_user_id = w.owner_id AND f.addressee_user_id = :uid)
                                                            )
                                                        )
                                                      )
                                                    ) AND NOT EXISTS (SELECT 1 FROM wishlist_permission p
                                                                    WHERE p.deleted=0 AND p.wishlist_id = w.id AND p.user_id = :uid )
                                                  ORDER BY w.updated_at DESC LIMIT 200");
            $stVisible->execute([':uid' => $userId]);
            $visible = $stVisible->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($mine)) $mine = [];
            //mylog("despues del if wishlists array mine: " . var_dump($mine));
            if (!is_array($shared)) $shared = [];
            if (!is_array($visible)) $visible = [];
            Http::json(200, ['mine' => $mine, 'shared' => $shared, 'visible' => $visible]);
        }
        catch(Exception $e){
            mylog("Error en listWishlists, " . $e->getMessage());
            Http::json(422, ['error' => 'bad_response', 'message' => 'we could not load data for userid: '.$userId]);
        }
    }

    public static function createWishlist( array $body): void
    {
        Middleware::requireAppSignature();
        $userId = Middleware::requireAuthUserId();
        $title = trim((string)($body['title'] ?? ''));
        $description = array_key_exists('description', $body) ? (string)$body['description'] : null;
        $visibility = (string)($body['visibility'] ?? 'private');

        if ($title === '') {
            Http::json(400, ['error' => 'bad_request', 'message' => 'title_required']);
            return;
        }
        if (!in_array($visibility, ['private','friends','public'], true)) $visibility = 'private';
        if ($description !== null) $description = trim($description);

        $pdo = DB::pdo();
        $st = $pdo->prepare("INSERT " . " INTO wishlist (owner_id, title, description, visibility, created_at, updated_at) " .
                                    "VALUES (:uid, :title, :desc, :vis, " . self::nowExpr() . ", " . self::nowExpr() . ")");
        $st->execute([
            ':uid' => $userId,
            ':title' => $title,
            ':desc' => $description,
            ':vis' => $visibility,
        ]);

        $id = (int)$pdo->lastInsertId();

        // Permiso owner explícito (opcional pero útil)
        $sql = "INSERT " . " INTO wishlist_permission (wishlist_id, user_id, role, created_at, updated_at) " .
                                "VALUES (:wid, :uid, 'owner', " . self::nowExpr() . ", " . self::nowExpr() . ") " .
                                " ON DUPLICATE KEY UPDATE role='owner', deleted_at=NULL, updated_at=VALUES(updated_at)";
        $pdo->prepare($sql)->execute([':wid' => $id, ':uid' => $userId]);

        Http::json(201, ['id' => $id]);
    }

    public static function getWishlist(int $wishlistId): void
    {
        Middleware::requireAppSignature();
        $userId = Middleware::requireAuthUserId();
        $wl = self::getWishlistRow($wishlistId);
        if (!$wl) {
            Http::json(404, ['error' => 'not_found', 'message' => 'wishlist_not_found']);
            return;
        }
        if (!self::canViewWishlist($userId, $wl)) {
            Http::json(403, ['error' => 'forbidden', 'message' => 'no_access']);
            return;
        }

        $role = self::getRole($userId, $wishlistId, (int)$wl['owner_id']);

        Http::json(200, [
            'wishlist' => [
                'id' => (int)$wl['id'],
                'owner_id' => (int)$wl['owner_id'],
                'title' => $wl['title'],
                'description' => $wl['description'],
                'visibility' => $wl['visibility'],
                'created_at' => $wl['created_at'],
                'updated_at' => $wl['updated_at'],
            ],
            'viewerRole' => $role,
        ]);
    }

    public static function updateWishlist( int $wishlistId, array $body): void
    {
        Middleware::requireAppSignature();
        $userId = Middleware::requireAuthUserId();
        $wl = self::getWishlistRow($wishlistId);
        if (!$wl) {
            Http::json(404, ['error' => 'not_found', 'message' => 'wishlist_not_found']);
            return;
        }
        if (!self::canEditWishlist($userId, $wl)) {
            Http::json(403, ['error' => 'forbidden', 'message' => 'only_owner_or_editor']);
            return;
        }

        $title = array_key_exists('title', $body) ? trim((string)$body['title']) : $wl['title'];
        $description = array_key_exists('description', $body) ? trim((string)$body['description']) : $wl['description'];
        $visibility = array_key_exists('visibility', $body) ? (string)$body['visibility'] : $wl['visibility'];

        if ($title === '') {
            Http::json(400, ['error' => 'bad_request', 'message' => 'title_required']);
            return;
        }
        if (!in_array($visibility, ['private','friends','public'], true)) $visibility = (string)$wl['visibility'];

        $pdo = DB::pdo();
        $st = $pdo->prepare("UPDATE wishlist " .
                                    "SET title = :title,description = :desc,visibility = :vis,updated_at = " . self::nowExpr() .
                                    "WHERE id = :id AND deleted=0");
        $st->execute([
            ':title' => $title,
            ':desc' => $description,
            ':vis' => $visibility,
            ':id' => $wishlistId
        ]);

        Http::json(200, ['updated' => true]);
    }

    public static function deleteWishlist( int $wishlistId): void
    {
        Middleware::requireAppSignature();
        $userId = Middleware::requireAuthUserId();
        $wl = self::getWishlistRow($wishlistId);
        if (!$wl) {
            Http::json(404, ['error' => 'not_found', 'message' => 'wishlist_not_found']);
            return;
        }
        if ((int)$wl['owner_id'] !== $userId) {
            Http::json(403, ['error' => 'forbidden', 'message' => 'only_owner']);
            return;
        }

        $pdo = DB::pdo();

        $pdo->prepare("UPDATE wishlist " .
                                " SET deleted_at = " . self::nowExpr() . ", updated_at = " . self::nowExpr() .
                                " WHERE id = :id AND deleted=0")->execute([':id' => $wishlistId]);

        $pdo->prepare("UPDATE wishlist_item " .
                              " SET deleted_at = " . self::nowExpr() . ", updated_at = " . self::nowExpr() .
                              " WHERE wishlist_id = :id AND deleted=0")->execute([':id' => $wishlistId]);

        $pdo->prepare("UPDATE wishlist_permission " .
                              " SET deleted_at = " . self::nowExpr() . ", updated_at = " . self::nowExpr() .
                              " WHERE wishlist_id = :id AND deleted=0")->execute([':id' => $wishlistId]);

        $pdo->prepare("UPDATE wishlist_share_link " .
                              " SET deleted_at = " . self::nowExpr() . ", updated_at = " . self::nowExpr() .
                              " WHERE wishlist_id = :id AND deleted=0")->execute([':id' => $wishlistId]);

        Http::json(200, ['deleted' => true]);
    }

    // =========================================================
    // Endpoints: Items
    // =========================================================

    public static function listItems(int $wishlistId): void
    {
        Middleware::requireAppSignature();
        $userId = Middleware::requireAuthUserId();
        $wl = self::getWishlistRow($wishlistId);
        if (!$wl) {
            Http::json(404, ['error' => 'not_found', 'message' => 'wishlist_not_found']);
            return;
        }

        $canView = self::canViewWishlist($userId, $wl);
        if (!$canView) {
            Http::json(403, ['error' => 'forbidden', 'message' => 'no_access']);
            return;
        }

        $pdo = DB::pdo();

        // No-owner nunca ve items marcados como private.
        $whereExtra = "";
        if ((int)$wl['owner_id'] !== $userId) {
            $whereExtra = " AND visibility <> 'private' ";
        }

        $st = $pdo->prepare("SELECT id, wishlist_id, title, image_url, link_url, price_amount, price_currency, " . "
                                             notes, priority, visibility, is_gifted, created_at, updated_at
                                      FROM wishlist_item
                                      WHERE deleted=0
                                        AND wishlist_id = :wid
                                        $whereExtra
                                      ORDER BY updated_at DESC");
        $st->execute([':wid' => $wishlistId]);
        $items = $st->fetchAll(PDO::FETCH_ASSOC);

        Http::json(200, [
            'wishlist' => [
                'id' => (int)$wl['id'],
                'owner_id' => (int)$wl['owner_id'],
                'visibility' => $wl['visibility'],
            ],
            'isOwner' => ((int)$wl['owner_id'] === $userId),
            'items' => $items
        ]);
    }

    public static function createItem(int $wishlistId, array $body): void
    {
        Middleware::requireAppSignature();
        $userId = Middleware::requireAuthUserId();
        $wl = self::getWishlistRow($wishlistId);
        if (!$wl) {
            Http::json(404, ['error' => 'not_found', 'message' => 'wishlist_not_found']);
            return;
        }

        if (!self::canEditWishlist($userId, $wl)) {
            Http::json(403, ['error' => 'forbidden', 'message' => 'only_owner_or_editor_can_add']);
            return;
        }

        $title = trim((string)($body['title'] ?? ''));
        if ($title === '') {
            Http::json(400, ['error' => 'bad_request', 'message' => 'title_required']);
            return;
        }

        $imageUrl = array_key_exists('imageUrl', $body) ? trim((string)$body['imageUrl']) : null;
        $linkUrl  = array_key_exists('linkUrl', $body)  ? trim((string)$body['linkUrl'])  : null;

        $priceAmount = array_key_exists('priceAmount', $body) ? $body['priceAmount'] : null;
        $priceCurrency = array_key_exists('priceCurrency', $body) ? strtoupper(trim((string)$body['priceCurrency'])) : null;

        $notes = array_key_exists('notes', $body) ? trim((string)$body['notes']) : null;

        $priority = (string)($body['priority'] ?? 'medium');
        if (!in_array($priority, ['low','medium','high'], true)) $priority = 'medium';

        $visibility = (string)($body['visibility'] ?? 'inherit');
        if (!in_array($visibility, ['inherit','private'], true)) $visibility = 'inherit';

        $isGifted = !empty($body['isGifted']) ? 1 : 0;

        $pdo = DB::pdo();
        $sql = "INSERT " . " INTO wishlist_item " .
            "(wishlist_id, title, image_url, link_url, price_amount, price_currency, notes, priority, 
                                    visibility, is_gifted, created_at, updated_at) VALUES
                                    (:wid, :title, :img, :link, :pamt, :pcur, :notes, :prio, :vis, :gifted, " . self::nowExpr() . ", " . self::nowExpr() . ")";
        $st = $pdo->prepare($sql);

        $st->execute([
            ':wid' => $wishlistId,
            ':title' => $title,
            ':img' => $imageUrl ?: null,
            ':link' => $linkUrl ?: null,
            ':pamt' => ($priceAmount === null || $priceAmount === '') ? null : $priceAmount,
            ':pcur' => ($priceCurrency === null || $priceCurrency === '') ? null : $priceCurrency,
            ':notes' => $notes ?: null,
            ':prio' => $priority,
            ':vis' => $visibility,
            ':gifted' => $isGifted
        ]);

        Http::json(201, ['id' => (int)$pdo->lastInsertId()]);
    }

    public static function updateItem( int $wishlistId, int $itemId, array $body): void
    {
        Middleware::requireAppSignature();
        $userId = Middleware::requireAuthUserId();
        $wl = self::getWishlistRow($wishlistId);
        if (!$wl) {
            Http::json(404, ['error' => 'not_found', 'message' => 'wishlist_not_found']);
            return;
        }

        if (!self::canEditWishlist($userId, $wl)) {
            Http::json(403, ['error' => 'forbidden', 'message' => 'only_owner_or_editor']);
            return;
        }

        $pdo = DB::pdo();
        $st = $pdo->prepare("SELECT * " .
                                    " FROM wishlist_item
                                      WHERE id = :id AND wishlist_id = :wid AND deleted=0 LIMIT 1 ");
        $st->execute([':id' => $itemId, ':wid' => $wishlistId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            Http::json(404, ['error' => 'not_found', 'message' => 'item_not_found']);
            return;
        }

        $fields = [];
        $params = [':id' => $itemId, ':wid' => $wishlistId];

        $map = [
            'title' => 'title',
            'imageUrl' => 'image_url',
            'linkUrl' => 'link_url',
            'priceAmount' => 'price_amount',
            'priceCurrency' => 'price_currency',
            'notes' => 'notes',
            'priority' => 'priority',
            'visibility' => 'visibility',
            'isGifted' => 'is_gifted',
        ];

        foreach ($map as $in => $col) {
            if (!array_key_exists($in, $body)) continue;

            $val = $body[$in];

            if ($in === 'title') {
                $val = trim((string)$val);
                if ($val === '') {
                    Http::json(400, ['error' => 'bad_request', 'message' => 'title_required']);
                    return;
                }
            }

            if ($in === 'priceCurrency' && $val !== null && $val !== '') {
                $val = strtoupper(trim((string)$val));
            }

            if ($in === 'priority') {
                $val = (string)$val;
                if (!in_array($val, ['low','medium','high'], true)) $val = 'medium';
            }

            if ($in === 'visibility') {
                $val = (string)$val;
                if (!in_array($val, ['inherit','private'], true)) $val = 'inherit';
            }

            if ($in === 'isGifted') {
                $val = !empty($val) ? 1 : 0;
            }

            $fields[] = "$col = :$col";
            $params[":$col"] = ($val === '' ? null : $val);
        }

        if (!$fields) {
            Http::json(200, ['updated' => false, 'message' => 'no_changes']);
            return;
        }

        $sql = "UPDATE wishlist_item " . " SET " . implode(", ", $fields) . ",updated_at = " . self::nowExpr() . "
                  WHERE id = :id AND wishlist_id = :wid AND deleted=0";
        $upd = $pdo->prepare($sql);
        $upd->execute($params);

        Http::json(200, ['updated' => true]);
    }

    public static function deleteItem( int $wishlistId, int $itemId): void
    {
        Middleware::requireAppSignature();
        $userId = Middleware::requireAuthUserId();
        $wl = self::getWishlistRow($wishlistId);
        if (!$wl) {
            Http::json(404, ['error' => 'not_found', 'message' => 'wishlist_not_found']);
            return;
        }

        if (!self::canEditWishlist($userId, $wl)) {
            Http::json(403, ['error' => 'forbidden', 'message' => 'only_owner_or_editor']);
            return;
        }

        $pdo = DB::pdo();
        $pdo->prepare("UPDATE wishlist_item " . "
                              SET deleted_at = " . self::nowExpr() . ", updated_at = " . self::nowExpr() . "
                              WHERE id = :id AND wishlist_id = :wid AND deleted=0
                            ")->execute([':id' => $itemId, ':wid' => $wishlistId]);

        Http::json(200, ['deleted' => true]);
    }

    // =========================================================
    // Endpoints: Share with users (wishlist_permission)
    // =========================================================

    public static function shareWishlist( int $wishlistId, array $body): void
    {
        Middleware::requireAppSignature();
        $userId = Middleware::requireAuthUserId();
        $wl = self::getWishlistRow($wishlistId);
        if (!$wl) {
            Http::json(404, ['error' => 'not_found', 'message' => 'wishlist_not_found']);
            return;
        }
        if (!self::canManageShares($userId, $wl)) {
            Http::json(403, ['error' => 'forbidden', 'message' => 'only_owner']);
            return;
        }

        $withUserId = (int)($body['withUserId'] ?? 0);
        if ($withUserId <= 0) {
            Http::json(400, ['error' => 'bad_request', 'message' => 'withUserId_required']);
            return;
        }
        if ($withUserId === (int)$wl['owner_id']) {
            Http::json(400, ['error' => 'bad_request', 'message' => 'cannot_share_with_owner']);
            return;
        }

        $role = (string)($body['role'] ?? 'reader');
        if (!in_array($role, ['reader','editor'], true)) $role = 'reader';

        $pdo = DB::pdo();

        $st = $pdo->prepare("SELECT id " . "
                                      FROM wishlist_permission
                                      WHERE wishlist_id = :wid AND user_id = :uid
                                      LIMIT 1");
        $st->execute([':wid' => $wishlistId, ':uid' => $withUserId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $pdo->prepare("UPDATE wishlist_permission " . "
                                    SET role = :role,deleted_at = NULL,updated_at = " . self::nowExpr() . "
                                    WHERE id = :id ")->execute([':role' => $role, ':id' => (int)$row['id']]);

            Http::json(200, ['shared' => true, 'id' => (int)$row['id'], 'role' => $role]);
            return;
        }

        $ins = $pdo->prepare("INSERT " . " INTO wishlist_permission (wishlist_id, user_id, role, created_at, updated_at)
                                        VALUES (:wid, :uid, :role, " . self::nowExpr() . ", " . self::nowExpr() . ")");
        $ins->execute([':wid' => $wishlistId, ':uid' => $withUserId, ':role' => $role]);

        Http::json(201, ['shared' => true, 'id' => (int)$pdo->lastInsertId(), 'role' => $role]);
    }

    public static function listShares( int $wishlistId): void
    {
        Middleware::requireAppSignature();
        $userId = Middleware::requireAuthUserId();
        $wl = self::getWishlistRow($wishlistId);
        if (!$wl) {
            Http::json(404, ['error' => 'not_found', 'message' => 'wishlist_not_found']);
            return;
        }
        if (!self::canManageShares($userId, $wl)) {
            Http::json(403, ['error' => 'forbidden', 'message' => 'only_owner']);
            return;
        }

        $pdo = DB::pdo();
        $st = $pdo->prepare("SELECT p.user_id, p.role, p.created_at, p.updated_at,u.display_name, u.email " . "
                                  FROM wishlist_permission p JOIN users u ON u.id = p.user_id
                                  WHERE p.deleted=0 AND p.wishlist_id = :wid
                                  ORDER BY p.created_at DESC");
        $st->execute([':wid' => $wishlistId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        Http::json(200, ['shares' => $rows]);
    }

    public static function unshareWishlist( int $wishlistId, int $withUserId): void
    {
        Middleware::requireAppSignature();
        $userId = Middleware::requireAuthUserId();
        $wl = self::getWishlistRow($wishlistId);
        if (!$wl) {
            Http::json(404, ['error' => 'not_found', 'message' => 'wishlist_not_found']);
            return;
        }
        if (!self::canManageShares($userId, $wl)) {
            Http::json(403, ['error' => 'forbidden', 'message' => 'only_owner']);
            return;
        }

        $pdo = DB::pdo();
        $pdo->prepare("UPDATE wishlist_permission " . "
                              SET deleted_at = " . self::nowExpr() . ", updated_at = " . self::nowExpr() . "
                              WHERE wishlist_id = :wid AND user_id = :uid AND deleted=0
                            ")->execute([':wid' => $wishlistId, ':uid' => $withUserId]);

        Http::json(200, ['deleted' => true]);
    }

    // =========================================================
    // Endpoints: Share links (wishlist_share_link)
    // =========================================================

    public static function createShareLink( int $wishlistId, array $body): void
    {
        Middleware::requireAppSignature();
        $userId = Middleware::requireAuthUserId();
        $wl = self::getWishlistRow($wishlistId);
        if (!$wl) {
            Http::json(404, ['error' => 'not_found', 'message' => 'wishlist_not_found']);
            return;
        }
        if (!self::canManageShares($userId, $wl)) {
            Http::json(403, ['error' => 'forbidden', 'message' => 'only_owner']);
            return;
        }

        $expiresAt = array_key_exists('expiresAt', $body) ? trim((string)$body['expiresAt']) : null;
        $expiresSql = ($expiresAt !== null && $expiresAt !== '') ? $expiresAt : null;

        $token = self::randomToken64Hex();

        $pdo = DB::pdo();
        $st = $pdo->prepare("INSERT " . " INTO wishlist_share_link (wishlist_id, token, role, expires_at, revoked_at, created_by, created_at, updated_at) " .
                                        "VALUES (:wid, :tok, 'reader', :exp, NULL, :cby, " . self::nowExpr() . ", " . self::nowExpr() . ")");
        $st->execute([
            ':wid' => $wishlistId,
            ':tok' => $token,
            ':exp' => $expiresSql,
            ':cby' => $userId
        ]);

        Http::json(201, [
            'id' => (int)$pdo->lastInsertId(),
            'token' => $token,
            'expires_at' => $expiresSql
        ]);
    }

    public static function listShareLinks(int $wishlistId): void
    {
        Middleware::requireAppSignature();
        $userId = Middleware::requireAuthUserId();
        $wl = self::getWishlistRow($wishlistId);
        if (!$wl) {
            Http::json(404, ['error' => 'not_found', 'message' => 'wishlist_not_found']);
            return;
        }
        if (!self::canManageShares($userId, $wl)) {
            Http::json(403, ['error' => 'forbidden', 'message' => 'only_owner']);
            return;
        }

        $pdo = DB::pdo();
        $st = $pdo->prepare("SELECT id, token, role, expires_at, revoked_at, created_by, created_at, updated_at " . "
                                      FROM wishlist_share_link
                                      WHERE deleted=0 AND wishlist_id = :wid
                                      ORDER BY created_at DESC");
        $st->execute([':wid' => $wishlistId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        Http::json(200, ['shareLinks' => $rows]);
    }

    public static function revokeShareLink( int $wishlistId, int $shareLinkId): void
    {
        Middleware::requireAppSignature();
        $userId = Middleware::requireAuthUserId();
        $wl = self::getWishlistRow($wishlistId);
        if (!$wl) {
            Http::json(404, ['error' => 'not_found', 'message' => 'wishlist_not_found']);
            return;
        }
        if (!self::canManageShares($userId, $wl)) {
            Http::json(403, ['error' => 'forbidden', 'message' => 'only_owner']);
            return;
        }

        $pdo = DB::pdo();
        $pdo->prepare("UPDATE " . " wishlist_share_link SET revoked_at = " . self::nowExpr() . ", updated_at = " . self::nowExpr() . "
                                WHERE id = :id AND wishlist_id = :wid AND deleted=0")->execute([':id' => $shareLinkId, ':wid' => $wishlistId]);

        Http::json(200, ['revoked' => true]);
    }

    // =========================================================
    // Public (token) endpoints - readonly
    // =========================================================

    public static function getWishlistByToken(string $token): void
    {
        $pdo = DB::pdo();
        $st = $pdo->prepare("SELECT sl.*, w.id AS wishlist_id_real, w.owner_id, w.title, w.description, w.visibility, w.created_at, w.updated_at " . "
                                  FROM wishlist_share_link sl JOIN wishlist w ON w.id = sl.wishlist_id
                                  WHERE sl.token = :tok AND sl.deleted=0 AND w.deleted=0
                                  LIMIT 1");
        $st->execute([':tok' => $token]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row || !self::isShareLinkValid($row)) {
            Http::json(404, ['error' => 'not_found', 'message' => 'share_link_invalid']);
            return;
        }

        Http::json(200, [
            'wishlist' => [
                'id' => (int)$row['wishlist_id_real'],
                'owner_id' => (int)$row['owner_id'],
                'title' => $row['title'],
                'description' => $row['description'],
                'visibility' => $row['visibility'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ],
            'share' => [
                'role' => $row['role'],
                'expires_at' => $row['expires_at'],
            ]
        ]);
    }

    public static function listItemsByToken(string $token): void
    {
        $pdo = DB::pdo();
        $st = $pdo->prepare("SELECT sl.*, w.id AS wishlist_id_real " . "
                                      FROM wishlist_share_link sl JOIN wishlist w ON w.id = sl.wishlist_id
                                      WHERE sl.token = :tok AND sl.deleted=0 AND w.deleted=0
                                      LIMIT 1");
        $st->execute([':tok' => $token]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row || !self::isShareLinkValid($row)) {
            Http::json(404, ['error' => 'not_found', 'message' => 'share_link_invalid']);
            return;
        }

        $wishlistId = (int)$row['wishlist_id_real'];

        // Por token => readonly y nunca devolvemos items.visibility='private'
        $it = $pdo->prepare("SELECT id, wishlist_id, title, image_url, link_url, price_amount, price_currency, ". "
                                             notes, priority, visibility, is_gifted, created_at, updated_at
                                      FROM wishlist_item
                                      WHERE deleted=0 AND wishlist_id = :wid AND visibility <> 'private'
                                      ORDER BY updated_at DESC ");
        $it->execute([':wid' => $wishlistId]);
        $items = $it->fetchAll(PDO::FETCH_ASSOC);

        Http::json(200, [
            'wishlistId' => $wishlistId,
            'items' => $items,
            'readonly' => true
        ]);
    }
}
