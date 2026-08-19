# Bank Coding — Problem Discovery

## Problem

Bank coding is the work of assigning each bank-feed line to one account from
the official chart of categories so that the farm's reports mean something.
The current page is deliberately manual: the adviser chooses a category from a
dropdown and the choice is saved immediately.

There are 185 bank transactions in the official seed data. Only 36 have a
category, while 149 are blank. The first blank transaction is dated 2026-02-01.
The work is grunt work because many lines repeat a known merchant or description
and still have to be classified one at a time. It is not safe to treat every
line as automatic, because some descriptions are ambiguous or change meaning
when the amount direction changes.

> Advisers repeatedly classify obvious bank transactions by hand, while
> ambiguous transactions still require professional judgement.

## What we learnt

### 1. The historical labels are a useful local pattern library

The 36 completed rows are the official senior-adviser standard; no labels were
inferred for this note. Repeated descriptions are especially clear:

- `WAGES DIRECT CREDIT` is labelled `Wages` in all 4 historical occurrences;
  the same description appears in 9 later uncoded rows.
- `FMG INSURANCE DD 0044817` is labelled `Insurance` twice and appears in 4
  later uncoded rows.
- `SEALES WINSLOW LTD` is labelled `Feed` twice and appears in 5 later uncoded
  rows.
- `Z ENERGY TE RAPA` is labelled `Fuel` twice and appears in 4 later uncoded
  rows.
- `COUNTDOWN TE RAPA` and `NETFLIX.COM AUCKLAND` are labelled
  `Personal/Drawings`, not farm operating costs.

This means the first useful AI job is not inventing a new accounting scheme. It
is helping repeat an existing local coding pattern, while showing the adviser
why the pattern was used.

### 2. The category meaning is visible in the official examples

The source data gives concrete meanings without needing an external rulebook:

| Observed description | Official historical category |
| --- | --- |
| `FONTERRA CO-OP PAYMENT ...` | `Milk Income` |
| `PGG WRIGHTSON LIVESTOCK PROCEEDS ...` | `Livestock Sales` |
| `RD1 HAMILTON`, `SEALES WINSLOW LTD`, `FARMLANDS TE AWAMUTU` | `Feed` |
| `BALLANCE AGRI-NUTRIENTS LTD` | `Fertiliser` |
| `VETORA WAIKATO`, `CAMBRIDGE VET SERVICES` | `Vet & Animal Health` |
| `Z ENERGY ...`, `WAITOMO FUEL LTD BULK DELIVERY` | `Fuel` |
| `ANZ RURAL LOAN INTEREST 2201` | `Interest` |
| `FMG INSURANCE DD ...` | `Insurance` |

The official category list also contains `Rates`, but there is no historical
non-empty `Rates` label in the 36 completed rows. The later uncoded
`WAIPA DISTRICT COUNCIL RATES` row is therefore a good validation/review case,
not a reason to create a new label.

### 3. Amount direction is useful evidence, not a final decision

In the completed rows, the two income categories are positive: Fonterra
payments are `Milk Income` and PGG livestock proceeds are `Livestock Sales`.
The completed expense examples are negative. That sign is a useful feature for
an AI suggestion.

It is not sufficient by itself. The uncoded data includes positive refunds
(`RD1 HAMILTON REFUND`, `GO BUS REFUND`, `IRD GST REFUND`), a positive transfer
with a human note (`DC FROM T PRESTON REF PUT IT ON WAGES LOL`), and positive
`INTEREST EARNED`. A negative transaction can also be a transfer rather than an
expense. These should not be silently auto-coded from sign alone.

### 4. Merchant identity is not always enough

`PGG WRIGHTSON` illustrates why description and direction must be considered
together. `PGG WRIGHTSON LIVESTOCK PROCEEDS ...` is historically labelled
`Livestock Sales`, while the negative `PGG WRIGHTSON LTD` example is labelled
`Feed`. A model that only matches the merchant name could produce the wrong
category.

The other seeded pages can provide supporting context. For example, the invoice
fixtures include Ballance superphosphate, Vetora treatment, Waitomo bulk diesel
and McDougall hay cartage; the email fixtures include an Agriseed invoice and a
Waitomo account-change message. They help explain a supplier, but the current
bank transaction model has no direct invoice/email relation, so they should be
treated as corroborating context rather than an automatic join.

## How we checked

The conclusions above were checked against:

- `data/bank_transactions.csv` — the only source of historical bank labels and
  the 149 blank transactions.
- `data/categories.csv` — the official 12-category list.
- `evaluation/official_historical_bank_coding.csv` and `.jsonl` — a derived
  evaluation view containing only the 36 non-empty source labels.
- `resources/js/pages/BankCoding.vue` — confirms that the completed Dec–Jan
  rows are the senior-adviser example and that coding is currently a manual
  dropdown action.
- `app/Http/Controllers/BankTransactionController.php` and
  `app/Models/BankTransaction.php` — confirm that the existing write operation
  updates only `category_id` for a bank transaction.
- `data/invoices/`, `data/emails/`, `data/report_lines.csv` and
  `data/stock_records.csv` — checked for cross-page context, without changing
  or using them to invent a bank label.

## Chosen scope

The first vertical slice will handle one selected uncoded Bank Coding row at a
time:

1. Send the transaction, the official category list and a small set of matching
   completed examples to `/api/ai`.
2. Receive a structured suggestion containing one category, a short reason,
   confidence and a review flag.
3. Validate the response and fail closed if it is malformed or uncertain.
4. Show the suggestion to the adviser; only an explicit human action applies
   the category through the existing PATCH endpoint.

The first version will not bulk-auto-code 149 rows, auto-save ambiguous
transactions, infer labels absent from the official history, modify seeded data,
or automate Inbox, Monthly Report, Invoice Entry or Stock Reconciliation.

## Demo candidates

Good first demos are uncoded rows with a repeated historical pattern:

- `2026-02-04 WAGES DIRECT CREDIT` → `Wages`
- `2026-03-01 FMG INSURANCE DD 0044817` → `Insurance`
- `2026-02-02 WAITOMO FUEL OHAUPO` → `Fuel`
- `2026-03-20 FONTERRA CO-OP PAYMENT 000247` → `Milk Income`
- `2026-02-24 PGG WRIGHTSON LIVESTOCK PROCEEDS 019442` → `Livestock Sales`

## Transactions to treat as ambiguous

These are useful review cases rather than automatic-success demos:

- `2026-03-23 RD1 HAMILTON REFUND` — positive direction conflicts with the
  historical negative `RD1 HAMILTON` → `Feed` pattern.
- `2026-04-06 DC FROM T PRESTON REF PUT IT ON WAGES LOL` — a transfer-like
  description with an informal note, not a historical category pattern.
- `2026-03-31 TRANSFER TO ... SAVINGS` — an internal transfer description.
- `2026-05-06 IRD GST REFUND 043-712-889` — a refund with no historical label
  for this exact description.
- `2026-04-08 A M BISHOP MOTORS LTD` and `2026-05-18 NOEL LEEMING THE BASE` —
  the merchant description alone does not establish the intended official
  category.

The correct product behaviour for these cases is to surface uncertainty and
ask the adviser to decide, not to pretend that a plausible guess is a fact.
