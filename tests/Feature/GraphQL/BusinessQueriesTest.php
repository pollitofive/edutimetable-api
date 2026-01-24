<?php

namespace Tests\Feature\GraphQL;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessQueriesTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Business $business1;

    protected Business $business2;

    protected function setUp(): void
    {
        parent::setUp();

        // Create user
        $this->user = User::factory()->create();

        // Create businesses
        $this->business1 = Business::factory()->create(['name' => 'Business One']);
        $this->business2 = Business::factory()->create(['name' => 'Business Two']);

        // Attach businesses to user
        $this->user->businesses()->attach($this->business1->id, ['role' => 'owner']);
        $this->user->businesses()->attach($this->business2->id, ['role' => 'admin']);
    }

    public function test_can_fetch_user_businesses(): void
    {
        $query = <<<'GQL'
        query {
            myBusinesses {
                business {
                    id
                    name
                    slug
                }
                role
            }
        }
        GQL;

        $response = $this->actingAs($this->user)
            ->postJson('/api/graphql', ['query' => $query]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'myBusinesses' => [
                        '*' => [
                            'business' => ['id', 'name', 'slug'],
                            'role',
                        ],
                    ],
                ],
            ]);

        $businesses = $response->json('data.myBusinesses');
        $this->assertCount(2, $businesses);

        // Check first business
        $this->assertEquals($this->business1->id, $businesses[0]['business']['id']);
        $this->assertEquals('Business One', $businesses[0]['business']['name']);
        $this->assertEquals('owner', $businesses[0]['role']);

        // Check second business
        $this->assertEquals($this->business2->id, $businesses[1]['business']['id']);
        $this->assertEquals('Business Two', $businesses[1]['business']['name']);
        $this->assertEquals('admin', $businesses[1]['role']);
    }

    public function test_my_businesses_requires_authentication(): void
    {
        $query = <<<'GQL'
        query {
            myBusinesses {
                business {
                    id
                    name
                }
                role
            }
        }
        GQL;

        $response = $this->postJson('/api/graphql', ['query' => $query]);

        $response->assertStatus(200)
            ->assertJsonStructure(['errors']);
    }

    public function test_can_set_default_business(): void
    {
        $mutation = <<<GQL
        mutation {
            setDefaultBusiness(business_id: {$this->business1->id}) {
                id
                name
                default_business_id
            }
        }
        GQL;

        $response = $this->actingAs($this->user)
            ->postJson('/api/graphql', ['query' => $mutation]);

        $response->assertStatus(200)
            ->assertJsonPath('data.setDefaultBusiness.default_business_id', (string) $this->business1->id);

        // Verify in database
        $this->assertDatabaseHas('users', [
            'id' => $this->user->id,
            'default_business_id' => $this->business1->id,
        ]);
    }

    public function test_cannot_set_default_business_without_access(): void
    {
        // Create a business the user doesn't have access to
        $otherBusiness = Business::factory()->create(['name' => 'Other Business']);

        $mutation = <<<GQL
        mutation {
            setDefaultBusiness(business_id: {$otherBusiness->id}) {
                id
                name
                default_business_id
            }
        }
        GQL;

        $response = $this->actingAs($this->user)
            ->postJson('/api/graphql', ['query' => $mutation]);

        $response->assertStatus(200)
            ->assertJsonStructure(['errors']);

        $this->assertStringContainsString(
            'access',
            $response->json('errors.0.message')
        );
    }

    public function test_set_default_business_requires_authentication(): void
    {
        $mutation = <<<GQL
        mutation {
            setDefaultBusiness(business_id: {$this->business1->id}) {
                id
                name
                default_business_id
            }
        }
        GQL;

        $response = $this->postJson('/api/graphql', ['query' => $mutation]);

        $response->assertStatus(200)
            ->assertJsonStructure(['errors']);
    }

    public function test_me_query_includes_default_business_id(): void
    {
        // Set default business
        $this->user->default_business_id = $this->business1->id;
        $this->user->save();

        $query = <<<'GQL'
        query {
            me {
                id
                name
                email
                default_business_id
            }
        }
        GQL;

        $response = $this->actingAs($this->user)
            ->postJson('/api/graphql', ['query' => $query]);

        $response->assertStatus(200)
            ->assertJsonPath('data.me.default_business_id', (string) $this->business1->id);
    }
}
