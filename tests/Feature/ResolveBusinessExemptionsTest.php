<?php

use App\Models\Business;
use App\Models\Teacher;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

// ResolveBusiness lets a few operations (me, myBusinesses, setDefaultBusiness) run without a
// business context. The exemption used to be decided by the operation name, which the client
// chooses, so "query me { teachers }" ran with no tenant scope and leaked every business.

beforeEach(function () {
    $this->businessA = Business::factory()->create();
    $this->businessB = Business::factory()->create();

    $this->userA = User::factory()->create(['default_business_id' => $this->businessA->id]);
    $this->userA->businesses()->attach($this->businessA->id, ['role' => 'owner']);

    $this->teacherA = Teacher::factory()->create(['business_id' => $this->businessA->id]);
    $this->teacherB = Teacher::factory()->create(['business_id' => $this->businessB->id]);
});

function teacherIds($response): array
{
    return collect($response->json('data.teachers.data'))->pluck('id')->all();
}

it('rejects guests even when the operation is named like an exempt one', function (array $body) {
    $response = $this->postGraphQL($body);

    $response->assertStatus(401)
        ->assertJsonMissingPath('data')
        ->assertJson(['errors' => [['message' => 'Unauthenticated.']]]);
})->with([
    'named query' => [['query' => 'query me { teachers { data { id } } }']],
    'operationName' => [['operationName' => 'me', 'query' => 'query me { teachers { data { id } } }']],
    'exempt field' => [['query' => '{ me { id } }']],
]);

it('does not exempt an operation that mixes exempt and tenant fields', function () {
    Sanctum::actingAs($this->userA);

    $response = $this->postGraphQL([
        'operationName' => 'me',
        'query' => 'query me { me { id } teachers { data { id } } }',
    ]);

    $response->assertOk();
    expect(teacherIds($response))->toBe([(string) $this->teacherA->id]);
});

it('does not exempt a tenant field aliased as an exempt one', function () {
    Sanctum::actingAs($this->userA);

    $response = $this->postGraphQL(['query' => '{ me: teachers { data { id } } }']);

    $response->assertOk();
    expect(collect($response->json('data.me.data'))->pluck('id')->all())
        ->toBe([(string) $this->teacherA->id]);
});

it('requires a business when the exempt name points to a non exempt operation', function () {
    $user = User::factory()->create(['default_business_id' => null]);
    Sanctum::actingAs($user);

    $response = $this->postGraphQL([
        'operationName' => 'me',
        'query' => 'query me { teachers { data { id } } } query other { me { id } }',
    ]);

    $response->assertStatus(400);
});

it('still exempts the real exempt operations', function (string $query) {
    // A user without default business and without header: only exempt operations get through
    $user = User::factory()->create(['default_business_id' => null]);
    $user->businesses()->attach($this->businessA->id, ['role' => 'owner']);
    Sanctum::actingAs($user);

    $response = $this->postGraphQL(['query' => $query]);

    $response->assertOk()->assertJsonMissingPath('errors');
})->with([
    'me (unnamed)' => '{ me { id } }',
    'Me (as the front sends it)' => 'query Me { me { id email } }',
    'myBusinesses' => 'query MyBusinesses { myBusinesses { business { id } role } }',
    'me + myBusinesses' => '{ me { id } myBusinesses { role } }',
]);

it('still exempts setDefaultBusiness', function () {
    $user = User::factory()->create(['default_business_id' => null]);
    $user->businesses()->attach($this->businessA->id, ['role' => 'owner']);
    Sanctum::actingAs($user);

    $response = $this->postGraphQL([
        'query' => 'mutation SetDefaultBusiness($id: ID!) { setDefaultBusiness(business_id: $id) { default_business_id } }',
        'variables' => ['id' => $this->businessA->id],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.setDefaultBusiness.default_business_id', (string) $this->businessA->id);
});

it('never exempts REST routes, whatever comes in the query string', function () {
    $user = User::factory()->create(['default_business_id' => null]);
    Sanctum::actingAs($user);

    $this->getJson('/api/user?operationName=me')->assertStatus(400);
    $this->getJson('/api/user?query='.urlencode('{ me { id } }'))->assertStatus(400);
});
