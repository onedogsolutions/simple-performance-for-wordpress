# CSP Policy Builder — Coverage Gaps for `worker-src` / `script-src-elem`

## Symptom

With CSP enabled, the **Violation reports** panel lists entries under
*"Other violations (directives not in the builder)"*:

```
worker-src      → blob (3)
script-src-elem → blob (1)
```

These land in the "other" bucket, which is display-only: there is no **Allow**
button and no builder row, so an admin cannot resolve them without dropping to
the raw-policy editor. We need the builder to cover them.

## Root cause

The panel groups a report against a builder row only when the report's
directive name string-matches a row name in `CSP_DIRECTIVES`
(`src/components/CspPolicyCard.jsx`). Anything else falls into `otherReports`
(`CspPolicyCard.jsx:275`). The two entries above miss for two *different*
reasons:

### 1. `worker-src` — a genuinely missing directive

`worker-src` governs Web/Service/Shared Workers. It is **not** in any of the
three places the builder tracks directives:

- the React row list `CSP_DIRECTIVES` (`CspPolicyCard.jsx:12`),
- the PHP sanitiser allowlist `SPFW_Settings::CSP_DIRECTIVES`
  (`includes/class-spfw-settings.php:434`),
- the recommended `DEFAULT_CSP` / default `csp_directives`
  (`includes/modules/class-spfw-module-hardening.php:39`,
  `includes/class-spfw-settings.php:76`).

`worker-src` falls back to `default-src 'self'`, so a worker created from a
`blob:` URL (common in map, PDF, chart, and analytics libraries) is blocked.
With no row to hold it, the violation is unactionable.

### 2. `script-src-elem` — a reporting-granularity gap

Browsers report the **effective directive** at its most specific granularity:
`script-src-elem` / `script-src-attr` for scripts, `style-src-elem` /
`style-src-attr` for styles. These are *fallbacks of* `script-src` / `style-src`
— which **are** builder rows — but the raw name `script-src-elem` does not
match the row name `script-src`, so the report is orphaned into "other".

Critically, the policy we emit only ever contains `script-src` (the builder has
no `-elem` row), so **allowing the source on `script-src` is exactly what
resolves a `script-src-elem` violation** — the UI just never offers it.
`normalize_directive()` (`includes/class-spfw-rest-settings.php:397`) already
reduces a raw directive to its bare name; it simply does not collapse the
`-elem` / `-attr` fallback suffixes.

## Fix

Two independent changes, one per root cause, plus a small discoverability tweak.

### Fix A — add `worker-src` as a first-class builder directive

Keep all three directive registries in sync:

1. **`src/components/CspPolicyCard.jsx`** — add a row to `CSP_DIRECTIVES`
   (place it after `media-src`):
   ```js
   {
       name: 'worker-src',
       label: __( 'Workers (Web / Service Workers)', 'simple-performance-for-wordpress' ),
       tokens: [ "'self'", 'blob:', 'https:', "'none'" ],
   },
   ```
   `blob:` is a preset chip because instantiating a worker from a blob URL is
   the overwhelmingly common legitimate case (and the exact violation seen).

2. **`includes/class-spfw-settings.php:434`** — add `'worker-src'` to the
   `CSP_DIRECTIVES` allowlist, or `sanitize_csp_directives()` will silently drop
   anything the new row submits.

3. **Recommended default** — add `worker-src 'self' blob:` so the common case
   does not break out of the box, keeping the two representations in sync:
   - `DEFAULT_CSP` string (`class-spfw-module-hardening.php:39`). Note
     `default_csp_directives()` derives from this string, so "Reset to
     recommended" and the seeded builder pick it up automatically.
   - the static `csp_directives` default array
     (`class-spfw-settings.php:76`) — add `'worker-src' => array( "'self'", 'blob:' )`.

   *Trade-off:* adding `blob:` marginally widens the default, but `worker-src`
   is isolated from `script-src`, blob workers are near-universal, and the
   alternative (ship `worker-src 'self'` only, let admins click **Allow**) leaves
   the default policy breaking a very common pattern. Recommend including `blob:`.

### Fix B — fold granular effective-directives back to their base row

Extend `normalize_directive()`
(`includes/class-spfw-rest-settings.php:397`) so, after reducing to the bare
directive name, it collapses the script/style fallback variants:

```php
$aliases = array(
    'script-src-elem' => 'script-src',
    'script-src-attr' => 'script-src',
    'style-src-elem'  => 'style-src',
    'style-src-attr'  => 'style-src',
);
$directive = isset( $aliases[ $directive ] ) ? $aliases[ $directive ] : $directive;
```

Effects:
- Stored reports carry `directive = script-src`, so they group under the
  **Scripts** row's "Blocked by this directive" list and the existing **Allow**
  button adds the token (e.g. `blob:`) to `script-src` — the directive the
  policy actually emits — resolving the violation.
- The dedup key `directive|origin` (`class-spfw-rest-settings.php:352`) now
  merges `-elem` / `-attr` variants of the same origin into one entry.
- Consistent with the existing normalisation that already strips a trailing
  source list from `violated-directive`.

Because reports live in a 7-day transient (`CSP_REPORTS_TTL`), **no migration
is needed** — any already-stored `script-src-elem` rows age out. Doing this in
PHP at store time keeps the collapse in one place rather than duplicating the
alias map in the React grouping logic.

### Fix C — discoverability (optional, recommended)

Add `blob:` as a preset chip to the existing **Scripts** (`script-src`) and
**Styles** (`style-src`) rows in `CSP_DIRECTIVES` so an admin can toggle it
directly, not only via **Allow** on a violation.

## Files touched

| File | Change |
| --- | --- |
| `src/components/CspPolicyCard.jsx` | Add `worker-src` row; add `blob:` chip to `script-src`/`style-src` (Fix A, C) |
| `includes/class-spfw-settings.php` | Add `'worker-src'` to `CSP_DIRECTIVES` allowlist and to default `csp_directives` (Fix A) |
| `includes/modules/class-spfw-module-hardening.php` | Add `worker-src 'self' blob:` to `DEFAULT_CSP` (Fix A) |
| `includes/class-spfw-rest-settings.php` | Collapse `-elem`/`-attr` to base in `normalize_directive()` (Fix B) |

## Build & release steps

- `npm run build` to regenerate `build/index.js` (the enqueued bundle;
  `build/` is not committed, so this runs as part of packaging, not the source
  commit).
- Bump version in `simple-performance-for-wordpress.php` (header + `SPFW_VERSION`)
  and `readme.txt` (`Stable tag` + a new changelog entry), matching the existing
  release-housekeeping pattern.

## Verification

1. **Worker case:** enable CSP (Report-Only), load a page that spawns a
   `blob:` worker → confirm the violation now appears under the **Workers** row
   with an **Allow** button; toggling `blob:` on the row and saving emits
   `worker-src 'self' blob:` in the generated-policy preview and header.
2. **Script-elem case:** trigger a `script-src-elem → blob` violation →
   confirm it now groups under the **Scripts** row (not "other") and that
   **Allow** adds `blob:` to `script-src`, clearing the violation on reload.
3. **Regression:** existing directives (img-src, style-src, …) still round-trip
   through save/sanitise unchanged; the "other" bucket still catches truly
   unknown directives (e.g. `manifest-src`).
