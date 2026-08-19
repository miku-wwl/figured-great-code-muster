<?php

namespace App\Http\Controllers;

use App\Models\BankTransaction;
use App\Services\BankCodingSuggestionService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class BankCodingEvaluationController extends Controller
{
    public function __invoke(BankCodingSuggestionService $suggestionService): JsonResponse
    {
        try {
            $transactions = $this->officialHistoricalTransactions();
        } catch (RuntimeException $e) {
            return response()->json([
                'error' => 'Official historical cases do not match the seeded database.',
                'detail' => $e->getMessage(),
            ], 409);
        }

        $correct = 0;
        $needsReview = 0;
        $apiErrors = 0;
        $parseErrors = 0;
        $otherErrors = 0;
        $failures = [];

        foreach ($transactions as $transaction) {
            $expected = $transaction->category->name;

            // Leave this transaction out of the historical examples so its label
            // is hidden while the normal production classifier handles the task.
            $result = $suggestionService->classify($transaction, $transaction->id);
            $suggestion = $result['suggestion'];

            if ($result['status'] === BankCodingSuggestionService::STATUS_NEEDS_REVIEW) {
                $needsReview++;
            }

            if ($result['errorType'] === 'api') {
                $apiErrors++;
            } elseif ($result['errorType'] === 'parse') {
                $parseErrors++;
            } elseif ($result['errorType'] !== null) {
                $otherErrors++;
            }

            $isCorrect = $suggestion !== null && $suggestion['category'] === $expected;
            if ($isCorrect) {
                $correct++;

                continue;
            }

            $failures[] = [
                'date' => $transaction->transacted_on->format('Y-m-d'),
                'description' => $transaction->description,
                'amount' => (float) $transaction->amount,
                'expected' => $expected,
                'actual' => $suggestion['category'] ?? null,
                'confidence' => $suggestion['confidence'] ?? null,
                'reason' => $suggestion['reason'] ?? collect($result['reviewReasons'])->pluck('message')->implode(' '),
                'status' => $result['status'],
                'errorType' => $result['errorType'],
            ];
        }

        $total = count($transactions);
        $accuracy = $total === 0 ? 0 : $correct / $total;

        return response()->json([
            'source' => 'official_historical_adviser_coded',
            'sourceFile' => 'data/bank_transactions.csv',
            'model' => $suggestionService->model(),
            'evaluatedAt' => now()->toIso8601String(),
            'summary' => [
                'totalEvaluated' => $total,
                'correct' => $correct,
                'categoryAccuracy' => round($accuracy, 4),
                'categoryAccuracyPercent' => round($accuracy * 100, 2),
                'needsReviewCount' => $needsReview,
                'apiParseErrors' => $apiErrors + $parseErrors,
                'apiErrors' => $apiErrors,
                'parseErrors' => $parseErrors,
                'otherErrors' => $otherErrors,
            ],
            'failures' => $failures,
        ]);
    }

    /** @return list<BankTransaction> */
    private function officialHistoricalTransactions(): array
    {
        $sourcePath = base_path('data/bank_transactions.csv');
        $handle = fopen($sourcePath, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Could not read data/bank_transactions.csv.');
        }

        try {
            $headers = fgetcsv($handle, null, ',', '"', '');
            if ($headers === false || ! collect(['date', 'description', 'amount', 'category'])->every(
                fn (string $column): bool => in_array($column, $headers, true),
            )) {
                throw new RuntimeException('The official bank transaction columns are missing.');
            }

            $transactions = [];

            while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
                if (count($values) !== count($headers)) {
                    throw new RuntimeException('An official bank transaction row is malformed.');
                }

                $row = array_combine($headers, $values);
                if ($row === false || trim($row['category']) === '') {
                    continue;
                }

                $matches = BankTransaction::with('category')
                    ->whereDate('transacted_on', $row['date'])
                    ->where('description', $row['description'])
                    ->where('amount', (float) $row['amount'])
                    ->get();

                if ($matches->count() !== 1) {
                    throw new RuntimeException("Expected one database match for {$row['description']} on {$row['date']}.");
                }

                $transaction = $matches->first();
                if ($transaction->category?->name !== $row['category']) {
                    throw new RuntimeException("Historical category drift detected for {$row['description']} on {$row['date']}.");
                }

                $transactions[] = $transaction;
            }

            return $transactions;
        } finally {
            fclose($handle);
        }
    }
}
