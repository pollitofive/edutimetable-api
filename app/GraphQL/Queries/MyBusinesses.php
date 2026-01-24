<?php

namespace App\GraphQL\Queries;

use Illuminate\Support\Facades\Auth;

final readonly class MyBusinesses
{
    /**
     * Return all businesses where the authenticated user is a member
     */
    public function __invoke(): array
    {
        $user = Auth::user();

        return $user->businesses()
            ->get()
            ->map(function ($business) {
                return [
                    'business' => $business,
                    'role' => $business->pivot->role,
                ];
            })
            ->all();
    }
}
