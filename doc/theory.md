# Theory and proofs

What tron guarantees, why, and which test proves each claim. Every proof named here is a test in `tests/` that runs on
every `php tests/run.php` and on every PHP from 7.4 to 8.5 (`php tests/matrix.php`); `tests/conformance/TheoryTest.php`
fails if a test named on this page does not exist. Claims about a real installation are proven by the runs recorded in
[testing.md](testing.md).

Notation: **C** is a claim, **P** its proof.

## 1. The protocol

tron speaks JSON-RPC 2.0 with the MCP methods `initialize`, `ping`, `tools/list`, `tools/call`, `resources/list`,
`resources/templates/list`, `resources/read`, `prompts/list`, `prompts/get`, in the versions 2025-11-25,
2025-06-18, 2025-03-26 and 2024-11-05.

**C1.1 Negotiation.** A client asking for a version tron speaks gets that version; any other is answered with the
newest. *P:* `TronCoreUnitsTest::testProtocolNegotiation`, `TronMcpConformanceTest::testStdioNegotiatesEachVersion`
(all four versions over real stdio), `TronServerAuditTest::testInitializeAnswers`.

**C1.2 One answer per request, none per notification.** Every request with an id gets exactly one answer carrying the
same id (string or integer); notifications and client responses get none. *P:*
`TronServerAuditTest::testJsonRpcErrors`, `TronMcpConformanceTest::testStdioLifecycleAndErrors` (stray
notifications and responses, then the next answer belongs to the next request).

**C1.3 Errors are JSON-RPC errors.** Malformed JSON is -32700 with id null; a malformed message -32600; an unknown
method -32601; bad params, an unknown tool or prompt -32602; an unknown resource -32002 with the URI.
*P:* `TronServerAuditTest::testJsonRpcErrors`, `TronMcpConformanceTest::testStdioLifecycleAndErrors`.

**C1.4 Tool failures are results, not protocol errors.** A refused permission, invalid arguments, a tool's own
refusal and an internal failure come back as a `tools/call` result with `isError: true`, so the model can read them;
invalid arguments carry the schema. *P:* `TronServerAuditTest::testToolCallResults`.

**C1.5 Batches follow the version.** A batch is answered under 2025-03-26 and before, refused from 2025-06-18 on.
*P:* `TronServerAuditTest::testBatchesFollowTheVersion`, `TronHttpTransportTest::testNoHeaderMeansTheDefaultVersion`.

**C1.6 stdio carries only the protocol.** Every line tron writes to stdout is a JSON-RPC message; the session must
begin with `initialize` (ping excepted); end of input ends the process with exit code 0; an oversized line is refused
and the server carries on. *P:* `TronServerAuditTest::testRequireInitializeOnStdio`,
`TronMcpConformanceTest::testStdioLifecycleAndErrors`.

**C1.7 HTTP is stateless.** No session id, no event stream: POST answers JSON, a notification 202 without body, GET
405; the protocol version comes from the `MCP-Protocol-Version` header (2025-03-26 without it). Any node can answer
any request. *P:* `TronHttpTransportTest::testInitializeAndCall`, `TronHttpTransportTest::testNoHeaderMeansTheDefaultVersion`,
`TronMcpConformanceTest::testHttpEndToEnd`; on a real installation through Velocity and through the load balancer:
testing.md, "Proving run".

**C1.8 Pagination is complete and stable.** Paging through `tools/list` with the cursor yields every visible tool
once, sorted by name. *P:* `TronServerAuditTest::testToolsListPaginates`.

## 2. The permission model

tron has no permissions of its own. A principal is an Exponential user (plus, over a token, the token's scopes).

**C2.1 Visibility equals permission.** A user sees in `tools/list` (and resource and prompt lists) exactly the tools
whose policy the user holds; nothing more, nothing less. *P:* `TronPermissionMatrixTest::testToolsListPerRole` (ten
roles), `TronPermissionMatrixTest::testResourcesAndPromptsFollowPolicies`; with real roles on PostgreSQL:
`tests/kernel/kernel_tools_run.php` (testing.md).

**C2.2 Every call is checked again.** Calling a tool one may not see is refused with "Permission denied" and audited
as `denied`, whatever the client sends. *P:* `TronPermissionMatrixTest::testEveryCallPerRole` (every tool by every role).

**C2.3 Scopes only narrow.** A token's scope removes programs from what its user may do; it never adds a permission,
and an out-of-scope tool looks unknown. *P:* `TronPermissionMatrixTest::testTokenScopesNarrowTheUser`,
`TronHttpTransportTest::testScopedTokens`.

**C2.4 Limitations are not silently widened.** A policy granted with limitations counts as not granted unless the tool
declares it checks the limitation itself. *P:* `TronPermissionMatrixTest::testLimitedPoliciesAreNotEnough`.

**C2.5 Disabled users hold nothing.** A disabled user, and every token of that user, is refused at once. *P:*
`TronPermissionMatrixTest::testDisabledUserHoldsNothing`, `TronTokenServiceTest::testDisabledUsers`.

**C2.6 HTTP needs tron/use.** A valid token of a user without `tron/use` gets 403; no token or a wrong one 401.
*P:* `TronHttpTransportTest::testUsersWithoutTronUseAreRefused`, `TronHttpTransportTest::testRefusals`.

**C2.7 Bridged programs are double-gated.** With expcentre, tron requires `centre/api` before centre is called, and
centre's own refusals reach the agent unchanged. *P:* `TronCentreBridgeTest::testTronGateBeforeCentre`,
`TronCentreBridgeTest::testCentreErrorsBecomeToolErrors`.

## 3. Tokens

**C3.1 The table holds no usable token.** Only `sha256(token)` is stored; the secret (256 random bits) appears nowhere
in the database. *P:* `TronTokenServiceTest::testCreateAndAuthenticate`; on PostgreSQL: kernel_tools_run.php.

**C3.2 Expiry and revocation take effect on the next request.** *P:* `TronTokenServiceTest::testRefusals`,
`TronHttpTransportTest::testRevokedTokensStopAtOnce`.

## 4. The audit guarantees

**C4.1 Every call leaves a row.** Every `tools/call`, `resources/read`, `prompts/get` and refused HTTP sign-in is
recorded with user, token, transport, method, target, program, outcome and duration. *P:*
`TronServerAuditTest::testEveryCallIsAudited`, `TronHttpTransportTest::testRefusals` (sign-in rows).

**C4.2 No audit, no write.** A write tool is recorded as `started` before it runs; if that record cannot be written,
the tool does not run (read tools still answer). *P:* `TronServerAuditTest::testWriteToolsRefuseWithoutAudit`,
`TronServerAuditTest::testEveryCallIsAudited` (started, then ok; the started row carries the arguments).

**C4.3 Secrets do not reach the log.** Values under secret-looking keys, secrets written into text, tron tokens and
bearer strings are masked before any sink sees them. *P:* `TronCoreUnitsTest::testRedactor`,
`TronServerAuditTest::testEveryCallIsAudited`, `TronHttpTransportTest::testRefusals`.

**C4.4 All sinks or an error.** With several sinks an entry counts as kept only when every sink took it. *P:*
`TronServerAuditTest::testFileAndMultiSinks`.

**C4.5 Internal details stay inside.** An internal failure answers "internal error"; the exception and database
messages go to the server log only. *P:* `TronServerAuditTest::testInternalErrorsAreNotLeaked`.

## 5. Programs: isolation by construction

**C5.1 Names cannot collide.** A program registers only under its own prefix; a second program of the same name is a
conflict and loads nothing. *P:* `TronCoreUnitsTest::testRegistryEnforcesNamesAndStaging`,
`TronProgramLoaderTest::testBrokenProgramsCostOnlyThemselves`.

**C5.2 A broken program costs only itself.** Ten kinds of broken program (throwing, unparsable, wrong class, missing
class, a file outside its extension, bad manifests, too new, name clash, foreign prefix) each fail alone, leave nothing
registered, and a healthy program after them loads. *P:* `TronProgramLoaderTest::testBrokenProgramsCostOnlyThemselves`.

**C5.3 The core is always there.** The program `tron` and its tools cannot be disabled or switched off. *P:*
`TronProgramLoaderTest::testFactoryNeverDisablesTheCore`, `TronSwitchesTest::testSwitchedOffProgramsAndToolsAreLeftOut`.

**C5.4 Discovery is cheap.** Finding and loading programs across 300 extensions costs a few milliseconds. *P:*
`TronProgramLoaderTest::testDiscoveryIsFast` (limit 250 ms; measured about 3 ms).

## 6. Data integrity

**C6.1 Values never become SQL.** Every statement uses named parameters; on the kernel's connection each value is
quoted by the engine, text inside quotes and casts left alone, an unknown or unused parameter refused. *P:*
`TronCoreUnitsTest::testKernelInterpolation`, `TronCentreToolsTest::testListAndFilters` (literal `%` and `_`);
on PostgreSQL an injection attempt matches nothing (kernel_tools_run.php).

**C6.2 Install is idempotent and engine-correct.** *P:* `TronCoreUnitsTest::testSchemaInstallsAndSqlFilesAreCurrent`;
on PostgreSQL: testing.md (defect 1 and its fix).

**C6.3 Every centre change is history.** *P:* `TronCentreToolsTest::testUpdateAndReopen`.

## 7. Documentation is executable

**C7.1 The cookbook is true.** Every example runs and prints exactly what the cookbook shows. *P:*
`TronCookbookTest::testEveryExampleRunsAndPrintsItsExpectedOutput`, `TronCookbookTest::testCookbookAndExamplesMatch`.

**C7.2 This page is checked.** *P:* `TronTheoryTest::testEveryProofNamedHereExists`.

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
