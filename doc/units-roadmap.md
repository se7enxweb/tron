# Units roadmap

The units ([units.md](units.md)) are built in waves. Each wave adds one kind of unit and keeps every rule of the waves
before it: one job per unit, typed input and output, the calling user's policies, an audit entry per call, redaction on
the way out. The goal is that an agent, through expai or directly through tron, can design and change a whole site at
the level of features ("a landing page with a header, three teasers and a newsletter box") by composing units, while
every step stays small enough to check and to undo.

## Wave A: read units (done)

Twenty read units in six areas: INI (resolve, diff, list), templates (resolve, lint, source), nodes (read, tree, find,
versions), classes (describe, list, diff), Exponential Layouts (layouts, rules, which layout a page gets, block types)
and roles (describe, find users). The type catalog, `tron_pipe` (read only, at most 8 steps and 100 calls) and the
resource `tron://units`. Nothing in wave A changes the installation.

## Wave B: write units with a dry run

Units that change one thing each, every one with a dry run (`tron_plan`, then `tron_apply` with the plan id), so an
agent first sees the exact change and a person can approve it:

- settings: set or reset one setting in one chosen file of the chain (the file is part of the plan);
- templates: write an override template inside a design directory, add or reorder an override rule;
- nodes: create, update attributes, move, hide or reveal, add a location; never remove in this wave;
- classes: add an attribute, change an attribute's settings, create a class from a class value (as class.describe
  gives it);
- layouts: create a draft, add or move a block, link a zone to a shared layout, publish a draft, add a rule;
- roles: assign or unassign a role with a limitation.

A write unit's output type is a `change` (what was planned or applied, with before and after); its input types are
the same refs the read units use, so a read pipe can select what a write unit then changes. `tron_pipe` will run write
units only as a plan (every step a dry run) until each step is applied.

## Wave C: data movers

Units that move data between installations or formats without a direct connection: export a class, a subtree or a
layout with its zones and blocks as a typed value, compare it with the target (the diff units), and import it as a
planned change. The class value of class.describe already works as an exchange format for class.diff.

## Wave D: recipes

Recipes are named, parameterised pipes for a use case (design patterns by use case): "landing page with layout and
teasers", "news section with list and full view override", "member area with role and section". A recipe declares its
parameters and the units it uses, runs as a plan first, and records what it built so it can be checked and rolled back.
Extensions will be able to ship recipes and units in their program manifests, the same way tron's programs do.

## Wave E: planning from expai

expai's assistant plans a site change as recipes and pipes, shows the plan (with the dry runs) to the editor, and applies
it step by step after approval. The policies of the editor decide what the plan may contain; the audit and the activity
journal tell the story afterwards.

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
