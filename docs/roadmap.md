# Roadmap — parked features

Things we've intentionally deferred. Captured here so they're not forgotten.
Newest ideas at the top; move items into [changelog.md](changelog.md) once built.

## Chat — persistence & memory (parked 2026-06-20)

Today the chat is **stateless**: the conversation lives only in the page, is sent
to Claude in full each turn, and is **not stored anywhere**. A refresh wipes it,
and there's no memory across sessions. See [features.md](features.md) §7.

Planned, in suggested build order:

1. **Save conversations to the database** *(foundational)*
   - New tables: `conversations` (id, user_id, title, timestamps) and `messages`
     (id, conversation_id, role, content, model, timestamps).
   - Persist each user/assistant turn; load past chats on page open.
   - A conversation list/sidebar so users can switch between and resume chats.
   - Title conversations automatically (e.g. from the first user message).

2. **Streaming responses**
   - Stream tokens from Claude to the browser (SSE) so replies appear
     progressively instead of all at once after a wait.
   - Backend: `client.messages.createStream(...)`; frontend reads the stream.

3. **Long-term / cross-conversation memory**
   - Let the assistant recall facts from earlier conversations (e.g. a per-user
     "memory" store summarized into the system prompt, or retrieval over past
     messages).
   - Decide what's remembered and give the user control to view/clear it.

### Notes / decisions to revisit
- **Privacy:** messages are sent to Anthropic's API. Before persisting, decide on
  a retention policy and whether to let users delete their history.
- **Model per conversation:** we may want to store which model was used per
  message (the `messages.model` column above already allows this).
