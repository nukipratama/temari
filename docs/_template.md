---
title: <human title>
description: <one line, used for retrieval + MOC listing>
tags: [architecture|feature|decision, <domain>]
status: living            # features/architecture track code; ADRs use: accepted | superseded
reviewed: 2026-06-20      # date last verified against the code it cites
code_refs:                # files this note describes — drift awareness + CI citation guard
  - app/Services/Example/Example.php
# superseded_by: <note>   # ADRs only, set when a newer ADR replaces this one
---

# <Title>

> Template for `docs/` notes. Copy this file, fill the frontmatter, delete this blockquote.

**Writing rules**

- Narrate for a human; **cite code by `path:line`, never transcribe it** — code is the source of truth, the note explains and points.
- Connect related notes with `[[wikilinks]]`.
- **Generate-or-link volatile lists** (route tables, enum values) — never hand-copy them, they rot.
- Link out to the `teman-lari` skill / `memory` for conventions rather than restating them.
- Be the **agent's efficient first stop**: concise and citation-anchored, so a reader jumps to one cited line instead of broad-exploring the code.
- Keep it **small and modular** — big docs never stay current.
