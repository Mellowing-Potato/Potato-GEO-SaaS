<?php

use App\Providers\AppServiceProvider;
use App\Providers\OutboundServiceProvider;
use App\Providers\TenantServiceProvider;
use App\Services\GeoServiceProvider;

return [
    AppServiceProvider::class,
    OutboundServiceProvider::class,
    TenantServiceProvider::class,
    GeoServiceProvider::class,
];
