<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Execute a GraphQL query.
     */
    protected function postGraphQL(array $data, array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/graphql', $data, $headers);
    }
}
