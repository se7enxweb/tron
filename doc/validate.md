# The validate program

Twelve validators with one result format. Policy `tron/validate`. Tool table and the format:
[root-programs.md](root-programs.md#uniform-validator-result).

Every finding has `severity` (`error`, `warning`, `info`), a stable `rule` id skills can match on, a `message` and a
`location` (`line`, `column`, `path`). `valid` is true when there is no error. At most 500 findings are returned
(`meta.cut` counts the rest). A validator never echoes the values it checks: `validate_format` names `values[3]`,
never the address or number.

| tool | rules (prefix) | notes |
|---|---|---|
| `validate_html` | `html.*`: parse, tag-mismatch, entity, structure, doctype, lang, title, duplicate-id, id-syntax, label-for, img-alt, button-name, link-name, javascript-url, target-blank, nested-form, input-label, obsolete, inline-style, inline-handler | libxml for structure (HTML5 elements and custom elements are not complaints), Exponential Tron's rules on the tree; `html` or `url` |
| `validate_css` | `css.*`: comment, string, brace, paren, selector, declaration, declaration-outside, property, value, color, duplicate-property, empty-rule, at-rule, important, trailing | a tokenizer that understands strings, comments, `url()`, nesting and custom properties |
| `validate_json` | `json.syntax`, `schema.*` (one per keyword) | the syntax error's line and column; JSON Schema draft 2020-12 and draft-07 keywords, local `$ref` only |
| `validate_xml` | `xml.syntax`, `xml.entity`, `xsd.invalid`, `xsd.entity` | entity declarations refused (XXE); an XSD's imports are never fetched |
| `validate_yaml` | `yaml.syntax`, `yaml.tab`, `yaml.indent`, `yaml.duplicate-key`, `yaml.quote`, `yaml.unsupported` | tron's reader for configuration YAML; anchors, aliases, tags and several documents are reported, not read |
| `validate_ini` | `ini.syntax`, `ini.outside-block`, `ini.wrapper`, `ini.type-mix`, `ini.duplicate-block`, `ini.duplicate-key`, `ini.name`, `ini.whitespace` | the way eZINI reads a file; `file_name` ending in `.php` checks the wrapper |
| `validate_tpl` | `tpl.syntax`, `tpl.warning`, `tpl.unknown` | the `tpl` program's `tpl_lint` (the installation's own parser), called as the same user, so its policy applies too |
| `validate_php` | `php.parse-error`, `php.fatal-error`, `php.warning`, `php.deprecated`, `php.lint` | `php -l -n` on a copy in `[ValidateSettings] SandboxDir`, never run, `PhpLintTimeout` seconds, the copy removed after |
| `validate_links` | `links.http-4xx`, `links.http-5xx`, `links.redirect`, `links.refused`, `links.robots`, `links.failed` | each link through the web client (guard, robots.txt), HEAD then GET on 405, one at a time, `delay_ms` apart, `max_links` at most |
| `validate_a11y` | `a11y.*` | a `browser_a11y` job (an installed engine on the runner node); left out on other nodes |
| `validate_format` | `format.email`, `format.email-length`, `format.email-domain`, `format.url`, `format.url-scheme`, `format.phone` | E.164 for phones: `+` and 8 to 15 digits |
| `validate_seo` | `seo.*`: title, title-length, description, description-length, h1, heading-order, canonical, lang, viewport, noindex, img-alt, open-graph | `html` or `url` |

## Example

```json
{"name":"validate_ini","arguments":{"file_name":"site.ini.append.php","ini":"[A]\nX=1\nX[]=2\n"}}
```

```json
{"validator":"ini","valid":false,"counts":{"error":2,"warning":1,"info":0},
 "findings":[{"severity":"warning","rule":"ini.wrapper","message":"A .php settings file should start with <?php /* #?ini charset=\"utf-8\"? so the web server never shows it","location":{"line":1,"column":null,"path":null}},
             {"severity":"error","rule":"ini.type-mix","message":"X is a single value earlier in [A] and a list here","location":{"line":3,"column":null,"path":null}}, "..."],
 "meta":{"blocks":1,"settings":1}}
```

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
