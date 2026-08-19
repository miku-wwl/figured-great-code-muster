<script setup>
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import { money } from '../format';

const invoices = ref([]);
const bankTransactions = ref([]);
const bankCategories = ref([]);
const categories = ref([]);
const selected = ref(null);
const loading = ref(true);
const saving = ref(false);
const savedAt = ref(null);
const extracting = ref(false);
const extractionProgress = ref({ completed: 0, total: 0 });
const aiDrafts = ref({});
const aiError = ref('');
const aiWarnings = ref([]);
const aiConfidence = ref(null);
const checkingBank = ref(false);
const bankCheckProgress = ref({ completed: 0, total: 0 });
const bankCheckResults = ref({});

// The entry form being filled in for the selected invoice.
const form = ref(emptyForm());

function emptyForm() {
    return {
        supplier: '',
        invoice_date: '',
        total: null,
        category_id: null,
        lines: [{ description: '', amount: null }],
    };
}

onMounted(async () => {
    const [{ data: invoiceData }, { data: bankData }] = await Promise.all([
        axios.get('/api/invoices'),
        axios.get('/api/bank-transactions'),
    ]);
    invoices.value = invoiceData.invoices;
    categories.value = invoiceData.categories;
    bankTransactions.value = bankData.transactions;
    bankCategories.value = bankData.categories;
    loading.value = false;
});

function open(invoice) {
    selected.value = invoice;
    savedAt.value = null;
    aiError.value = '';
    aiWarnings.value = [];
    aiConfidence.value = null;
    if (aiDrafts.value[invoice.id] && !aiDrafts.value[invoice.id].error) {
        applyDraft(aiDrafts.value[invoice.id]);
    } else if (invoice.entered_at) {
        // Pre-fill from the saved entry so it can be reviewed or corrected.
        form.value = {
            supplier: invoice.supplier,
            invoice_date: invoice.invoice_date,
            total: invoice.total,
            category_id: invoice.category_id,
            lines: invoice.lines.map((l) => ({ description: l.description, amount: l.amount })),
        };
    } else {
        form.value = emptyForm();
    }
}

function addLine() {
    form.value.lines.push({ description: '', amount: null });
}

function removeLine(index) {
    form.value.lines.splice(index, 1);
}

const lineTotal = computed(() => form.value.lines.reduce((sum, l) => sum + (Number(l.amount) || 0), 0));

const totalCheck = computed(() => {
    const invoiceTotal = Number(form.value.total);
    if (!Number.isFinite(invoiceTotal) || !form.value.lines.some((line) => line.amount !== null && line.amount !== '')) {
        return null;
    }

    const expectedTotal = Math.round(lineTotal.value * 1.15 * 100) / 100;
    const difference = Math.round((invoiceTotal - expectedTotal) * 100) / 100;

    return {
        expectedTotal,
        difference,
        matches: Math.abs(difference) <= 0.01,
    };
});

function parseAiJson(text) {
    // Models sometimes wrap otherwise valid JSON in a markdown code fence or a sentence.
    const cleaned = text.replace(/^\s*```(?:json)?\s*/i, '').replace(/\s*```\s*$/i, '').trim();
    const start = cleaned.indexOf('{');
    const end = cleaned.lastIndexOf('}');
    if (start < 0 || end <= start) throw new Error('AI did not return a JSON object.');
    return JSON.parse(cleaned.slice(start, end + 1));
}

function normaliseResult(result) {
    const category = categories.value.find(
        (item) => item.name.toLowerCase() === String(result.category_name ?? '').toLowerCase(),
    );
    const lines = Array.isArray(result.lines)
        ? result.lines
              .filter((line) => line && line.description)
              .map((line) => ({
                  description: String(line.description),
                  amount: Number.isFinite(Number(line.amount)) ? Number(line.amount) : null,
              }))
        : [];
    const warnings = Array.isArray(result.warnings) ? [...result.warnings] : [];

    if (result.category_name && !category) {
        warnings.push(`AI suggested an unknown category: ${result.category_name}. Please choose one.`);
    }
    if (result.document_type === 'credit_note') {
        warnings.push('This is a credit note. Confirm how the credit should be recorded before saving.');
    }

    const form = {
        supplier: result.supplier ?? '',
        invoice_date: result.invoice_date ?? '',
        total: Number.isFinite(Number(result.total)) ? Number(result.total) : null,
        category_id: category?.id ?? null,
        lines: lines.length ? lines : [{ description: '', amount: null }],
    };
    const extractedLineTotal = lines.reduce((sum, line) => sum + (Number(line.amount) || 0), 0);
    const expectedTotal = Math.round(extractedLineTotal * 1.15 * 100) / 100;
    const difference = form.total === null ? null : Math.round((form.total - expectedTotal) * 100) / 100;
    const totalCheck = {
        expectedTotal,
        difference,
        matches: difference !== null && Math.abs(difference) <= 0.01,
    };

    if (!lines.length) {
        warnings.push('No line items were extracted. Add or verify the line items manually.');
    } else if (difference !== null && !totalCheck.matches) {
        warnings.push(`GST total check failed: expected ${expectedTotal.toFixed(2)}, invoice total is ${form.total.toFixed(2)}.`);
    }

    return {
        form,
        confidence: result.confidence ?? null,
        warnings,
        totalCheck,
        error: null,
    };
}

function applyDraft(draft) {
    form.value = {
        supplier: draft.form.supplier,
        invoice_date: draft.form.invoice_date,
        total: draft.form.total,
        category_id: draft.form.category_id,
        lines: draft.form.lines.map((line) => ({ ...line })),
    };
    aiConfidence.value = draft.confidence;
    aiWarnings.value = [...draft.warnings];
    aiError.value = draft.error ?? '';
}

async function extractInvoice(invoice) {
    try {
        const categoryNames = categories.value.map((category) => category.name).join(', ');
        const { data } = await axios.post('/api/ai', {
            system: 'You extract structured data from New Zealand farm invoices. Return valid JSON only, with no markdown or explanation.',
            prompt: `Extract this invoice into the exact JSON shape below.

Rules:
- Return JSON only; do not use a markdown code fence.
- Convert dates to YYYY-MM-DD.
- Copy the printed invoice total exactly. It is normally GST-inclusive.
- Line item amounts should be GST-exclusive when the invoice provides a subtotal.
- Include freight, cartage, delivery, discounts, and credit items as line items.
- For a credit note, preserve the positive credit amount and set document_type to credit_note.
- Never invent missing values; use null and add a warning.
- category_name must be exactly one of: ${categoryNames}.
- Add a warning when the total or a line item is unclear.

JSON shape:
{
  "supplier": string|null,
  "invoice_date": string|null,
  "document_type": "invoice"|"credit_note"|"unknown",
  "total": number|null,
  "category_name": string|null,
  "lines": [{"description": string, "amount": number}],
  "confidence": "high"|"medium"|"low",
  "warnings": string[]
}

Invoice text:
${invoice.raw_text}`,
        });

        return normaliseResult(parseAiJson(data.text));
    } catch (error) {
        return {
            form: emptyForm(),
            confidence: null,
            warnings: [],
            totalCheck: null,
            error: error.response?.data?.error ?? error.message ?? 'AI extraction failed. Enter the invoice manually.',
        };
    }
}

async function extractAllWithAi() {
    const pendingInvoices = invoices.value.filter((invoice) => !invoice.reviewed_at);
    if (extracting.value || !pendingInvoices.length) return;

    extracting.value = true;
    aiError.value = '';
    extractionProgress.value = { completed: 0, total: pendingInvoices.length };

    for (const invoice of pendingInvoices) {
        aiDrafts.value[invoice.id] = await extractInvoice(invoice);
        extractionProgress.value.completed += 1;
    }

    extracting.value = false;
    if (selected.value && aiDrafts.value[selected.value.id] && !aiDrafts.value[selected.value.id].error) {
        applyDraft(aiDrafts.value[selected.value.id]);
    } else if (selected.value && aiDrafts.value[selected.value.id]?.error) {
        aiError.value = aiDrafts.value[selected.value.id].error;
    } else if (!selected.value) {
        open(invoices.value.find((invoice) => !invoice.entered_at) ?? invoices.value[0]);
    }
}

function bankCandidates(invoice) {
    const supplierWords = String(invoice.supplier ?? '')
        .toLowerCase()
        .split(/[^a-z0-9]+/)
        .filter((word) => word.length >= 4);
    const total = Math.abs(Number(invoice.total));

    return bankTransactions.value.filter((transaction) => {
        const description = transaction.description.toLowerCase();
        const supplierMatch = supplierWords.some((word) => description.includes(word));
        const amountMatch = Number.isFinite(total) && Math.abs(Math.abs(transaction.amount) - total) <= Math.max(25, total * 0.05);
        return supplierMatch || amountMatch;
    });
}

async function checkBankCoding(invoice) {
    try {
        const candidates = bankCandidates(invoice);
        const categoryNames = bankCategories.value.map((category) => category.name).join(', ');
        const { data } = await axios.post('/api/ai', {
            system: 'You reconcile reviewed supplier invoices against bank transactions. Return valid JSON only, with no markdown or explanation.',
            prompt: `Check whether this reviewed invoice matches one of the candidate bank transactions and whether the bank category is consistent with the invoice category.

Rules:
- Return JSON only.
- Match by supplier clues, amount, and date; do not invent a transaction id.
- Only recommend a correction when the match is clear and confidence is high.
- If the bank category is inconsistent, recommended_category_name must equal the reviewed invoice category.
- category_name must be one of: ${categoryNames}.

Return exactly:
{
  "match_found": boolean,
  "transaction_id": number|null,
  "current_category_name": string|null,
  "invoice_category_name": string|null,
  "category_consistent": boolean|null,
  "recommended_category_name": string|null,
  "confidence": "high"|"medium"|"low",
  "reason": string
}

Reviewed invoice:
${JSON.stringify({
    supplier: invoice.supplier,
    invoice_date: invoice.invoice_date,
    total: invoice.total,
    category_name: categories.value.find((category) => category.id === invoice.category_id)?.name ?? null,
})}

Candidate bank transactions:
${JSON.stringify(candidates)}`,
        });

        const result = parseAiJson(data.text);
        const transaction = bankTransactions.value.find((item) => item.id === Number(result.transaction_id));
        const invoiceCategory = categories.value.find((category) => category.id === invoice.category_id);
        const recommendedCategory = result.category_consistent === false
            ? invoiceCategory
            : bankCategories.value.find(
                  (category) => category.name.toLowerCase() === String(result.recommended_category_name ?? '').toLowerCase(),
              );
        const canAutoCorrect = Boolean(
            transaction &&
                recommendedCategory &&
                result.match_found &&
                result.category_consistent === false &&
                result.confidence === 'high',
        );

        if (canAutoCorrect) {
            const { data: updated } = await axios.patch(`/api/bank-transactions/${transaction.id}`, {
                category_id: recommendedCategory.id,
            });
            Object.assign(transaction, updated);
        }

        bankCheckResults.value[invoice.id] = {
            ...result,
            transaction,
            autoCorrected: canAutoCorrect,
            recommendedCategory,
            error: null,
        };
    } catch (error) {
        bankCheckResults.value[invoice.id] = {
            error: error.response?.data?.error ?? error.message ?? 'Bank coding check failed.',
        };
    }
}

const reviewedInvoices = computed(() => invoices.value.filter((invoice) => invoice.reviewed_at));
const selectedBankCheck = computed(() => (selected.value ? bankCheckResults.value[selected.value.id] : null));
const correctedBankCount = computed(
    () => Object.values(bankCheckResults.value).filter((result) => result.autoCorrected).length,
);

async function checkAllBankCoding() {
    if (checkingBank.value || !reviewedInvoices.value.length) return;

    checkingBank.value = true;
    aiError.value = '';
    bankCheckProgress.value = { completed: 0, total: reviewedInvoices.value.length };

    for (const invoice of reviewedInvoices.value) {
        await checkBankCoding(invoice);
        bankCheckProgress.value.completed += 1;
    }

    checkingBank.value = false;
}

async function save() {
    saving.value = true;
    const { data } = await axios.put(`/api/invoices/${selected.value.id}`, form.value);
    Object.assign(selected.value, data);
    delete aiDrafts.value[selected.value.id];
    saving.value = false;
    savedAt.value = new Date();
}
</script>

<template>
    <div>
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold">Invoice entry</h2>
                <p class="text-sm text-fg-mid-grey">
                    Extract every invoice with AI, then review the structured draft before saving each entry.
                </p>
            </div>
            <button
                class="rounded bg-fg-dark-blue px-4 py-2 text-sm font-medium text-white hover:opacity-90 disabled:opacity-50"
                :disabled="extracting || loading || !invoices.some((invoice) => !invoice.reviewed_at)"
                @click="extractAllWithAi"
            >
                {{
                    extracting
                        ? `Extracting ${extractionProgress.completed}/${extractionProgress.total}…`
                        : `Extract pending invoices with AI (${invoices.filter((invoice) => !invoice.reviewed_at).length})`
                }}
            </button>
                <button
                    class="rounded border border-fg-dark-blue px-4 py-2 text-sm font-medium text-fg-dark-blue hover:bg-fg-main-blue-9 disabled:opacity-50"
                    :disabled="checkingBank || loading || !reviewedInvoices.length"
                    @click="checkAllBankCoding"
                >
                    {{
                        checkingBank
                            ? `Checking ${bankCheckProgress.completed}/${bankCheckProgress.total}…`
                            : `AI check all reviewed (${reviewedInvoices.length})`
                    }}
                </button>
        </div>

            <p v-if="correctedBankCount" class="mb-3 rounded bg-fg-positive-9 p-2 text-xs text-fg-positive-dark">
                AI corrected {{ correctedBankCount }} bank categor{{ correctedBankCount === 1 ? 'y' : 'ies' }}.
            </p>

        <p v-if="loading" class="text-fg-light-grey">Loading…</p>

        <div v-else class="grid grid-cols-1 gap-4 lg:grid-cols-4">
            <!-- Invoice list -->
            <div class="overflow-hidden rounded border border-fg-muted-grey bg-white">
                <button
                    v-for="invoice in invoices"
                    :key="invoice.id"
                    class="block w-full border-b border-fg-pale-grey px-3 py-2 text-left text-sm hover:bg-fg-pale-grey"
                    :class="selected?.id === invoice.id ? 'bg-fg-main-blue-9' : ''"
                    @click="open(invoice)"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span class="truncate font-mono text-xs">{{ invoice.filename }}</span>
                        <span v-if="invoice.entered_at" class="shrink-0 rounded-full bg-fg-positive-15 px-2 py-0.5 text-xs text-fg-positive-dark">
                            saved
                        </span>
                        <span v-if="invoice.reviewed_at" class="shrink-0 rounded-full bg-fg-positive-15 px-2 py-0.5 text-xs text-fg-positive-dark">
                            manually reviewed
                        </span>
                        <span v-else-if="aiDrafts[invoice.id] && !aiDrafts[invoice.id].error" class="shrink-0 rounded-full bg-fg-main-blue-9 px-2 py-0.5 text-xs text-fg-dark-blue">
                            AI ready
                        </span>
                        <span v-else-if="aiDrafts[invoice.id]?.error" class="shrink-0 rounded-full bg-fg-danger-9 px-2 py-0.5 text-xs text-fg-danger-dark">
                            needs review
                        </span>
                    </div>
                </button>
            </div>

            <!-- Raw invoice text -->
            <div class="lg:col-span-2">
                <p v-if="!selected" class="rounded border border-dashed border-fg-muted-grey p-8 text-center text-fg-light-grey">
                    Select an invoice.
                </p>
                <pre
                    v-else
                    class="overflow-x-auto rounded border border-fg-muted-grey bg-white p-4 font-mono text-xs leading-relaxed"
                    >{{ selected.raw_text }}</pre
                >
            </div>

            <!-- Entry form -->
            <div v-if="selected" class="rounded border border-fg-muted-grey bg-white p-4">
                <div class="mb-3 flex items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold">Entry form</h3>
                    <span v-if="aiConfidence" class="rounded-full bg-fg-main-blue-9 px-2 py-0.5 text-xs text-fg-dark-blue">
                        AI confidence: {{ aiConfidence }}
                    </span>
                </div>

                <p v-if="aiDrafts[selected.id] && !aiDrafts[selected.id].error" class="mb-3 rounded bg-fg-main-blue-9 p-2 text-xs text-fg-dark-blue">
                    AI draft loaded. Review every field and the total check before saving.
                </p>

                <p v-if="aiError" class="mb-3 rounded bg-fg-danger-9 p-2 text-xs text-fg-danger-dark">
                    {{ aiError }}
                </p>
                <div v-if="selected.reviewed_at" class="mb-3 rounded border border-fg-muted-grey p-2">
                    <p class="text-xs text-fg-positive-dark">Status: manually reviewed</p>
                    <div v-if="selectedBankCheck" class="mt-2 text-xs">
                        <p v-if="selectedBankCheck.error" class="text-fg-danger-dark">
                            {{ selectedBankCheck.error }}
                        </p>
                        <p v-else-if="selectedBankCheck.autoCorrected" class="text-fg-positive-dark">
                            Corrected bank transaction #{{ selectedBankCheck.transaction.id }} to {{ selectedBankCheck.recommendedCategory.name }}.
                        </p>
                        <p v-else-if="selectedBankCheck.match_found && selectedBankCheck.category_consistent" class="text-fg-positive-dark">
                            Bank category is consistent with this invoice.
                        </p>
                        <p v-else class="text-fg-warning-text">
                            {{ selectedBankCheck.reason || 'No high-confidence correction was applied. Please review manually.' }}
                        </p>
                        <p v-if="selectedBankCheck.transaction" class="mt-1 text-fg-light-grey">
                            Matched transaction: {{ selectedBankCheck.transaction.description }}
                            ({{ money(selectedBankCheck.transaction.amount) }}) · confidence {{ selectedBankCheck.confidence }}
                        </p>
                    </div>
                </div>
                <div v-if="aiWarnings.length" class="mb-3 rounded bg-fg-warning-15 p-2 text-xs text-fg-warning-text">
                    <p class="font-medium">Review before saving:</p>
                    <ul class="mt-1 list-disc space-y-0.5 pl-4">
                        <li v-for="warning in aiWarnings" :key="warning">{{ warning }}</li>
                    </ul>
                </div>

                <label class="block text-xs font-medium text-fg-mid-grey">Supplier</label>
                <input v-model="form.supplier" class="mb-2 w-full rounded border border-fg-muted-grey px-2 py-1 text-sm" />

                <label class="block text-xs font-medium text-fg-mid-grey">Invoice date</label>
                <input
                    v-model="form.invoice_date"
                    type="date"
                    class="mb-2 w-full rounded border border-fg-muted-grey px-2 py-1 text-sm"
                />

                <label class="block text-xs font-medium text-fg-mid-grey">Category</label>
                <select v-model="form.category_id" class="mb-2 w-full rounded border border-fg-muted-grey px-2 py-1 text-sm">
                    <option :value="null">— choose —</option>
                    <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                </select>

                <label class="block text-xs font-medium text-fg-mid-grey">Line items (excl. GST)</label>
                <div v-for="(line, index) in form.lines" :key="index" class="mb-1 flex gap-1">
                    <input
                        v-model="line.description"
                        placeholder="Description"
                        class="w-full rounded border border-fg-muted-grey px-2 py-1 text-xs"
                    />
                    <input
                        v-model.number="line.amount"
                        type="number"
                        step="0.01"
                        placeholder="0.00"
                        class="w-24 rounded border border-fg-muted-grey px-2 py-1 text-right font-mono text-xs"
                    />
                    <button
                        v-if="form.lines.length > 1"
                        class="px-1 text-fg-light-grey hover:text-fg-danger"
                        title="Remove line"
                        @click="removeLine(index)"
                    >
                        ✕
                    </button>
                </div>
                <button class="mb-2 text-xs text-fg-main-blue hover:text-fg-main-blue-hover hover:underline" @click="addLine">+ add line</button>
                <p class="mb-2 text-xs text-fg-light-grey">Lines sum to {{ money(lineTotal) }}</p>

                <label class="block text-xs font-medium text-fg-mid-grey">Invoice total (incl. GST)</label>
                <input
                    v-model.number="form.total"
                    type="number"
                    step="0.01"
                    class="mb-3 w-full rounded border border-fg-muted-grey px-2 py-1 text-right font-mono text-sm"
                />

                <div
                    v-if="totalCheck"
                    class="mb-3 rounded p-2 text-xs"
                    :class="totalCheck.matches ? 'bg-fg-positive-9 text-fg-positive-dark' : 'bg-fg-danger-9 text-fg-danger-dark'"
                >
                    <span v-if="totalCheck.matches">
                        Total check passed: {{ money(totalCheck.expectedTotal) }} including GST.
                    </span>
                    <span v-else>
                        Total mismatch: expected {{ money(totalCheck.expectedTotal) }} including GST,
                        invoice says {{ money(form.total) }} (difference {{ money(totalCheck.difference) }}).
                    </span>
                </div>

                <button
                    class="w-full rounded bg-fg-main-blue px-4 py-1.5 text-sm font-medium text-white hover:bg-fg-main-blue-hover disabled:opacity-50"
                    :disabled="saving"
                    @click="save"
                >
                    {{ saving ? 'Saving…' : 'Save entry' }}
                </button>
                <p v-if="savedAt" class="mt-1 text-center text-xs text-fg-positive-dark">Saved ✓</p>
            </div>
        </div>
    </div>
</template>
