<?php

namespace App\GraphQL\Mutations;

use App\Models\User;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Support\Facades\Auth;
use Nuwave\Lighthouse\Exceptions\AuthorizationException;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final readonly class SetDefaultBusiness
{
    /**
     * Set the user's default business
     */
    public function __invoke($rootValue, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): User
    {
        $user = Auth::user();
        $businessId = $args['business_id'];

        // Verify user has access to this business
        if (! $user->hasAccessToBusiness((int) $businessId)) {
            throw new AuthorizationException('You do not have access to this business.');
        }

        // Update default business
        $user->default_business_id = $businessId;
        $user->save();

        return $user->fresh();
    }
}
