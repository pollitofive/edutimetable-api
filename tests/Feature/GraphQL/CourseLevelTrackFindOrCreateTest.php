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

it('reuses an existing track when track_name matches exactly', function () {
    $existing = Track::factory()->create(['name' => 'English']);

    $mutation = '
        mutation {
            createCourseLevel(input: {
                track_name: "English"
                name: "Beginner"
                slug: "beginner"
                sort_order: 10
            }) {
                id
                track { id name }
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $mutation]);

    $response->assertJson([
        'data' => ['createCourseLevel' => ['track' => ['id' => (string) $existing->id, 'name' => 'English']]],
    ]);
    expect(Track::count())->toBe(1);
});

it('reuses an existing track when track_name only differs by case/whitespace', function () {
    $existing = Track::factory()->create(['name' => 'English']);

    $mutation = '
        mutation {
            createCourseLevel(input: {
                track_name: "  english  "
                name: "Beginner"
                slug: "beginner"
                sort_order: 10
            }) {
                id
                track { id name }
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $mutation]);

    $response->assertJson([
        'data' => ['createCourseLevel' => ['track' => ['id' => (string) $existing->id, 'name' => 'English']]],
    ]);
    expect(Track::count())->toBe(1);
});

it('creates a new track when track_name has no match', function () {
    $mutation = '
        mutation {
            createCourseLevel(input: {
                track_name: "Mandarin"
                name: "Beginner"
                slug: "beginner"
                sort_order: 10
            }) {
                id
                track { name }
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $mutation]);

    $response->assertJson([
        'data' => ['createCourseLevel' => ['track' => ['name' => 'Mandarin']]],
    ]);
    expect(Track::count())->toBe(1);
    expect(Track::first()->name)->toBe('Mandarin');
});

it('gives each business its own track row for the same track_name', function () {
    $mutationFor = fn () => '
        mutation {
            createCourseLevel(input: {
                track_name: "English"
                name: "Beginner"
                slug: "beginner"
                sort_order: 10
            }) {
                id
                track { id name }
            }
        }
    ';

    $this->postGraphQL(['query' => $mutationFor()]);

    $businessB = Business::factory()->create();
    $userB = User::factory()->create(['default_business_id' => $businessB->id]);
    $userB->businesses()->attach($businessB->id, ['role' => 'owner']);
    app(CurrentBusiness::class)->setId($businessB->id);
    Sanctum::actingAs($userB);

    $this->postGraphQL(['query' => $mutationFor()]);

    // withoutGlobalScopes(): BelongsToBusiness' global scope would otherwise
    // restrict these counts to whichever business is currently active.
    expect(Track::withoutGlobalScopes()->count())->toBe(2);
    expect(Track::withoutGlobalScopes()->where('business_id', $this->business->id)->count())->toBe(1);
    expect(Track::withoutGlobalScopes()->where('business_id', $businessB->id)->count())->toBe(1);
});

it('can switch track_id on update', function () {
    $trackA = Track::factory()->create(['name' => 'English']);
    $trackB = Track::factory()->create(['name' => 'Spanish']);

    $level = CourseLevel::factory()->create(['track_id' => $trackA->id]);

    $mutation = "
        mutation {
            updateCourseLevel(id: {$level->id}, input: { track_id: {$trackB->id} }) {
                id
                track { id name }
            }
        }
    ";

    $response = $this->postGraphQL(['query' => $mutation]);

    $response->assertJson([
        'data' => ['updateCourseLevel' => ['track' => ['id' => (string) $trackB->id, 'name' => 'Spanish']]],
    ]);
});

it('requires either track_id or track_name when creating a course level', function () {
    $mutation = '
        mutation {
            createCourseLevel(input: {
                name: "Beginner"
                slug: "beginner"
                sort_order: 10
            }) {
                id
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $mutation], ['X-Locale' => 'es']);

    expect($response->json('errors.0.message'))->toContain('Se requiere track_id o track_name');
    expect(CourseLevel::count())->toBe(0);
});