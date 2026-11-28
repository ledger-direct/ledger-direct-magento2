# Notes for coding agents

## Decisions (binding)

Cross-repo decisions live in the private repo https://github.com/ledger-direct/ledger-direct-decisions; this repo's own ones in
`docs/adr/`. Before planning or changing code, run `bin/decisions` (or read INDEX.md there) and read every
accepted ADR that applies to the files you touch. If the decisions repo is not accessible in this session, say
so at the start and rely on `docs/adr/` only; never guess cross-repo decisions.

- Accepted ADRs are binding. If a task conflicts with one, STOP: do not work around it. Write a superseding ADR
  with status `proposed`, explain the conflict, and ask Alexander.
- Only accepted ADRs bind. A `proposed` ADR is recorded, not decided, also when it is on `main`; if your task
  conflicts with one, say so, but it does not stop you.
- Agents never accept. Only Alexander does, and explicitly: with a line starting with `Accepts:` in the
  description of the PR he merges, or with `bin/accept`. A merge alone accepts nothing. Never write
  `status: accepted`, never run `bin/accept`, never write an `Accepts:` line into a PR description.
- When your work makes a lasting decision (see the decisions repo README, "What is an ADR"), add a `proposed` ADR
  in the same PR. Reference ADR ids in PR descriptions ("Follows LD-0007").
- Never edit an accepted ADR's content. Supersede it with a new ADR, or add to it with one that `refines` it.
