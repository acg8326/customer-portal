# CW Global People — Customer Portal

Internal documentation for the Customer Portal (CWGP-AIMe). This folder
describes **what the application is right now** — its features, stack, and how
to run and operate it.

> Status as of 2026-07-13. Keep these docs updated as the app grows — every
> feature change updates `features.md` + `changelog.md` in the same commit.

## What this app is

A Laravel + Vue (Inertia) customer portal with **login-only authentication**
(no public registration), a **collapsible left sidebar**, and **AiMe BOT** — a
Claude-powered chat assistant with web access, connected tools (Slack, native
NetSuite, MCP), file exports, and claude.ai-style UX. Built on the Laravel Vue
starter kit, then extended and rebranded for CW Global People.

## Documentation index

| Doc                                      | What's in it                                                         |
| ---------------------------------------- | -------------------------------------------------------------------- |
| [getting-started.md](getting-started.md) | How to set up, run, and log in                                       |
| [features.md](features.md)               | Every feature currently in the app                                   |
| [prompts.md](prompts.md)                 | Every prompt AiMe is given, verbatim, and when each block is sent    |
| [security.md](security.md)               | Every security control, layer by layer, and how to tune it           |
| [performance.md](performance.md)         | Cost & performance levers — caching, routing, compaction, budgets    |
| [netsuite.md](netsuite.md)               | NetSuite setup (TBA + OAuth 2.0), role permissions, troubleshooting  |
| [composio-integrations.md](composio-integrations.md) | Composio integrations — API quirks, toolkit modes, add/debug playbook |
| [DEPLOYMENT.md](DEPLOYMENT.md)           | Production server setup + the one-command deploy                     |
| [tech-stack.md](tech-stack.md)           | Frameworks, libraries, and project layout                            |
| [changelog.md](changelog.md)             | What we've changed from the base starter kit                         |
| [roadmap.md](roadmap.md)                 | Parked features & future plans                                       |

## TL;DR — current state

- **Auth:** Login only (Fortify) + 2FA + passkeys. `super_admin`/`admin`/`user`
  roles; member management at `/users`.
- **Chat:** streaming Claude assistant — model picker, extended thinking,
  web search/fetch with sources, file/image uploads, PDF/Word/CSV/XLSX export,
  auto-titles, manual + auto compaction, retry/edit/feedback, per-user
  preferences.
- **Tools:** Slack/GitHub/HubSpot/Airtable (Composio), native NetSuite
  (TBA + OAuth 2.0), custom MCP servers, n8n/Zapier/Make webhooks — with a
  **hard approval gate** on destructive tool calls.
- **Database:** PostgreSQL (`cwgp_aime`).
- **Seeded logins:** `alex.gordo@cwglobalpeople.com` (super admin),
  `dennies.salenga@cwglobalpeople.com` (admin), `admin@example.com`
  (local-dev user) — all `password`, change after first login.

## Quick start

```bash
composer run dev      # runs server + queue + logs + vite together
```

Then open the app, and you'll land on the login page. See
[getting-started.md](getting-started.md) for full setup.
