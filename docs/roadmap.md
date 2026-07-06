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

3. **File uploads** — ✅ _shipped for images + PDFs_ (2026-07-07)
    - Chat accepts **images + PDFs**, sent to Claude natively and re-sent each
      turn. Config under `services.anthropic.uploads`.
    - _Still to do:_ **Office formats** (DOCX/XLSX/CSV) via server-side text
      extraction (needs PHP parsing libraries) — central to the RMA-report
      workflow; and **project-level files** (a persistent per-project knowledge
      base rather than per-message attachments).

4. **Streaming responses**
    - Stream tokens from Claude to the browser (SSE) so replies appear
      progressively instead of all at once after a wait.
    - Backend: `client.messages.createStream(...)`; frontend reads the stream.

5. **Long-term / cross-conversation memory** _(advanced)_
    - Auto-updating memory the assistant maintains across chats (vs the editable
      project memory in step 2). Decide what's remembered and let the user
      view/clear it.

### Notes / decisions to revisit

- **Privacy:** messages are sent to Anthropic's API and now stored in our DB.
  Decide on a retention policy and surfacing history deletion to end users.
- **Memory style:** project memory will start as **editable notes** (chosen
  2026-06-23); auto-updating memory is the later, advanced step (5).
