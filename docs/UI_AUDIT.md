# System-wide UI audit

## Scope

The audit covers the authenticated shell, dashboard, transaction workflows, car/outside-car management, people and partner modules, reports, settings, login, setup, tables, forms, filters, feedback, overlays, responsive behavior, and keyboard accessibility.

## Findings and remediation

| Finding | System-wide remediation |
| --- | --- |
| Page sections used unrelated top/bottom margins, causing irregular rhythm. | `.page-content` now owns a token-based `--section-gap`; direct sections no longer provide ad hoc margins. |
| Card headers, bodies, filters, and form sections used different padding values. | Shared `--panel-padding`, `--table-cell-*`, and control-height contracts now govern every common component. |
| Templates contained 315 inline style declarations. | All template inline styles were removed and replaced with semantic components/utilities. Conditional UI now uses the native `hidden` state. |
| The existing stylesheet had undefined semantic variables and several competing responsive rules. | `ui-system.css` defines one final semantic token map and authoritative responsive contracts; all CSS custom-property references now resolve. |
| Wide content stretched without a stable canvas and header/content edges did not align. | The top bar and page canvas share `--content-max` and responsive `--page-gutter` values. |
| Forms and filters had inconsistent field heights, label spacing, and action alignment. | Controls use 40 px desktop / 44 px touch sizing, one focus ring, shared field rows, and reusable filter/action layouts. |
| Empty table states and supporting metadata were hand-spaced per page. | `.empty-table-cell`, `.table-secondary`, `.table-section-title`, and common table shells standardize density and whitespace. |
| Detail pages repeated one-off two-column tables. | `.detail-table` now owns label/value alignment, dividers, wrapping, and mobile behavior. |
| Report totals and accounting summaries used one-off large type and colour styles. | Shared report-total, summary-strip, and accounting-equation components now use semantic tokens. |
| Modal behavior was visual only. | Modals now receive dialog semantics, focus entry/trapping/restoration, Escape handling, and backdrop close behavior. |
| The off-canvas sidebar remained keyboard-focusable while closed. | Mobile sidebar state now synchronizes `aria-expanded`, `aria-hidden`, and `inert`; Escape closes and restores focus. |
| Icon actions and generated controls had incomplete accessible names/relationships. | Active navigation receives `aria-current`, icon-only controls receive labels, generated table headers receive `scope`, and unlabeled visible fields are associated with their controls. |
| Login and setup screens followed separate sizing rules. | Both public screens consume the same surface, spacing, control, type, and responsive tokens. |

## Guardrails

- Visual rules: [`UI_GUIDELINES.md`](UI_GUIDELINES.md)
- Authoritative CSS: `assets/css/ui-system.css`
- Static contract audit: `php scripts/test-ui-contract.php`
- Acceptance widths: 320, 375, 768, 1024, 1366, and wide desktop

The contract audit prevents a return to inline template styles, unresolved CSS variables, or missing/out-of-order UI stylesheet loading.
