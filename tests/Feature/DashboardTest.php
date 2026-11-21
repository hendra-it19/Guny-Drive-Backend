<?php

use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('administrators can visit the dashboard', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('mobile accounts cannot administer storage or users', function () {
    $this->actingAs(User::factory()->create());
    $this->get('/users')->assertForbidden();
    $this->post('/storage/link')->assertForbidden();
});
