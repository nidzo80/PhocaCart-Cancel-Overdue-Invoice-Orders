<?php

/**
 * @package     LeNix.Plugin.Task.PcpInvoiceCancel
 * @copyright   Copyright (C) LeNix Dizajn Studio. All rights reserved.
 * @license     GNU General Public License version 2 or later
 *
 * Verified against PhocaCz/PhocaCart source (github.com/PhocaCz/PhocaCart, master,
 * Phoca Cart 6.1.x) on 2026-09-03:
 *  - #__phocacart_orders columns used: id, order_number, date, invoice_due_date,
 *    status_id, payment_date  (admin/install/sql/mysql/install.utf8.sql)
 *  - Due date resolution mirrors PhocacartOrder::getInvoiceDueDate() +
 *    PhocacartDate::activeDatabaseDate() (admin/libraries/phocacart/order/order.php,
 *    admin/libraries/phocacart/date/date.php)
 *  - Status change sequence mirrors site/models/cancellation.php::cancel(), which is
 *    Phoca Cart's own "EU Right of Withdrawal" auto-cancel flow:
 *      1. PhocacartOrderStatus::changeStatusInOrderTable()  - writes status_id + fires
 *         the onPhocaCartOrderStatusChange event
 *      2. PhocacartOrderStatus::changeStatus()               - stock, user group, points,
 *         download access, customer/vendor emails
 *      3. PhocacartOrderStatus::setHistory()                 - order history log entry
 *    (admin/libraries/phocacart/order/status.php, site/models/cancellation.php)
 *  - Forces UTC for the task's duration: Joomla forces PHP's default timezone to
 *    UTC on every normal web/admin request, but a CLI/webcron trigger may not -
 *    without this, due-date comparisons (ours and Phoca Cart's own
 *    getInvoiceDueDate()) can be skewed by the server's local UTC offset.
 *  - Works around a Phoca Cart core bug in changeStatus(): its 11th param
 *    ($emailSendFormat) defaults to the string '99', but the fallback check uses
 *    strict `=== 99` (int), so callers passing fewer than 11 args (this plugin,
 *    RaiAccept, the NLB Kombank plugin) always get attachPDF=true regardless of
 *    the status' actual "Attach PDF" setting. Worth reporting upstream to Jan.
 */

namespace LeNix\Plugin\Task\PcpInvoiceCancel\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status as TaskStatus;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;

final class PcpInvoiceCancel extends CMSPlugin implements SubscriberInterface
{
    use TaskPluginTrait;
    use DatabaseAwareTrait;

    private const TASKS_MAP = [
        'pcp.invoicecancel' => [
            'langConstPrefix' => 'PLG_TASK_PCPINVOICECANCEL_TASK',
            'form'            => 'task_params',
            'method'          => 'cancelOverdueInvoiceOrders',
        ],
    ];

    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return [
            'onTaskOptionsList'    => 'advertiseRoutines',
            'onExecuteTask'        => 'standardRoutineHandler',
            'onContentPrepareForm' => 'enhanceTaskItemForm',
        ];
    }

    protected function cancelOverdueInvoiceOrders(ExecuteTaskEvent $event): int
    {
        // Loads PhocacartOrder / PhocacartOrderStatus / PhocacartDate / PhocacartLog
        // (legacy "Phocacart"-prefixed classes, autoload-registered by the component).
        if (!\defined('JPATH_ADMINISTRATOR') || !\is_file(JPATH_ADMINISTRATOR . '/components/com_phocacart/libraries/bootstrap.php')) {
            Log::add('pcpinvoicecancel: com_phocacart not found, aborting.', Log::ERROR, 'task');
            return TaskStatus::KNOCKOUT;
        }
        require_once JPATH_ADMINISTRATOR . '/components/com_phocacart/libraries/bootstrap.php';

        // Force UTC for the duration of this task. Joomla always stores dates in
        // the DB as UTC and forces PHP's default timezone to UTC on every normal
        // web/admin request - but a CLI cron or webcron trigger may run under a
        // different php.ini `date.timezone`. Without this, naive DateTime parsing
        // (both ours below AND Phoca Cart's own PhocacartOrder::getInvoiceDueDate())
        // would misinterpret UTC-intended date strings as local time, silently
        // skewing the due-date comparison by the server's UTC offset.
        $previousTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');

        try {
            return $this->doCancelOverdueInvoiceOrders($event);
        } finally {
            date_default_timezone_set($previousTimezone);
        }
    }

    private function doCancelOverdueInvoiceOrders(ExecuteTaskEvent $event): int
    {
        $params = $event->getArgument('params');

        // NOTE: uses `?:` (empty-string safe), not `??` (null-only) - Joomla can
        // legitimately store these task params as an empty string "" rather than
        // leaving them unset, and "" is not caught by `??`.
        $pendingStates  = $this->parseIdList($this->paramOrDefault($params, 'order_state_pending', '1'));
        $cancelledState = (int) $this->paramOrDefault($params, 'order_state_cancelled', '0');
        $paymentIds     = $this->parseIdList($this->paramOrDefault($params, 'payment_ids', ''));
        $comment        = (string) $this->paramOrDefault($params, 'cancel_comment', 'Auto-cancelled: invoice due date exceeded');
        $batchLimit     = (int) $this->paramOrDefault($params, 'batch_limit', '200');
        $dryRun         = (bool) (int) $this->paramOrDefault($params, 'dry_run', '1');

        if ($batchLimit <= 0) {
            $batchLimit = 200;
        }

        if (!$pendingStates || $cancelledState <= 0) {
            Log::add('pcpinvoicecancel: pending/cancelled status IDs not configured, aborting.', Log::ERROR, 'task');
            return TaskStatus::KNOCKOUT;
        }

        $db = $this->getDatabase();

        // Candidate set: orders in a "still pending" status that have never been
        // marked paid. The actual due-date cutoff is evaluated in PHP below via
        // Phoca Cart's own PhocacartOrder::getInvoiceDueDate(), because
        // invoice_due_date is only populated when set manually - otherwise it has
        // to be derived from order date + the component's invoice_due_date_days.
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'order_number', 'date', 'invoice_due_date', 'status_id', 'order_token']))
            ->from($db->quoteName('#__phocacart_orders'))
            ->where($db->quoteName('payment_date') . ' IS NULL')
            ->whereIn($db->quoteName('status_id'), $pendingStates)
            ->order($db->quoteName('id') . ' ASC')
            ->setLimit($batchLimit);

        if ($paymentIds) {
            $query->whereIn($db->quoteName('payment_id'), $paymentIds);
        }

        $db->setQuery($query);

        try {
            $orders = $db->loadObjectList();
        } catch (\Throwable $e) {
            Log::add('pcpinvoicecancel: query failed - ' . $e->getMessage(), Log::ERROR, 'task');
            return TaskStatus::KNOCKOUT;
        }

        if (!$orders) {
            $this->logTask('No candidate orders found (none pending/unpaid).');
            return TaskStatus::OK;
        }

        $now       = new \DateTime('now', new \DateTimeZone('UTC'));
        $cancelled = 0;
        $skipped   = 0;
        $failed    = 0;

        foreach ($orders as $order) {
            $dueDate = \PhocacartDate::activeDatabaseDate($order->invoice_due_date)
                ? $order->invoice_due_date
                : \PhocacartOrder::getInvoiceDueDate((int) $order->id, $order->date);

            try {
                $due = new \DateTime($dueDate, new \DateTimeZone('UTC'));
            } catch (\Throwable $e) {
                $skipped++;
                continue;
            }

            if ($due >= $now) {
                // Not overdue yet.
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $this->logTask(sprintf(
                    '[DRY RUN] Would cancel order #%d (%s), due %s',
                    $order->id,
                    $order->order_number,
                    $due->format('Y-m-d H:i:s')
                ));
                continue;
            }

            try {
                $this->cancelOrder((int) $order->id, $cancelledState, $comment, (string) $order->order_token);
                $cancelled++;
            } catch (\Throwable $e) {
                $failed++;
                Log::add(sprintf(
                    'pcpinvoicecancel: failed to cancel order #%d - %s in %s:%d',
                    $order->id,
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine()
                ), Log::ERROR, 'task');
            }
        }

        $this->logTask(sprintf(
            'Checked %d candidate order(s): cancelled %d, not yet due %d, failed %d, dry_run=%s',
            \count($orders),
            $cancelled,
            $skipped,
            $failed,
            $dryRun ? 'yes' : 'no'
        ));

        return $failed > 0 ? TaskStatus::KNOCKOUT : TaskStatus::OK;
    }

    /**
     * Mirrors site/models/cancellation.php::cancel() - Phoca Cart's own
     * EU withdrawal auto-cancel flow - so stock, points, user group, download
     * access and customer/vendor notification emails all behave exactly as they
     * would for a manual status change, driven by whatever the target
     * "Cancelled" status has configured under Order Statuses.
     */
    private function cancelOrder(int $orderId, int $cancelledStatusId, string $comment, string $orderToken = ''): void
    {
        // 1) Write status_id on the order + fire onPhocaCartOrderStatusChange.
        //    Always runs unconditionally - this is the source of truth for the
        //    Orders list, independent of anything below.
        \PhocacartOrderStatus::changeStatusInOrderTable($orderId, $cancelledStatusId);

        // 2) Run the side effects tied to the target status' own configuration
        //    (stock movement, user group, points, download, customer/vendor email).
        //    $orderToken is passed as the order's own token so that
        //    PhocacartOrderStatus::canSendEmail() authorises sending the customer
        //    email regardless of whether the scheduler runs via a web cron
        //    (administrator app context) or CLI cron (no "administrator" client,
        //    so it falls through to the orderToken check).
        //
        //    Isolated in its own try/catch - same pattern already used in
        //    RaiAccept's ShopHelper::setOrderStatus() for the identical reason:
        //    a failure while rendering/sending the notification email must not
        //    prevent the order history entry (step 3) from being written, nor
        //    count as a failed cancellation - the status change itself already
        //    succeeded via step 1.
        $notified = 0;

        try {
            // Phoca Cart core bug workaround: changeStatus()'s 11th param
            // ($emailSendFormat) defaults to the STRING '99', but the internal
            // fallback check compares with strict `=== 99` (int), so it never
            // fires for callers passing fewer than 11 args - forcing
            // attachPDF=true unconditionally regardless of the status' real
            // "Attach PDF" setting (affects RaiAccept/Kombank too - worth
            // reporting to Jan). We pass 0 explicitly to force no PDF
            // attachment on auto-cancelled orders (there's no invoice to
            // attach anyway), bypassing the bug. The other params keep their
            // normal "read from status" sentinel values.
            $notified = (int) \PhocacartOrderStatus::changeStatus(
                $orderId,
                $cancelledStatusId,
                $orderToken,
                99,
                99,
                99,
                '99',
                '99',
                '99',
                '99',
                0
            );
        } catch (\Throwable $e) {
            Log::add(sprintf(
                'pcpinvoicecancel: changeStatus (notify/side-effects) failed for order #%d - %s in %s:%d',
                $orderId,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ), Log::ERROR, 'task');
        }

        // 3) Order history log entry, visible in the admin order view. Runs
        //    regardless of whether step 2 succeeded.
        try {
            \PhocacartOrderStatus::setHistory($orderId, $cancelledStatusId, $notified, $comment);
        } catch (\Throwable $e) {
            Log::add(sprintf(
                'pcpinvoicecancel: setHistory failed for order #%d - %s',
                $orderId,
                $e->getMessage()
            ), Log::ERROR, 'task');
        }

        \PhocacartLog::add(1, 'Invoice Due Date - Auto Cancel', $orderId, $comment);
    }

    /**
     * Like `$params->$key ?? $default`, but also falls back when the stored
     * value is an empty string - which `??` does not catch.
     */
    private function paramOrDefault($params, string $key, string $default): string
    {
        $value = $params->$key ?? '';
        $value = trim((string) $value);

        return $value !== '' ? $value : $default;
    }

    private function parseIdList($raw): array
    {
        return array_values(array_filter(array_map(
            static fn ($v) => (int) trim($v),
            explode(',', (string) $raw)
        ), static fn ($v) => $v > 0));
    }
}
