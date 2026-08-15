# Xtra

WordPress plugin. Donors sponsor specific weekly hours of a staff position on a monthly Stripe subscription. The public grid never shows who paid — only that the hour is taken. Names stay in the admin sponsors list.

Version 0.1.0. Text domain `xtra`. No DGR language. No Composer. Stripe is called with `wp_remote_post` against the Stripe REST API.

Live site (later): https://xtra.cubedigital.com.au

## Install

1. Unzip `xtra.zip` so you have a folder `xtra/` (not loose files).
2. Copy that folder into `wp-content/plugins/xtra`.
3. In WordPress admin, activate **Xtra**.

On first activation, if the site has no positions yet, the plugin creates:

- A **Chaplain** position for the fictional **Perkins High School Chaplaincy** (35 hour target, $45 per weekly hour per month, Monday–Friday 9:00–15:00).
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

On the later live host that is:

```
https://xtra.cubedigital.com.au/wp-json/xtra/v1/webhook
```

In the Stripe Dashboard (or Stripe CLI for local), send at least:

- `checkout.session.completed` — pending hours become sponsored
- `invoice.payment_failed` — keep sponsored for one grace invoice, then release
- `customer.subscription.deleted` — schedule release at the end of the calendar month

Paste the webhook signing secret (`whsec_…`) on the settings screen.

Stripe cannot reach `localhost`. Use `stripe listen --forward-to http://localhost/.../wp-json/xtra/v1/webhook` on a local machine, or a public staging URL, before taking a real card.

## How money works

- One cell = that hour, every week, billed monthly.
- Charge = hourly monthly rate × number of cells. **Never × 4.33.**
- Demo rate is $45/month per weekly hour. Two hours = $90/month.
- Continue locks selected cells as **Pending** for 15 minutes (configurable), then Stripe Checkout (`mode=subscription`).
- Public cells: Available (light green, time, $45/mo), Selected (dark green, checkmark), Pending (locked), Sponsored (grey, “Sponsored”). No names, initials, or badges.

## Cancel rules

- Cancel is effective at the **end of the calendar month**.
- Cells stay Sponsored until then, then become Available.
- Donors cancel via the Stripe Customer Portal link in the confirmation email. There is no donor WordPress account.
- Admins can schedule cancel-at-month-end from **Xtra → Sponsors**.
- Cancel applies to **all hours on that Stripe subscription**.

## Emails

Hard-coded `wp_mail` in Australian English. No emojis. No “tax deductible”.

- Checkout received (pending reservation)
- Payment confirmed (hours, monthly amount, portal link)
- Cancel scheduled
- Hours released

From-name and from-email are on the settings screen.

## Uninstall warning

Deleting the plugin **does not cancel live Stripe subscriptions**. Donors will keep being billed until those subscriptions are cancelled in Stripe. Uninstall removes positions, the `{prefix}hour_sponsorships` table, and plugin options only.

## Admin

- **Positions** — organisation name, weekly hour target, monthly price per weekly hour (AUD, stored as cents), Monday–Friday start/end hour. Schedule UI freezes once any non-ended sponsorship exists.
- **Sponsors** — name, email, cell (e.g. Tue 9:00), status, subscription id, monthly amount. Super-user list. Not public.
- **Settings** — Stripe keys, webhook secret, from name/email, pending minutes (default 15), terms URL.

## Out of scope (MVP)

PayPal, per-cell prices, live admin preview, donor WordPress accounts, names on cells, GiveWP, fee passthrough, email template UI.

## Requirements

- WordPress 6.x+
- PHP 8.0+
- HTTPS recommended for Stripe Checkout
