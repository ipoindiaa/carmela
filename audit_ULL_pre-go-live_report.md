# AutoBooks Pro — Complete Car Mela Audit & Digitalisation Review

**Business:** Multi-partner Indian used-car ("car mela") trading firm moving from paper to digital.
**Role:** Practicing CA / auditor / systems reviewer protecting the owner's money and making the daily work feel natural.
**Date:** 2026-08-09
**Basis:** Full source review of schema, accounting engine, every entry flow, all reports, and the daily UI. (No PHP runtime available in this environment, so every change was verified by static inspection, brace-balance checks, and cross-checking every call site against signatures — **please run the `scripts/test-*.php` suite in your own environment before go-live.**)

---

## Part 1 — Does every entry go to the correct account? (Verification)

I traced each entry type through the engine to confirm the Dr/Cr and the accounts it touches. This is the "does the money land in the right place" check a CA would run.

| Entry type | Dr (debit) | Cr (credit) | Tagged to | Verdict |
|---|---|---|---|---|
| **Car Purchase** | Car (inventory asset) | Cash/Bank (paid now) + Seller/Creditor (unpaid balance) | car | ✅ Correct. Full price capitalised into the car asset (accrual basis). |
| **Partner funding on purchase** | Car (asset) | Partner Capital | car + partner | ✅ Correct. Partner money funds the car, no false seller payable. |
| **Car Repair / Service expense** | Car Repair (expense) then allocated Car (asset) | Cash/Bank then Car Repair | car | ✅ Correct — cost capitalised into the car; appears once in P&L as part of COGS at sale. |
| **RTO expense** | RTO Expense | Cash/Bank | car | ✅ Correct — stays a direct expense, subtracted in car P&L. |
| **RTO recovery** | Cash/Bank | RTO Recovery Income | car | ✅ Correct. |
| **Token received** | Cash/Bank | Customer Token Advances (liability) | car + party | ✅ Correct — a liability, never revenue (per your rule). |
| **Token forfeited** | Customer Token Advances | Token Forfeiture Income | car + party | ✅ Correct — becomes that car's profit. |
| **Token refunded** (of a forfeited) | Token Forfeiture Income | Cash/Bank | car + party | ✅ Correct — reduces that car's profit, even after the car is sold. |
| **Car sale** | Cash/Bank + (token applied) + Buyer (outstanding) | Car Sale Revenue | car + party | ✅ Correct — full revenue recognised at sale (accrual), buyer becomes a debtor for the balance. |
| **Close car account (COGS)** | P&L (cost of car sold) | Car (asset) | car | ✅ Correct — asset cleared, cost hits P&L. |
| **Partner profit split** | P&L | Partner Current A/c | car + partner | ✅ Correct — profit allocated to partners. |
| **Buyer pays outstanding** | Cash/Bank | Buyer/Debtor | car + party | ✅ Correct — clears the receivable. |
| **Pay seller outstanding** | Seller/Creditor | Cash/Bank | car + party | ✅ Correct — clears the payable. |
| **Salary** | Salary Expense | Cash/Bank (− advance recovery) | employee | ✅ Correct. |
| **Employee advance** | Employee Advance | Cash/Bank | employee | ✅ Correct. |
| **Employee commission** | Employee Commission Expense | Cash/Bank | employee + car | ✅ Correct (car optional). |
| **Loan given** | Party/Debtor | Cash/Bank | party | ✅ Correct. |
| **Loan taken** | Cash/Bank | Party/Creditor | party | ✅ Correct. |
| **Bad debt write-off** | Bad Debt Expense | Party/Debtor | party | ✅ Correct. |
| **Contra transfer** | Bank | Cash (or reverse) | — | ✅ Correct. |
| **Opening balance** | Account | Opening Balance Equity | — | ✅ Correct — balanced, journal-backed. |

**Overall accounting verdict: the double-entry spine is sound.** Every operational flow posts balanced Dr = Cr, every car-related entry carries the `car_id` (and party/partner/employee where relevant) tag, so the Car ledger, the Party ledger, and the Cash/Bank book all show the same transaction — the Universal Ledger Linking requirement holds. **No entry lands in the wrong account in the standard flows.**

The gaps below are not wrong-account bugs — they are *missing* flows, *missing* links, and *missing* controls. I fixed the three that hurt the most; the rest are documented with a clear fix.

---

## Part 2 — Gaps I found and fixed this round

### 1. Source (seller) relationship was lost on fully-paid purchases  ✅ FIXED
**The gap:** `carPurchase()` only created/linked the seller party *when money was still owed*. A car bought and paid in full on the spot (very common in a mela) had **no seller link**, so:
- The car page showed no "bought from Ramesh Motors".
- Ramesh Motors' profile/ledger showed nothing for that deal.
- The owner couldn't answer "which cars did I buy from Ramesh Motors and how much did I pay him across our whole relationship?"

This violated the ledger-linking principle for the most common deal type.

**The fix:** In `carPurchase()`, whenever a seller name is entered, the source party is now created/linked and `seller_party_id` is set on the car **even when fully paid** (journal unchanged — no false payable). 
- The **car page** now shows a clickable **"Seller (Source)"** with the seller's name and any payable.
- The **party page** now shows a **"Cars Bought From This Source"** table (and "Cars Purchased By This Buyer" for buyers) — full relationship history for negotiation and dispute resolution.

### 2. No sale-price control — an employee could sell a car cheap and pocket the difference  ✅ FIXED
**The gap:** Owned cars had **no "expected sale value"** and **no guard**; the only use of `expected_sale_price` was for commission cars. So a sale below what the car should have gone for was recorded silently.

**The fix:**
- Added an **"Expected Selling Value (₹)"** field on the **Add Car** form and the **Car Details** edit form.
- On the **Car page**, the sale price row now flags **"Sold below expected"** and shows the expected value.
- `carSale()` now raises a **WARNING alert** ("Car sold below expected value" / "Car sold below cost") into the owner's **Alerts feed** whenever a car sells for less than its expected value or less than its total cost. The alert is created **after** the sale commits, inside a try/catch, so it can never roll back a valid sale.
- Added two new alert types (`LOW_SALE_PRICE`, `SALE_BELOW_COST`) to the schema, the auto-migration, and the alert labels.

### 3. End-of-Day cash reconciliation  ✅ BUILT (full feature)
**The gap:** No place to record "cash book says ₹1,24,500 but physical count is ₹1,22,000" — a shortage silently vanished and couldn't be investigated.

**What I built:**
- **New screen** — `reports/cash_reconciliation.php` ("End-of-Day Cash Count"), linked in the sidebar under Reports. Pick a cash account, see the **book balance**, type the **counted cash**, pick a date, and reconcile.
- **Engine method `reconcileCash()`** posts a proper double-entry adjustment:
  - **Shortage** (counted < book): `Cash Shortage/Surplus (expense) Dr [diff] / Cash Cr [diff]` — the missing money becomes an **investigable expense line**, and the cash book is brought down to the physical count.
  - **Surplus** (counted > book): `Cash Dr [diff] / Cash Shortage/Surplus Cr [diff]`.
  - **Exact match:** recorded with no journal (just the history entry).
- **Owner sign-off enforced:** any mismatch requires an **admin (owner)** and a **reason**. A staff member cannot silently write off missing cash.
- **Full history table** per cash account (date, book, counted, shortage, surplus, status, linked journal entry, reason, approved-by), printable.
- **Shortage raises a `CASH_SHORTAGE` WARNING alert** into the owner's Alerts feed.
- New `cash_reconciliations` table (auto-migrated), new `CASH_RECONCILIATION` journal type, `CASH_SHORTAGE` alert type, and a `Cash Shortage / Surplus` system account.

### 4. Car details / source visibility for a paper-accustomed owner  ✅ FIXED (UX)
A traditional owner thinks in terms of "which car, bought from whom, sold to whom, and what's my profit." The car page and party page now surface that directly (source link, buyer link, expected vs actual, relationship history) instead of making them hunt through ledgers.

---

## Part 3 — Gaps still open (recommended, not yet built)

These are real gaps for a car mela. Each needs a deliberate decision because they change workflows or add entry types. Ranked by value/risk to the owner.

| # | Gap | Why it matters | Recommended fix |
|---|---|---|---|
| 1 | **External broker / "khatak" commission** — the system only pays commission to *employees*. The broker who brings a car or a buyer (the heart of every mela) has no clean entry. | You must create a fake employee or post an untagged journal to pay a broker, losing the car-link and broker-ledger history. | Add a **"Broker Commission"** entry type: `Commission Expense (car-tagged) Dr / Broker Party Cr` (payable) with the broker as a party ledger, so a broker's full commission history across all cars is trackable and settle-able. |
| 2 | ~~Cash shortage / surplus (End-of-Day count)~~ | **✅ DONE** — built `reports/cash_reconciliation.php`, admin-approved shortage/surplus journal, history, `CASH_SHORTAGE` alert. See Part 2 #3. | — |
| 3 | **Post-dated / bounced cheque** — no "cheque received vs cleared" state. | A bounced cheque leaves the books overstated until someone manually reverses. | Add a "cheque in hand" suspense account + clear/bounce flow. |
| 4 | **Trade-in / exchange deal** — old car + cash for a new car. | The traded-in car becomes invisible inventory without its own cost basis. | Add an exchange flow that creates a new car ledger at the assessed trade-in value. |
| 5 | **Bulk purchase of multiple cars in one deal** — no forced per-car apportionment. | A lazy entry can lump 3 cars into one cost record, wrecking per-car profit. | Add a bulk-purchase flow that forces a per-car cost split. |
| 6 | **Open-token ageing** — a second buyer's token can sit parked indefinitely. | Forgetting a parked token means forgetting someone's money. | Add a "tokens open past N days" report/alert so the owner reviews them. |
| 7 | **RTO Form 29/30 reference** — RTO records have `application_no`/`receipt_no` but no explicit Form 29/30 field. | For audit and ownership-transfer tracking. | Add a `form_ref` field (or map `application_no` to it) on RTO records. |
| 8 | **"Customer handles RTO directly" flag** — currently `is_recoverable` covers part of this, but a customer-paid-directly case can still tempt a fabricated recovery entry. | Avoids booking RTO money that never moved through you. | Add an explicit RTO-handling flag (dealer-handled vs customer-handled). |

---

## Part 4 — UX review for the paper-to-digital transition

**What's already good for a traditional owner:**
- **Today's Control Desk** dashboard: one screen with Ready Cars / Outside Cars / Sold Cars / Month P&L / Alerts, plus a **Cash/Bank ledger selector** and one-click **New Entry**. This mirrors the daily "morning check of the rojmal/ledger".
- **`transactions/new.php` is the single entry point** (per the build spec) — one place to add money in/money out, with searchable car/party/pay-from pickers and split-bill allocation.
- **Every report and every transaction view has a Print button** (`printPage()`), so you can print a cash-book page, a ledger, or a journal voucher — the closest thing to the paper register the staff are used to.
- **Held-token, seller, buyer, and expected-sale-value** are now visible on the car page without opening other screens.
- Per-book **read/write permissions** so staff can enter but not silently alter the books.

**Suggested UX polish (low-risk, do before go-live if possible):**
1. **Add a prominent "Today's Takings & Spending" strip** on the dashboard (today's cash/bank in vs out), so the owner can reconcile the day's cash at closing — the single most-used ritual in a mela.
2. **Shorten the New Entry screen's default labels** to mela terms (e.g., "Rozna (Token)", "Car Khareedi", "Car Bech", "Khatak/Broker") with a Gujlish label map, since operators think in those words — the build spec already allows Gujlish labels.
3. **A printable one-page Sale Receipt / Token Receipt** (formatted like a hand-written voucher, with car reg, buyer, amount, token, balance due) on the sale and token screens — a traditional owner expects to hand the customer a paper slip.
4. **Car list "profit snapshot"** — show expected sale, current cost, and projected profit per in-stock car so the owner can decide pricing without opening each car.

None of these touch the accounting engine, so they are safe to layer on. Items 1–3 are the biggest day-to-day usability wins.

---

## Part 5 — Overall readiness

**Verdict (as the owner's CA):** The accounting core is trustworthy — entries are balanced, correctly posted to the right accounts, and fully ledger-linked across car, party, and cash/bank. With token profit/refund, GST/TCS removed, source-history tracking, and the sale-price floor now in place, the system is ready to move this mela off paper **provided** you (a) run the test suite in your own environment, (b) decide the external-broker commission question before day one (it's the biggest missing daily flow), and (c) adopt the EOD cash reconciliation habit (gap #2) because cash is where melas actually leak money.

**Readiness score: 84 / 100** (up from 82 after the seller-history and sale-price control fixes).

**Top 3 actions before real money:**
1. Run `scripts/testing-env.sh reset` + every `scripts/test-*.php` in your environment; confirm migrations apply and all balances balance.
2. Decide and build the **external broker/khatak commission** flow (Part 3, #1) — you will need it the first week.
3. Add the **End-of-Day cash reconciliation** (Part 3, #2) and make it a daily habit.

---
*Prepared as the owner's protecting CA. Fixes shipped this round are live in the code; the open items are documented with concrete fixes, not vague "handle edge cases better."*
