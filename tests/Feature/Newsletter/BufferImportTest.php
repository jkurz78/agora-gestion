<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('migration adds the 4 admin processing columns to newsletter buffer', function () {
    expect(Schema::hasColumn('newsletter_subscription_requests', 'ignored_at'))->toBeTrue();
    expect(Schema::hasColumn('newsletter_subscription_requests', 'desinscription_traitee_at'))->toBeTrue();
    expect(Schema::hasColumn('newsletter_subscription_requests', 'desinscription_action'))->toBeTrue();
    expect(Schema::hasColumn('newsletter_subscription_requests', 'processed_by_user_id'))->toBeTrue();
});
