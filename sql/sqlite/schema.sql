-- tron 0.0.0.13 schema for sqlite. Generated from tronSchema::tables(); do not edit by hand.
-- Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).

CREATE TABLE tron_token (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL DEFAULT 0,
  name VARCHAR(100) NOT NULL DEFAULT '',
  token_prefix VARCHAR(16) NOT NULL DEFAULT '',
  token_hash VARCHAR(64) NOT NULL DEFAULT '',
  scopes VARCHAR(255) NOT NULL DEFAULT '',
  created INTEGER NOT NULL DEFAULT 0,
  expires INTEGER NOT NULL DEFAULT 0,
  last_used INTEGER NOT NULL DEFAULT 0,
  revoked INTEGER NOT NULL DEFAULT 0,
  created_by INTEGER NOT NULL DEFAULT 0
);
CREATE UNIQUE INDEX tron_token_prefix ON tron_token (token_prefix);
CREATE INDEX tron_token_user ON tron_token (user_id);

CREATE TABLE tron_audit (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  created INTEGER NOT NULL DEFAULT 0,
  user_id INTEGER NOT NULL DEFAULT 0,
  login VARCHAR(150) NOT NULL DEFAULT '',
  token_id INTEGER NOT NULL DEFAULT 0,
  transport VARCHAR(16) NOT NULL DEFAULT '',
  remote VARCHAR(64) NOT NULL DEFAULT '',
  method VARCHAR(64) NOT NULL DEFAULT '',
  target VARCHAR(255) NOT NULL DEFAULT '',
  program VARCHAR(32) NOT NULL DEFAULT '',
  outcome VARCHAR(16) NOT NULL DEFAULT '',
  duration_ms INTEGER NOT NULL DEFAULT 0,
  detail TEXT
);
CREATE INDEX tron_audit_created ON tron_audit (created);
CREATE INDEX tron_audit_user ON tron_audit (user_id, created);

CREATE TABLE tron_switch (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  kind VARCHAR(16) NOT NULL DEFAULT '',
  name VARCHAR(64) NOT NULL DEFAULT '',
  enabled INTEGER NOT NULL DEFAULT 0,
  changed INTEGER NOT NULL DEFAULT 0,
  changed_by INTEGER NOT NULL DEFAULT 0
);
CREATE UNIQUE INDEX tron_switch_name ON tron_switch (kind, name);

CREATE TABLE tron_centre_ticket (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  kind VARCHAR(16) NOT NULL DEFAULT '',
  board VARCHAR(64) NOT NULL DEFAULT '',
  lane VARCHAR(64) NOT NULL DEFAULT '',
  position INTEGER NOT NULL DEFAULT 0,
  status VARCHAR(16) NOT NULL DEFAULT '',
  priority VARCHAR(16) NOT NULL DEFAULT '',
  title VARCHAR(255) NOT NULL DEFAULT '',
  body TEXT,
  labels VARCHAR(255) NOT NULL DEFAULT '',
  assignee_id INTEGER NOT NULL DEFAULT 0,
  created_by INTEGER NOT NULL DEFAULT 0,
  created INTEGER NOT NULL DEFAULT 0,
  modified INTEGER NOT NULL DEFAULT 0,
  closed INTEGER NOT NULL DEFAULT 0,
  closed_reason VARCHAR(255) NOT NULL DEFAULT '',
  due INTEGER NOT NULL DEFAULT 0,
  decision_week VARCHAR(10) NOT NULL DEFAULT '',
  decision TEXT
);
CREATE INDEX tron_centre_ticket_board ON tron_centre_ticket (board, lane, status);
CREATE INDEX tron_centre_ticket_status ON tron_centre_ticket (status, modified);
CREATE INDEX tron_centre_ticket_week ON tron_centre_ticket (kind, decision_week);

CREATE TABLE tron_centre_comment (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ticket_id INTEGER NOT NULL DEFAULT 0,
  user_id INTEGER NOT NULL DEFAULT 0,
  body TEXT,
  created INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX tron_centre_comment_ticket ON tron_centre_comment (ticket_id);

CREATE TABLE tron_centre_link (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ticket_id INTEGER NOT NULL DEFAULT 0,
  kind VARCHAR(16) NOT NULL DEFAULT '',
  ref VARCHAR(255) NOT NULL DEFAULT '',
  note VARCHAR(255) NOT NULL DEFAULT '',
  user_id INTEGER NOT NULL DEFAULT 0,
  created INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX tron_centre_link_ticket ON tron_centre_link (ticket_id);
CREATE INDEX tron_centre_link_ref ON tron_centre_link (kind, ref);

CREATE TABLE tron_centre_history (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ticket_id INTEGER NOT NULL DEFAULT 0,
  user_id INTEGER NOT NULL DEFAULT 0,
  created INTEGER NOT NULL DEFAULT 0,
  field VARCHAR(32) NOT NULL DEFAULT '',
  old_value VARCHAR(255) NOT NULL DEFAULT '',
  new_value VARCHAR(255) NOT NULL DEFAULT ''
);
CREATE INDEX tron_centre_history_ticket ON tron_centre_history (ticket_id);

CREATE TABLE tron_plan (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  plan_key VARCHAR(32) NOT NULL DEFAULT '',
  user_id INTEGER NOT NULL DEFAULT 0,
  token_id INTEGER NOT NULL DEFAULT 0,
  tool VARCHAR(64) NOT NULL DEFAULT '',
  mode VARCHAR(16) NOT NULL DEFAULT '',
  input_hash VARCHAR(64) NOT NULL DEFAULT '',
  state_hash VARCHAR(64) NOT NULL DEFAULT '',
  changes_hash VARCHAR(64) NOT NULL DEFAULT '',
  created INTEGER NOT NULL DEFAULT 0,
  expires INTEGER NOT NULL DEFAULT 0,
  used INTEGER NOT NULL DEFAULT 0
);
CREATE UNIQUE INDEX tron_plan_key ON tron_plan (plan_key);
CREATE INDEX tron_plan_expires ON tron_plan (expires);

CREATE TABLE tron_journal_session (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  session_key VARCHAR(32) NOT NULL DEFAULT '',
  kind VARCHAR(16) NOT NULL DEFAULT '',
  parent_id INTEGER NOT NULL DEFAULT 0,
  user_id INTEGER NOT NULL DEFAULT 0,
  login VARCHAR(150) NOT NULL DEFAULT '',
  token_id INTEGER NOT NULL DEFAULT 0,
  transport VARCHAR(16) NOT NULL DEFAULT '',
  client_name VARCHAR(100) NOT NULL DEFAULT '',
  client_version VARCHAR(50) NOT NULL DEFAULT '',
  protocol VARCHAR(16) NOT NULL DEFAULT '',
  node VARCHAR(100) NOT NULL DEFAULT '',
  remote VARCHAR(64) NOT NULL DEFAULT '',
  title VARCHAR(255) NOT NULL DEFAULT '',
  intent TEXT,
  started INTEGER NOT NULL DEFAULT 0,
  last_activity INTEGER NOT NULL DEFAULT 0,
  ended INTEGER NOT NULL DEFAULT 0,
  end_reason VARCHAR(255) NOT NULL DEFAULT '',
  status VARCHAR(16) NOT NULL DEFAULT '',
  steps INTEGER NOT NULL DEFAULT 0,
  writes INTEGER NOT NULL DEFAULT 0,
  errors INTEGER NOT NULL DEFAULT 0,
  refused INTEGER NOT NULL DEFAULT 0,
  head_hash VARCHAR(64) NOT NULL DEFAULT ''
);
CREATE UNIQUE INDEX tron_journal_session_key ON tron_journal_session (session_key);
CREATE INDEX tron_journal_session_started ON tron_journal_session (started);
CREATE INDEX tron_journal_session_user ON tron_journal_session (user_id, started);

CREATE TABLE tron_journal_step (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  session_id INTEGER NOT NULL DEFAULT 0,
  seq INTEGER NOT NULL DEFAULT 0,
  created INTEGER NOT NULL DEFAULT 0,
  kind VARCHAR(16) NOT NULL DEFAULT '',
  method VARCHAR(64) NOT NULL DEFAULT '',
  tool VARCHAR(255) NOT NULL DEFAULT '',
  program VARCHAR(64) NOT NULL DEFAULT '',
  write_call INTEGER NOT NULL DEFAULT 0,
  input TEXT,
  result TEXT,
  outcome VARCHAR(16) NOT NULL DEFAULT '',
  code VARCHAR(64) NOT NULL DEFAULT '',
  duration_ms INTEGER NOT NULL DEFAULT 0,
  objects TEXT,
  diff_ref VARCHAR(255) NOT NULL DEFAULT '',
  diff TEXT,
  ref_seq INTEGER NOT NULL DEFAULT 0,
  prev_hash VARCHAR(64) NOT NULL DEFAULT '',
  hash VARCHAR(64) NOT NULL DEFAULT ''
);
CREATE UNIQUE INDEX tron_journal_step_seq ON tron_journal_step (session_id, seq);
CREATE INDEX tron_journal_step_tool ON tron_journal_step (tool);
CREATE INDEX tron_journal_step_created ON tron_journal_step (created);

CREATE TABLE tron_journal_object (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  session_id INTEGER NOT NULL DEFAULT 0,
  step_id INTEGER NOT NULL DEFAULT 0,
  kind VARCHAR(32) NOT NULL DEFAULT '',
  ref VARCHAR(255) NOT NULL DEFAULT '',
  action VARCHAR(16) NOT NULL DEFAULT '',
  created INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX tron_journal_object_ref ON tron_journal_object (kind, ref);
CREATE INDEX tron_journal_object_session ON tron_journal_object (session_id);
