<?php

namespace App\Http\Controllers;

use App\Models\BankTransaction;
use App\Models\Category;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;

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

    public function suggest(BankTransaction $bankTransaction): JsonResponse
    {
        if ($bankTransaction->category_id !== null) {
            return response()->json([
                'error' => 'Only uncoded transactions can receive a suggestion.',
            ], 422);
        }

        $categories = Category::orderBy('id')->pluck('name')->values()->all();
        if ($categories === []) {
            return response()->json(['error' => 'No bank coding categories are configured.'], 500);
        }

        $apiKey = config('services.anthropic.key');
        if (! $apiKey || str_starts_with($apiKey, 'sk-ant-your-key')) {
            return response()->json([
                'error' => 'No Anthropic API key configured. Set ANTHROPIC_API_KEY in .env.',
            ], 500);
        }

        $historicalExamples = BankTransaction::with('category')
            ->whereNotNull('category_id')
            ->orderBy('transacted_on')
            ->orderBy('id')
            ->get()
            ->map(fn (BankTransaction $transaction): array => [
                'description' => $transaction->description,
                'amount' => (float) $transaction->amount,
                'category' => $transaction->category?->name,
            ])
            ->values()
            ->all();

        $system = implode("\n", [
            'You are helping a human adviser code one New Zealand farm bank-feed transaction.',
            'Return exactly one JSON object and no prose or markdown fences.',
            'The JSON object must contain exactly these fields: category, confidence, reason, requiresReview.',
            'category must be exactly one of: '.json_encode($categories, JSON_UNESCAPED_SLASHES),
            'confidence must be a number from 0 to 1.',
            'reason must be a short string grounded only in the transaction and supplied examples.',
            'requiresReview must be true when the description is ambiguous, evidence conflicts, or the suggestion is not clearly supported.',
            'This is a suggestion only. Never claim certainty that the supplied data does not support.',
        ]);

        $prompt = implode("\n", [
            'Official task data:',
            json_encode([
                'description' => $bankTransaction->description,
                'amount' => (float) $bankTransaction->amount,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            '',
            'Official completed historical examples from this database:',
            json_encode($historicalExamples, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            '',
            'Choose one allowed category. Return only the required JSON object.',
        ]);

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ])->timeout(120)->post('https://api.anthropic.com/v1/messages', [
                'model' => 'claude-sonnet-4-6',
                'max_tokens' => 512,
                'system' => $system,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);
        } catch (ConnectionException $e) {
            report($e);

            return response()->json(['error' => 'Could not reach the Anthropic API.'], 502);
        } catch (JsonException $e) {
            report($e);

            return response()->json(['error' => 'Could not build the AI request.'], 500);
        }

        if ($response->failed()) {
            return response()->json([
                'error' => $response->json('error.message') ?? 'Anthropic API request failed.',
            ], $response->status());
        }

        $text = collect($response->json('content'))
            ->where('type', 'text')
            ->pluck('text')
            ->implode('');

        try {
            $suggestion = $this->parseSuggestion($text, $categories);
        } catch (RuntimeException $e) {
            report($e);

            return response()->json([
                'error' => 'AI returned an invalid bank coding suggestion.',
            ], 502);
        }

        return response()->json(['suggestion' => $suggestion]);
    }

    /** @return array{category:string,confidence:float,reason:string,requiresReview:bool} */
    private function parseSuggestion(string $text, array $categories): array
    {
        $text = trim($text);
        if (str_starts_with($text, '```') && str_ends_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?? $text;
            $text = trim($text);
        }

        try {
            $value = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $firstException) {
            $start = strpos($text, '{');
            $end = strrpos($text, '}');
            if ($start === false || $end === false || $end <= $start) {
                throw new RuntimeException('AI response did not contain a JSON object.', 0, $firstException);
            }

            try {
                $value = json_decode(substr($text, $start, $end - $start + 1), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $secondException) {
                throw new RuntimeException('AI response contained invalid JSON.', 0, $secondException);
            }
        }

        if (! is_array($value)) {
            throw new RuntimeException('AI response was not a JSON object.');
        }

        $required = ['category', 'confidence', 'reason', 'requiresReview'];
        if (array_diff($required, array_keys($value)) !== [] || array_diff(array_keys($value), $required) !== []) {
            throw new RuntimeException('AI response did not match the required schema.');
        }

        if (! is_string($value['category']) || ! in_array($value['category'], $categories, true)) {
            throw new RuntimeException('AI returned a category outside the database category list.');
        }

        if (
            (is_bool($value['confidence']) || (! is_int($value['confidence']) && ! is_float($value['confidence'])))
            || $value['confidence'] < 0
            || $value['confidence'] > 1
        ) {
            throw new RuntimeException('AI confidence must be a number from 0 to 1.');
        }

        if (! is_string($value['reason']) || trim($value['reason']) === '') {
            throw new RuntimeException('AI reason must be a non-empty string.');
        }

        if (! is_bool($value['requiresReview'])) {
            throw new RuntimeException('AI requiresReview must be a boolean.');
        }

        return [
            'category' => $value['category'],
            'confidence' => (float) $value['confidence'],
            'reason' => trim($value['reason']),
            'requiresReview' => $value['requiresReview'],
        ];
    }
}
