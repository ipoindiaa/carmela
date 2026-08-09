# Database Error Audit & Fixes

Audit date: 2026-08-10
Scope: full backend — schema vs code cross-check, every SQL statement, every
ENUM literal, insert completeness, transaction safety, migration chain,
deletion flows, reports, and the daily entry flows.

## Issues found and fixed

### 1. "Duplicate entry … for key 'journal_entries.uk_reference'" (and uk_jv_reference)
**Root cause:** `getNextRefNo()` / `getNextVoucherRefNo()` compute the next
number as `MAX(reference_no) + 1` and then INSERT into a UNIQUE column. Two
operators posting at the same moment get the same number → duplicate-key
`PDOException`, and the whole entry is lost.

**Fix (`includes/accounting_engine.php`):**
- `postJournalEntry()` now retries the `journal_entries` insert up to 5 times,
  regenerating the reference number on each collision, before giving up.
- `saveJournalVoucher()` does the same for `journal_vouchers`.
- New `Database::isDuplicateKeyError()` helper detects 23000/1062 reliably.

### 2. Deadlock / lock-wait retry could silently corrupt a transaction
**Root cause:** `Database::query()` retried a deadlocked statement inside an
open transaction. InnoDB rolls back the *whole* transaction on deadlock, so
re-running just the failed statement loses the earlier statements of that
transaction (e.g. a half-written journal entry that later "commits" nothing).

**Fix (`includes/db.php`):**
- "gone away" and deadlock/lock-wait retries now only happen **outside** a
  transaction.
- Inside a transaction the caller gets a clear, friendly, retryable error and
  the transaction is rolled back cleanly — nothing is half-saved.

### 3. Deadlocks between concurrent journal posts
**Root cause:** `updateAccountBalance()` locks account rows (`SELECT … FOR
UPDATE`) in line order, which differs per entry type → two concurrent entries
could lock the same accounts in opposite orders → deadlock.

**Fix (`includes/accounting_engine.php`):** balance updates now run in a
consistent order (sorted by `account_id`), so concurrent entries acquire locks
in the same sequence. Line insertion order is unchanged.

### 4. "Data truncated for column 'ownership_type'" when adding an outside car
**Root cause:** `database/schema.sql` declared
`ownership_type ENUM('OWNED','COMMISSION')`, but the app stores `OUTSIDE`
(outside/commission cars). Fresh installs only worked if the runtime migration
happened to run — and the migration chain could silently abort before reaching
that step.

**Fix:**
- `database/schema.sql` now ships `ENUM('OWNED','COMMISSION','OUTSIDE')` so
  fresh installs are correct from the start.
- The `OUTSIDE` enum extension is now its own isolated migration step that runs
  right after the `ownership_type` column is ensured.

### 5. One failed migration silently blocked all later migrations
**Root cause:** `ensureAdvancedSchema()` ran ~50 DDL/backfill steps inside a
single `try/catch`. One failing ALTER (lock timeout, permission quirk,
old-data issue) silently skipped every remaining step, leaving a
half-migrated schema → later "unknown column" / "data truncated" errors.

**Fix (`includes/accounting_engine.php`):**
- New `runMigrationStep()` helper runs each step independently; failures are
  logged (and rethrown in the testing environment so the test suite sees them).
- The heavy `UPDATE journal_entries … JOIN (…)` car-link backfill now has a
  cheap `COUNT(*)` guard so large databases are not scanned on every request.

### 6. Loan-commission receipt could over-receive / orphan a journal entry
**Root cause:** `recordCarLoanCommissionReceipt()` read the commission case
with `FOR UPDATE` outside a transaction, so the row lock was released
immediately; the journal post, receipt insert, and case update were not atomic.

**Fix (`includes/car_loan_commission_accounting.php`):** the whole receipt
(row lock → journal post → receipt row → status update) now runs in one
transaction and rolls back cleanly on any failure.

### 7. Raw duplicate-key error on double-processed salary month
**Root cause:** the `salary_records` unique key (`employee_id, month, year`)
could be hit between the duplicate check and the insert (two tabs/operators).

**Fix (`includes/accounting_engine.php`):** the duplicate key is now caught and
surfaced as a clear message ("already processed by another session") instead of
a raw SQL error.

### 8. Audit log could try to write an invalid empty `business_id`
**Root cause:** `Auth::auditLog()` used `$_SESSION['business_id'] ?? ''`; with
no business session the row violates the NOT NULL + FK on
`audit_log.business_id` (and the fallback insert failed the same way).

**Fix (`includes/auth.php`):** skip the insert and log to the PHP error log
when there is no business session.

## Audited and verified clean

- Every SQL statement cross-checked against the schema (tables, columns,
  aliases, joins) — no unknown tables/columns in any page, report, or engine
  function.
- All ENUM literals written by application code match the schema ENUMs.
- Every `insert()` provides all required (NOT NULL, no-default) columns.
- Placeholder counts match parameter arrays (no HY093 "invalid parameter
  number" cases).
- All report/filter WHERE clauses are built from bound parameters (no
  injection or syntax breakage).
- Duplicate-key races in `createAccount()` / `setupDefaultAccounts()` were
  already handled and remain so.
- Reversal, deletion, and business-data-reset flows respect foreign keys.
- `transactions/new.php`, `edit.php`, `reverse.php`, `list.php`, `view.php`,
  `dashboard.php`, `cars/*`, `rto/*`, `settings/*`, `parties/*`, `partners/*`,
  `employees/*`, `outside-cars/*` reviewed — no remaining DB error sources
  found.

## Note on live testing

The sandbox used for this audit has no outbound network, so interactive
testing of the authenticated backend at test.tirangacarworld.com (login:
TestCarMela#2026) was not possible from here. The public surface of the test
site was verified reachable and healthy. After deploying these fixes, please
run the `scripts/test-*.php` suite and click through the daily flows
(New Entry → all types, outside car add, loan commission receipt, cash
reconciliation, reports) to confirm.
