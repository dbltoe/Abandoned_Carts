# Compatibility

## Versions

- Zen Cart 1.5.8, 2.0, 2.1, 2.2, 2.3 and 3.0.0 from one codebase.
- PHP 7.4 through 8.5. The source parses on 7.4 and is deprecation-clean on 8.5.
- Encapsulated: installs, upgrades and uninstalls from Plugin Manager; no core or template file is changed.

## One Page Checkout

Guests are recorded through two of One Page Checkout's notifiers, `NOTIFY_OPC_VALIDATE_SAVE_GUEST_INFO` and `NOTIFY_OPC_ADDRESS_VALIDATION`, present in every release from 2.3.6 through 2.7.0. A guest is recorded when they click Save on their contact details; nothing reaches the server before that. Without One Page Checkout, reminders go to customers with an account.

## Hooks used, all present on every branch

- Cart: `NOTIFIER_CART_ADD_CART_END`, `NOTIFIER_CART_UPDATE_QUANTITY_END`, `NOTIFIER_CART_REMOVE_END`, `NOTIFIER_CART_RESTORE_CONTENTS_END`, `NOTIFIER_CART_RESET_START/END`. Core runs cart actions at autoloader breakpoint 140 and instantiates `auto.*` observers at 175, so the cart observer is created at 139 by the plugin's own auto_loader.
- `NOTIFY_LOGIN_SUCCESS`, `NOTIFY_LOGIN_SUCCESS_VIA_CREATE_ACCOUNT`, `NOTIFY_CHECKOUT_PROCESS_BEFORE_CART_RESET`.
- Email: `NOTIFY_EMAIL_DETERMINING_EMAIL_FORMAT` (HTML for guests), `NOTIFY_EMAIL_BEFORE_PROCESS_ATTACHMENTS` (unsubscribe headers).

## Things the code relies on, checked against every branch

- Zen Cart memoizes every SELECT for the length of a request and writes don't clear it, so every lookup the plugin makes passes `Execute()`'s `removeFromQueryCache` argument.
- The storefront's CSRF check applies only to requests with an `action` parameter, so the token-less one-click unsubscribe POST is accepted; the plugin never reads `action`.
- `zen_mail()` answers `''` when sent and error text or false when not.

## Tested

- Harness suite on PHP 7.4, 8.0, 8.1, 8.2, 8.3, 8.4 and 8.5, against clean checkouts of every branch.
- Live on a Zen Cart 2.2.2 store (PHP 8.4) with One Page Checkout 2.6.3 and 2.7.0: guest and customer carts, the reminder, Return to Your Cart, unsubscribe (page and one-click), an order closing a cart as recovered, and Abandoned Carts Pro's three reminders and coupon.
