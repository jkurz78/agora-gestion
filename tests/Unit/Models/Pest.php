<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Apply TestCase and RefreshDatabase to all tests in this directory.
// This is needed because the worktree vendor symlink causes Pest's rootPath
// detection to use the main app's path, so the top-level tests/Pest.php
// ->in('Unit') does not match these test files.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in(__DIR__);
