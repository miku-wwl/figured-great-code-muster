<script setup>
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import { money, shortDate } from '../format';

const categories = ref([]);
const transactions = ref([]);
const loading = ref(true);
const savingId = ref(null);
const suggestingIds = ref({});
const suggestionResults = ref({});
const suggestionError = ref('');
const saveError = ref('');
const loadError = ref('');
const batchRunning = ref(false);
const batchCompleted = ref(0);
const batchTotal = ref(0);

const BATCH_CONCURRENCY = 3;

const uncodedTransactions = computed(() => transactions.value.filter((transaction) => !transaction.category_id));
const uncodedCount = computed(() => uncodedTransactions.value.length);
const suggestedCount = computed(
    () => uncodedTransactions.value.filter((transaction) => suggestionResults.value[transaction.id]?.status === 'SUGGESTED').length,
);
const needsReviewCount = computed(
    () =>
        uncodedTransactions.value.filter((transaction) => suggestionResults.value[transaction.id]?.status === 'NEEDS_REVIEW')
            .length,
);
const withoutSuggestionCount = computed(
    () => uncodedTransactions.value.filter((transaction) => !suggestionResults.value[transaction.id]).length,
);

onMounted(async () => {
    try {
        const { data } = await axios.get('/api/bank-transactions');
        categories.value = data.categories;
        transactions.value = data.transactions;
    } catch (error) {
        loadError.value = error.response?.data?.message ?? error.message;
    } finally {
        loading.value = false;
    }
});

function suggestionFor(transactionId) {
    return suggestionResults.value[transactionId] ?? null;
}

function isSuggesting(transactionId) {
    return Boolean(suggestingIds.value[transactionId]);
}

function setSuggestion(transactionId, result) {
    suggestionResults.value[transactionId] = result;
}

function clearSuggestion(transactionId) {
    delete suggestionResults.value[transactionId];
}

function setSuggesting(transactionId, value) {
    if (value) {
        suggestingIds.value[transactionId] = true;
    } else {
        delete suggestingIds.value[transactionId];
    }
}

async function saveCategory(transaction) {
    savingId.value = transaction.id;
    saveError.value = '';

    try {
        await axios.patch(`/api/bank-transactions/${transaction.id}`, {
            category_id: transaction.category_id,
        });
        clearSuggestion(transaction.id);
        return true;
    } catch (error) {
        saveError.value = error.response?.data?.message ?? error.response?.data?.error ?? error.message;
        return false;
    } finally {
        savingId.value = null;
    }
}

async function suggestCategory(transaction) {
    if (transaction.category_id || isSuggesting(transaction.id)) return;

    setSuggesting(transaction.id, true);
    clearSuggestion(transaction.id);
    suggestionError.value = '';

    try {
        const { data } = await axios.post(`/api/bank-transactions/${transaction.id}/suggest-category`);
        setSuggestion(transaction.id, data);
    } catch (error) {
        const failure = error.response?.data;
        if (failure?.status === 'NEEDS_REVIEW') {
            setSuggestion(transaction.id, failure);
        } else {
            setSuggestion(transaction.id, {
                status: 'NEEDS_REVIEW',
                suggestion: null,
                reviewReasons: [
                    {
                        code: 'suggestion_request_failure',
                        message: 'The suggestion request failed. Choose a category manually or try again.',
                    },
                ],
            });
        }
    } finally {
        setSuggesting(transaction.id, false);
    }
}

async function suggestUncoded() {
    if (batchRunning.value) return;

    const targets = uncodedTransactions.value.filter((transaction) => !suggestionFor(transaction.id));
    if (targets.length === 0) return;

    batchRunning.value = true;
    batchCompleted.value = 0;
    batchTotal.value = targets.length;

    let nextIndex = 0;

    async function worker() {
        while (nextIndex < targets.length) {
            const transaction = targets[nextIndex];
            nextIndex += 1;

            await suggestCategory(transaction);
            batchCompleted.value += 1;
        }
    }

    try {
        const workerCount = Math.min(BATCH_CONCURRENCY, targets.length);
        await Promise.all(Array.from({ length: workerCount }, () => worker()));
    } finally {
        batchRunning.value = false;
    }
}

async function acceptSuggestion(transaction) {
    const result = suggestionFor(transaction.id);
    if (!result?.suggestion) return;

    const category = categories.value.find((item) => item.name === result.suggestion.category);
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
            <button
                class="rounded bg-fg-main-blue px-4 py-1.5 text-sm font-medium whitespace-nowrap text-white hover:bg-fg-main-blue-hover disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="loading || batchRunning || uncodedCount === 0 || withoutSuggestionCount === 0"
                @click="suggestUncoded"
            >
                {{ batchRunning ? `Suggesting ${batchCompleted}/${batchTotal}…` : 'Suggest uncoded' }}
            </button>
        </div>

        <div class="mb-3 flex flex-wrap gap-2 text-xs font-medium">
            <span class="rounded-full bg-fg-warning-15 px-2.5 py-1 text-fg-warning-text">Uncoded: {{ uncodedCount }}</span>
            <span class="rounded-full bg-fg-main-blue-9 px-2.5 py-1 text-fg-main-blue">Suggested: {{ suggestedCount }}</span>
            <span class="rounded-full bg-fg-danger-9 px-2.5 py-1 text-fg-danger-dark">
                Needs review: {{ needsReviewCount }}
            </span>
        </div>

        <p class="mb-3 text-xs text-fg-mid-grey">
            AI suggestions are never saved automatically. Review the suggestion and click <strong>Accept suggestion</strong>
            or continue using the category dropdown.
        </p>

        <p v-if="batchRunning" class="mb-4 rounded bg-fg-main-blue-9 p-3 text-sm text-fg-dark-blue">
            Processing {{ batchCompleted }} of {{ batchTotal }} uncoded transactions. Completed rows remain available while the rest continue.
        </p>
        <p
            v-else-if="batchTotal > 0 && batchCompleted === batchTotal"
            class="mb-4 rounded bg-fg-main-blue-9 p-3 text-sm text-fg-dark-blue"
        >
            Suggestions complete. Nothing was saved — accept clear suggestions or review ambiguous transactions.
        </p>

        <p v-if="loadError" class="mb-4 rounded bg-fg-danger-9 p-3 text-sm text-fg-danger-dark">{{ loadError }}</p>
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
                                    :disabled="isSuggesting(txn.id) || savingId === txn.id"
                                    @click.stop="suggestCategory(txn)"
                                >
                                    {{
                                        isSuggesting(txn.id)
                                            ? 'Suggesting…'
                                            : suggestionFor(txn.id)
                                              ? 'Refresh suggestion'
                                              : 'Suggest category'
                                    }}
                                </button>

                                <div
                                    v-if="suggestionFor(txn.id) && !txn.category_id"
                                    class="max-w-xs rounded border p-2 text-xs"
                                    :class="
                                        suggestionFor(txn.id).status === 'SUGGESTED'
                                            ? 'border-fg-main-blue-30 bg-fg-main-blue-9'
                                            : 'border-fg-warning bg-fg-warning-15'
                                    "
                                >
                                    <p
                                        class="font-semibold"
                                        :class="
                                            suggestionFor(txn.id).status === 'SUGGESTED'
                                                ? 'text-fg-dark-blue'
                                                : 'text-fg-warning-text'
                                        "
                                    >
                                        {{ suggestionFor(txn.id).status === 'SUGGESTED' ? 'Suggested' : 'Needs adviser review' }}
                                    </p>

                                    <template v-if="suggestionFor(txn.id).suggestion">
                                        <p class="mt-1 font-medium text-fg-dark-grey">
                                            AI category: {{ suggestionFor(txn.id).suggestion.category }}
                                        </p>
                                        <p class="mt-1 text-fg-mid-grey">
                                            Confidence: {{ Math.round(Number(suggestionFor(txn.id).suggestion.confidence) * 100) }}%
                                        </p>
                                        <p class="mt-1 leading-snug text-fg-dark-grey">
                                            {{ suggestionFor(txn.id).suggestion.reason }}
                                        </p>
                                    </template>

                                    <ul
                                        v-if="suggestionFor(txn.id).reviewReasons?.length"
                                        class="mt-1 list-disc pl-4 text-fg-warning-text"
                                    >
                                        <li v-for="reason in suggestionFor(txn.id).reviewReasons" :key="reason.code">
                                            {{ reason.message }}
                                        </li>
                                    </ul>

                                    <p class="mt-1 text-fg-mid-grey">Nothing has been saved. You remain in control.</p>

                                    <button
                                        v-if="suggestionFor(txn.id).suggestion"
                                        class="mt-2 rounded bg-fg-main-blue px-2 py-1 font-medium text-white hover:bg-fg-main-blue-hover disabled:opacity-50"
                                        :disabled="savingId === txn.id"
                                        @click.stop="acceptSuggestion(txn)"
                                    >
                                        {{
                                            savingId === txn.id
                                                ? 'Saving…'
                                                : suggestionFor(txn.id).status === 'SUGGESTED'
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
