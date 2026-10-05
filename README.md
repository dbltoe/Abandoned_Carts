# Abandoned Carts for Zen Cart

About seven in ten online carts are left behind. Abandoned Carts emails the shopper a while after they stop, with what they left, the total and a **Return to Your Cart** button that puts the cart back exactly as it was, options and all. It runs by itself from a scheduler (a cron job every 15 minutes), and it stops the moment the shopper orders, empties the cart or unsubscribes.

It reaches customers with an account **and** One Page Checkout guests who saved their contact details at checkout. A guest's cart never reaches the database the way a customer's does, so older tools that read the saved carts can't see them; this plugin records the guest at the moment their email reaches the server.

Runs on Zen Cart 1.5.8 through 3.0.0 and PHP 7.4 through 8.5 from one codebase, as an encapsulated plugin: no core or template files are changed.

## What's in it

- Automatic reminder email, a set number of hours after the shopper last changed their cart, with a wait while they're still browsing (Who's Online).
- Checks before every send: too old, unsubscribed, already ordered (any payment path, PayPal IPN included), guests or newsletter-only settings, minimum value.
- Return to Your Cart: restores the exact cart (text and checkbox options included), skips products since disabled, saves it to a logged-in customer's account.
- One-click unsubscribe: a link on every email and the `List-Unsubscribe` / one-click headers (RFC 8058) mail providers use for their own button. The list keeps a hash, never the address.
- Your store's postal address on every email (CAN-SPAM), HTML for guests, Preview Email definition.
- Customers > Abandoned Carts: the scheduler's health, a 30-day summary (reminders sent, carts recovered and their value), every cart with its items and history, and Stop Reminders.
- Cart records (guest emails included) deleted after the days you set.

**Abandoned Carts Pro** (sold separately) adds up to three reminders per cart, a single-use coupon in the one you choose, and a report of what each reminder brought back.

## Documentation

- `readme.html`: the store owner's guide (also linked from Plugin Manager).
- `docs/INSTALL.md`: installing, the scheduler, upgrading, uninstalling.
- `docs/CONFIGURATION.md`: every setting.
- `docs/CUSTOMIZING.md`: wording, the unsubscribe page, notifiers and what Pro hooks into.
- `docs/COMPATIBILITY.md`: versions, One Page Checkout, the hooks used and what was tested where.
- `CHANGELOG.md`: changes by version.

## Layout

- `zc_plugins/AbandonedCarts/v1.0.2/`: the plugin, exactly as it's uploaded.
- `readme.html`: a copy of the plugin's readme at the top of the download.

## License

GNU General Public License v2.0. Copyright (c) 2026 My Zen Cart Host (dbltoe).
