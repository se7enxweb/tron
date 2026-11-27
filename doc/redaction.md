# Redaction: answers are blind to secrets

Every answer Exponential Tron sends passes its redactor on the way out: tool results and tool errors, resources, prompts, the
initialize instructions, JSON-RPC errors. Secrets and personal data are masked wherever they appear, and the audit
records that it happened: which kinds and how many, never the values.

## What is masked

| Kind | In text | By key (structured answers) |
|---|---|---|
| `secret` | `password=...`, `passwd: ...`, `secret = ...`, `api_key: ...`, `client_secret ...`, `access_key ...`, `token: ...` (the value) | `password`, `passwd`, `pwd`, `secret`, `*_secret`, `api_key`, `private_key`, `access_key`, `access_token`, `refresh_token`, `auth_token`, `token`, `bearer`, `credentials`, `authorization`, `cookie`, `dsn`, `connection_string`, `password_hash` |
| `token` | Exponential Tron tokens, `Bearer ...`, JWTs, provider tokens (`ghp_`, `github_pat_`, `glpat-`, `xox?-`, `sk-`, `sk_live_`, ...) | |
| `key` | PEM private keys (whole block), cloud access keys (`AKIA...`, `ASIA...`) | |
| `dsn` | `scheme://user:password@host` (user and password), PDO data source names (`mysql:host=...;password=...`) | |
| `email` | e-mail addresses | `email`, `e_mail`, `mail`, `email_address` |
| `phone` | international numbers (`+49 30 1234 5678`, 8 to 15 digits), numbers after a label (`Tel:`, `phone`, `mobile`, `fax`, `Telefon`, `Handy`) | `phone`, `phone_number`, `telephone`, `tel`, `mobile`, `cell`, `fax` |

A masked value reads `[redacted:<kind>]`; the key and the rest of the text stay. Numbers and booleans are never
changed (`token_id: 5` stays). JSON text inside an answer is masked value by value, so it stays valid JSON.

What is not masked on purpose: logins (they are how users are named in tickets and audits), dates, ISO weeks, ids,
versions, ticket numbers, gnubus message ids, session to-do refs (`claude@cloud#12`) and commit refs
(`repo@sha`). The test `testNoFalseAlarms` keeps it that way, and checks every tool definition passes unharmed.

Katalyst's domains keep their own, finer masking (`ex****e.com`) for users without `tron/katalyst_detail`.

## The audit

Each audit entry of a tool call, resource read or prompt carries, in its `detail`, the kinds and counts the redactor
masked in that answer:

```json
{"args": {...}, "note": "", "redacted": {"email": 2, "secret": 1}}
```

Arguments in the audit were masked before (the same kinds, and every value under a secret-looking key), and long
text is cut at 200 characters.

## Settings

```ini
[RedactionSettings]
# enabled (default): every answer passes the redactor; disabled: answers go out as the tools give them
Output=enabled
# The kinds to mask; empty: all (secret, token, key, dsn, email, phone)
Kinds[]
```

Switching output redaction off is for a closed test installation only. The audit's own masking cannot be switched
off.

## Proof

- `tests/unit/RedactionTest.php`: each kind masked and counted; no false alarms; canary secrets planted in tickets,
  comments, links, content attributes, Katalyst rows, a card of centre's API and the INI instructions never appear
  in any answer of any tool (each called 12 times with valid arguments), resource, prompt or the instructions, in
  either world; the audit counts every kind and holds no canary.
- The contract fuzzer (doc/contract-testing.md) sends the canaries as arguments of every tool and searches every
  answer and the whole audit log for them.
- `tests/kernel/redaction_canary_live.php`: canaries planted in a content object, a user's account and the INI of a
  real installation, every tool called; results in doc/testing.md.

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
