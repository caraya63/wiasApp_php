<?php
declare(strict_types=1);

try {

    ini_set('display_errors', "0");
    ini_set('log_errors', "1");
    ini_set('error_log', __DIR__ . '/../src/php-error.log');

    require __DIR__ . '/../src/Mailer.php';
    require __DIR__ . '/../src/Config.php';
    require __DIR__ . '/../src/Db.php';
    require __DIR__ . '/../src/Http.php';
    require __DIR__ . '/../src/Middleware.php';
    require __DIR__ . '/../src/AuthController.php';
    require __DIR__ . '/../src/FriendsController.php';
    require __DIR__ . '/../src/WishListsController.php';

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
    mylog("\nentrada... method: " . $method . "  path: " . $path . "  uri: " . $uri . "\n");

    /******************************************************************** */
    // LINK PREVIEW (unfurl) - backend
    /******************************************************************** */
    if ($method === 'GET' && $path === '/link-preview') {
        require __DIR__ . '/../src/utils/link_preview.php';
        exit;
    }


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
    if ($method === 'GET' && $path === '/friends') {
        FriendsController::listFriends();
        exit;
    }

    if ($method === 'GET' && $path === '/friends/requests') {
        FriendsController::listRequests();
        exit;
    }

    if ($method === 'POST' && $path === '/friends/requests') {
        $body = Http::jsonBody();
        FriendsController::createRequest($body);
        exit;
    }

    if ($method === 'POST' && preg_match('#^/friends/requests/(\d+)/accept$#', $path, $m)) {
        FriendsController::acceptRequest( (int)$m[1]);
        exit;
    }

    if ($method === 'POST' && preg_match('#^/friends/requests/(\d+)/reject$#', $path, $m)) {
        FriendsController::rejectRequest( (int)$m[1]);
        exit;
    }

    if ($method === 'DELETE' && preg_match('#^/friends/(\d+)$#', $path, $m)) {
        FriendsController::deleteFriend( (int)$m[1]);
        exit;
    }

    /******************************************************************** */
    //SERVICIOS DE WISHLIST
    /******************************************************************** */

    // mis Wishlists y listas visibles
    if ($method === 'GET' && $path === '/wishlists/mine') {
        WishlistsController::listWishlists();
        exit;
    }

    // crear lista
    if ($method === 'POST' && $path === '/wishlists') {
        $body = Http::jsonBody();
        WishlistsController::createWishlist( $body);
        exit;
    }

    //detalle de datos de lista
    if ($method === 'GET' && preg_match('#^/wishlists/(\d+)$#', $path, $m)) {
        WishlistsController::getWishlist((int)$m[1]);
        exit;
    }

    //renombrar, actualizar lista
    if ($method === 'PATCH' && preg_match('#^/wishlists/(\d+)$#', $path, $m)) {
        $body = Http::jsonBody();
        WishlistsController::updateWishlist((int)$m[1], $body);
        exit;
    }

    // "borrar" lista
    if ($method === 'DELETE' && preg_match('#^/wishlists/(\d+)$#', $path, $m)) {
        WishlistsController::deleteWishlist( (int)$m[1]);
        exit;
    }

    // Items de lista
    if ($method === 'GET' && preg_match('#^/wishlists/(\d+)/items$#', $path, $m)) {
        WishlistsController::listItems( (int)$m[1]);
        exit;
    }

    // Agregar item a lista (texto/imagen/link + visibilidad)
    if ($method === 'POST' && preg_match('#^/wishlists/(\d+)/items$#', $path, $m)) {
        $body = Http::jsonBody();
        WishlistsController::createItem( (int)$m[1], $body);
        exit;
    }

    //Actualizar Item de lista
    if ($method === 'PATCH' && preg_match('#^/wishlists/(\d+)/items/(\d+)$#', $path, $m)) {
        $body = Http::jsonBody();
        WishlistsController::updateItem((int)$m[1], (int)$m[2], $body);
        exit;
    }

    //"Eliminar" item de lista
    if ($method === 'DELETE' && preg_match('#^/wishlists/(\d+)/items/(\d+)$#', $path, $m)) {
        WishlistsController::deleteItem( (int)$m[1], (int)$m[2]);
        exit;
    }

    // Permissions (compartir con usuarios)
    if ($method === 'POST' && preg_match('#^/wishlists/(\d+)/share$#', $path, $m)) {
        $body = Http::jsonBody();
        WishlistsController::shareWishlist((int)$m[1], $body);
        exit;
    }

    if ($method === 'GET' && preg_match('#^/wishlists/(\d+)/share$#', $path, $m)) {
        WishlistsController::listShares((int)$m[1]);
        exit;
    }

    if ($method === 'DELETE' && preg_match('#^/wishlists/(\d+)/share/(\d+)$#', $path, $m)) {
        WishlistsController::unshareWishlist((int)$m[1], (int)$m[2]);
        exit;
    }

// Share links (token público readonly)
    if ($method === 'POST' && preg_match('#^/wishlists/(\d+)/share-links$#', $path, $m)) {
        $body = Http::jsonBody();
        WishlistsController::createShareLink( (int)$m[1], $body);
        exit;
    }

    if ($method === 'GET' && preg_match('#^/wishlists/(\d+)/share-links$#', $path, $m)) {
        WishlistsController::listShareLinks( (int)$m[1]);
        exit;
    }

    if ($method === 'DELETE' && preg_match('#^/wishlists/(\d+)/share-links/(\d+)$#', $path, $m)) {
        WishlistsController::revokeShareLink( (int)$m[1], (int)$m[2]);
        exit;
    }

    // Acceso por token (sin auth)
    if ($method === 'GET' && preg_match('#^/share/wishlists/([a-fA-F0-9]{64})$#', $path, $m)) {
        WishlistsController::getWishlistByToken($m[1]);
        exit;
    }

    if ($method === 'GET' && preg_match('#^/share/wishlists/([a-fA-F0-9]{64})/items$#', $path, $m)) {
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