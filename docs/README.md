# CW Global People — Customer Portal

Internal documentation for the Customer Portal. This folder describes **what
the application is right now** — its features, stack, and how to run it.

> Status as of 2026-06-20. Keep these docs updated as the app grows.

## What this app is

A Laravel + Vue (Inertia) customer portal with **login-only authentication**
(no public registration), a **top navigation bar**, and a **modern login
screen**. It's built on the Laravel Vue starter kit, then trimmed and rebranded
for CW Global People.

## Documentation index

| Doc                                      | What's in it                                                         |
| ---------------------------------------- | -------------------------------------------------------------------- |
| [getting-started.md](getting-started.md) | How to set up, run, and log in                                       |
| [features.md](features.md)               | Every feature currently in the app                                   |
| [tech-stack.md](tech-stack.md)           | Frameworks, libraries, and project layout                            |
| [changelog.md](changelog.md)             | What we've changed from the base starter kit                         |
| [roadmap.md](roadmap.md)                 | Parked features & future plans (chat persistence, memory, streaming) |

## TL;DR — current state

- **Auth:** Login only. Registration is **removed**. Backed by Laravel Fortify.
- **Entry point:** `/` redirects to the dashboard (if logged in) or the login page.
- **Login screen:** Custom "sonar" dark-glass design with an animated background.
- **Navigation:** Top header bar with **Dashboard** and **Chat**.
- **Pages:** Dashboard (placeholder), **Chat (working — Claude AI)**, Settings
  (profile, security, appearance).
- **Database:** MySQL / MariaDB (`customerportal`).
- **Login account (seeded):** `admin@example.com` / `password`.

## Quick start

```bash
composer run dev      # runs server + queue + logs + vite together
```

Then open the app, and you'll land on the login page. See
[getting-started.md](getting-started.md) for full setup.
