<?php

use App\Models\Business;
use App\Models\Teacher;
use App\Models\User;

beforeEach(function () {
    // Create two businesses
    $this->businessA = Business::factory()->create(['name' => 'Business A']);
    $this->businessB = Business::factory()->create(['name' => 'Business B']);

    // Create users for each business
    $this->userA = User::factory()->create();
    $this->userB = User::factory()->create();

    // Assign users to their respective businesses
    $this->userA->businesses()->attach($this->businessA->id, ['role' => 'owner']);
    $this->userA->update(['default_business_id' => $this->businessA->id]);

    $this->userB->businesses()->attach($this->businessB->id, ['role' => 'owner']);
    $this->userB->update(['default_business_id' => $this->businessB->id]);
});

test('middleware requires business id or returns 400', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/user');

    $response->assertStatus(400)
        ->assertJson([
            'message' => 'Business ID is required. Provide X-Business-Id header or set default business.',
        ]);
});

test('middleware rejects invalid business id with 400', function () {
    $response = $this->actingAs($this->userA, 'sanctum')
        ->withHeader('X-Business-Id', 'invalid')
        ->getJson('/api/user');

    $response->assertStatus(400)
        ->assertJson([
            'message' => 'Invalid Business ID format.',
        ]);
});

test('middleware rejects unauthorized business access with 403', function () {
    // User A tries to access Business B
    $response = $this->actingAs($this->userA, 'sanctum')
        ->withHeader('X-Business-Id', $this->businessB->id)
        ->getJson('/api/user');

    $response->assertStatus(403)
        ->assertJson([
            'message' => 'You do not have access to this business.',
        ]);
});

test('middleware accepts valid business id from header', function () {
    $response = $this->actingAs($this->userA, 'sanctum')
        ->withHeader('X-Business-Id', $this->businessA->id)
        ->getJson('/api/user');

    $response->assertStatus(200);
});

test('middleware uses default business id when header is missing', function () {
    $response = $this->actingAs($this->userA, 'sanctum')
        ->getJson('/api/user');

    $response->assertStatus(200);
});

test('user can only see teachers from their business', function () {
    // Create teachers for each business
    $teacherA = Teacher::factory()->create([
        'business_id' => $this->businessA->id,
        'email' => 'teacher-a@example.com',
    ]);

    $teacherB = Teacher::factory()->create([
        'business_id' => $this->businessB->id,
        'email' => 'teacher-b@example.com',
    ]);

    // User A should only see teachers from Business A
    $this->actingAs($this->userA, 'sanctum');
    app(\App\Services\CurrentBusiness::class)->setId($this->businessA->id);

    $teachers = Teacher::all();

    expect($teachers)->toHaveCount(1)
        ->and($teachers->first()->id)->toBe($teacherA->id);
});

test('creating teacher automatically sets business id', function () {
    $this->actingAs($this->userA, 'sanctum');
    app(\App\Services\CurrentBusiness::class)->setId($this->businessA->id);

    $teacher = Teacher::create([
        'name' => 'New Teacher',
        'email' => 'new-teacher@example.com',
    ]);

    expect($teacher->business_id)->toBe($this->businessA->id);
});

test('user cannot create teacher with different business id', function () {
    $this->actingAs($this->userA, 'sanctum');
    app(\App\Services\CurrentBusiness::class)->setId($this->businessA->id);

    // Try to create teacher with Business B id
    $teacher = Teacher::create([
        'name' => 'New Teacher',
        'email' => 'new-teacher@example.com',
        'business_id' => $this->businessB->id, // This should be ignored
    ]);

    // Should have Business A id, not Business B
    expect($teacher->business_id)->toBe($this->businessA->id);
});
