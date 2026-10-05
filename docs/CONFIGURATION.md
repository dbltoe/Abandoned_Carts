# Configuring Abandoned Carts

Configuration > Abandoned Carts:

| Setting | Default | What it does |
|---|---|---|
| Send Reminder E-Mails? | true | Turns the emails on or off. Carts are still recorded while it's off. |
| Reminder E-Mail, Hours After Last Activity | 1 | How long after the shopper last changed their cart the reminder goes out. 1 to 168. |
| Don't Remind About Carts Older Than (Days) | 7 | Carts last active longer ago than this are never emailed. 1 to 60. |
| Minimum Cart Value | 0 | Carts worth less are not emailed. Default currency, with tax when your prices show tax. |
| E-Mail Guests? | true | Whether One Page Checkout guests who saved their contact details are emailed. When false, nothing about a guest is kept. |
| E-Mail Only Newsletter Subscribers? | false | Only customers who opted in to your newsletter are emailed, and nothing is kept about anyone else; a guest only after ticking the Ask Guests Before Reminding? box. |
| Ask Guests Before Reminding? | false | One Page Checkout shows a guest an "Email me a reminder if I leave items in my cart." checkbox, never ticked for them; only guests who tick it and save are recorded. |
| Wait While the Shopper Is Still Browsing (Minutes) | 20 | A shopper Who's Online shows clicking within this window isn't emailed yet. 0 turns it off. |
| Keep Cart Records (Days) | 30 | Records (guest emails included) are deleted this many days after the cart's last activity. 7 to 365. |
| Delete Cart Records on Uninstall? | false | See docs/INSTALL.md. |
| Scheduler Key | generated | The secret in the scheduler address. Read-only. |
| Link Secret | generated | Signs the email links. Read-only; changing it breaks every link already sent. |
| Scheduler Last Ran | | Set by the scheduler. Read-only. |

## Who gets a reminder

A cart is recorded when the shopper changes it while the plugin knows their email (a logged-in customer, or a guest who saved their details). Each change pushes the reminder back. A shopper the settings rule out (guests off, a guest who didn't tick the box, newsletter-only) is never recorded. When it's due, the scheduler checks, in order: too old; unsubscribed; an order from that address since the cart was recorded; the same settings again (a record they now rule out is deleted); minimum value; still browsing. Then it sends.

## Privacy and the law

Not legal advice. US (CAN-SPAM): every reminder says it's an advertisement from your store, and has an unsubscribe and your postal address. The EU and UK (GDPR, PECR), Canada (CASL) and Australia (Spam Act 2003) need consent first: set E-Mail Only Newsletter Subscribers? true and Ask Guests Before Reminding? true (or E-Mail Guests? false), keep Keep Cart Records short, and mention cart reminders in your privacy notice.
