# Xtra

WordPress plugin. Donors sponsor specific weekly hours of a staff position on a monthly Stripe subscription. The public grid never shows who paid — only that the hour is taken. Names stay in the admin sponsors list.

Version 0.1.4. Text domain `xtra`. No DGR language. No Composer. Stripe is called with `wp_remote_post` against the Stripe REST API.

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
- **Granular Cell Locking**: Active live sponsorships lock only their specific hour/cell, allowing admins to freely add new hours or days without freezing the entire schedule.
- **Sponsors** — Super-user list showing donor name, email, hour cell, status, subscription ID, and monthly amount. Includes options to **Cancel month-end**, **Cancel NOW**, or clear pending slots immediately.

## Emails

Hard-coded `wp_mail` in Australian English. No emojis. No “tax deductible”.

- Checkout received (pending reservation)
- Payment confirmed (hours, monthly amount, portal link)
- Cancel scheduled
- Hours released

## Uninstall warning

Deleting the plugin **does not cancel live Stripe subscriptions**. Donors will keep being billed until those subscriptions are cancelled in Stripe. Uninstall removes positions, tables (`{prefix}hour_sponsorships`, `{prefix}xtra_payments`), and plugin options only.

## Requirements

- WordPress 6.x+
- PHP 8.0+
- Pretty permalinks enabled (`/wp-json/`)
- HTTPS recommended for Stripe Checkout
