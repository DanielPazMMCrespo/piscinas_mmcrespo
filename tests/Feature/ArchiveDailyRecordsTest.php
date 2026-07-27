<?php

declare(strict_types=1);

use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\Product;
use App\Models\RecordAddition;
use App\Models\RecordPhoto;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    foreach (['admin', 'tecnico'] as $role) {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }

    $this->user = User::factory()->create();
    $this->user->assignRole('tecnico');

    $this->installation = Installation::factory()->create(['name' => 'Porto']);
    $this->pool = Pool::factory()->create([
        'installation_id' => $this->installation->id,
        'name' => 'Competição',
    ]);
    $this->product = Product::factory()->create(['name' => 'Cloro HTH', 'unidade' => 'kg']);
});

test('archive:daily-records archives records and related tables older than cutoff', function (): void {
    // 1. Create a record older than 365 days
    $oldRecord = DailyRecord::factory()->create([
        'pool_id' => $this->pool->id,
        'user_id' => $this->user->id,
        'created_at' => now()->subDays(370),
    ]);

    // Add addition and photo to old record
    $addition = RecordAddition::factory()->create([
        'daily_record_id' => $oldRecord->id,
        'product_id' => $this->product->id,
        'quantity' => 1.5,
    ]);

    $photo = RecordPhoto::create([
        'daily_record_id' => $oldRecord->id,
        'type' => 'tecnico',
        'path' => 'photos/test.jpg',
    ]);

    // 2. Create a recent record (should NOT be archived)
    $newRecord = DailyRecord::factory()->create([
        'pool_id' => $this->pool->id,
        'user_id' => $this->user->id,
        'created_at' => now()->subDays(10),
    ]);

    $newAddition = RecordAddition::factory()->create([
        'daily_record_id' => $newRecord->id,
        'product_id' => $this->product->id,
        'quantity' => 2.5,
    ]);

    // Run archival command
    $this->artisan('archive:daily-records --older-than=365')
        ->assertExitCode(0);

    // Verify database status
    // Old record must be archived
    $this->assertDatabaseHas('daily_records_archive', [
        'id' => $oldRecord->id,
        'pool_id' => $this->pool->id,
    ]);
    $this->assertDatabaseHas('record_additions_archive', [
        'id' => $addition->id,
        'daily_record_id' => $oldRecord->id,
        'product_id' => $this->product->id,
        'quantity' => 1.5,
    ]);
    $this->assertDatabaseHas('record_photos_archive', [
        'id' => $photo->id,
        'daily_record_id' => $oldRecord->id,
        'path' => 'photos/test.jpg',
    ]);

    // Originals of old record must be deleted
    $this->assertDatabaseMissing('daily_records', ['id' => $oldRecord->id]);
    $this->assertDatabaseMissing('record_additions', ['id' => $addition->id]);
    $this->assertDatabaseMissing('record_photos', ['id' => $photo->id]);

    // Recent record must still be in active tables
    $this->assertDatabaseHas('daily_records', ['id' => $newRecord->id]);
    $this->assertDatabaseHas('record_additions', ['id' => $newAddition->id]);

    // And NOT in archive tables
    $this->assertDatabaseMissing('daily_records_archive', ['id' => $newRecord->id]);
    $this->assertDatabaseMissing('record_additions_archive', ['id' => $newAddition->id]);
});
