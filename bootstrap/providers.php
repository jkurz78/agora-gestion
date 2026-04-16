<?php

use App\Providers\AppServiceProvider;
use App\Providers\IncomingDocumentsServiceProvider;
use App\Providers\LayoutAssociationComposerProvider;

return [
    AppServiceProvider::class,
    IncomingDocumentsServiceProvider::class,
    LayoutAssociationComposerProvider::class,
];
