# Implementation Plan — Fix localized Google Fonts rendering at the wrong (heavier) weight

**Branch:** `claude/plugin-font-weight-issues-2xfjms` · **Version target:** 1.7.1
**Status:** 📝 Plan approved-pending — not yet implemented
**Author date:** 2026-07-15

---

## 1. Problem statement

After enabling **Self-host Google Fonts** and scanning, text renders at a heavier
weight than specified, site-wide:

- Footer newsletter-signup links render at ~700 instead of the specified 400.
- Blog archive and single-post `<p>` body copy (specified at the theme's
  lightest body weight, 300/400 Roboto Condensed) renders bold.
- DevTools **computed styles still report `font-weight: 400`** while the glyphs
  are visibly the 700 face — i.e. the CSS cascade is correct; the wrong *font
  file/face* is being used to satisfy it.

The 1.7.0 discovery hardening (commit `e986f48` — multi-page scan targets,
manual family declarations, cache-busting loopback) did **not** resolve this,
because the defect is not in *discovery* at all. It is downstream, in how
discovered `@font-face` blocks are deduplicated before the rewritten
`fonts.css` is generated. Even a perfect scan that sees every weight still
produces a broken stylesheet.

## 2. Root cause (verified 2026-07-15 against the live Google Fonts API)

Google Fonts now serves **variable fonts** for many families — including
Roboto Condensed (v31), this site's body font. For variable-font families,
every requested weight shares the **same `.woff2` file**; the weights are
distinguished only by the `font-weight` descriptor on otherwise-identical
`@font-face` blocks.

Verified with the plugin's own Chrome UA against both API versions the plugin
can encounter:

| Request | `@font-face` blocks | Unique `.woff2` URLs |
|---|---|---|
| `css?family=Roboto+Condensed:300,400,700` (v1 — what BB Theme enqueues) | 21 | **7** |
| `css2?family=Roboto+Condensed:wght@300;400;700` (v2 — manual declarations) | 21 | **7** |

21 blocks = 3 weights × 7 unicode-range subsets (latin, latin-ext, cyrillic,
…). Each subset has **one** file URL shared by all three weights, and Google
emits the blocks in ascending weight order (300 → 400 → 700).

The plugin dedupes parsed faces **by source URL alone**, in three places:

1. `SPFW_Module_Fonts::parse_font_faces()` — `$faces[ $src ] = array( … )`
   (`includes/modules/class-spfw-module-fonts.php:753`): within one CSS
   response, the last block per URL overwrites the earlier ones.
2. `SPFW_Module_Fonts::scan()` — `$font_faces[ $face['src_url'] ] = $face;`
   (`includes/modules/class-spfw-module-fonts.php:221`): the same collapse when
   unioning faces across multiple CSS URLs.
3. `SPFW_Module_Fonts::find_inlined_gstatic_faces()` —
   `$faces[ $face['src_url'] ] = $face;`
   (`includes/modules/class-spfw-module-fonts.php:189` and `:494`): same again
   for CDN-inlined blocks.

Because blocks arrive in ascending weight order, **only the `font-weight: 700`
block survives for each subset URL**. The generated `fonts.css` therefore
declares `Roboto Condensed` at weight 700 *only*. Browser font-matching then
uses that sole face for every requested weight — the variable font is
instanced at the 700 descriptor — so 300/400 text renders bold while computed
styles still say 400. This exactly matches every reported symptom, and
explains why the 1.7.0 discovery fix changed nothing.

Static (non-variable) families are unaffected only by luck: their weights have
distinct URLs. Any variable-font family (an ever-growing share of Google's
catalog) collapses to its heaviest requested weight; multi-weight italics
collapse the same way (italic VFs are a separate shared file).

## 3. Fix design

### Step F1 — Dedupe faces by full identity, not by URL

`includes/modules/class-spfw-module-fonts.php`:

- `parse_font_faces()`: add a `key` to each parsed face — a hash of the
  whitespace-normalized full block text (e.g.
  `sha1( preg_replace( '/\s+/', ' ', $block[0] ) )`). Store faces as
  `$faces[ $key ] = …` and return them **keyed** (drop the
  `array_values()`). A block-content hash is the simplest identity that
  distinguishes weight/style/unicode-range/src simultaneously, while still
  deduping genuinely identical blocks that appear in more than one scanned CSS
  response (e.g. the same URL captured from the enqueue pipeline *and* the
  HTML regex pass).
- `scan()`: union faces with `$font_faces[ $face['key'] ] = $face;` (both the
  inlined-gstatic loop and the per-CSS-URL loop).
- `find_inlined_gstatic_faces()`: filter on `fonts.gstatic.com` in `src_url`
  but key the returned array by `$face['key']`.
- Everything else about a face (verbatim `block`, `family`, `weight`, `style`,
  `src_url`) is unchanged — the rewrite already preserves `font-weight`
  descriptors and `unicode-range` because it keeps the whole block; the fix is
  purely about *which blocks survive*.

Result: all 21 Roboto Condensed blocks survive into `fonts.css`, several of
them pointing at the same local file with different `font-weight` descriptors
— which is exactly correct for a variable font.

### Step F2 — Memoize font downloads within a scan

With F1, faces legitimately share URLs (3 faces per subset file for this
site), and `scan()` calls `download_font()` once per face. Add a per-scan
memo (`$downloaded = array( url => filename|false )`) so each unique URL is
fetched from `fonts.gstatic.com` exactly once; reuse the memoized filename
(or skip the face if the memoized value is `false`). `$files` already passes
through `array_unique()` at persist time, so the summary counts stay correct
(7 files, not 21).

### Step F3 — Remediation for already-scanned installs (stale-CSS flag)

The broken stylesheet is *persisted* (in `fonts.discovered.css` and the static
`uploads/ods-fonts/fonts.css`); the code fix alone changes nothing until the
site is re-scanned. Make that visible instead of silent:

- **Schema** (`includes/class-spfw-settings.php`): add
  `fonts.needs_rescan` (bool, default `false`) to defaults + `sanitize()`
  (cast via the existing `to_bool()` helper).
- **Migration**: in `SPFW_Settings::get()`'s existing migration ladder, add a
  `version_compare( $stored_ver, '1.7.1', '<' )` step: if
  `fonts.discovered.css` is non-empty, persist `fonts.needs_rescan = true`
  (mirror the `run_csp_mode_migration()` pattern).
- **Clearing**: `SPFW_Module_Fonts::finish_scan()` sets
  `fonts.needs_rescan = false` on every completed scan (found or empty — the
  stale marker refers to the *old generator*, and any post-fix scan supersedes
  it; an empty result also leaves prior CSS intact by design, but the flag has
  served its purpose once the admin has re-scanned).
- **REST**: nothing new needed — `GET spfw/v1/settings` already returns the
  full `fonts` group, so `needs_rescan` rides along.
- **React** (`src/components/FontsSettings.jsx`): when
  `fonts.needs_rescan && fonts.localize_google`, render an amber warning
  banner in the Google Fonts card: fonts were localized by an earlier plugin
  version that could drop font weights (text may render bolder than
  designed) — click **Scan fonts now** to rebuild them. Reuse the amber
  warning-box styling conventions from `CspPolicyCard.jsx`.
- **Admin notice** (`SPFW_Module_Fonts`, new small `admin_init`/`admin_notices`
  hook following the `SPFW_Module_Hardening` precedent): when
  `needs_rescan && localize_google`, show a dismissible-by-fixing native
  warning notice linking to Settings → Simple Performance → Fonts, so an admin
  who never opens the Fonts tab still learns their live site is rendering
  wrong weights. Gate it to users with `manage_options`.

### Step F4 — Version bump + docs

- Bump to **1.7.1**: plugin header + `SPFW_VERSION` in
  `simple-performance-for-wordpress.php`; `Stable tag` + changelog entry in
  `readme.txt` ("Fixed: variable-font families (e.g. Roboto Condensed) were
  localized with only their heaviest weight, making body text render bold —
  re-scan fonts after updating.").
- Update `STATE.md` per the update protocol (dated decisions-log entry, Next
  action, Overall status) in the same commit as the code.
- `.pot` regeneration remains outstanding project-wide; the new banner/notice
  strings from F3 add to that backlog (note it in STATE.md, don't block on it).

## 4. Verification plan

Scratch harnesses (reflection over private methods, mocked `wp_remote_get` /
temp-dir filesystem — same style as every prior step; not committed):

1. **Parser**: feed the saved *real* v1 and v2 Roboto Condensed fixtures
   (21 blocks / 7 URLs, captured 2026-07-15) into `parse_font_faces()` →
   assert 21 faces survive, weights {300, 400, 700} present for every one of
   the 7 subset URLs, italic/style and `unicode-range` text preserved
   verbatim in `block`.
2. **Static-font regression**: a fixture with distinct URLs per weight (the
   pre-variable-font shape) still parses to one face per block with no
   duplicates, and two different CSS responses containing byte-identical
   blocks dedupe to a single face (the block-hash key working as intended).
3. **Full scan**: mocked network returning the fixture → generated CSS
   contains all three `font-weight` values per subset, every
   `fonts.gstatic.com` URL rewritten to the local `ods-fonts` URL, exactly
   **7** files written to disk, and `wp_remote_get` called exactly once per
   unique font URL (memoization); `discovered.families` lists
   `Roboto Condensed:300`, `:400`, `:700`.
4. **Inlined-gstatic path**: HTML containing two inlined `@font-face` blocks
   sharing one URL but differing in weight → both survive.
5. **Migration/flag**: stored settings at version 1.7.0 with non-empty
   `discovered.css` → `needs_rescan` becomes `true`; fresh install → `false`;
   running `scan()` (any outcome) → back to `false`.
6. **Toolchain**: `php -l` on all changed PHP; `npm run build`;
   `wp-scripts lint-js` on changed JSX; `npm run lint:css`.

Live QA on onedog.solutions (manual, post-deploy):

- Update plugin → warning notice + Fonts-tab banner appear.
- Re-scan → banner clears; families list shows all three weights; fetch
  `/wp-content/uploads/ods-fonts/fonts.css` and confirm `font-weight: 300`,
  `400`, and `700` blocks all present.
- Purge LSCache, then verify: footer newsletter link renders at 400; blog
  archive + single-post body copy renders at the theme's specified light
  weight (compare against a `?LSCWP_CTRL=before_optm` or Google-served
  control); DevTools Network shows no `fonts.googleapis.com` /
  `fonts.gstatic.com` requests; DevTools → rendered fonts shows the local
  file serving both regular and bold text.

## 5. Acceptance criteria

- [ ] For a variable-font family requested at N weights, the generated
      `fonts.css` contains one `@font-face` block per (weight × subset), with
      original `font-weight` descriptors and `unicode-range` intact.
- [ ] Each unique remote `.woff2` is downloaded exactly once per scan.
- [ ] Static (per-weight-URL) families behave exactly as before.
- [ ] Upgrading an install that has previously scanned sets
      `fonts.needs_rescan`, surfaces the Fonts-tab banner + admin notice, and
      a re-scan clears both.
- [ ] All harness checks in §4 green; `php -l`, build, and both linters clean.
- [ ] Version 1.7.1 everywhere; STATE.md updated in the same commit.

## 6. Out of scope (deliberately)

- **Requesting `wght@100..900` ranges** or merging same-URL blocks into a
  single range-descriptor block: an optimization, not a correctness fix, and
  it risks diverging from what the theme actually requested.
- **Per-page conditional dequeue** of Google styles: the site-wide dequeue is
  correct once the stylesheet carries every discovered weight.
- **Font subsetting / unicode-range pruning**: the verbatim-block model
  already ships Google's own subsetting.
