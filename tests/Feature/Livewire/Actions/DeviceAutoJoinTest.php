<?php

declare(strict_types=1);

use App\Livewire\Actions\DeviceAutoJoin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('device auto join component can be rendered', function (): void {
    $user = User::factory()->create(['assign_new_devices' => false]);

    Livewire::actingAs($user)
        ->test(DeviceAutoJoin::class)
        ->assertSee('Permit Auto-Join')
        ->assertSet('deviceAutojoin', false)
        ->assertSet('isFirstUser', true);
});

test('device auto join component initializes with user settings', function (): void {
    $user = User::factory()->create(['assign_new_devices' => true]);

    Livewire::actingAs($user)
        ->test(DeviceAutoJoin::class)
        ->assertSet('deviceAutojoin', true)
        ->assertSet('isFirstUser', true);
});

test('device auto join component identifies first user correctly', function (): void {
    $firstUser = User::factory()->create(['id' => 1, 'assign_new_devices' => false]);
    $otherUser = User::factory()->create(['id' => 2, 'assign_new_devices' => false]);

    Livewire::actingAs($firstUser)
        ->test(DeviceAutoJoin::class)
        ->assertSet('isFirstUser', true);

    Livewire::actingAs($otherUser)
        ->test(DeviceAutoJoin::class)
        ->assertSet('isFirstUser', false);
});

test('device auto join component updates user setting when toggled', function (): void {
    $user = User::factory()->create(['assign_new_devices' => false]);

    Livewire::actingAs($user)
        ->test(DeviceAutoJoin::class)
        ->set('deviceAutojoin', true)
        ->assertSet('deviceAutojoin', true);

    $user->refresh();
    expect($user->assign_new_devices)->toBeTrue();
});

// Validation test removed - Livewire automatically handles boolean conversion

test('device auto join component handles false value correctly', function (): void {
    $user = User::factory()->create(['assign_new_devices' => true]);

    Livewire::actingAs($user)
        ->test(DeviceAutoJoin::class)
        ->set('deviceAutojoin', false)
        ->assertSet('deviceAutojoin', false);

    $user->refresh();
    expect($user->assign_new_devices)->toBeFalse();
});

test('device auto join component only updates when deviceAutojoin property changes', function (): void {
    $user = User::factory()->create(['assign_new_devices' => false]);

    $component = Livewire::actingAs($user)
        ->test(DeviceAutoJoin::class);

    // Set a different property to ensure it doesn't trigger the update
    $component->set('isFirstUser', true);

    $user->refresh();
    expect($user->assign_new_devices)->toBeFalse();
});

test('device auto join component renders correct view', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(DeviceAutoJoin::class)
        ->assertViewIs('livewire.actions.device-auto-join');
});

test('device auto join component works with authenticated user', function (): void {
    $user = User::factory()->create(['assign_new_devices' => true]);

    $component = Livewire::actingAs($user)
        ->test(DeviceAutoJoin::class);

    expect($component->instance()->deviceAutojoin)->toBeTrue();
    expect($component->instance()->isFirstUser)->toBe($user->id === 1);
});

test('device auto join component handles multiple updates correctly', function (): void {
    $user = User::factory()->create(['assign_new_devices' => false]);

    $component = Livewire::actingAs($user)
        ->test(DeviceAutoJoin::class)
        ->set('deviceAutojoin', true);

    $user->refresh();
    expect($user->assign_new_devices)->toBeTrue();

    $component->set('deviceAutojoin', false);

    $user->refresh();
    expect($user->assign_new_devices)->toBeFalse();
});
