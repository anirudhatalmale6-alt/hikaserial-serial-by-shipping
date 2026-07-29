# HikaSerial – Serials by Shipping Method

A small Joomla plugin (HikaSerial group) that generates and delivers the HikaSerial
serial/ticket **only** for orders whose customer chose one of the allowed shipping
methods (e.g. your "E-Mails" dispatch types). Orders that used any other shipping
method (e.g. delivery by post) get **no serial at all** — it is not generated, not
shown in the customer's front-end order view, and not e-mailed.

Built and verified against: **Joomla 5/6 · HikaShop 6.5.2 · HikaSerial 6.1.1 · PHP 8.4**

## How it works

The plugin gates HikaSerial at two levels:

**1. Generation (primary).** HikaSerial decides whether to assign serials when an
order reaches its assignable status, from `class.order::preUpdate()` →
`postUpdate()`. The plugin hooks `onSerialOrderPreUpdate` and, for orders whose
shipping method is **not** in your allowed list, suppresses the assignment. No
serial is created, so nothing appears in the customer's account/front-end or in the
order e-mail — exactly what you want for "normal" postal orders.

**2. Delivery (defence-in-depth).** In case a serial exists for any other reason,
the official generator rule hooks also skip attaching the ticket:
- `onCustomPdfSerialRule($serial, $params, &$do)` — PDF tickets
- `onCustomAttachSerialRule($serial, $params, &$do)` — image/QR tickets
- `onBeforeSerialMailSend` — strips any inline serials from the order e-mail.

For allowed (E-Mails) orders the plugin does nothing at all — HikaSerial generates,
displays and e-mails the ticket exactly as it does today, with your ticket layout
and default e-mail template untouched. **No HikaSerial settings need to be changed.**

## Install

1. Joomla admin → **System → Install → Extensions** → upload
   `plg_hikaserial_serialbyshipping.zip`.
2. **System → Manage → Plugins** → search "Send Tickets by Shipping" → **enable** it.
3. Open the plugin and set:
   - **Allowed shipping method(s)** — pick your Email dispatch types
     (pre-filled with 1, 4, 8, 17, 20, 26). Multi-select.
   - **When an order has no shipping method** — default "Do not send the ticket".
   - **Debug logging** — ON while testing, OFF in production.

That's it. No changes to HikaShop or HikaSerial configuration are required.

## Testing

1. Turn **Debug logging** ON.
2. Place a test order using an **allowed** shipping method and mark it Paid →
   a serial is generated, shown in the customer's order/account, and the PDF ticket
   is in the e-mail as usual.
3. Place a test order using a **non-allowed** shipping method and mark it Paid →
   **no serial** is generated, nothing is shown in the front-end, and the e-mail
   arrives with no ticket.
4. Check `administrator/logs/plg_hikaserial_serialbyshipping.php` — each decision
   is logged (e.g. "serial generation suppressed" / "ticket skipped") with the
   order id.

## Notes / scope

- Primary behaviour is the **generation gate**: non-allowed orders never get a
  serial, so nothing leaks into the front-end. The delivery hooks are a safety net.
- Log file: `administrator/logs/plg_hikaserial_serialbyshipping.php`.
