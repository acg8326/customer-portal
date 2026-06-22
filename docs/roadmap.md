# Roadmap — parked features

Things we've intentionally deferred. Captured here so they're not forgotten.
Newest ideas at the top; move items into [changelog.md](changelog.md) once built.

## Toward Claude-style Projects

The goal is a Projects feature like Claude.ai: a named workspace with
**Instructions**, **Memory**, **Files**, and chats scoped to it.

1. ✅ **Save conversations to the database** *(done 2026-06-23)*
   - `conversations` + `messages` tables, per-user history, sidebar, resume on
     reload. This is the foundation Projects build on. See [features.md](features.md) §7.

2. **Projects** *(next)*
   - `projects` table (name, **instructions**, **memory** as editable notes).
   - Conversations optionally belong to a project; the project's instructions +
     memory are injected into the system prompt for every chat in it.
   - Projects list + detail UI (instructions/memory panels, chats list).

3. **Project files**
   - Upload + store files, **extract text** from PDF/DOCX/XLSX (needs PHP parsing
     libraries), and feed it to Claude as context. Central to the RMA-report
     workflow.

4. **Streaming responses**
   - Stream tokens from Claude to the browser (SSE) so replies appear
     progressively instead of all at once after a wait.
   - Backend: `client.messages.createStream(...)`; frontend reads the stream.

5. **Long-term / cross-conversation memory** *(advanced)*
   - Auto-updating memory the assistant maintains across chats (vs the editable
     project memory in step 2). Decide what's remembered and let the user
     view/clear it.

### Notes / decisions to revisit
- **Privacy:** messages are sent to Anthropic's API and now stored in our DB.
  Decide on a retention policy and surfacing history deletion to end users.
- **Memory style:** project memory will start as **editable notes** (chosen
  2026-06-23); auto-updating memory is the later, advanced step (5).
