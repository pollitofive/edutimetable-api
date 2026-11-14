<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __construct(private readonly SearchService $service) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:'.SearchService::MIN_LENGTH, 'max:100'],
        ]);

        return response()->json($this->service->search(trim($validated['q'])));
    }
}
