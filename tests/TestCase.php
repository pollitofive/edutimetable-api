<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;

abstract class TestCase extends BaseTestCase
{
    use MakesGraphQLRequests;

    /**
     * Override Lighthouse's postGraphQL to automatically include X-Business-Id header
     * when CurrentBusiness context is set.
     */
    protected function postGraphQL(array $data, array $headers = [], array $routeParams = []): \Illuminate\Testing\TestResponse
    {
        // Automatically include X-Business-Id header if business context is set
        $currentBusiness = app(\App\Services\CurrentBusiness::class);
        if ($currentBusiness->id() && ! isset($headers['X-Business-Id'])) {
            $headers['X-Business-Id'] = $currentBusiness->id();
        }

        // Call parent trait's method with schema cache refresh
        $this->refreshSchemaCacheIfNecessary();

        return $this->postJson(
            $this->graphQLEndpointUrl($routeParams),
            $data,
            $headers
        );
    }
}
