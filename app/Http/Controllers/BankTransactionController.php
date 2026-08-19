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
    private const STATUS_SUGGESTED = 'SUGGESTED';

    private const STATUS_NEEDS_REVIEW = 'NEEDS_REVIEW';

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

        $threshold = $this->reviewConfidenceThreshold();
        if ($threshold === null) {
            return $this->needsReviewResponse('configuration_error', 500);
        }

        $categories = Category::orderBy('id')->pluck('name')->values()->all();
        if ($categories === []) {
            return $this->needsReviewResponse('categories_unavailable', 500, $threshold);
        }

        $apiKey = config('services.anthropic.key');
        if (! $apiKey || str_starts_with($apiKey, 'sk-ant-your-key')) {
            return $this->needsReviewResponse('configuration_error', 500, $threshold);
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

            return $this->needsReviewResponse('api_failure', 502, $threshold);
        } catch (JsonException $e) {
            report($e);

            return $this->needsReviewResponse('request_build_failure', 500, $threshold);
        }

        if ($response->failed()) {
            return $this->needsReviewResponse('api_failure', 502, $threshold);
        }

        $text = collect($response->json('content'))
            ->where('type', 'text')
            ->pluck('text')
            ->implode('');

        try {
            $suggestion = $this->parseSuggestion($text, $categories);
        } catch (RuntimeException $e) {
            report($e);

            $reasonCode = in_array($e->getMessage(), ['invalid_category', 'malformed_ai_output'], true)
                ? $e->getMessage()
                : 'malformed_ai_output';

            return $this->needsReviewResponse($reasonCode, 502, $threshold);
        }

        $reviewReasons = [];
        if ($suggestion['confidence'] < $threshold) {
            $reviewReasons[] = $this->reviewReason('confidence_below_threshold', $threshold);
        }
        if ($suggestion['requiresReview']) {
            $reviewReasons[] = $this->reviewReason('ai_requires_review', $threshold);
        }

        return response()->json([
            'status' => $reviewReasons === [] ? self::STATUS_SUGGESTED : self::STATUS_NEEDS_REVIEW,
            'suggestion' => $suggestion,
            'confidenceThreshold' => $threshold,
            'reviewReasons' => $reviewReasons,
        ]);
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
        } catch (JsonException $e) {
            throw new RuntimeException('malformed_ai_output', 0, $e);
        }

        if (! is_array($value)) {
            throw new RuntimeException('malformed_ai_output');
        }

        $required = ['category', 'confidence', 'reason', 'requiresReview'];
        if (array_diff($required, array_keys($value)) !== [] || array_diff(array_keys($value), $required) !== []) {
            throw new RuntimeException('malformed_ai_output');
        }

        if (! is_string($value['category']) || ! in_array($value['category'], $categories, true)) {
            throw new RuntimeException('invalid_category');
        }

        if (
            (is_bool($value['confidence']) || (! is_int($value['confidence']) && ! is_float($value['confidence'])))
            || $value['confidence'] < 0
            || $value['confidence'] > 1
        ) {
            throw new RuntimeException('malformed_ai_output');
        }

        if (! is_string($value['reason']) || trim($value['reason']) === '') {
            throw new RuntimeException('malformed_ai_output');
        }

        if (! is_bool($value['requiresReview'])) {
            throw new RuntimeException('malformed_ai_output');
        }

        return [
            'category' => $value['category'],
            'confidence' => (float) $value['confidence'],
            'reason' => trim($value['reason']),
            'requiresReview' => $value['requiresReview'],
        ];
    }

    private function reviewConfidenceThreshold(): ?float
    {
        $threshold = config('services.anthropic.review_confidence_threshold');

        if (is_bool($threshold) || (! is_int($threshold) && ! is_float($threshold))) {
            return null;
        }

        $threshold = (float) $threshold;

        return $threshold >= 0 && $threshold <= 1 ? $threshold : null;
    }

    private function needsReviewResponse(string $reasonCode, int $httpStatus, ?float $threshold = null): JsonResponse
    {
        return response()->json([
            'status' => self::STATUS_NEEDS_REVIEW,
            'suggestion' => null,
            'confidenceThreshold' => $threshold,
            'reviewReasons' => [$this->reviewReason($reasonCode, $threshold)],
        ], $httpStatus);
    }

    /** @return array{code:string,message:string} */
    private function reviewReason(string $reasonCode, ?float $threshold): array
    {
        $thresholdPercent = $threshold === null ? null : (int) round($threshold * 100);

        return [
            'code' => $reasonCode,
            'message' => match ($reasonCode) {
                'confidence_below_threshold' => "Confidence is below the configured {$thresholdPercent}% threshold.",
                'ai_requires_review' => 'The AI marked this transaction as requiring adviser judgement.',
                'invalid_category' => 'The AI category is not in the current Category table.',
                'malformed_ai_output' => 'The AI response could not be reliably parsed and validated.',
                'api_failure' => 'The AI service was unavailable, so no category was proposed.',
                'request_build_failure' => 'The AI request could not be built safely.',
                'categories_unavailable' => 'No bank coding categories are currently configured.',
                default => 'The AI safety configuration is invalid.',
            },
        ];
    }
}
