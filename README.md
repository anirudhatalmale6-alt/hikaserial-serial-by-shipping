# HikaSerial – Send Tickets by Shipping Method

A small Joomla plugin (HikaSerial group) that delivers the HikaSerial ticket
(PDF or image serial) **only** for orders whose customer chose one of the allowed
shipping methods (e.g. your "E-Mails" dispatch types). Orders that used any other
shipping method still receive their normal HikaShop order e-mail — just without
the ticket attached.

Built and verified against: **Joomla 5/6 · HikaShop 6.5.2 · HikaSerial 6.1.1 · PHP 8.4**

## How it works

HikaSerial attaches the ticket by looping the order's serials inside its PDF/image
generator, and for each one it fires an official rule hook:

- `onCustomPdfSerialRule($serial, $params, &$do)` — PDF tickets
- `onCustomAttachSerialRule($serial, $params, &$do)` — image/QR tickets

This plugin answers those hooks. It reads the order's shipping method and, if it
is **not** in your allowed list, sets `$do = false` so HikaSerial simply skips
attaching that ticket. A secondary `onBeforeSerialMailSend` gate strips any inline
serials from the same e-mail as a safety net (covers text-mode serials too).

Nothing else changes: HikaSerial still generates/assigns serials exactly as it does
today, your ticket layout is untouched, and the default HikaSerial e-mail template
is used as-is. **No HikaSerial settings need to be disabled.**

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
   the customer e-mail should include the PDF ticket as usual.
3. Place a test order using a **non-allowed** shipping method and mark it Paid →
   the customer e-mail should arrive **without** the ticket.
4. Check `administrator/logs/plg_hikaserial_serialbyshipping.php` — each decision
   is logged ("ticket delivered" / "ticket skipped") with the order id.

## Notes / scope

- The plugin gates **delivery** of the ticket by shipping method. HikaSerial still
  internally assigns a serial to every paid order (its normal behaviour). If you
  ever need non-Email orders to not consume a serial at all, that is a separate
  change — ask and it can be added.
- Log file: `administrator/logs/plg_hikaserial_serialbyshipping.php`.
