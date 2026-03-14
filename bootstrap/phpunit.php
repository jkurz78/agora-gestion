<?php

declare(strict_types=1);

// Load the shared vendor autoload
$loader = require __DIR__ . '/../vendor/autoload.php';

// Register the worktree's own app/ and database/ directories so that
// classes created in this worktree (not yet merged into the main branch)
// are discoverable during testing.
$worktreeBase = dirname(__DIR__);

$loader->addPsr4('App\\', $worktreeBase . '/app/');
$loader->addPsr4('Database\\Factories\\', $worktreeBase . '/database/factories/');
$loader->addPsr4('Database\\Seeders\\', $worktreeBase . '/database/seeders/');
$loader->addPsr4('Tests\\', $worktreeBase . '/tests/');
