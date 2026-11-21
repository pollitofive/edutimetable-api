<?php

namespace App\Http\Middleware;

use App\Services\CurrentBusiness;
use Closure;
use GraphQL\Error\SyntaxError;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\Parser;
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
        // Guests can't have a business context. Reject them before anything else: queries
        // without @guard would run with no tenant scope at all.
        if (! $request->user()) {
            return response()->json([
                'errors' => [['message' => 'Unauthenticated.']],
            ], 401);
        }

        // Skip business validation for operations that don't require business context
        if ($this->shouldSkipBusinessValidation($request)) {
            return $next($request);
        }

        // Get business ID from X-Business-Id header
        $businessId = $request->header('X-Business-Id');

        // If no header, try to use user's default_business_id
        if (! $businessId) {
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
     * Root fields that can run without a business context. All of them are @guard'ed and only
     * expose the authenticated user's own data.
     */
    private const EXEMPT_ROOT_FIELDS = [
        'me',
        'myBusinesses',
        'setDefaultBusiness',
    ];

    /**
     * Determine if business validation should be skipped for this request.
     *
     * The operation name is chosen by the client, so it can't be trusted: "query me { teachers }"
     * would skip the business context and run without tenant scope. Instead, parse the query
     * and only skip when every root field of the executed operation is exempt.
     */
    private function shouldSkipBusinessValidation(Request $request): bool
    {
        // The exemption only applies to GraphQL: REST routes behind this middleware always need
        // a business context, whatever comes in the query string
        if (! $request->routeIs('graphql')) {
            return false;
        }

        $query = $request->input('query');

        // Batched requests (JSON array) or missing query: never exempt
        if (! is_string($query) || trim($query) === '') {
            return false;
        }

        try {
            $document = Parser::parse($query, ['noLocation' => true]);
        } catch (SyntaxError) {
            return false;
        }

        $operation = $this->executedOperation($document, $request->input('operationName'));

        if (! $operation) {
            return false;
        }

        foreach ($operation->selectionSet->selections as $selection) {
            // Fragment spreads / inline fragments at the root: don't try to resolve them
            if (! $selection instanceof FieldNode) {
                return false;
            }

            if (! in_array($selection->name->value, self::EXEMPT_ROOT_FIELDS, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The operation GraphQL will execute: the one named by operationName, or the only one.
     */
    private function executedOperation(DocumentNode $document, mixed $operationName): ?OperationDefinitionNode
    {
        $operations = [];

        foreach ($document->definitions as $definition) {
            if ($definition instanceof OperationDefinitionNode) {
                $operations[] = $definition;
            }
        }

        if (is_string($operationName) && $operationName !== '') {
            foreach ($operations as $operation) {
                if ($operation->name?->value === $operationName) {
                    return $operation;
                }
            }

            return null;
        }

        return count($operations) === 1 ? $operations[0] : null;
    }
}
