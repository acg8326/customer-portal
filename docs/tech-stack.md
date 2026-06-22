# Tech stack & project layout

## Stack

| Layer | Technology | Version |
| --- | --- | --- |
| Backend framework | Laravel | 13.x |
| Language (backend) | PHP | 8.3+ |
| Auth | Laravel Fortify | — |
| Frontend framework | Vue | 3.5 |
| SPA bridge | Inertia.js (Vue 3 adapter) | 3.x |
| Language (frontend) | TypeScript | 5.x |
| Styling | Tailwind CSS | 4.x |
| UI components | shadcn-vue (Reka UI) | — |
| Icons | lucide (`@lucide/vue`) | — |
| Typed routes | Laravel Wayfinder | — |
| Build tool | Vite (Rolldown) | — |
| Database | MySQL / MariaDB | — |
| AI chat | Claude API (`anthropic-ai/sdk`) | 0.30.x |
| Tests | Pest | 4.x |

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
  Http/Controllers/       # incl. Settings/ (Profile, Security)
  Models/User.php         # the only domain model right now
  Providers/FortifyServiceProvider.php   # auth views, features
config/
  fortify.php             # enabled auth features + (disabled) limiters
database/
  migrations/             # users, cache, jobs, passkeys, 2FA columns
  seeders/DatabaseSeeder.php   # seeds admin@example.com
routes/
  web.php                 # /, /dashboard, /chat
  settings.php            # /settings/* (profile, security, appearance)
resources/js/
  pages/                  # Vue pages (Dashboard, Chat, auth/*, settings/*)
  layouts/                # AppLayout (header), AuthLayout (login scene)
  components/             # AppHeader, AppLogo, SonarBackground, ui/*
  routes/                 # Wayfinder-generated typed routes
docs/                     # you are here
```

## Layout system

- `resources/js/app.ts` maps page names to layouts:
  - `auth/*` → `AuthLayout` (the custom login scene)
  - `settings/*` → `AppLayout` + settings sub-layout
  - everything else → `AppLayout` (**top header** navigation)
- `AppLayout` currently uses the **header** layout
  ([`AppHeaderLayout.vue`](../resources/js/layouts/app/AppHeaderLayout.vue)).
  A sidebar layout also exists in the codebase but is not used.
