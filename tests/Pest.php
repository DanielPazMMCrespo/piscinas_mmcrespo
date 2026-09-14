<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(
    TestCase::class,
    RefreshDatabase::class,
)->in('Feature');
