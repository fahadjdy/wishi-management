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
- WISHI status must be `draft` / `planned` / `active`.
- Capacity check (can't exceed `total_members`).
- `invite` simply bypasses the "no self-join" rule for admin-invited flows.

> Historical: `min_credit_score`, `max_active_wishis_per_member`, and the
> `block_if_missed_payments` gate used to live here. All three were removed
> (2026-04-15) — admin vetting replaces automated eligibility gates.

Once accepted/approved, member gets a `MemberStatusNotification` in their inbox. The invitation carries a `MemberStatusNotification(status='invited')` push when sent. In parallel, the WISHI admin (creator) receives a **MemberJoinedNotification** whenever:
- a user posts a new join request (`event='requested'`),
- a user accepts an admin invitation (`event='accepted_invite'`),
- the admin approves a pending member (`event='joined'`).

### Auto-rejection when WISHI fills up

Pending requests / unanswered invites don't reserve a seat — only `approved` / `active` rows count toward capacity. So if other members fill the WISHI first, the leftover pending rows are orphaned. `MembershipService::autoRejectPendingOnFull($wishi)` sweeps them: each row is marked `removed` + soft-deleted, the member receives a `MemberStatusNotification(status='rejected_full')` ("WISHI is now full") and an audit log records `pending_auto_rejected_wishi_full`. The sweep runs:
- inside `notifyIfJustFilled()` — triggered by every `approve()` / `acceptInvite()` / auto-approved `requestJoin()` path whenever the capacity check just flipped to full;
- inside `WishiService::activate()` — any rows still pending at start time are definitively orphaned once cycle #1 opens.

### Token numbers

Every approved member is assigned a sequential `token_no` on the `wishi_members` row, unique per WISHI. Assignment happens once — when the member first transitions to `approved` (via `requestJoin` auto-approve, `acceptInvite`, or admin `approve`). Tokens are stable: removal does **not** renumber remaining members, so gaps are allowed.

**Token #1 is reserved for the admin/organizer** (cycle-#1 organizer payout, see §6). The admin is not stored in `wishi_members`, but is surfaced as a virtual Member #1 in the Members tab payload. Real members therefore start at **token #2** and count upward. `MembershipService::assignTokenIfMissing()` computes `max($max, 1) + 1` under a WISHI row lock so the first real approval lands on #2 and concurrent approvals can't collide. (Rule effective 2026-04-15 — older data may still have a member on token #1; not backfilled.)

### Admin-created accounts

`POST /api/v1/admin/users` (platform admin only) — accepts `name`, `email`, `phone?`, `password`, `credit_score?`, `is_admin?`. Password is hashed via `User::$casts`. Email verified immediately.

The admin UI (`/admin/users`) has a **"+ Add member"** button that opens a modal with auto-generated passwords so the admin can paste the credentials to the member over WhatsApp/SMS/etc.

### Admin Member Detail page

Clicking any row in `/admin/users` opens `/admin/users/:id` — a full-page admin view where the platform admin can manage a single member end-to-end without leaving the page:

- **Profile edit** — name, email (login identifier), phone, WhatsApp, avatar. Email change hits `POST /admin/users/{id}` and keeps the same user row; it must be shared with the member out-of-band.
- **Password reset** — admin sets a new password via `PUT /admin/users/{id}/password` and shares it manually (no email reset link flow). Admin cannot reset their own password here; they are redirected to use `/me/password` on their own Profile page.
- **Lock / unlock** — inline form with duration + reason; reason is recorded in the audit log.
- **Role & status** — toggle platform admin, soft-delete, restore.
- **Credit adjust** — inline points + reason form (requires a reason, 0 points blocked).
- **WISHI activity** — active memberships, pending dues with inline "Mark paid", recent payments with "Undo" (gated by the `can_undo` flag — open cycle, no winner announced yet, or organizer payout).

The Members list page itself is intentionally minimal: search, sort, and a click-through row. Deep actions live on the detail page.

### Members table row tinting

Each row in the Members table is tinted based on a per-user `payment_status` computed in `AdminUserResource`:

- **`late`** → light red (`bg-red-50`). User has ≥1 contribution with `paid_at IS NULL` AND (`due_date < today` OR `status IN ('late','missed')`). Takes priority over `advance`.
- **`advance`** → light green (`bg-emerald-50`). User has ≥1 contribution with `paid_at IS NOT NULL` AND `due_date > today` (i.e. paid a future cycle ahead of schedule).
- **`normal`** → default hover-only styling.

Row tooltip shows the exact count. Canonical paid indicator (`paid_at IS NOT NULL`) is used — consistent with §9.

### Member cancellation window

Admin can cancel (`DELETE /wishis/{wishi}/members/{member}` → `MembershipService::remove()`) any approved/pending member **only while the WISHI is in `draft` or `planned` state**. Once the admin clicks **Start WISHI** and `status` transitions to `active`, the Cancel button disappears from the Members tab and `WishiMemberPolicy::remove()` returns `false`. Rationale: once cycle #1 opens, contributions + token assignments are baked in; removing a member mid-flight would orphan cycle rows and break pool math. If a member absolutely must be removed post-start, the admin must cancel the entire WISHI and create a replacement.

### Start WISHI entry points

The "🚀 Start WISHI now" button is rendered for the admin in two places whenever `can_start = true` (i.e. `status ∈ {draft, planned}` AND `active_members_count >= memberCapacity()`):

- On the WISHI **card** in `/wishis` listing (`Index.vue`).
- On the WISHI **detail** page header banner (`Show.vue`).

Both buttons call `POST /wishis/{uuid}/activate` → `WishiService::activate()`. Admin also receives a `WishiFullNotification` the moment `notifyIfJustFilled()` detects capacity was just reached — so they get a push + see both buttons the next time they load the dashboard.

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

This is why a WISHI with `total_members = 6` ends up with admin winning cycle #1 and the 5 invited members winning cycles #2–#6 (one each). Admin-as-seat-#1 is canonical now — there is no 6th unlucky member.

> **`total_members` includes the admin** (rule effective 2026-04-15). The admin holds seat #1 as organizer; only `total_members − 1` members can be invited / approved. `Wishi::memberCapacity()` is the single source of truth for **invitable seats** — every capacity check (invite, accept, approve, request-join, activate, can_start, seats_remaining, is_full) uses it. Frontend exposes `member_capacity` on `WishiResource`.
>
> **Admin contributes equally with every member** (rule effective 2026-04-15). `Wishi::totalPool()` is `monthly_contribution × total_members` (admin pays the monthly contribution every cycle, including cycle #1). Admin still receives the cycle-#1 organizer payout — economically the admin breaks even (pays in `monthly × total_members` over the full WISHI, receives the same amount back in cycle #1). `CycleService::bootstrapContributions()` creates a Contribution row for `wishi.created_by` alongside every approved member's row, so the admin sees their own pending dues on their dashboard like any other member. The admin is still NOT stored in `wishi_members` — only their `Contribution` rows materialise.
>
> `duration_months` is no longer asked on the creation form. It is auto-set to `total_members` by `WishiService::create` — giving one cycle per seat (cycle #1 = admin, cycles 2..N = the invited members). The column still exists and is read throughout the UI/services — only the admin-input field was removed.

---

## 7. Cycle types

| Type | How cycles run |
|---|---|
| `random` | Every cycle uses a cryptographic random draw (`random_bytes`) among members who haven't won yet. Seed stored for transparency. |
| `hybrid` | Each cycle's mode is taken from `hybrid_pattern` — see §8. Individual cycles inside a hybrid pattern can still be `tender`. |

Cycle #1 is always `random` (see §6), regardless of WISHI type.

> Pure `tender` WISHIs were removed from the creation form on 2026-04-15 — only `random` and `hybrid` are selectable. The enum value `tender` is retained in DB for back-compat with existing WISHIs and as a valid step inside `hybrid_pattern`. `StoreWishiRequest` enforces `cycle_type ∈ {random, hybrid}`.

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

### Undoing a payment

Admin can reverse a mistaken "Mark as paid" via `DELETE /wishis/{uuid}/cycles/{c}/contributions/{id}/payment` (`ContributionService::revertPayment`). The action is admin-only and:

- Refuses if the cycle has been paid out (`paid_out_at IS NOT NULL`) or already has a winner selected (except cycle #1 organizer payout, which is pre-assigned and revertible).
- Recomputes which credit action was originally applied (`early_payment` / `on_time_payment` / `late_payment`) and applies the **inverse** delta as a `payment_reverted` credit-score log entry.
- Clears `paid_at`, `payment_method`, `payment_reference`, `notes` on the contribution; status flips back to `pending` (or `late` if today is past due).
- Rolls the cycle status back to `contribution_open` if it had auto-advanced to `selection_pending` / `bidding_open`.
- Writes an `audit_logs` row of action `contribution_reverted` with the original payment timestamp, action, and reverse points so the trail stays intact.

Surfaced in the UI both on the cycle detail (admin contribution table → "Undo" link next to each paid row) and inside the **admin member-profile modal** (Recent payments → "Undo" button per row, gated by `can_undo`).

---

## 10. Winner selection

### Maturity guard (all modes)

Winner announcement is **locked until the cycle's `contribution_due_at` is reached**. `WinnerSelectionService::ensureCycleMature` is called at the start of `selectRandomWinner`, `selectTenderWinner`, `selectTenderMultiWinner`, and `manualSelectWinner` — any attempt before the due date throws `DomainException("Winner can only be announced on or after {date}")`. The CycleDetail UI mirrors this: the "Select winner" button is replaced by a countdown card ("⏳ Winner announcement locked — available on <date> (N days left)") until the cycle matures. Cycle #1 is exempt because its winner is pre-assigned at creation.

### Random mode
`WinnerSelectionService::selectRandomWinner` — picks uniformly from eligible members (active, not yet won). Uses `random_bytes(32)` for the seed, stores the seed on the cycle so the draw is verifiable.

### Tender bidding window (all tender cycles)

Bidding spans the full cycle window, driven by a single time-of-day on the WISHI:

- **Opens** at `wishi.wishi_opening_time` on the cycle's start date.
- **Closes** at `wishi.wishi_opening_time` on the **next** cycle's start date (i.e. the whole cycle span is the bidding window). For the final cycle, closing uses the same "next" computation from `startDateForCycle(N+1)` so there's always a concrete close.
- A single `wishi_opening_time` is configured on WISHI creation and reused for every tender cycle. There is no separate `tender_start_time` / `tender_end_time` / `bidding_window_days` — those were removed (2026-04-15).

Example: WISHI starts 1-Jan-2026 with `wishi_opening_time='00:00'`. Cycle #2 starts 1-Feb-2026 — bidding opens at 00:00 on 1-Feb-2026 and closes at 00:00 on 1-Mar-2026. After close, no new bids are accepted; the admin can declare the winner (auto-lowest via `selectTenderWinner` or manual/multi via `selectTenderMultiWinner`) only after the `contribution_due_at` maturity guard allows it (see §10 maturity guard).

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
| `WishiFullNotification` | Last member joins; creator receives this. Payload includes `url` (`/wishis/{uuid}`) so the dashboard inbox can deep-link straight to the WISHI's Start screen. |
| `WishiStartedNotification` | Admin activates; every member receives this |
| `PaymentReminderNotification` | `wishi:payment-reminders` scheduler, 3 days before due |
| `TenderWindowNotification` | `wishi:tender-reminders` scheduler, open + close alerts |
| `WinnerAnnouncedNotification` | Queued hook after winner selected (present but currently not auto-fired) |

---

## 13a. Member dashboard UX rules

The member dashboard (`pages/Dashboard.vue`) follows a **"don't cry wolf"** rule: red/warning chrome appears **only when a payment is actually late** (its due date has passed and it's still unpaid). Anything still in the future — including "due today" or "due tomorrow" — is rendered in neutral gray.

| Section | Visibility rule | Wording |
|---|---|---|
| **Late-payment hero** (red surface) | Only when the member has ≥1 contribution with `due_date < today` and `paid_at IS NULL`. Lists every late row inline. | "X late payments" header; per-row "X days late" |
| **Next month total** (neutral surface) | Always visible when there are any upcoming payments. Sum of every upcoming contribution amount across all joined WISHIs. Label uses **unique WISHI count** (deduped by uuid), not the payment row count. | "Next month total" + "Across N WISHI you're a member of" |
| **Upcoming payments table** | Always visible; one row per upcoming contribution. Cell color follows `urgencyClass` — red only for `days < 0`, gray otherwise. | "Due in N days" / "Due today" / "Due tomorrow" / "N days late" |

### Stat cards — admin-as-organizer semantics (rule effective 2026-04-16)

The four stat cards (`Active WISHIs`, `Total Contributed`, `Total Won`, `Credit Score`) treat the admin as an **implicit member of every WISHI they organize**, matching FLOW.md §4 (admin holds seat #1). Concretely, `DashboardController::index` computes:

- `active_wishis_count` = (WISHIs the user has an `approved`/`active` `wishi_members` row in, where wishi status = `active`) **∪** (WISHIs the user created where wishi status = `active`). Deduped by wishi id.
- `created_wishis_count` = lifetime count of wishis the user created, across **all** statuses (`draft`, `planned`, `active`, `completed`). Previously this was filtered to `active` only, which contradicted the "N created by you" label under the stat card.
- `upcoming_payments` — relies on the admin having a `Contribution` row for every open cycle of their own WISHI. `CycleService::bootstrapContributions` creates that row from 2026-04-15 onward; migration `2026_04_16_110000_backfill_admin_contributions` retroactively fills the row for cycles bootstrapped before that date.

**Forbidden language:** the word *"overdue"* is reserved for admin-side WISHI-opening cards (where the admin needs to act on a late activation). On the payment-due path, late payments are always called **"late"**, never overdue, so the messaging stays consistent across dashboard, modals, and notifications.

## 13c. Privacy — what members can and cannot see

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
| PUT | `/me/password` | Change own password |
| POST | `/me/profile` | Self-service profile update — avatar for everyone; platform admins can additionally edit their own `email` / `phone` / `whatsapp_number`. Members still route contact edits through Admin → Members |
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
| DELETE | `/wishis/{uuid}/cycles/{c}/contributions/{id}/payment` | Undo a recorded payment (admin only — see §9 "Undoing a payment") |
| GET/POST | `/wishis/{uuid}/cycles/{c}/tenders` | List bids / place bid (immutable) |
| GET | `/wishis/{uuid}/audit-logs` | Wishi-scoped audit |
| GET | `/admin/dashboard` | Platform analytics feed (platform admin only) — consumed inline on the main `/dashboard` page when viewer `is_admin`; there is no separate `/admin` route. Payload also includes `pending_join_requests` (member-initiated joins awaiting approval, `invited_by_admin=false`) so the admin dashboard can show an inline Approve/Reject queue without drilling into each WISHI |
| GET | `/admin/users` | All users, search + filter. Each row carries `payment_status` (`late` \| `advance` \| `normal`) + `late_contributions_count` + `advance_contributions_count` so the Members table can tint rows red/green without a second round-trip |
| POST | `/admin/users` | **Create a new account (replaces self-registration)** |
| GET | `/admin/users/{id}` | User detail — also returns `active_wishis`, `pending_contributions`, `paid_contributions` (with `can_undo` flag) and `totals` so the admin Member Detail page can render WISHIs + dues + payment history in one round-trip |
| POST | `/admin/users/{id}` | Update member profile (multipart) — `name`, `email`, `phone`, `whatsapp_number`, `avatar`, `remove_avatar`. Email is changeable (unique-ignore-self); must be shared with member since it's their login identifier |
| PUT | `/admin/users/{id}/password` | Admin-set password reset. Sets a new password the admin shares out-of-band (no email link flow). Admin cannot reset their own password here — they must use `/me/password`. |
| PUT | `/admin/users/{id}/toggle-admin` | Grant/revoke platform admin |
| PUT | `/admin/users/{id}/lock` | Lock account |
| PUT | `/admin/users/{id}/unlock` | Unlock |
| DELETE/POST | `/admin/users/{id}` + `/restore` | Soft-delete / restore |
| PUT | `/admin/users/{id}/credit-score` | Manual credit adjustment |

All write routes are throttled (`sensitive` / `bid` / `auth` buckets per `AppServiceProvider`).

---

## 16. Frontend structure

### Design language (2026-05-12 redesign — "warm desi-modern")

Cream paper background with terracotta primary + deep green accents — feels closer to a kitty among friends than a fintech dashboard.

| Token (Tailwind) | Maps to | Notes |
|---|---|---|
| `brand-*` (also `indigo-*`) | terracotta `#FBEEE6 → #2C0E08` | primary actions, accents |
| `accent-*` / `green-*` / `emerald-*` | deep green `#ECF3EE → #1F4A3C` | success, trust, money paid |
| `slate-*` / `gray-*` | warm sand → ink `#FAF5EC → #1F1812` | surfaces, text |
| `amber-*` | mustard `#FAEFD1 → #6A4C12` | warnings, deferred |
| `rose-*` | warm red `#F8DCDC → #5A1C1C` | late / cancelled |

Typography:
- **Instrument Serif** (`.display`, `.serif`, `font-display`) for all hero numerals, page headers, large money figures. Use italic + terracotta for emphasis (e.g. `Namaste, <em>Rajesh</em>.`).
- **DM Sans** for body. **JetBrains Mono** for code/tabular numerals (`.num` uses tnum/lnum features).
- Wordmark is text-only: `WISHI.` in Instrument Serif with the terminal dot in terracotta — `<Logo variant="stacked" />`.

Helper classes (in `resources/css/app.css`):
- `.surface` / `.surface-padded` — paper-white card with subtle warm shadow + sand border, rounded-2xl.
- `.stat-tile` — stat panel with `.v` (serif numeral) + `.l` (uppercase tracked label).
- `.pill-planned / -active / -completed / -cancelled / -draft` — lifecycle status pills.
- `.section-title` + `.section-sub` — display serif heading + muted subline.
- `.paper-tex` — subtle radial-dot paper texture; layer over gradient hero areas.
- `.coin` — radial-gradient brass coin glyph for trust / win callouts.
- `.brandmark` (+ `.mono` for dark-bg variants) — typographic wordmark utility.

Shell:
- Desktop sidebar is **ink** (`#1F1812`) with terracotta accent rail on active items; brand wordmark shown stacked at top.
- Top bar is paper white with cream tint; bottom mobile nav keeps cream paper feel with a terracotta FAB for "+ New WISHI" (admin only).
- Hero pages (member dashboard, Create WISHI summary) use the cream → terracotta paper-tex gradient as their signature flourish.

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
    Dashboard.vue             - single home page. Members: next-payment card, discover, credit. Admins: platform analytics (tiles + AMCharts + top contributors + recent activity). No separate admin analytics page.
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
      Users.vue               - split admins/members tables, lock/unlock/credit
    (platform analytics now rendered inline on Dashboard.vue when viewer is admin)
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
