<?php

declare(strict_types=1);

it('TenantUrl is invoked from at least one mail template', function () {
    $files = array_merge(
        glob(app_path('Mail/*.php')) ?: [],
        glob(resource_path('views/emails/**/*.blade.php')) ?: [],
        glob(resource_path('views/email/**/*.blade.php')) ?: [],
    );

    $found = false;
    foreach ($files as $file) {
        $content = file_get_contents($file);
        if ($content !== false && str_contains($content, 'TenantUrl::')) {
            $found = true;
            break;
        }
    }

    expect($found)->toBeTrue('Expected at least one Mail class or Blade template to reference TenantUrl::');
});
