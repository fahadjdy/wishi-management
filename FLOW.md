# WISHI — System Flow & Rules

> **Live specification document.** Every time the business logic, data model, or user-facing flow changes, update this file in the same commit. Read top-to-bottom to understand how the platform works.

---

## 1. What WISHI is

A digital chit-fund / committee / society platform. A **platform admin** creates a WISHI (pool), **members** join, contribute monthly, and one member (or a group, in tender mode) wins each cycle's pool.

**Stack:** Laravel 12 (PHP 8.2) · Vue 3 + Pinia + Vue Router · Tailwind v4 · MySQL · Sanctum cookie SPA auth · AMCharts 5 · Laravel queues + scheduler.

---

## 2. Roles

| Role | What they can do |
|---|---|
| **Platform Admin** (`users.is_admin = true`) | Create WISHIs, **create member accounts and assign credentials**, **invite members to WISHIs**, manage all platform users (lock / unlock / promote / adjust credit), view analytics dashboard. When they create a WISHI they also become that WISHI's admin. **Admins are never members** — no contributions, no bidding, no wins (except cycle #1 "organizer payout"). |
| **Member** (`users.is_admin = false`) | See their own WISHIs, accept/decline admin invitations, place tender bids (immutable), view their own contribution and credit score, change their password. **Cannot**: create WISHIs, self-register, see other members, see contribution lists, see bid amounts of others during open window, record their own payments. |

**Key rules:**
- Creator of a WISHI and its members are disjoint sets. Admins manage; members participate.
- **Self-registration is disabled.** Only platform admins create accounts (via Admin → Members → "+ Create member account"). The generated email + password must be shared with the member out-of-band.
- **Members cannot record their own payments.** Only the WISHI admin clicks "Mark paid" once the cash has actually been received — keeps the audit trail authoritative.

---

## 3. WISHI lifecycle

```
admin creates ──► [planned]  ──► (all seats filled + start_date reached) ──► admin clicks Start  ──►  [active]  ──► [completed]
                     │
                     └─► admin cancels ──► [cancelled]

[draft] is a manual-only state — admin can move a WISHI back to draft to pause discovery; never the default on create.
```

| Status | Visibility | Rules |
|---|---|---|
| `draft` | Admin-only | Manually paused/archived by the admin. Invisible to other users. (Not the default on creation.) |
| `planned` | Public (discoverable) | **Default on `WishiService::create`.** Visible in every member's Discover list. Members can request/join based on `require_approval`. `start_date` is preserved — activation blocked until that date is reached. |
| `active` | Joined members + admin | Cycles running. Can't switch back. |
| `completed` | Joined members + admin | All cycles paid out. Deferred tender amounts auto-released here. |
| `cancelled` | Joined members + admin | Pre-activation termination. No cycles created. |

Member-facing UIs only show `planned`, `active`, `completed`, `cancelled` — and among the last three, only WISHIs the member actually joined. `draft` never leaks.

### Creation broadcast
- New WISHIs are created with `status='planned'` directly — no extra publish step is required.
- On `WishiService::create`, every non-admin, non-creator platform user receives `WishiCreatedNotification` and the WISHI immediately surfaces on their Discover list.
- `WishiService::publish` still exists for the rare manual draft → planned re-publish (e.g., after admin paused a WISHI back to draft) and re-broadcasts the notification when used.

### Activation rules
- WISHI can only be started when `active_members_count == total_members`.
- `WishiService::activate` accepts either `draft` or `planned`, so admins who skipped publish can still activate (the WISHI was just never discoverable).
- **WISHI cannot open before its planned `start_date`.** `WishiService::activate` throws `DomainException` if `start_date` is in the future. On the admin's dashboard, every upcoming opening (≤ 5 days away, or past-due drafts) shows a prominent countdown card (`GET /dashboard` → `upcoming_wishi_openings`).
- On activation:
  1. `status = active`
  2. `start_date` is kept as the planned date (if it equals today) or stamped to today if activation happened late.
  3. Cycle #1 is created (organizer payout, see §6)
  4. All approved members receive a **WishiStartedNotification** with cycle #1 due date.
- Admin receives **WishiFullNotification** the moment the last member is approved (idempotent — won't spam).
- When a WISHI is **created**, every non-admin, non-creator user on the platform receives **WishiCreatedNotification** so they can discover and request to join.

---

## 4. Member lifecycle inside a WISHI

Two entry paths:

```
A) Admin invites (preferred):
   admin clicks "+ Invite member" ──► [pending, invited_by_admin=true]
       ├── member clicks "Join now"   ──► [approved] / [active]
       └── member clicks "Decline"    ──► (soft-deleted, admin can re-invite)

B) Member requests join (when admin enables it):
   member posts /join ──► [pending, invited_by_admin=false] ──► admin approves ──► [approved]
```

Invitation is the default UX. Admin-created accounts typically come with invitations already queued; the member just sees "Join now / Decline" on their dashboard after logging in.

Guards enforced in `MembershipService`:
- Admin cannot join their own WISHI (neither direction).
- WISHI status must be `draft` or `active`.
- Min credit score (if set on WISHI) must be met for `requestJoin`.
- `max_active_wishis_per_member` cap (if set) must not be exceeded.
- If WISHI has `block_if_missed_payments = true`, user's past missed contributions anywhere on the platform block them (for `requestJoin`).
- Capacity check (can't exceed `total_members`).
- `invite` skips credit/cap/missed-payment checks (admin has explicitly vetted the member by clicking Invite).

Once accepted/approved, member gets a `MemberStatusNotification` in their inbox. The invitation carries a `MemberStatusNotification(status='invited')` push when sent. In parallel, the WISHI admin (creator) receives a **MemberJoinedNotification** whenever:
- a user posts a new join request (`event='requested'`),
- a user accepts an admin invitation (`event='accepted_invite'`),
- the admin approves a pending member (`event='joined'`).

### Token numbers

Every approved member is assigned a sequential `token_no` (1..n) on the `wishi_members` row, unique per WISHI. Assignment happens once — when the member first transitions to `approved` (via `requestJoin` auto-approve, `acceptInvite`, or admin `approve`). Tokens are stable: removal does **not** renumber remaining members, so gaps are allowed. The admin (creator) is never stored as a `wishi_members` row, so they hold no token. Surfaced in `WishiMemberResource` and the Members tab. `MembershipService::assignTokenIfMissing()` locks the WISHI row while computing `max(token_no)+1` to make concurrent approvals safe.

### Admin-created accounts

`POST /api/v1/admin/users` (platform admin only) — accepts `name`, `email`, `phone?`, `password`, `credit_score?`, `is_admin?`. Password is hashed via `User::$casts`. Email verified immediately.

The admin UI (`/admin/users`) has a **"+ Create member account"** button that opens a modal with auto-generated passwords so the admin can paste the credentials to the member over WhatsApp/SMS/etc.

---

## 5. Cycle data model (per WISHI)

```
cycles
  id, wishi_id, cycle_number, mode (random|tender), status,
  total_pool, winner_id, winning_bid, payout_amount,
  surplus, surplus_action, deferred_amount, deferred_released_at, deferred_payout_id,
  admin_topup_amount, admin_topup_by_user_id, winners_count,
  selection_method (auto_random|auto_tender|manual|organizer_payout),
  selection_seed, selected_at, paid_out_at,
  contribution_due_at, tender_opens_at, tender_closes_at
```

### Status flow of a cycle

```
contribution_open  ──► (all members paid) ──►  selection_pending ──► admin records payout ──► completed
                              └── (if mode=tender) ──►  bidding_open  ──► window closes ──► selection_pending
```

---

## 6. Cycle #1 rule — Organizer Payout

**Every WISHI's cycle #1 is the admin's cycle.**

- Mode is forced to `random` (no bidding in cycle #1, regardless of WISHI `cycle_type`).
- `winner_id = wishi.created_by` is pre-set at cycle creation.
- `selection_method = 'organizer_payout'`.
- Members still contribute. Admin receives the full pool.
- `CyclePolicy::selectWinner` denies any attempt to re-select cycle #1.
- `CyclePolicy::placeBid` denies bidding on cycle #1.
- UI: 👑 "Organizer Payout" badge, winner shown as admin, no "Select winner" or "Place bid" buttons.
- **Members tab** prepends the admin as a virtual "Member #1 (👑 Organizer)" row (flag `is_organizer_virtual=true` on the API payload) — admin appears first in the list even though they are still not stored in `wishi_members`. Real member tokens remain 1..n as assigned to approved members.

This is why a WISHI with `total_members = 6` and `duration_months = 6` ends up with admin winning one cycle and 5 members winning the remaining 5. If the admin wants every member to get a turn, they should set `duration_months = total_members + 1`.

---

## 7. Cycle types

| Type | How cycles run |
|---|---|
| `random` | Every cycle uses a cryptographic random draw (`random_bytes`) among members who haven't won yet. Seed stored for transparency. |
| `tender` | Every cycle opens a bidding window (`tender_opens_at` → `tender_closes_at`). Admin picks single or multiple winners. |
| `hybrid` | Each cycle's mode is taken from `hybrid_pattern` — see §8. |

Cycle #1 is always `random` (see §6), regardless of WISHI type.

---

## 8. Hybrid pattern semantics

Pattern is an ordered array of `'random'` / `'tender'` steps. It **repeats** across the full duration using modulo indexing: `mode = pattern[(cycle_number - 1) % length(pattern)]`.

**Example** — 9-month WISHI with pattern `['random', 'random', 'tender']`:

| Cycle | Index `(n-1) % 3` | Mode |
|---|---|---|
| 1 | 0 | **organizer payout** (admin wins, mode=random) |
| 2 | 1 | random |
| 3 | 2 | tender |
| 4 | 0 | random |
| 5 | 1 | random |
| 6 | 2 | tender |
| 7 | 0 | random |
| 8 | 1 | random |
| 9 | 2 | tender |

So "first 2 random, then 1 tender" repeats three times across 9 months → 3 tenders, 6 randoms (incl. cycle #1 organizer). The Create form shows a live grid preview of exactly this expansion before the admin confirms.

---

## 9. Contribution flow (per cycle)

1. Cycle opens → `contribution_open`. A `contributions` row is created for every approved/active member. Admin is **not** in this list.
2. Payments are always recorded **by the admin** via `POST /contributions` with the target `user_id`. Members cannot self-report payments — they pay the admin out-of-band (cash/UPI/bank transfer) and the admin marks it received.
3. **Due-date rule:** A cycle's `contribution_due_at` equals the cycle's own start date (no extra grace is added). For monthly cycles this means the due date is exactly one month after the previous cycle — never more than 30 days out. This is enforced in `CycleService::createNextCycle`.
4. Payment validation in `ContributionService::recordPayment`:
   - If paid ≤ 2 days before due → `early_payment` (+15 credit)
   - If paid on/before due → `on_time_payment` (+10)
   - If paid after due → `late_payment` (-5) and status flagged `late`
5. On admin marking a contribution paid, the paying member is notified via `PaymentApprovedNotification` (database channel) — "Your ₹X payment for cycle #N has been approved."
6. When every contribution has `paid_at` set, cycle auto-advances:
   - If `mode = tender` → `bidding_open`
   - Else → `selection_pending`
7. Scheduler command `wishi:check-payments` runs daily → marks unpaid past-due contributions as `late` and applies -5 credit.
8. `wishi:mark-missed` (after 14-day grace by default) marks them `missed` and applies -20 credit.

**Canonical paid indicator:** `paid_at IS NOT NULL` (NOT `status`, because `status='late'` is ambiguous — could mean "unpaid overdue" or "paid after due").

---

## 10. Winner selection

### Maturity guard (all modes)

Winner announcement is **locked until the cycle's `contribution_due_at` is reached**. `WinnerSelectionService::ensureCycleMature` is called at the start of `selectRandomWinner`, `selectTenderWinner`, `selectTenderMultiWinner`, and `manualSelectWinner` — any attempt before the due date throws `DomainException("Winner can only be announced on or after {date}")`. The CycleDetail UI mirrors this: the "Select winner" button is replaced by a countdown card ("⏳ Winner announcement locked — available on <date> (N days left)") until the cycle matures. Cycle #1 is exempt because its winner is pre-assigned at creation.

### Random mode
`WinnerSelectionService::selectRandomWinner` — picks uniformly from eligible members (active, not yet won). Uses `random_bytes(32)` for the seed, stores the seed on the cycle so the draw is verifiable.

### Tender bidding window (all tender cycles)

Bidding is a **single-day** window, opening and closing on the cycle's own start date:

- **Opens** at `wishi.tender_start_time` (default `06:00`) on the cycle's start date.
- **Closes** at `wishi.tender_end_time` (default `20:00`) on the same date.
- Both times are configured once on the WISHI and reused for every tender cycle.

Example: WISHI starts 1-Jan-2026 (cycle #1 is the organizer payout). Cycle #2 starts 1-Feb-2026 — bidding opens at 06:00 on 1-Feb-2026 and closes at 20:00 on 1-Feb-2026. After close, no new bids are accepted; the admin can declare the winner (either auto-lowest via `selectTenderWinner` or manual/multi via `selectTenderMultiWinner`) but only after the `contribution_due_at` maturity guard allows it (see §10 maturity guard).

### Tender mode — single winner
`WinnerSelectionService::selectTenderWinner` — picks the lowest bid, stores surplus as `deferred_amount`.

### Tender mode — multiple winners (admin-driven)
`WinnerSelectionService::selectTenderMultiWinner(cycle, tenderIds, actor, reason)`.

Admin picks any subset of the submitted bids. System calculates:

```
total_bids = sum(selected bid amounts)
if total_bids  > pool:   admin_topup_amount = total_bids - pool   (admin personally covers shortfall)
if total_bids == pool:   no topup, no deferred
if total_bids  < pool:   deferred_amount = pool - total_bids      (split equally, released at WISHI end)
```

Every selected bidder has their `WishiMember.has_won = true` (they can't win again this WISHI, can't bid in later tender cycles). `cycle.winners_count` records how many winners this cycle had.

**Worked example A** — pool ₹25,000; bids A=₹10k, B=₹15k. Admin picks both. Total ₹25,000 == pool → zero topup, zero deferred, both members open this cycle.

**Worked example B** — pool ₹25,000; bids A=₹10k, B=₹7k, C=₹10k. Admin picks all three. Total ₹27,000 > pool → `admin_topup_amount = ₹2,000`. All three open this cycle, cycle detail shows "Admin top-up: +₹2,000".

### Manual override
`WinnerSelectionService::manualSelectWinner(cycle, userId, actor, reason)` — admin names a specific eligible member as the winner. Logged as `selection_method = 'manual'`.

### Tender bid rules
- Members place bids via `POST /tenders` during `tender_opens_at → tender_closes_at` window.
- **Bids are immutable.** `TenderService::placeBid` throws if user already has a bid for this cycle.
- Row-locked lookup prevents race conditions from two rapid POSTs.
- While window is open, non-admin members see only a count of bids (not names or amounts). Admin sees the full sorted list.

---

## 11. Deferred payouts (tender-only)

For tender cycles where `bid_amount < pool`, the difference is held as `deferred_amount`. The winner already received their bid at cycle payout. The deferred remainder is released **automatically** when the WISHI completes (i.e., the last cycle is paid out).

Release logic lives in `DeferredPayoutService::releaseAll(Wishi)` and is triggered from `PayoutService::record` when `DeferredPayoutService::shouldRelease` returns true. Each release creates a distinct `Payout` row (reference prefixed `DEFERRED-`) and stamps `deferred_released_at` on the cycle. Idempotent — re-running won't double-pay.

---

## 12. Credit score system

- Score range: 0–100 (clamped).
- Default on signup: 70 (`good`).
- Every score change is logged in `credit_score_logs` with before/after values and the triggering action.
- Trust levels recomputed on every update:
  - 90–100 → `excellent`
  - 70–89 → `good`
  - 50–69 → `average`
  - 0–49 → `risky`
- Platform admin can make `manual_adjust` adjustments (±100) with a required reason.

---

## 13. Notifications

Stored in Laravel's `notifications` table; served via `GET /api/v1/notifications`.

| Notification | Trigger |
|---|---|
| `MemberStatusNotification` | Member approved / rejected / removed |
| `WishiFullNotification` | Last member joins; creator receives this |
| `WishiStartedNotification` | Admin activates; every member receives this |
| `PaymentReminderNotification` | `wishi:payment-reminders` scheduler, 3 days before due |
| `TenderWindowNotification` | `wishi:tender-reminders` scheduler, open + close alerts |
| `WinnerAnnouncedNotification` | Queued hook after winner selected (present but currently not auto-fired) |

---

## 13a. Privacy — what members can and cannot see

Members have **minimal visibility by design**. Almost everything in a WISHI is admin-only.

| Item | Visible to member? |
|---|---|
| WISHI list they're part of | ✅ |
| Their own contribution (status, amount, due date) | ✅ |
| Their own tender bid (locked after first submission) | ✅ |
| Winner name for any cycle | ✅ |
| Cycle status, pool size, dates | ✅ |
| Pending admin invitations (on dashboard) | ✅ |
| Password change (their own) | ✅ |
| **Other members' details (names, phones, scores)** | ❌ |
| **Contributions list (who paid, who didn't)** | ❌ |
| **Bid amounts of other bidders while tender window is open** | ❌ |
| **Admin topup amount on cycles** | ❌ |
| **Surplus action, selection seed, deferred pool totals** | ❌ |
| **Members tab, Settings tab, Audit log tab** | ❌ (tabs hidden entirely) |
| **Record / mark-paid any contribution** | ❌ (admin only) |

Enforcement happens at three levels:
1. **API Resources** (`CycleResource`, `WishiResource`, `UserSummaryResource`) use `$this->when($isAdmin, ...)` to strip admin-only fields before serialization.
2. **Controllers** scope queries by viewer (e.g. `ContributionController::index` only returns the viewer's own row when not admin; `MemberController::index` returns only their own membership).
3. **Frontend Router + Tabs** hide UI affordances members shouldn't see (`Members`, `Settings`, `Audit` tabs are only added to the tab list when `isAdmin` is true; a `requiresWishiAdmin` route meta guards direct URL access).

All three are needed — UI hiding is courtesy; the API must never leak admin-only fields regardless of UI state.

## 14. Security baseline

- **Sanctum SPA cookies** (HttpOnly, SameSite=Lax, Secure in prod).
- **Strong password policy**: min 10 chars, mixed case, number, symbol, breach-checked via `Password::uncompromised()`.
- **Bcrypt rounds = 12**.
- **Login throttling**: 5 attempts / 15 min per email+IP combo, plus 10-fail lockout for 30 min.
- **CSRF** protection via stateful Sanctum. Frontend uses `withXSRFToken: true` + primes `/sanctum/csrf-cookie` before every write.
- **Security headers middleware**: `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, strict CSP in prod, HSTS in prod.
- **Policies on every model** — `WishiPolicy`, `CyclePolicy`, `WishiMemberPolicy`, `AuditLogPolicy`. Every controller either calls `$this->authorize(...)` or uses `Gate::authorize(...)` inside the Form Request.
- **Rate limits**: `auth` 5/min, `sensitive` 15/min (60 in local), `bid` 20/min, default API 60/min.
- **Form Requests** for all writes — no `request()->all()` mass-assignments.
- **Fillable whitelist** on every model. Sensitive fields (`is_admin`, `locked_until`, `failed_login_attempts`, etc.) set via direct attribute assignment only, never user-controlled payloads.
- **Audit log** (`audit_logs`) records every admin action with actor, IP, user agent, and structured metadata.
- **Soft deletes** on `wishis` and `wishi_members` (never hard-delete financial records).

---

## 15. Routing overview

### Web
- `GET /*` → `app.blade.php` (Vue SPA mounts; `api|sanctum|storage|up` are excluded).

### API (`/api/v1` prefix, Sanctum-authenticated except `/login`)

| Method | Path | Purpose |
|---|---|---|
| POST | `/login` | Issue session cookie |
| POST | `/logout` | Destroy session |
| GET | `/me` | Current user |
| PUT | `/me/password` | Change own password (only self-service edit) |
| GET | `/dashboard` | Member-side stats + upcoming payments |
| GET | `/me/credit-score` | Own score + 50 most recent logs |
| GET | `/notifications` | Inbox |
| PUT | `/notifications/{id}/read` | Mark one read |
| PUT | `/notifications/read-all` | Mark all read |
| GET/POST | `/wishis` | List / create (create is admin-only) |
| GET/PUT | `/wishis/{uuid}` | Show / update settings |
| POST | `/wishis/{uuid}/activate` | Start WISHI (when full) |
| POST | `/wishis/{uuid}/join` | Request to join (member-initiated) |
| POST | `/wishis/{uuid}/invite` | Admin invites an existing user |
| POST | `/wishis/{uuid}/accept-invite` | Member accepts admin invitation |
| POST | `/wishis/{uuid}/decline-invite` | Member declines admin invitation |
| GET | `/wishis/{uuid}/members` | Member roster |
| PUT | `/wishis/{uuid}/members/{m}/approve` | Approve join |
| PUT | `/wishis/{uuid}/members/{m}/reject` | Reject join |
| DELETE | `/wishis/{uuid}/members/{m}` | Remove member |
| GET | `/wishis/{uuid}/cycles` | Cycles (DESC) |
| POST | `/wishis/{uuid}/cycles/next` | Open next cycle |
| GET | `/wishis/{uuid}/cycles/{c}` | Cycle detail |
| PUT | `/wishis/{uuid}/cycles/{c}/select-winner` | Pick single winner |
| PUT | `/wishis/{uuid}/cycles/{c}/select-multi-winners` | Pick multiple tender winners (with optional topup) |
| PUT | `/wishis/{uuid}/cycles/{c}/surplus` | Handle surplus (non-tender only) |
| PUT | `/wishis/{uuid}/cycles/{c}/payout` | Record cycle payout(s) |
| GET/POST | `/wishis/{uuid}/cycles/{c}/contributions` | List / record |
| GET/POST | `/wishis/{uuid}/cycles/{c}/tenders` | List bids / place bid (immutable) |
| GET | `/wishis/{uuid}/audit-logs` | Wishi-scoped audit |
| GET | `/admin/dashboard` | Platform analytics (platform admin only) |
| GET | `/admin/users` | All users, search + filter |
| POST | `/admin/users` | **Create a new account (replaces self-registration)** |
| GET | `/admin/users/{id}` | User detail |
| PUT | `/admin/users/{id}/toggle-admin` | Grant/revoke platform admin |
| PUT | `/admin/users/{id}/lock` | Lock account |
| PUT | `/admin/users/{id}/unlock` | Unlock |
| DELETE/POST | `/admin/users/{id}` + `/restore` | Soft-delete / restore |
| PUT | `/admin/users/{id}/credit-score` | Manual credit adjustment |

All write routes are throttled (`sensitive` / `bid` / `auth` buckets per `AppServiceProvider`).

---

## 16. Frontend structure

```
resources/js/
  app.js                      - Vue + Pinia + Router + Toast bootstrap
  bootstrap.js                - axios defaults (withCredentials, CSRF)
  api/client.js               - axios instance for /api/v1 + ensureCsrf()
  stores/                     - Pinia: auth, wishi, cycle, contribution, tender,
                                member, notification, dashboard, credit, admin, audit, ui
  composables/useBreadcrumbs  - hook each page uses to publish its crumb trail
  components/Breadcrumbs.vue  - renders the trail in the topbar
  layouts/
    AppLayout.vue             - sidebar + topbar + breadcrumbs for authenticated pages
    AuthLayout.vue            - login/register split-screen
  router/index.js             - routes + auth + admin-only guards
  pages/
    Dashboard.vue             - member/admin home with next-payment card
    auth/Login.vue                 - only auth page; registration removed
    wishis/
      Index.vue               - list of WISHIs with rich cards
      Create.vue              - 4-step wizard incl. live pattern preview
      Show.vue                - tabbed detail shell (Overview/Cycles/Members/Settings/Audit)
      CycleDetail.vue         - full cycle management (contributions, bids,
                                multi-winner selection modal, payout, deferred)
      tabs/
        OverviewTab.vue       - snapshot + pending payments + cycle history DESC
        CyclesTab.vue         - table + mobile cards, newest-first
        MembersTab.vue        - roster with expandable cross-platform history
        SettingsTab.vue       - admin settings form
        AuditTab.vue          - audit timeline
    admin/
      Dashboard.vue           - AMCharts analytics dashboard
      Users.vue               - split admins/members tables, lock/unlock/credit
    Profile.vue, Notifications.vue, NotFound.vue
```

---

## 17. Seeded test scenarios

The seeder (`database/seeders/DatabaseSeeder.php`) creates:

- **7 fixed accounts**: `demo@wishi.test` / `Demo@1234` (platform admin), `admin2@wishi.test` / `Admin2@1234` (platform admin), `member1..5@wishi.test` / `Member@1234`.
- **30 factory users** with `Password@123`.
- **10 WISHIs** covering every state:
  1. Mumbai Monthly Pool — completed
  2. Pune Tech Hybrid Pool — active, live tender cycle
  3. Office Quick Saver — active, all paid, selection pending
  4. Family Savers — draft, 5/6 filled + 1 pending approval
  5. Colony Committee — draft, FULL, ready to start
  6. Startup Founders Pool — draft, 3/10 filled
  7. Abandoned Experiment — cancelled
  8. Old School Reunion — active, mixed payment states
  9. Shop Owners Tender — active, multiple deferred tender cycles
  10. School Alumni Hybrid — active, hybrid with completed cycles

Every WISHI's cycle #1 winner is the admin (organizer payout).

Run `php artisan migrate:fresh --seed --force` to rebuild.

---

## 18. Maintenance discipline

Update this document **in the same PR** whenever you:
- Change the WISHI / cycle / member lifecycle
- Add / modify a business rule (caps, eligibility, cycle #1 rule, bidding rules, tender math)
- Change the notification set or scheduler jobs
- Add / remove API endpoints or Pinia stores
- Change the security baseline (policies, rate limits, headers)
- Add or remove seeded scenarios

Out-of-date specs are worse than missing specs. If the code and this file disagree, the PR is not ready.
