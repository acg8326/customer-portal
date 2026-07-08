# CWGP-AIMe vs. per-seat SaaS (e.g. toppfive.net)

Why building **AiMe** in-house beats renting a per-seat AI SaaS like
toppfive.net ($250 / $500 / $1000 **per user, per month**).

## 1. Pricing model — the big one

toppfive charges **per user, per month**. AiMe is an **internal application**:
you deploy it once and **every employee uses it — there is no per-seat fee**.
You pay only for what Claude actually processes (Anthropic API tokens) plus
ordinary hosting.

**What that means at scale** (toppfive's own list prices):

| Team size | toppfive Pro ($250) | toppfive Business ($500) | toppfive Enterprise ($1000) | AiMe (internal) |
| --------- | ------------------- | ------------------------ | --------------------------- | --------------- |
| 5 users   | $1,250 / mo         | $2,500 / mo              | $5,000 / mo                 | API usage only  |
| 20 users  | $5,000 / mo         | $10,000 / mo             | $20,000 / mo                | API usage only  |
| 50 users  | $12,500 / mo        | $25,000 / mo             | $50,000 / mo                | API usage only  |

AiMe's cost **doesn't grow with headcount** — adding the 6th or 60th user costs
nothing extra. Their bill is a **recurring rental that rises with every hire**;
ours is **pay-for-actual-usage**, and the app is an asset you own. AiMe already
enforces a **per-user token budget** (default 1M/period, configurable) and
**rate limits**, so usage — and spend — stays predictable and controllable.

## 2. You own it — and can change anything

toppfive is a closed product: you get the features they ship, on their timeline.
AiMe is **your codebase**. If the business needs a new capability — a bespoke
RMA workflow, a NetSuite sync, a custom report — **we just build it**. No waiting
for a vendor roadmap, no feature request queue, no "Enterprise-tier only" gate.

- Custom features and UI on demand.
- Custom integrations (see below).
- No vendor lock-in; the data and code are yours.

## 3. Native tool use via MCP — connect the tools you actually run

AiMe supports the **Model Context Protocol (MCP)**: connect a server (Slack,
GitHub, Notion, …) and the assistant **calls that tool's functions natively**,
streaming, with a live "Using &lt;tool&gt;…" indicator. For tools without MCP,
the **n8n** connector bridges to virtually anything via webhooks/workflows.
That's an **open, extensible** integration story rather than a fixed connector
list.

## 4. Your data stays internal

AiMe runs on **your** infrastructure. The Anthropic API key lives server-side
and **never reaches the browser**; conversations are stored in **your** database,
scoped per user. Integration secrets (n8n, MCP tokens) are **encrypted at rest**,
and outbound webhook/MCP URLs are **SSRF-guarded**. With a SaaS, your prompts and
business data flow through **their** platform under **their** policies.

## 5. Feature comparison

toppfive's advertised features, and where AiMe stands today:

| toppfive feature                         | AiMe status |
| ---------------------------------------- | ----------- |
| AI workspace for any business question   | ✅ Chat + Projects (scoped workspaces) |
| Document processing (PDF / CSV / Excel)  | ⚙️ Images + **PDF** today; DOCX/CSV/Excel text-extraction on the roadmap |
| Data syncing across connected integrations | ✅ via **MCP** (native tools) + **n8n** |
| Email monitoring & inbox triage          | 🔜 Roadmap (buildable via n8n/MCP) |
| Scheduled workflows & recurring analysis | ✅ via **n8n** (webhooks/schedules) |
| Business intelligence, reports & charts  | 🔜 Roadmap |
| Higher query capacity / priority         | ✅ You control limits & model choice (no artificial tiers) |
| Morning briefings & daily summaries      | 🔜 Roadmap (buildable via n8n schedules) |
| SMS alerts for critical events           | 🔜 Roadmap (buildable via n8n/MCP) |
| Team member access                       | ✅ Multi-user, per-user scoping |
| Custom workflow automation               | ✅ n8n + MCP + **direct code changes** |
| Dedicated support & onboarding           | ✅ It's your team's own app |
| SLA guarantees                           | ✅ Set by your own ops, not a vendor contract |

**Honest take:** toppfive ships some polished features AiMe hasn't built **yet**
(BI dashboards, inbox triage, SMS, Office-doc extraction). The difference is that
with AiMe those are a **backlog we control**, not a paywall — and several already
have a clear path through n8n/MCP. Meanwhile AiMe already delivers the core
(streaming chat, projects, uploads, native tools, automation, usage governance)
with **no per-seat cost and full ownership**.

## Bottom line

- **Cost:** their bill scales with every hire; ours scales with actual usage and
  never charges per seat.
- **Control:** we own the code and can add any feature or integration.
- **Privacy:** data and keys stay on our infrastructure.
- **Capability:** streaming AI chat with native MCP tools and n8n automation,
  today — with a roadmap we own for the rest.
