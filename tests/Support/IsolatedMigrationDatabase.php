<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Support\Facades\DB;

final class IsolatedMigrationDatabase
{
    public static function run(Closure $createSchema, Closure $test): mixed
    {
        $connection = 'isolated_migration_test';
        $originalConnection = DB::getDefaultConnection();

        config()->set("database.connections.{$connection}", [
            ...config('database.connections.sqlite'),
            'database' => ':memory:',
            'foreign_key_constraints' => true,
        ]);

        DB::purge($connection);
        DB::setDefaultConnection($connection);

        try {
            $createSchema();

            return $test();
        } finally {
            DB::setDefaultConnection($originalConnection);
            DB::purge($connection);
        }
    }
}
