<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Helper function to setup multi-tenancy for tests.
 * Creates a business, user, and sets up the business context.
 *
 * @return object Object with properties: user, business
 */
function setupTenancy()
{
    $business = \App\Models\Business::factory()->create();
    $user = \App\Models\User::factory()->create(['default_business_id' => $business->id]);
    $user->businesses()->attach($business->id, ['role' => 'owner']);

    // Set the business context
    app(\App\Services\CurrentBusiness::class)->setId($business->id);

    return (object) [
        'user' => $user,
        'business' => $business,
    ];
}

/**
 * Helper to make a GraphQL request with business header.
 *
 * @param  array  $data  The GraphQL query data
 * @param  int|null  $businessId  Optional business ID to include in header
 * @return \Illuminate\Testing\TestResponse
 */
function postGraphQLWithBusiness(array $data, ?int $businessId = null)
{
    $headers = [];

    if ($businessId !== null) {
        $headers['X-Business-Id'] = $businessId;
    }

    return test()->postGraphQL($data, $headers);
}
