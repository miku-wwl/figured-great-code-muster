<?php

namespace App\Http\Controllers;

use App\Models\BankTransaction;
use App\Models\Category;
use App\Services\BankCodingSuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BankTransactionController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'categories' => Category::orderBy('id')->get(),
            'transactions' => BankTransaction::orderBy('transacted_on')->orderBy('id')->get(),
        ]);
    }

    public function update(Request $request, BankTransaction $bankTransaction): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => ['nullable', 'exists:categories,id'],
        ]);

        $bankTransaction->update($validated);

        return response()->json($bankTransaction);
    }

    public function suggest(
        BankTransaction $bankTransaction,
        BankCodingSuggestionService $suggestionService,
    ): JsonResponse {
        if ($bankTransaction->category_id !== null) {
            return response()->json([
                'error' => 'Only uncoded transactions can receive a suggestion.',
            ], 422);
        }

        $result = $suggestionService->classify($bankTransaction);
        $httpStatus = $result['httpStatus'];
        unset($result['httpStatus'], $result['errorType']);

        return response()->json($result, $httpStatus);
    }
}
