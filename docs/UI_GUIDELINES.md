# Carmela UI system

This document is the visual contract for every authenticated page, report, form, modal, and public screen in Carmela.

## 1. Source of truth

- `assets/css/ui-system.css` is the final, authoritative UI layer. It is loaded after the legacy feature stylesheet so shared component contracts win consistently.
- Use CSS variables from `:root`; do not add one-off hex colours, radii, shadows, margins, or control heights in PHP templates.
- Inline `style` attributes are not allowed for layout or spacing. A dynamic inline value is acceptable only when it exposes a data-driven CSS custom property (for example, `--stat-color`).
- New page-specific CSS should describe a reusable component or a genuine feature layout. It must still consume the shared colour, space, type, radius, and shadow tokens.

## 2. Spacing and rhythm

The system uses a 4 px base grid.

| Token | Value | Typical use |
| --- | ---: | --- |
| `--space-1` | 4 px | icon/text micro-gap |
| `--space-2` | 8 px | compact control gap |
| `--space-3` | 12 px | related-item gap |
| `--space-4` | 16 px | component padding / field gap |
| `--space-5` | 20 px | panel padding |
| `--space-6` | 24 px | desktop page gutter |
| `--space-8` | 32 px | large section separation |
| `--space-10` | 40 px | empty-state breathing room |
| `--space-12` | 48 px | public-screen spacing |

Rules:

1. `.page-content` owns page-level vertical spacing. Direct page sections must not add their own top or bottom margins.
2. Use `--section-gap` between page sections and `--panel-padding` inside cards.
3. Use `--space-4` between form fields and `--space-2` between actions.
4. Avoid negative margins. Group related content in a card, stack, toolbar, or section instead.

## 3. Layout

- The authenticated shell has one sidebar, one sticky top bar, and one centred content canvas.
- The content canvas is fluid up to `--content-max`; data tables may scroll inside their own shell but must never widen the page.
- Standard responsive breakpoints are 1200 px, 900 px, 768 px, and 480 px.
- Use `.grid-2`, `.grid-3`, `.grid-4`, `.stats-grid`, `.form-row`, and `.form-row-3`. All use `minmax(0, 1fr)` and collapse through the shared responsive contract.
- Use `.content-narrow` (640 px), `.content-medium` (800 px), or `.content-wide` (1040 px) for bounded forms. Do not write `max-width` inline.
- Use `.stack`, `.cluster`, `.page-actions`, and `.form-actions` for flow. Do not hand-code flexbox in a template.

## 4. Surfaces

- Page background: `--surface-page`.
- Primary cards: `--surface-panel`, one subtle border, `--shadow-1`, `--radius-panel`.
- Nested/secondary groups: `--surface-subtle`; do not place a heavily shadowed card inside another card.
- Card anatomy is `.card-header`, `.card-body`, and `.card-footer`. A table that touches card edges uses `.card-body-flush`.
- Use `.danger-zone` only for destructive administration tasks.

## 5. Typography

- Body copy uses `--font-size-base` and `--line-height-base`.
- Page identity lives in the sticky top bar. In-content page headers provide breadcrumbs, description, and actions rather than duplicating a large title.
- Card titles use `--font-size-lg`; helper text uses `--font-size-sm`; metadata uses `--font-size-xs`.
- Use `.amount` for currency and numeric balances so tabular figures align.
- Do not use colour as the only status cue; pair it with a label, icon, or badge.

## 6. Controls and actions

- Default controls are 40 px high on desktop and 44 px on touch layouts.
- Primary action: `.btn-primary`. One primary action per region.
- Secondary action: `.btn-outline`. Quiet toolbar action: `.btn-ghost`.
- Destructive action: `.btn-danger` or `.btn-danger-outline`; never represent deletion with a neutral primary button.
- Icon-only actions use `.btn-icon` and must have an `aria-label`.
- Every interactive element must expose a visible `:focus-visible` ring.
- Disabled and submitting states must remain legible and must not rely on cursor changes alone.

## 7. Forms

- Every input has a visible label. Required state belongs in label text or native validation, not placeholder-only copy.
- Use `.form-hint` for persistent guidance and `.form-error` for validation.
- Related fields belong in `.form-row` / `.form-row-3`; complex groups use `.form-section` and `.form-section-heading`.
- Filters belong in `.filter-bar` with `.filter-form`. Filters use the same control heights and label spacing as regular forms.
- Long searchable datasets use the shared searchable select/picker components.

## 8. Tables and data density

- Wrap every data table in `.table-container`.
- Add `.table-columns-medium` or `.table-columns-wide` only when the data genuinely needs a minimum width.
- Headings are sentence-case visually compact labels; amounts align right; actions remain grouped.
- Empty rows use `.empty-table-cell`; supporting text uses `.table-secondary`.
- Card tables use `.card-body-flush` or a direct table shell, never inline zero-padding.
- Tables may scroll horizontally on small screens. The page itself may not scroll horizontally.

## 9. Feedback and overlays

- Alerts use `.alert-success`, `.alert-error`, `.alert-warning`, or `.alert-info`.
- Only transient flash alerts auto-dismiss. Guidance and warnings remain until the user acts.
- Empty states explain what is missing and, when possible, offer the next action.
- Modals use the shared header/body/footer anatomy, remain within the viewport, trap focus, close on Escape, and restore focus to their trigger.
- Use badges for concise state and alerts for messages; do not use badges as buttons.

## 10. Responsive and accessibility acceptance checks

Before a UI change is complete, verify:

- 320 px, 375 px, 768 px, 1024 px, 1366 px, and wide-desktop layouts.
- No page-level horizontal overflow.
- Keyboard access, visible focus, Escape-to-close, and meaningful icon-button labels.
- Text and controls remain usable at 200% zoom.
- Touch targets are at least 44 px on mobile.
- Status colours meet contrast requirements and include text/icon context.
- Reduced-motion preferences are honoured.
- Print views omit navigation, actions, filters, uploads, and overlays.
