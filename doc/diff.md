# The diff program

Diff engines, structured diffs, a three-way merge and four renderers. Policy `tron/diff`. Tool table:
[root-programs.md](root-programs.md#diff-policy-trondiff).

## Engines

Exponential Tron carries its own engines (`tronDiffEngine`); the installation needs no diff library (none is installed for
production use here: `sebastian/diff` is only a test dependency, and tron does not run composer).

- **Myers** (default): the shortest edit script, O(ND). Common prefix and suffix are taken first; above 6000 edits a
  range is reported as replaced to keep time bounded.
- **Patience**: lines unique on both sides are anchors, their longest increasing run is kept, Myers between them.
  Better for code: moved functions stay whole.
- Granularity: `line` (lines keep their end, so a missing final newline shows as `\ No newline at end of file`),
  `word` (words, spaces and punctuation are tokens), `char`. `ignore_whitespace` compares lines with runs of
  whitespace folded.

The suite checks both engines on 300 random pairs: the script turns the left side into the right, and Myers is
minimal (its unchanged count equals the longest common subsequence).

## Renderers (`format`)

| format | gives |
|---|---|
| `unified` | the usual `--- a`, `+++ b`, `@@ -l,n +l,n @@` hunks with `context` lines; word and character diffs inline as `[-old-]{+new+}` |
| `side_by_side` | `rows` (op `same`, `change`, `del`, `add`, `gap`, with line numbers) and the same as fixed-width text |
| `ansi` | the unified diff coloured for a terminal (red removed, green added, cyan hunk heads) |
| `html` | the administration's table (below), or one inline block for word and character diffs |
| `ops` | the edit script only: `{op, left, right}` indexes |

### The HTML component

`tronDiffRender::html()` and the template part `design:tron/parts/diff.tpl` produce the same markup: a table with
the shared `.a4-table` and `.a4-sr-only` classes and the module classes `.a4-tron-diff`, `.a4-tron-diff-num`,
`.a4-tron-diff-left`, `.a4-tron-diff-right`, `.a4-tron-diff-gap` and row states `.is-same`, `.is-change`,
`.is-del`, `.is-add`, `.is-gap`. Changed words are `<del>`/`<ins>`. Screen readers hear "removed:", "added:",
"changed:". Colours come only from admin4 tokens (`--a4-ok-bg`, `--a4-bad-bg`, `--a4-ink`, `--a4-muted`,
`--a4-line`, `--a4-soft`, `--a4-mono` ...) in `stylesheets/modules/tron.css`, which carry light and dark values; no
inline style anywhere (AdminStylingRulesTest).

Use it on any admin page:

```
{include uri='design:tron/parts/diff.tpl' rows=$rows left_label='Published' right_label='Draft'}
```

with rows from `tronDiffRender::rows()` and their `left_html`/`right_html` escaped by `tronDiffRender::e()` or, for a
changed pair, `tronDiffRender::pairHtml()` (see `modules/tron/diff.php`).

**Setup > tron > Diff** (`tron/diff`, policy `tron/diff`) compares two pasted texts with these engines and this
component. Nothing entered there is stored.

## Structured diffs

`diff_json`, `diff_yaml`: values flattened to paths (`$.a.b[0]`); a list of objects that all carry a unique `id`,
`identifier`, `name` or `key` is matched by it (`$.list[id=7].title`), so an insertion is one `add`.
`diff_xml`: `/root/item[2]/@attr`, `/root/item[2]/text()`. `diff_ini`: `[Block] Name` with the INI reader of the
validate program (lists and hashes compared as values). Each change: `op` (`add`, `remove`, `change`), `path`,
`from`, `to`; `summary` counts them; `rendered` as text, ANSI or an HTML table (`.a4-tron-diff-changes`).

`diff_content_versions` compares two versions of a content object attribute by attribute (the kernel's text form of
each datatype, `eZContentObjectAttribute::toString()`), with a word diff of each changed text (`inline`); it reads as
the caller (`content/read`, and `content/versionread` for versions other than the published one). `diff_class` and
`diff_layout` read through the class and layout sources of the units programs; `draft:<id>` names a layout's draft.

## Three-way merge

`diff_merge3` is diff3: base against left and base against right; regions both kept are stable; between them a
region only one side changed takes that side, the same change on both sides is taken once, different changes are a
conflict written with `<<<<<<< left`, `||||||| base`, `=======`, `>>>>>>> right` (labels configurable). It answers
`merged`, `clean`, `conflicts[{base_line, left, base, right}]` and the chunk kinds.

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
