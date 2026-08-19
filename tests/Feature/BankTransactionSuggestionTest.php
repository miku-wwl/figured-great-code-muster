<?php

namespace Tests\Feature;

use App\Models\BankTransaction;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BankTransactionSuggestionTest extends TestCase
{
    use RefreshDatabase;

    private BankTransaction $transaction;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.key' => 'test-anthropic-key',
            'services.anthropic.review_confidence_threshold' => 0.8,
        ]);

        Category::create(['name' => 'Fuel']);
        Category::create(['name' => 'Insurance']);

        $this->transaction = BankTransaction::create([
            'transacted_on' => '2026-02-01',
            'description' => 'Z ENERGY TE RAPA',
            'amount' => -212.40,
            'category_id' => null,
        ]);
    }

    public function test_high_confidence_valid_output_is_suggested_without_being_saved(): void
    {
        $this->fakeAnthropic([
            'category' => 'Fuel',
            'confidence' => 0.94,
            'reason' => 'The merchant matches a fuel supplier.',
            'requiresReview' => false,
        ]);

        $this->postJson($this->suggestionUrl())
            ->assertOk()
            ->assertJsonPath('status', 'SUGGESTED')
            ->assertJsonPath('suggestion.category', 'Fuel')
            ->assertJsonPath('confidenceThreshold', 0.8)
            ->assertJsonCount(0, 'reviewReasons');

        $this->assertTransactionRemainsUncoded();
    }

    public function test_an_already_coded_transaction_is_never_reclassified(): void
    {
        $fuel = Category::where('name', 'Fuel')->firstOrFail();
        $this->transaction->update(['category_id' => $fuel->id]);
        Http::fake();

        $this->postJson($this->suggestionUrl())
            ->assertStatus(422)
            ->assertJsonPath('error', 'Only uncoded transactions can receive a suggestion.');

        Http::assertNothingSent();
        $this->assertSame($fuel->id, $this->transaction->fresh()->category_id);
    }

    public function test_low_confidence_output_needs_review_without_being_saved(): void
    {
        config(['services.anthropic.review_confidence_threshold' => 0.95]);

        $this->fakeAnthropic([
            'category' => 'Fuel',
            'confidence' => 0.94,
            'reason' => 'The merchant may be a fuel supplier.',
            'requiresReview' => false,
        ]);

        $this->postJson($this->suggestionUrl())
            ->assertOk()
            ->assertJsonPath('status', 'NEEDS_REVIEW')
            ->assertJsonPath('suggestion.category', 'Fuel')
            ->assertJsonPath('confidenceThreshold', 0.95)
            ->assertJsonPath('reviewReasons.0.code', 'confidence_below_threshold');

        $this->assertTransactionRemainsUncoded();
    }

    public function test_model_review_flag_needs_review_even_with_high_confidence(): void
    {
        $this->fakeAnthropic([
            'category' => 'Fuel',
            'confidence' => 0.95,
            'reason' => 'The merchant name resembles a fuel supplier, but context is limited.',
            'requiresReview' => true,
        ]);

        $this->postJson($this->suggestionUrl())
            ->assertOk()
            ->assertJsonPath('status', 'NEEDS_REVIEW')
            ->assertJsonPath('reviewReasons.0.code', 'ai_requires_review');

        $this->assertTransactionRemainsUncoded();
    }

    public function test_invalid_json_fails_closed_to_needs_review(): void
    {
        $this->fakeAnthropic('{not valid json');

        $this->postJson($this->suggestionUrl())
            ->assertStatus(502)
            ->assertJsonPath('status', 'NEEDS_REVIEW')
            ->assertJsonPath('suggestion', null)
            ->assertJsonPath('reviewReasons.0.code', 'malformed_ai_output');

        $this->assertTransactionRemainsUncoded();
    }

    public function test_category_outside_current_database_list_fails_closed_to_needs_review(): void
    {
        $this->fakeAnthropic([
            'category' => 'Travel',
            'confidence' => 0.99,
            'reason' => 'The transaction appears travel-related.',
            'requiresReview' => false,
        ]);

        $this->postJson($this->suggestionUrl())
            ->assertStatus(502)
            ->assertJsonPath('status', 'NEEDS_REVIEW')
            ->assertJsonPath('suggestion', null)
            ->assertJsonPath('reviewReasons.0.code', 'invalid_category');

        $this->assertTransactionRemainsUncoded();
    }

    public function test_anthropic_api_error_fails_closed_to_needs_review(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'error' => ['message' => 'Service unavailable'],
            ], 503),
        ]);

        $this->postJson($this->suggestionUrl())
            ->assertStatus(502)
            ->assertJsonPath('status', 'NEEDS_REVIEW')
            ->assertJsonPath('suggestion', null)
            ->assertJsonPath('reviewReasons.0.code', 'api_failure');

        $this->assertTransactionRemainsUncoded();
    }

    private function fakeAnthropic(array|string $output): void
    {
        $text = is_array($output)
            ? json_encode($output, JSON_THROW_ON_ERROR)
            : $output;

        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'content' => [
                    ['type' => 'text', 'text' => $text],
                ],
            ]),
        ]);
    }

    private function suggestionUrl(): string
    {
        return "/api/bank-transactions/{$this->transaction->id}/suggest-category";
    }

    private function assertTransactionRemainsUncoded(): void
    {
        $this->assertNull($this->transaction->fresh()->category_id);
    }
}
