<script setup>
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import { money, shortDate } from '../format';

const categories = ref([]);
const transactions = ref([]);
const loading = ref(true);
const savingId = ref(null);
const suggestingId = ref(null);
const suggestionResult = ref(null);
const suggestionError = ref('');
const saveError = ref('');

const uncodedCount = computed(() => transactions.value.filter((t) => !t.category_id).length);

onMounted(async () => {
    const { data } = await axios.get('/api/bank-transactions');
    categories.value = data.categories;
    transactions.value = data.transactions;
    loading.value = false;
});

async function saveCategory(transaction) {
    savingId.value = transaction.id;
    saveError.value = '';

    try {
        await axios.patch(`/api/bank-transactions/${transaction.id}`, {
            category_id: transaction.category_id,
        });
        if (suggestionResult.value?.transactionId === transaction.id) {
            suggestionResult.value = null;
        }
        return true;
    } catch (error) {
        saveError.value = error.response?.data?.message ?? error.response?.data?.error ?? error.message;
        return false;
    } finally {
        savingId.value = null;
    }
}

async function suggestCategory(transaction) {
    if (transaction.category_id) return;

    suggestingId.value = transaction.id;
    suggestionResult.value = null;
    suggestionError.value = '';

    try {
        const { data } = await axios.post(`/api/bank-transactions/${transaction.id}/suggest-category`);
        suggestionResult.value = {
            ...data,
            transactionId: transaction.id,
        };
    } catch (error) {
        const failure = error.response?.data;
        if (failure?.status === 'NEEDS_REVIEW') {
            suggestionResult.value = {
                ...failure,
                transactionId: transaction.id,
            };
        } else {
            suggestionResult.value = {
                status: 'NEEDS_REVIEW',
                suggestion: null,
                reviewReasons: [
                    {
                        code: 'suggestion_request_failure',
                        message: 'The suggestion request failed. Choose a category manually or try again.',
                    },
                ],
                transactionId: transaction.id,
            };
        }
    } finally {
        suggestingId.value = null;
    }
}

async function acceptSuggestion(transaction) {
    if (suggestionResult.value?.transactionId !== transaction.id || !suggestionResult.value.suggestion) return;

    const category = categories.value.find((item) => item.name === suggestionResult.value.suggestion.category);
    if (!category) {
        suggestionError.value = 'The suggestion is not in the current category list.';
        return;
    }

    const previousCategoryId = transaction.category_id;
    transaction.category_id = category.id;
    const saved = await saveCategory(transaction);
    if (!saved) {
        transaction.category_id = previousCategoryId;
    }
}
</script>

<template>
    <div>
        <div class="mb-4 flex items-end justify-between">
            <div>
                <h2 class="text-lg font-semibold">Bank coding</h2>
                <p class="text-sm text-fg-mid-grey">
                    Code each bank feed line to an account. Last period (Dec–Jan) was coded by the senior adviser;
                    everything since is yours.
                </p>
            </div>
            <p class="rounded-full bg-fg-warning-15 px-2.5 py-1 text-xs font-medium whitespace-nowrap text-fg-warning-text">
                {{ uncodedCount }} still to code
            </p>
        </div>

        <p class="mb-4 text-xs text-fg-mid-grey">
            AI suggestions are never saved automatically. Review the suggestion and click <strong>Accept suggestion</strong>
            or continue using the category dropdown.
        </p>

        <p v-if="suggestionError" class="mb-4 rounded bg-fg-danger-9 p-3 text-sm text-fg-danger-dark">{{ suggestionError }}</p>
        <p v-if="saveError" class="mb-4 rounded bg-fg-danger-9 p-3 text-sm text-fg-danger-dark">{{ saveError }}</p>

        <p v-if="loading" class="text-fg-light-grey">Loading…</p>

        <div v-else class="overflow-x-auto rounded border border-fg-muted-grey bg-white">
            <table class="w-full text-sm">
                <thead class="bg-fg-super-pale-grey text-left text-fg-mid-grey">
                    <tr>
                        <th class="px-3 py-2 font-medium">Date</th>
                        <th class="px-3 py-2 font-medium">Description</th>
                        <th class="px-3 py-2 text-right font-medium">Amount</th>
                        <th class="px-3 py-2 font-medium">Category</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="txn in transactions"
                        :key="txn.id"
                        class="border-t border-fg-pale-grey hover:bg-fg-pale-grey"
                        :class="txn.category_id ? 'bg-fg-main-blue-9' : ''"
                    >
                        <td class="whitespace-nowrap px-3 py-1.5 text-fg-mid-grey">{{ shortDate(txn.transacted_on) }}</td>
                        <td class="px-3 py-1.5 font-mono text-xs">{{ txn.description }}</td>
                        <td
                            class="whitespace-nowrap px-3 py-1.5 text-right font-mono text-xs"
                            :class="txn.amount < 0 ? 'text-fg-danger' : ''"
                        >
                            {{ money(txn.amount) }}
                        </td>
                        <td class="px-3 py-1.5">
                            <div class="space-y-2">
                                <select
                                    v-model="txn.category_id"
                                    class="w-48 rounded border border-fg-muted-grey bg-white px-2 py-1 text-sm"
                                    :disabled="savingId === txn.id"
                                    @change="saveCategory(txn)"
                                >
                                    <option :value="null">— uncoded —</option>
                                    <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                                </select>

                                <button
                                    v-if="!txn.category_id"
                                    class="block rounded border border-fg-main-blue px-2 py-1 text-xs font-medium text-fg-main-blue hover:bg-fg-main-blue-9 disabled:opacity-50"
                                    :disabled="suggestingId === txn.id || savingId === txn.id"
                                    @click.stop="suggestCategory(txn)"
                                >
                                    {{ suggestingId === txn.id ? 'Suggesting…' : 'Suggest category' }}
                                </button>

                                <div
                                    v-if="suggestionResult?.transactionId === txn.id && !txn.category_id"
                                    class="max-w-xs rounded border p-2 text-xs"
                                    :class="
                                        suggestionResult.status === 'SUGGESTED'
                                            ? 'border-fg-main-blue-30 bg-fg-main-blue-9'
                                            : 'border-fg-warning bg-fg-warning-15'
                                    "
                                >
                                    <p
                                        class="font-semibold"
                                        :class="
                                            suggestionResult.status === 'SUGGESTED'
                                                ? 'text-fg-dark-blue'
                                                : 'text-fg-warning-text'
                                        "
                                    >
                                        {{ suggestionResult.status === 'SUGGESTED' ? 'Suggested' : 'Needs adviser review' }}
                                    </p>

                                    <template v-if="suggestionResult.suggestion">
                                        <p class="mt-1 font-medium text-fg-dark-grey">
                                            AI category: {{ suggestionResult.suggestion.category }}
                                        </p>
                                        <p class="mt-1 text-fg-mid-grey">
                                            Confidence: {{ Math.round(Number(suggestionResult.suggestion.confidence) * 100) }}%
                                        </p>
                                        <p class="mt-1 leading-snug text-fg-dark-grey">
                                            {{ suggestionResult.suggestion.reason }}
                                        </p>
                                    </template>

                                    <ul v-if="suggestionResult.reviewReasons?.length" class="mt-1 list-disc pl-4 text-fg-warning-text">
                                        <li v-for="reason in suggestionResult.reviewReasons" :key="reason.code">
                                            {{ reason.message }}
                                        </li>
                                    </ul>

                                    <p class="mt-1 text-fg-mid-grey">Nothing has been saved. You remain in control.</p>

                                    <button
                                        v-if="suggestionResult.suggestion"
                                        class="mt-2 rounded bg-fg-main-blue px-2 py-1 font-medium text-white hover:bg-fg-main-blue-hover disabled:opacity-50"
                                        :disabled="savingId === txn.id"
                                        @click.stop="acceptSuggestion(txn)"
                                    >
                                        {{
                                            savingId === txn.id
                                                ? 'Saving…'
                                                : suggestionResult.status === 'SUGGESTED'
                                                  ? 'Accept suggestion'
                                                  : 'Accept after review'
                                        }}
                                    </button>
                                </div>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
