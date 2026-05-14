<?php

use App\Providers\AppServiceProvider;
use App\Providers\IncomingDocumentsServiceProvider;
use App\Providers\LayoutAssociationComposerProvider;
use App\Providers\PortailServiceProvider;

return [
    AppServiceProvider::class,
    IncomingDocumentsServiceProvider::class,
    LayoutAssociationComposerProvider::class,
    PortailServiceProvider::class,
];
