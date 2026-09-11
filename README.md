# Phoca Cart – Cancel Overdue Invoice Orders (Joomla Task Plugin)

A Joomla Scheduled Task plugin (`plg_task_pcpinvoicecancel`) that automatically
cancels [Phoca Cart](https://www.phoca.cz/phocacart) orders whose **Invoice
Due Date** has passed while the order is still unpaid.

Phoca Cart supports an "Invoice Due Date Days" setting for offline/invoice
payments, but has no built-in mechanism to act on it once the date passes.
This plugin fills that gap: it runs on a schedule, finds overdue unpaid
orders, and moves them to a "Cancelled" status — going through Phoca Cart's
own status-change pipeline so stock, points, order history and customer
notification emails all behave exactly as they would for a manual status
change in the admin.

## Requirements

- Joomla 5.x / 6.x with `com_scheduler` (core, Joomla 4.1+)
- [Phoca Cart](https://www.phoca.cz/phocacart) 6.1.x

## Installation

1. Download the latest release zip (or build one — see below) and install it
   via **System → Install Extensions**, same as any Joomla plugin.
2. Enable the plugin under **System → Plugins** if it isn't enabled
   automatically.
3. Go to **System → Scheduled Tasks → New Task**, and pick **"Phoca Cart:
   Cancel overdue invoice orders"** as the task type.

## Configuration

All settings live on the **task itself** (Edit Task screen), not on the
plugin's own configuration:

| Field | Description | Default |
|---|---|---|
| Pending order status ID(s) | Comma-separated `status_id` value(s) (Components → Phoca Cart → Order Statuses) that count as "awaiting payment". Only orders in one of these statuses are checked. | `1` |
| Cancelled order status ID | The `status_id` to set on overdue orders. | `3` |
| Payment method ID(s) (optional) | Comma-separated `payment_id` values to restrict the task to specific (e.g. offline/invoice) payment methods. Leave empty for all. | *(empty)* |
| Cancellation comment | Text stored in the order history entry. | `Auto-cancelled: invoice due date exceeded` |
| Batch limit | Max orders processed per run. | `200` |
| Dry run | When on, matching orders are only logged, never changed. **Keep this on until you've verified the status IDs above are correct for your site.** | `Yes` |

Status IDs are **not** fixed across installs — check
**Components → Phoca Cart → Order Statuses** for the actual IDs on your
site before turning dry run off.

## Triggering the task

Joomla can run scheduled tasks two ways:

- **CLI cron** (`php cli/joomla.php scheduler:run`) — simplest if you have
  shell/SSH access, but runs in a `ConsoleApplication` context.
- **Web Cron** — a webhook URL (`System → Scheduled Tasks → Options →
  Webcron`) hit by `curl`/`wget` from a regular hosting cron job. Runs
  inside a full Joomla `SiteApplication` context.

**This plugin needs Web Cron**, not CLI cron. Phoca Cart's own code for
generating email/PDF invoice content relies on a full Joomla `Document`
object (`Factory::getApplication()->getDocument()`), which is only
available in a Site/Administrator web context — not in the CLI console
application. Running this task over CLI can prevent the customer
notification email from being sent.

Example cron entry (adjust interval to match the task's own):

```bash
curl -s -o /dev/null "https://YOURSITE/component/ajax?plugin=RunSchedulerWebcron&group=system&format=json&hash=YOUR_WEBCRON_HASH&id=YOUR_TASK_ID"
```

## Notes on Phoca Cart internals

A few things worth knowing if you're maintaining or extending this plugin
(see inline comments in `PcpInvoiceCancel.php` for details):

- **Invoice due date** isn't always stored on the order — it's only written
  once set explicitly; otherwise it must be derived from
  `order.date + invoice_due_date_days`. This plugin replicates Phoca Cart's
  own `PhocacartOrder::getInvoiceDueDate()` / `PhocacartDate::activeDatabaseDate()`
  logic rather than comparing the raw column.
- **UTC is forced** for the task's duration. Joomla forces PHP's default
  timezone to UTC on every normal web/admin request, but that isn't
  guaranteed for CLI/webcron triggers — without this, due-date comparisons
  can silently drift by the server's local UTC offset.
- **Status change sequence** mirrors Phoca Cart's own
  `site/models/cancellation.php::cancel()` (its "EU Right of Withdrawal"
  auto-cancel flow): `changeStatusInOrderTable()` →
  `changeStatus()` → `setHistory()`, each step isolated so a failure in the
  email/side-effects step never blocks the order history entry.
- **Known Phoca Cart core bug worked around here:**
  `PhocacartOrderStatus::changeStatus()`'s 11th parameter
  (`$emailSendFormat`, controls whether a PDF is attached to the
  notification email) defaults to the *string* `'99'`, but the internal
  fallback check compares against it with a *strict* `=== 99` (int). Since
  the types never match, the fallback to the order status' own "Attach PDF"
  setting never fires for any caller passing fewer than 11 arguments —
  which forces PDF attachment on unconditionally, regardless of what the
  status is actually configured to do. This plugin passes `0` explicitly
  for that parameter to avoid it. This bug isn't specific to this plugin —
  it affects any external integration calling `changeStatus()` with the
  short 3-argument form (payment gateway plugins included). Reported
  upstream to the Phoca Cart author.


## License

GNU General Public License v3.0 or later

## Author

[LeNix Dizajn Studio](https://lenix.rs)
