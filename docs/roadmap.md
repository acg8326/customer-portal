# Roadmap — parked features

Things we've intentionally deferred. Captured here so they're not forgotten.
Newest ideas at the top; move items into [changelog.md](changelog.md) once built.

## Toward Claude-style Projects

The goal is a Projects feature like Claude.ai: a named workspace with
**Instructions**, **Memory**, **Files**, and chats scoped to it.

1. ✅ **Save conversations to the database** _(done 2026-06-23)_
    - `conversations` + `messages` tables, per-user history, sidebar, resume on
      reload. This is the foundation Projects build on. See [features.md](features.md) §7.

2. ✅ **Projects** _(done 2026-06-23)_
    - `projects` table (name, **instructions**, **memory** as editable notes).
    - Conversations belong to a project; the project's instructions + memory are
      injected into the system prompt for every chat in it. Projects list +
      workspace UI with a settings dialog. See [features.md](features.md) §6.

3. **File uploads** — ✅ _shipped: images + PDFs (2026-07-07), Office formats
   DOCX/XLSX/CSV/TXT/MD via dependency-free server-side text extraction
   (2026-07-13)_
    - _Still to do:_ **project-level files** (a persistent per-project
      knowledge base rather than per-message attachments).

4. ✅ **Streaming responses** _(shipped)_
    - Replies stream token-by-token over SSE (`POST /chat/stream`), including
      extended-thinking deltas and web citations. See
      [features.md](features.md) §7.
    - _Remaining edge:_ connected-tools turns (Composio/NetSuite loop) deliver
      the final answer as one block after the tools finish.

5. ✅ **Long-term / cross-conversation memory** _(shipped 2026-07-13)_
    - Automatic memory distilled from chats every N messages (Haiku), injected
      as `## Memory`, fully user-visible/editable/erasable in Settings →
      Profile with a per-user off switch. See [features.md](features.md) §7.

### Notes / decisions to revisit

- **Privacy:** messages are sent to Anthropic's API and now stored in our DB.
  Decide on a retention policy and surfacing history deletion to end users.
- **Memory style:** project memory will start as **editable notes** (chosen
  2026-06-23); auto-updating memory is the later, advanced step (5).
