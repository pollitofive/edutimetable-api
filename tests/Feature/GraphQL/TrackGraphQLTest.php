<?php

namespace Tests\Feature\GraphQL;

use App\Models\Business;
use App\Models\CourseLevel;
use App\Models\Track;
use App\Models\User;
use App\Services\CurrentBusiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $tenancy = setupTenancy();
    $this->user = $tenancy->user;
    $this->business = $tenancy->business;
    Sanctum::actingAs($this->user);
});

it('can create a track via GraphQL', function () {
    $mutation = '
        mutation {
            createTrack(input: { name: "English" }) {
                id
                name
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $mutation]);

    $response->assertJson([
        'data' => [
            'createTrack' => ['name' => 'English'],
        ],
    ]);

    expect(Track::count())->toBe(1);
});

it('rejects creating a track with a duplicate name in the same business', function () {
    Track::factory()->create(['name' => 'English']);

    $mutation = '
        mutation {
            createTrack(input: { name: "English" }) {
                id
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $mutation], ['X-Locale' => 'es']);

    expect($response->json('errors.0.message'))->toContain('Ya existe');
    expect(Track::count())->toBe(1);
});

it('can query tracks via GraphQL', function () {
    Track::factory()->count(3)->create();

    $query = '
        query {
            tracks {
                data { id name }
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $query]);

    $response->assertJsonCount(3, 'data.tracks.data');
});

it('can update a track via GraphQL', function () {
    $track = Track::factory()->create(['name' => 'Portuguese']);

    $mutation = "
        mutation {
            updateTrack(id: {$track->id}, input: { name: \"Portuguese (Brazil)\" }) {
                id
                name
            }
        }
    ";

    $response = $this->postGraphQL(['query' => $mutation]);

    $response->assertJson([
        'data' => [
            'updateTrack' => ['name' => 'Portuguese (Brazil)'],
        ],
    ]);

    expect($track->fresh()->name)->toBe('Portuguese (Brazil)');
});

it('can delete a track that is not in use', function () {
    $track = Track::factory()->create();

    $mutation = "
        mutation {
            deleteTrack(id: {$track->id}) {
                id
            }
        }
    ";

    $response = $this->postGraphQL(['query' => $mutation]);

    $response->assertJson(['data' => ['deleteTrack' => ['id' => (string) $track->id]]]);
    expect(Track::count())->toBe(0);
});

it('rejects deleting a track that still has course levels using it', function () {
    $track = Track::factory()->create();
    CourseLevel::factory()->create(['track_id' => $track->id]);

    $mutation = "
        mutation {
            deleteTrack(id: {$track->id}) {
                id
            }
        }
    ";

    $response = $this->postGraphQL(['query' => $mutation], ['X-Locale' => 'es']);

    expect($response->json('errors.0.message'))->toContain('todavía la usan');
    expect(Track::count())->toBe(1);
});

it('isolates tracks by business scope', function () {
    $businessA = $this->business;
    $trackA = Track::factory()->create(['name' => 'English']);

    $businessB = Business::factory()->create();
    $userB = User::factory()->create(['default_business_id' => $businessB->id]);
    $userB->businesses()->attach($businessB->id, ['role' => 'owner']);

    app(CurrentBusiness::class)->setId($businessB->id);
    Sanctum::actingAs($userB);

    $query = '
        query {
            tracks {
                data { id name }
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $query]);

    $response->assertJsonCount(0, 'data.tracks.data');
    expect(Track::find($trackA->id))->toBeNull();
});