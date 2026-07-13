# Tech stack & project layout

## Stack

| Layer               | Technology                      | Version |
| ------------------- | ------------------------------- | ------- |
| Backend framework   | Laravel                         | 13.x    |
| Language (backend)  | PHP                             | 8.3+    |
| Auth                | Laravel Fortify                 | —       |
| Frontend framework  | Vue                             | 3.5     |
| SPA bridge          | Inertia.js (Vue 3 adapter)      | 3.x     |
| Language (frontend) | TypeScript                      | 5.x     |
| Styling             | Tailwind CSS                    | 4.x     |
| UI components       | shadcn-vue (Reka UI)            | —       |
| Icons               | lucide (`@lucide/vue`)          | —       |
| Typed routes        | Laravel Wayfinder               | —       |
| Build tool          | Vite (Rolldown)                 | —       |
| Database            | PostgreSQL (prod) — MySQL/MariaDB/SQLite also work | — |
| AI chat             | Claude API (`anthropic-ai/sdk`) | 0.30.x  |
| Tests               | Pest                            | 4.x     |

## How the pieces fit

- **Inertia** lets Laravel controllers render **Vue page components** directly —
  no separate API. Controllers return `Inertia::render('PageName', props)`.
- **Wayfinder** generates typed route helpers used in the frontend (e.g.
  `import { dashboard, chat } from '@/routes'`). These are regenerated on build.
- **Fortify** provides the auth backend (login, password reset, 2FA, passkeys).
  Views are wired to Inertia pages in
  [`FortifyServiceProvider`](../app/Providers/FortifyServiceProvider.php).

## Project layout (key folders)

```
app/
  Actions/Fortify/        # auth actions (create user, reset password)
  Console/Commands/       # chat:check-models, docs:pdf, …
  Http/Controllers/       # Chat, Dashboard, Project, User, Integration, Settings/, …
  Http/Middleware/        # SecurityHeaders, EnsureUserIsAdmin, …
  Jobs/ Services/         # auto-compaction, Composio/NetSuite, upload scanner, …
  Models/                 # User, Conversation, Message, Project, Skill,
                          # McpServer, ComposioConnection, NetsuiteConnection, …
config/
  services.php            # Claude/Composio/NetSuite tunables (.env-driven)
  security.php dashboard.php ratelimits.php fortify.php
database/
  migrations/             # users/roles, conversations, messages, projects, …
  seeders/DatabaseSeeder.php   # seeds the admin + super admin logins
routes/
  web.php                 # /, /dashboard, /chat, /projects, /integrations, /users
  settings.php            # /settings/* (profile, security, appearance, skills)
resources/js/
  pages/                  # Vue pages (Dashboard, Chat, Projects, Users, …)
  layouts/                # AppLayout (sidebar), AuthLayout (login scene)
  components/             # ChatPanel, ChatSidebar, AppSidebar, ui/*
  routes/                 # Wayfinder-generated typed routes
docs/                     # you are here
```

## Layout system

- `resources/js/app.ts` maps page names to layouts:
    - `auth/*` → `AuthLayout` (the custom login scene)
    - `settings/*` → `AppLayout` + settings sub-layout
    - everything else → `AppLayout` (**collapsible left sidebar** navigation)
- `AppLayout` uses the **sidebar** layout
  ([`AppSidebarLayout.vue`](../resources/js/layouts/app/AppSidebarLayout.vue));
  a header layout also exists in the codebase but is not used.
