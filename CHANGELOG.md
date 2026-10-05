# Changelog

## [1.0.2] - 2026-10-05

### Added

- Ask Guests Before Reminding? (off by default). When on, One Page Checkout shows a guest an "Email me a reminder if I leave items in my cart." checkbox in the contact block, never ticked for them, drawn by a script at `NOTIFY_FOOTER_END` and posted with OPC's own guest Save. Only a guest who ticks it is recorded and reminded; unticking and saving again deletes their record. With E-Mail Only Newsletter Subscribers? on, the tick is the guest's consent. Wording: `ABANDONED_CARTS_OPT_IN_LABEL`.
- Every reminder says "This email is an advertisement from (store)." above the unsubscribe line, CAN-SPAM's ad disclosure (`ABANDONED_CARTS_EMAIL_AD_NOTICE`).
- The readme's law section covers Australia (Spam Act 2003) beside the EU, UK and Canada.

### Changed

- Nothing is kept about a shopper the settings say can't be emailed. With E-Mail Guests? off, a guest's email and cart were still recorded (never emailed); now they aren't. Likewise a customer who isn't subscribed under E-Mail Only Newsletter Subscribers?. A record the settings rule out by send time (a setting changed since) is deleted with its history, not marked Not Emailed; the scheduler report counts these as "Deleted, not to be emailed under your settings".
- The cart table gains `opted_in`, added on upgrade.

Thanks to mprough for the review in the support thread.

## [1.0.1] - 2026-10-05

### Changed

- Plugin Manager's info box links the Abandoned Carts support thread on the Zen Cart forum, and so does the readme's Support section, with the GitHub repository beside it.

### Fixed

- The readme said Abandoned Carts Pro puts its coupon in the last reminder. Pro puts it in the reminder you choose (Coupon in Reminder Number).

Nothing else changed: no code, settings, data or emails. Upgrade in Plugin Manager: upload the v1.0.1 folder beside v1.0.0, click Upgrade, then Upgrade again on the confirmation screen.

## [1.0.0] - 2026-10-05

First release.

- Records a cart once the shopper can be emailed: a customer with an account, or a One Page Checkout guest who saved their contact details (OPC 2.3.6 through 2.7.0). Anonymous carts are never stored.
- One automatic reminder per cart, a set number of hours after the last cart change, sent by a scheduler (`main_page=abandoned_carts_cron&key=...`) from a cron job or an outside cron service.
- Before each send: too old, unsubscribed, ordered since (any payment path), guest and newsletter-only settings, minimum value, and a wait while the shopper is still browsing.
- Return to Your Cart link that restores the exact cart; one-click unsubscribe (link and RFC 8058 headers); postal address on every email; HTML for guests.
- Customers > Abandoned Carts with the scheduler's health, a 30-day summary, every cart's items and history, and Stop Reminders.
- Records purged after Keep Cart Records days; uninstall keeps or deletes the data as you choose, and keeps the scheduler key and link secret with it.
- Preview Email definition for the reminder.
- Hooks for Abandoned Carts Pro: up to three reminders, step wording, a coupon (`NOTIFY_ABANDONED_CARTS_BEFORE_COMPOSE`, `NOTIFY_ABANDONED_CARTS_SCHEDULER_END`).

[1.0.2]: https://github.com/dbltoe/Abandoned_Carts/releases/tag/v1.0.2
[1.0.1]: https://github.com/dbltoe/Abandoned_Carts/releases/tag/v1.0.1
[1.0.0]: https://github.com/dbltoe/Abandoned_Carts/releases/tag/v1.0.0
