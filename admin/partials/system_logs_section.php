<?php
/** @var array $systemLogsFilters */
/** @var string|null $systemLogsError */
/** @var string $systemLogsRowsHtml */
/** @var bool $systemLogsHasMore */
/** @var int $systemLogsLimit */
/** @var string $systemLogsSummary */
/** @var int $systemLogsCount */
/** @var PDO $pdo */

$systemLogsFilters = $systemLogsFilters ?? systemLogsNormaliseFilters([]);
$systemLogsRowsHtml = $systemLogsRowsHtml ?? '';
$systemLogsSummary = $systemLogsSummary ?? '';
$systemLogsHasMore = $systemLogsHasMore ?? false;
$systemLogsLimit = $systemLogsLimit ?? 100;
$systemLogsCount = $systemLogsCount ?? 0;
$systemLogsError = $systemLogsError ?? null;
$systemLogsRange = $systemLogsFilters['range'] ?? '7d';
$systemLogsSearch = $systemLogsFilters['search'] ?? '';
$systemLogsEndpoint = 'api/system_logs.php';
?>
<?php if (!empty($systemLogsError)): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($systemLogsError); ?></div>
<?php endif; ?>
<div class="system-logs" data-system-logs data-system-logs-endpoint="<?php echo htmlspecialchars($systemLogsEndpoint); ?>" data-system-logs-limit="<?php echo (int) $systemLogsLimit; ?>">
    <div class="system-logs__controls">
        <div class="system-logs__control">
            <label for="systemLogsRange">Time Range</label>
            <select id="systemLogsRange" data-system-logs-range>
                <?php foreach (systemLogsAvailableRanges() as $value => $label): ?>
                    <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $value === $systemLogsRange ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="system-logs__control system-logs__control--search">
            <label for="systemLogsSearch">Search</label>
            <input type="search" id="systemLogsSearch" placeholder="Event, description, user…" value="<?php echo htmlspecialchars($systemLogsSearch); ?>" data-system-logs-search>
        </div>
        <div class="system-logs__control system-logs__control--actions">
            <button type="button" class="secondary-action" data-system-logs-refresh>
                <i class="fas fa-sync-alt"></i> Refresh
            </button>
        </div>
    </div>
    <div class="system-logs__status">
        <span data-system-logs-count><?php echo htmlspecialchars($systemLogsSummary); ?></span>
        <span class="system-logs__limit <?php echo $systemLogsHasMore ? '' : 'hidden'; ?>" data-system-logs-truncated>
            Showing the latest <?php echo (int) $systemLogsLimit; ?> entries.
        </span>
    </div>
    <div class="system-logs__feedback" data-system-logs-feedback role="status" aria-live="polite"></div>
    <div class="table-wrapper system-logs__table-wrapper">
        <table class="system-logs__table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Event</th>
                    <th>Details</th>
                    <th>Performed By</th>
                    <th>IP Address</th>
                    <th>Timestamp</th>
                </tr>
            </thead>
            <tbody data-system-logs-body>
                <?php echo $systemLogsRowsHtml; ?>
            </tbody>
        </table>
        <div class="system-logs__empty <?php echo $systemLogsCount > 0 ? 'hidden' : ''; ?>" data-system-logs-empty>
            No logs found for the selected filters.
        </div>
    </div>
</div>
