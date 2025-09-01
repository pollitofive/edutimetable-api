<?php

use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create([
        'email' => 'test@example.com',
        'password' => bcrypt('password123')
    ]);
});

test('login with valid credentials returns token and user', function () {
    $response = $this->postJson('/api/login-token', [
        'email' => 'test@example.com',
        'password' => 'password123'
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'token',
            'user' => [
                'id',
                'name',
                'email',
                'email_verified_at',
                'created_at',
                'updated_at'
            ]
        ]);

    expect($response->json('user.email'))->toBe('test@example.com')
        ->and($response->json('token'))->toBeString();
});

test('login with invalid credentials returns error', function () {
    $response = $this->postJson('/api/login-token', [
        'email' => 'test@example.com',
        'password' => 'wrong-password'
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Credenciales inválidas'
        ]);
});

test('login with missing email returns validation error', function () {
    $response = $this->postJson('/api/login-token', [
        'password' => 'password123'
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

test('login with missing password returns validation error', function () {
    $response = $this->postJson('/api/login-token', [
        'email' => 'test@example.com'
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['password']);
});

test('login with invalid email format returns validation error', function () {
    $response = $this->postJson('/api/login-token', [
        'email' => 'invalid-email',
        'password' => 'password123'
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

test('login with empty fields returns validation errors', function () {
    $response = $this->postJson('/api/login-token', [
        'email' => '',
        'password' => ''
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['email', 'password']);
});
