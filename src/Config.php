<?php

declare(strict_types=1);

final class Config
{
    public const DB_HOST = '127.0.0.1';
    public const DB_NAME = 'claud284_wish_app';
    public const DB_USER = 'claud284_wp840';
    public const DB_PASS = 'laclave_deBD';

    public const JWT_SECRET = 'ESTA_ES_LA_CLAVE_DE_APP_PARA_JWT_DEB_SER_LARGA';
    public const JWT_ISSUER = 'wishapp-api';
    public const JWT_TTL_SECONDS = 60 * 60 * 24 * 1; // 1 días

    // Señal "viene de la app"
    public const APP_CLIENT_ID = 'wishapp.mobile';
    public const APP_SIGNATURE_KEY = 'lxCjzaqObIsISb869Swxz9xlWDQr7EjXxb9J1bYrPkAbEVtx6pfRBD79b5j2b1Aw';
    public const APP_SIGNATURE_MAX_SKEW_SECONDS = 300; // 5 minutos
}