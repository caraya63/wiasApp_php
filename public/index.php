<?php
declare(strict_types=1);

try {
   // require __DIR__ . '/../vendor/autoload.php';

    require __DIR__ . '/../src/Config.php';
    require __DIR__ . '/../src/Db.php';
    require __DIR__ . '/../src/Http.php';
    require __DIR__ . '/../src/Middleware.php';
    require __DIR__ . '/../src/AuthController.php';
    require __DIR__ . '/../src/FriendsController.php';
    require __DIR__ . '/../src/WishlistsController.php';

    function mylog(string $msg) : void
    {
        error_log($msg);
    }
    function getPathAfterIndex(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $script = $_SERVER['SCRIPT_NAME'] ?? '';

        // Quitar query string
        $uri = strtok($uri, '?');

        // Buscar index.php y devolver lo que viene después
        $pos = strpos($uri, $script);
        if ($pos !== false) {
            return substr($uri, strlen($script)) ?: '/';
        }

        return '/';
    }

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    //$path = strtok($uri, '?') ?: '/';
    $path = getPathAfterIndex();
    mylog("entrada... method: " . $method . "  path: " . $path . "\n");

    /******************************************************************** */
    //SERVICIOS DE LOGIN Y REGISTRO
    /******************************************************************** */
    if ($method === 'POST' && $path === '/auth/register') {
        mylog("en register...\n");
        AuthController::register();
    }
    if ($method === 'POST' && $path === '/auth/login') {
        mylog("en login...\n");
        AuthController::login();
    }
    if ($method === 'POST' && $path === '/auth/validateAccount') {
        mylog("en validateAccount...\n");
        AuthController::validateAccount();
    }
    if ($method === 'GET' && $path === '/auth/me') {
        mylog("en auth-me...\n");
        AuthController::me();
    }
    if ($method === 'PATCH' && $path === '/users/me') {
        mylog("en users-me...\n");
        AuthController::updateMe();
    }

    /******************************************************************** */
    // SERVICIOS DE "FRIENDS"
    /******************************************************************** */
    if ($method === 'GET' && $uri === '/friends') {
        FriendsController::listFriends();
        exit;
    }

    if ($method === 'GET' && $uri === '/friends/requests') {
        FriendsController::listRequests();
        exit;
    }

    if ($method === 'POST' && $uri === '/friends/requests') {
        $body = Http::bodyJson();
        FriendsController::createRequest($body);
        exit;
    }

    if ($method === 'POST' && preg_match('#^/friends/requests/(\d+)/accept$#', $uri, $m)) {
        FriendsController::acceptRequest( (int)$m[1]);
        exit;
    }

    if ($method === 'POST' && preg_match('#^/friends/requests/(\d+)/reject$#', $uri, $m)) {
        FriendsController::rejectRequest( (int)$m[1]);
        exit;
    }

    if ($method === 'DELETE' && preg_match('#^/friends/(\d+)$#', $uri, $m)) {
        FriendsController::deleteFriend( (int)$m[1]);
        exit;
    }

    /******************************************************************** */
    //SERVICIOS DE WISHLIST
    /******************************************************************** */

    // mis Wishlists y listas visibles
    if ($method === 'GET' && $uri === '/wishlists') {
        WishlistsController::listWishlists();
        exit;
    }

    // crear lista
    if ($method === 'POST' && $uri === '/wishlists') {
        $body = Http::bodyJson();
        WishlistsController::createWishlist( $body);
        exit;
    }

    //detalle de datos de lista
    if ($method === 'GET' && preg_match('#^/wishlists/(\d+)$#', $uri, $m)) {
        WishlistsController::getWishlist((int)$m[1]);
        exit;
    }

    //renombrar, actualizar lista
    if ($method === 'PATCH' && preg_match('#^/wishlists/(\d+)$#', $uri, $m)) {
        $body = Http::bodyJson();
        WishlistsController::updateWishlist((int)$m[1], $body);
        exit;
    }

    // "borrar" lista
    if ($method === 'DELETE' && preg_match('#^/wishlists/(\d+)$#', $uri, $m)) {
        WishlistsController::deleteWishlist( (int)$m[1]);
        exit;
    }

    // Items de lista
    if ($method === 'GET' && preg_match('#^/wishlists/(\d+)/items$#', $uri, $m)) {
        WishlistsController::listItems( (int)$m[1]);
        exit;
    }

    // Agregar item a lista (texto/imagen/link + visibilidad)
    if ($method === 'POST' && preg_match('#^/wishlists/(\d+)/items$#', $uri, $m)) {
        $body = Http::bodyJson();
        WishlistsController::createItem( (int)$m[1], $body);
        exit;
    }

    //Actualizar Item de lista
    if ($method === 'PATCH' && preg_match('#^/wishlists/(\d+)/items/(\d+)$#', $uri, $m)) {
        $body = Http::bodyJson();
        WishlistsController::updateItem((int)$m[1], (int)$m[2], $body);
        exit;
    }

    //"Eliminar" item de lista
    if ($method === 'DELETE' && preg_match('#^/wishlists/(\d+)/items/(\d+)$#', $uri, $m)) {
        WishlistsController::deleteItem( (int)$m[1], (int)$m[2]);
        exit;
    }

    // Permissions (compartir con usuarios)
    if ($method === 'POST' && preg_match('#^/wishlists/(\d+)/share$#', $uri, $m)) {
        $body = Http::bodyJson();
        WishlistsController::shareWishlist((int)$m[1], $body);
        exit;
    }

    if ($method === 'GET' && preg_match('#^/wishlists/(\d+)/share$#', $uri, $m)) {
        WishlistsController::listShares((int)$m[1]);
        exit;
    }

    if ($method === 'DELETE' && preg_match('#^/wishlists/(\d+)/share/(\d+)$#', $uri, $m)) {
        WishlistsController::unshareWishlist((int)$m[1], (int)$m[2]);
        exit;
    }

// Share links (token público readonly)
    if ($method === 'POST' && preg_match('#^/wishlists/(\d+)/share-links$#', $uri, $m)) {
        $body = Http::bodyJson();
        WishlistsController::createShareLink( (int)$m[1], $body);
        exit;
    }

    if ($method === 'GET' && preg_match('#^/wishlists/(\d+)/share-links$#', $uri, $m)) {
        WishlistsController::listShareLinks( (int)$m[1]);
        exit;
    }

    if ($method === 'DELETE' && preg_match('#^/wishlists/(\d+)/share-links/(\d+)$#', $uri, $m)) {
        WishlistsController::revokeShareLink( (int)$m[1], (int)$m[2]);
        exit;
    }

    // Acceso por token (sin auth)
    if ($method === 'GET' && preg_match('#^/share/wishlists/([a-fA-F0-9]{64})$#', $uri, $m)) {
        WishlistsController::getWishlistByToken($m[1]);
        exit;
    }

    if ($method === 'GET' && preg_match('#^/share/wishlists/([a-fA-F0-9]{64})/items$#', $uri, $m)) {
        WishlistsController::listItemsByToken($m[1]);
        exit;
    }



    echo "saliendo..\n";
    Http::notFound();
}
catch(Exception $e){
    error_log( "Error ".$e->getMessage());
    Http::forbidden("not found");
}