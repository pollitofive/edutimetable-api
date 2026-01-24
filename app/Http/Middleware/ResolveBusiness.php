<?php

namespace App\Http\Middleware;

use App\Services\CurrentBusiness;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveBusiness
{
    public function __construct(
        private CurrentBusiness $currentBusiness
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip business validation for queries that don't require business context
        if ($this->shouldSkipBusinessValidation($request)) {
            return $next($request);
        }
        // Get business ID from X-Business-Id header
        $businessId = $request->header('X-Business-Id');

        // If no header, try to use user's default_business_id
        if (! $businessId && $request->user()) {
            $businessId = $request->user()->default_business_id;
        }

        // If still no business ID, return 400
        if (! $businessId) {
            return response()->json([
                'message' => 'Business ID is required. Provide X-Business-Id header or set default business.',
            ], 400);
        }

        // Validate that business ID is numeric
        if (! is_numeric($businessId)) {
            return response()->json([
                'message' => 'Invalid Business ID format.',
            ], 400);
        }

        $businessId = (int) $businessId;

        // Check if user has access to this business
        if (! $request->user()->hasAccessToBusiness($businessId)) {
            return response()->json([
                'message' => 'You do not have access to this business.',
            ], 403);
        }

        // Set the current business in the context
        $this->currentBusiness->setId($businessId);

        // Optionally set as request attribute for easy access
        $request->attributes->set('business_id', $businessId);

        return $next($request);
    }

    /**
     * Determine if business validation should be skipped for this request.
     */
    private function shouldSkipBusinessValidation(Request $request): bool
    {
        // Get the operation name from the GraphQL request
        $operationName = $request->input('operationName', '');

        // If operationName is not provided, try to extract it from the query
        if (empty($operationName)) {
            $query = $request->input('query', '');

            // Try to extract named operation: "query myBusinesses {" or "mutation setDefaultBusiness("
            if (preg_match('/(?:query|mutation)\s+(\w+)\s*[{(]/', $query, $matches)) {
                $operationName = $matches[1];
            }
            // If no named operation, extract the root field: "query { me {" or "mutation { setDefaultBusiness("
            elseif (preg_match('/(?:query|mutation)\s*\{\s*(\w+)/', $query, $matches)) {
                $operationName = $matches[1];
            }
        }

        // List of queries/mutations that don't require business context
        $exemptOperations = [
            'myBusinesses',
            'me',
            'setDefaultBusiness',
        ];

        // Check for exact match of operation name
        return in_array($operationName, $exemptOperations, true);
    }
}
