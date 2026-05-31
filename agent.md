# AutoBooks Pro Build Agent

## Project Context

- Project: `AutoBooks Pro` for a car trading/reselling business
- Workspace: `/Volumes/ALLPROJECTS/CAR_MELA_ACCOUNTING/CAR_MELA_APP`
- Source specs:
  - `AutoBooks_Pro_SOW.docx`
  - `/Users/harshilvekariya/.codex/attachments/5d6931da-1042-47e7-801b-a80681e788f1/pasted-text.txt`

## Product Rules

- The app must be simple enough for non-accounting staff to use daily.
- Every transaction must preserve double-entry balance.
- The main accounting gateway remains Cash, Bank, and GST Bank.
- Corrections happen through reversal, not silent edits.
- The completed product should be light mode, not dark mode.
- The site should feel lightning fast: minimal UI chrome, small assets, fast server-rendered pages.
- Admin creates users with email and password.
- Admin assigns read and write access to books for each user.

## Stack Direction

- Keep building on the current PHP + MySQL codebase.
- Do not attempt a Node/React rewrite unless explicitly requested.

## Books Permission Model

Books currently tracked for permissions:

- Cash Book
- Bank Book
- GST Book
- General Ledger
- Trial Balance
- Profit & Loss
- Balance Sheet
- Car Profitability
- Debtor Ageing

Rules:

- `read` means the user can open and view the book/report.
- `write` means the user can post entries through that book when applicable.
- Admin always has full access.

## Implementation Tracker

### Done

- Read the SOW and pasted implementation prompt.
- Created a Codex skill for this project context.
- Added email-or-username login support.
- Added admin-managed user creation with email and password.
- Added per-book read/write permissions and server-side enforcement.
- Added report/sidebar visibility rules based on permissions.

### In Progress

- Replace the current dark theme with a clean light-mode UI foundation.
- Reduce visual heaviness so pages feel faster and easier to scan.

### Next

1. Finish light-mode foundation across layout, forms, tables, and login/setup screens.
2. Review the transaction flow against the pasted spec for missing JV and split-allocation behavior.
3. Expand partnership logic for funding ratio vs profit ratio handling.
4. Tighten report accuracy and date-based ledger calculations.
5. Add repeatable verification for posting rules and report correctness.

## Working Style

- Make changes in small, safe increments.
- Prefer centralized enforcement in auth/config/engine over scattered page-specific rules.
- When implementing business logic, trace impact through:
  - entry form
  - accounting engine
  - balances
  - reports
  - access control

