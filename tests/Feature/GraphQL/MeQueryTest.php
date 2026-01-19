<?php

namespace Tests\Feature\GraphQL;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('can query me when authenticated', function () {
    $user = User::factory()->create([
        'name' => 'Test User',
        'email' => 'test@example.com',
    ]);

    Sanctum::actingAs($user);

    $query = '
        query {
            me {
                id
                name
                email
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $query]);

    $response->assertJson([
        'data' => [
            'me' => [
                'id' => (string) $user->id,
                'name' => 'Test User',
                'email' => 'test@example.com',
            ],
        ],
    ]);
});

it('returns unauthenticated error when not logged in', function () {
    $query = '
        query {
            me {
                id
                name
                email
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $query]);

    expect($response->json('errors'))->not->toBeNull();
});
