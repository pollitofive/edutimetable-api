<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->user = User::factory()->create([
        'name' => 'Ana',
        'email' => 'ana@example.com',
        'password' => 'password123',
    ]);
    $this->token = $this->user->createToken('current')->plainTextToken;
});

/**
 * Request with a real Sanctum token, resetting the guards so each request
 * authenticates from scratch (as separate HTTP requests would)
 */
function withToken(string $token)
{
    app('auth')->forgetGuards();

    return test()->withHeader('Authorization', "Bearer {$token}");
}

describe('PATCH /api/me', function () {
    it('updates name and email', function () {
        withToken($this->token)
            ->patchJson('/api/me', ['name' => 'Ana María', 'email' => 'ana.maria@example.com'])
            ->assertOk()
            ->assertJsonPath('name', 'Ana María')
            ->assertJsonPath('email', 'ana.maria@example.com');

        expect($this->user->fresh())
            ->name->toBe('Ana María')
            ->email->toBe('ana.maria@example.com');
    });

    it('keeps the own email without complaining it is taken', function () {
        withToken($this->token)
            ->patchJson('/api/me', ['name' => 'Ana', 'email' => 'ana@example.com'])
            ->assertOk();
    });

    it('rejects an email used by another user', function () {
        User::factory()->create(['email' => 'otro@example.com']);

        withToken($this->token)
            ->patchJson('/api/me', ['name' => 'Ana', 'email' => 'otro@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        expect($this->user->fresh()->email)->toBe('ana@example.com');
    });

    it('requires name and a valid email', function () {
        withToken($this->token)
            ->patchJson('/api/me', ['name' => '', 'email' => 'no-es-un-mail'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email']);
    });

    it('requires authentication', function () {
        $this->patchJson('/api/me', ['name' => 'X', 'email' => 'x@example.com'])
            ->assertUnauthorized();
    });
});

describe('PUT /api/me/password', function () {
    it('changes the password', function () {
        withToken($this->token)
            ->putJson('/api/me/password', [
                'current_password' => 'password123',
                'password' => 'nueva-clave-1',
                'password_confirmation' => 'nueva-clave-1',
            ])
            ->assertOk();

        expect(Hash::check('nueva-clave-1', $this->user->fresh()->password))->toBeTrue();
    });

    it('rejects a wrong current password', function () {
        withToken($this->token)
            ->putJson('/api/me/password', [
                'current_password' => 'incorrecta',
                'password' => 'nueva-clave-1',
                'password_confirmation' => 'nueva-clave-1',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);

        expect(Hash::check('password123', $this->user->fresh()->password))->toBeTrue();
    });

    it('rejects a confirmation that does not match', function () {
        withToken($this->token)
            ->putJson('/api/me/password', [
                'current_password' => 'password123',
                'password' => 'nueva-clave-1',
                'password_confirmation' => 'otra-clave-1',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        expect(Hash::check('password123', $this->user->fresh()->password))->toBeTrue();
    });

    it('rejects a password shorter than the default rules allow', function () {
        withToken($this->token)
            ->putJson('/api/me/password', [
                'current_password' => 'password123',
                'password' => 'corta',
                'password_confirmation' => 'corta',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    });

    it('signs out the other sessions and keeps the current one', function () {
        $otherToken = $this->user->createToken('other-device')->plainTextToken;
        $anotherUserToken = User::factory()->create()->createToken('x')->plainTextToken;

        withToken($this->token)
            ->putJson('/api/me/password', [
                'current_password' => 'password123',
                'password' => 'nueva-clave-1',
                'password_confirmation' => 'nueva-clave-1',
            ])
            ->assertOk();

        withToken($this->token)->getJson('/api/me')->assertOk();
        withToken($otherToken)->getJson('/api/me')->assertUnauthorized();
        // Other users' sessions are untouched
        withToken($anotherUserToken)->getJson('/api/me')->assertOk();
    });
});
