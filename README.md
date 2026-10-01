# Xtra

WordPress plugin. Donors sponsor specific weekly hours of a staff position on a monthly Stripe subscription. The public grid never shows who paid — only that the hour is taken. Names stay in the admin sponsors list.

Version 0.1.16. Text domain `xtra`. No DGR language. No Composer. Stripe is called with `wp_remote_post` against the Stripe REST API.

Live site: https://xtra.cubedigital.com.au

## Install

1. Unzip `xtra.zip` so you have a folder `xtra/` (not loose files).
2. Copy that folder into `wp-content/plugins/xtra`.
3. In WordPress admin, activate **Xtra**.

On first activation, if the site has no positions yet, the plugin creates:

- A **Chaplain** position for the fictional **Perkins High School Chaplaincy** ($45 per weekly hour per month, schedule matrix).
- A published page titled **Perkins High School Chaplaincy** containing `[sponsor_position id="{position_id}"]`.

Open **Pages** to find that page and note the shortcode id.

## Stripe test keys

1. Go to **Xtra → Settings**.
2. Paste a publishable key (`pk_test_…`) and a secret key (`sk_test_…`).
3. Secret fields are masked. Leave them blank to keep a stored value; type a new key to replace it.
4. Until keys are saved, the public grid still renders. Checkout is blocked with a clear message. The plugin will not fatal if Stripe is missing.

There is **no Composer dependency** and no bundled `stripe-php` SDK.

## Webhook

Endpoint:

```
https://YOUR-SITE/wp-json/xtra/v1/webhook
```

On the live host:

```
https://xtra.cubedigital.com.au/wp-json/xtra/v1/webhook
```

In the Stripe Dashboard, send these 4 webhook events:
- `checkout.session.completed` — pending hours become sponsored
- `invoice.paid` — records invoice payments and receipt numbers
- `invoice.payment_failed` — keep sponsored for one grace invoice, then release
- `customer.subscription.deleted` — releases hours when subscription ends

Paste the webhook signing secret (`whsec_…`) on the settings screen.

**Xtra → Settings → Donation receipts** covers organisation logo, name, ABN, address, and free-form receipt text (DGR / tax wording) shown on every tax receipt email.

## How money works

- One cell = that hour, every week, billed monthly.
- Charge = hourly monthly rate × number of cells. **Never × 4.33.**
- Demo rate is $45/month per weekly hour. Two hours = $90/month.
- Continue locks selected cells as **Pending** for 15 minutes (configurable), then Stripe Checkout (`mode=subscription`).
- Public cells: Available (light neutral), Selected (dark green), Pending (reserved), Sponsored (forest green, “Sponsored”). No names, initials, or badges on the public grid.

## Admin Features & Schedule Matrix

- **Positions** — Organisation name, video embed URL (YouTube/Vimeo automatically formatted to embed links), monthly price per weekly hour, and an interactive **Schedule Matrix** spanning 7 days (Monday–Sunday) and hours (6:00 to 22:00).
- **Dual Checkboxes per Matrix Cell**:
  - **Show**: Ticks whether that hour is displayed on the public grid.
  - **Paid**: Ticks whether that hour is covered by an outside funding source (e.g. grant-funded), rendering it as sponsored/taken on the public grid.
- **CSV exports** — UTF-8 with a BOM so Excel opens them cleanly; `admin-post.php` actions with a nonce and a `manage_options` check. Any cell starting with `=`, `+`, `-` or `@` is prefixed with `'` to block CSV/formula injection. Dates are site-local (Australia/Sydney) `Y-m-d H:i:s`; amounts are dollars with two decimals.
- **Granular Cell Locking**: Active live sponsorships lock only their specific hour/cell, allowing admins to freely add new hours or days without freezing the entire schedule.
- **Payments** — Recent invoice payments with receipt numbers and **Resend tax receipt**. **Export CSV** with an optional financial-year filter (All, or FY 2026–27 etc., built from the data). Columns: `payment_id, date, financial_year, sponsor_name, email, position, hours, amount, currency, stripe_invoice_id, receipt_number, status, receipt_sent_at, stripe_customer_id, stripe_subscription_id`.
- **Donation summaries** (0.1.16) — **Xtra → Donation summaries**. Pick an Australian financial year (defaults to the current FY; lists every FY that has payments). The table groups successful payments by donor email (name, email, number of payments, FY total, last summary sent) with checkboxes and select-all. Buttons: **Send summary to selected** (HTML email in the tax-receipt branding — logo, organisation name, ABN, address, receipt/DGR text — listing each payment's date, position/hours, receipt and Stripe invoice number and amount, plus the FY total, with a plain-text alternative), **Preview** (renders one donor's email in the page without sending; each row also has a Preview link) and **Download summaries CSV** (one row per donor per FY; a "CSV for all years" link covers every FY). Sends run synchronously, at most 40 donors per click, with a sent/failed count notice. Each send attempt is logged in `{prefix}xtra_summary_log` (re-sending is allowed). Only payments with status `paid` or `succeeded` (and amount > 0) are counted; filter `xtra_success_payment_statuses` to change that.
- **Sponsors** — Super-user list showing donor name, email, hour cell, status, subscription ID, monthly amount, and email prefs (News / Hour emails). **Export CSV** (button beside the page title) downloads every sponsorship row, including ended ones: `id, position_id, position, organisation, day, hour, cell, status, live, sponsor_name, email, phone, address, suburb, state, postcode, message, amount_aud, stripe_customer_id, stripe_subscription_id, stripe_session_id, opt_in_news, opt_in_hour_start, pending_until, created_at, cancel_at, ended_at`. Includes **Resend confirmation**, **Cancel month-end**, **Cancel NOW** (live Stripe subscriptions), and **Clear pending** (ends a pending reservation immediately — same as expiry; no Stripe call).

## Emails

Hard-coded `wp_mail` in Australian English. No emojis. No “tax deductible”.

- Checkout received (pending reservation)
- Payment confirmed (hours, monthly amount, portal link)
- Cancel scheduled
- Hours released
- Hour-start (opt-in only; first ~10 minutes of the sponsored hour)
- Donation tax receipt (HTML email after invoice.paid; resend from Xtra → Payments)
- Annual donation summary per financial year (HTML + plain-text alternative; send from Xtra → Donation summaries)

Sponsors can opt in to position update / newsletter emails (default on) and hour-start emails (default off) at checkout. Preferences are stored on each sponsorship row.

Hour-start emails need WP-Cron (or a real system cron hitting `wp-cron.php`) roughly every 5 minutes; the plugin registers a 5-minute schedule for that.

**Xtra → Settings** shows a read-only **Last confirmation mail attempt** box (`xtra_last_confirm_mail`: time, donor email, result, session id prefix) for diagnosing missed payment-confirmed mail. On **Sponsors**, sponsored/cancelling rows have **Resend confirmation** (bypasses the per-session idempotency transient).

## Changelog

- **0.1.16** — Export CSV on Sponsors and Payments (FY filter). New **Donation summaries** submenu: per-FY donor table, preview, send (HTML in receipt branding, plain-text alternative, 40 per click), summaries CSV, send log table `{prefix}xtra_summary_log`. Payments table gains `status` (default `paid`) and `currency` (default `aud`) columns. HTML emails now carry a plain-text alternative part. DB version follows the plugin version (dbDelta on upgrade).
- **0.1.15** — Donation receipts (HTML email, settings, Payments admin).

## Uninstall warning

Deleting the plugin **does not cancel live Stripe subscriptions**. Donors will keep being billed until those subscriptions are cancelled in Stripe. Uninstall removes positions, the seeded demo page titled **Perkins High School Chaplaincy** (if present), tables (`{prefix}hour_sponsorships`, `{prefix}xtra_payments`, `{prefix}xtra_summary_log`), and plugin options (`xtra_options`, `xtra_db_version`, `xtra_receipt_counter`, and related). It does not cancel Stripe subscriptions.

## Requirements

- WordPress 6.x+
- PHP 8.0+
- Pretty permalinks enabled (`/wp-json/`)
- HTTPS recommended for Stripe Checkout