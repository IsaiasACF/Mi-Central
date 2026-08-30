<?php
declare(strict_types=1);

use App\Http\Csrf;
use App\Support\View;
use Modules\Expenses\ExpenseFormat;

$configuration = is_array($configuration ?? null) ? $configuration : [];
$activeTab = is_string($configuration['active_tab'] ?? null) ? (string) $configuration['active_tab'] : 'categories';
$categories = is_array($configuration['categories'] ?? null) ? $configuration['categories'] : [];
$activeCategories = is_array($configuration['active_categories'] ?? null) ? $configuration['active_categories'] : [];
$paymentMethods = is_array($configuration['payment_methods'] ?? null) ? $configuration['payment_methods'] : [];
$activePaymentMethods = is_array($configuration['active_payment_methods'] ?? null) ? $configuration['active_payment_methods'] : [];
$services = is_array($configuration['services'] ?? null) ? $configuration['services'] : [];
$activeServices = is_array($configuration['active_services'] ?? null) ? $configuration['active_services'] : [];
$paymentMethodTypeLabels = is_array($configuration['payment_method_type_labels'] ?? null) ? $configuration['payment_method_type_labels'] : [];
$month = is_array($month ?? null) ? $month : [];
$history = is_array($history ?? null) ? $history : [];
$activeMode = (string) ($month['active_tab'] ?? 'current');
$monthValue = (string) ($month['month_value'] ?? date('Y-m'));
$monthLabel = (string) ($month['month_label'] ?? '');
$previousMonth = (string) ($month['previous_month'] ?? $monthValue);
$nextMonth = (string) ($month['next_month'] ?? $monthValue);
$currentMonth = (string) ($month['current_month'] ?? $monthValue);
$today = (string) ($month['today'] ?? date('Y-m-d'));
$focusedExpenseId = (string) ($month['focused_expense_id'] ?? '');
$monthlyExpenses = is_array($month['items'] ?? null) ? $month['items'] : [];
$monthlySummaryExpenses = is_array($month['all_items'] ?? null) ? $month['all_items'] : $monthlyExpenses;
$monthSummary = is_array($month['summary'] ?? null) ? $month['summary'] : [];
$dashboardSummary = is_array($month['dashboard_summary'] ?? null) ? $month['dashboard_summary'] : [];
$filteredSummary = is_array($month['filtered_summary'] ?? null) ? $month['filtered_summary'] : [];
$filters = is_array($month['filters'] ?? null) ? $month['filters'] : [];
$sort = (string) ($month['sort'] ?? 'due');
$historyFilters = is_array($history['filters'] ?? null) ? $history['filters'] : [];
$historyRangeOptions = is_array($history['range_options'] ?? null) ? $history['range_options'] : [];
$historyMonthlyTotals = is_array($history['monthly_totals'] ?? null) ? $history['monthly_totals'] : [];
$historyCategoryBreakdown = is_array($history['category_breakdown'] ?? null) ? $history['category_breakdown'] : [];
$historyServiceBreakdown = is_array($history['service_breakdown'] ?? null) ? $history['service_breakdown'] : [];
$historyPaymentBreakdown = is_array($history['payment_method_breakdown'] ?? null) ? $history['payment_method_breakdown'] : [];
$historyStatusBreakdown = is_array($history['status_breakdown'] ?? null) ? $history['status_breakdown'] : [];
$historyMonthDetails = is_array($history['month_details'] ?? null) ? $history['month_details'] : [];
$historyCategorySeries = is_array($history['category_series'] ?? null) ? $history['category_series'] : [];
$historyServiceSeries = is_array($history['service_series'] ?? null) ? $history['service_series'] : [];

$defaultColor = '#2DD4BF';
$importTemplate = json_encode([
    'categories' => [
        [
            'name' => 'Servicios basicos',
            'color' => '#3366CC',
            'active' => true,
        ],
    ],
    'payment_methods' => [
        [
            'name' => 'Cuenta RUT',
            'type' => 'bank_account',
            'institution_name' => 'BancoEstado',
            'notes' => null,
            'active' => true,
        ],
    ],
    'services' => [
        [
            'name' => 'Aguas Andinas',
            'category' => 'Servicios basicos',
            'default_amount_clp' => null,
            'payment_methods' => ['Cuenta RUT'],
            'default_payment_method' => 'Cuenta RUT',
            'notes' => null,
            'active' => true,
        ],
    ],
    'expenses' => [
        [
            'period_month' => '2026-08',
            'description' => 'Aguas Andinas agosto',
            'service' => 'Aguas Andinas',
            'category' => 'Servicios basicos',
            'payment_method' => 'Cuenta RUT',
            'amount_clp' => null,
            'due_on' => '2026-08-15',
            'status' => 'pending',
            'paid_on' => null,
            'installment_current' => null,
            'installment_total' => null,
            'notes' => null,
        ],
        [
            'period_month' => '2026-08',
            'description' => 'Ajuste sin costo',
            'amount_clp' => 0,
            'category' => 'Servicios basicos',
            'status' => 'paid',
            'paid_on' => '2026-08-20',
        ],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

$contrastColor = static function (?string $hex): string {
    $hex = strtoupper(trim((string) $hex));

    if (preg_match('/\A#[0-9A-F]{6}\z/', $hex) !== 1) {
        return '#FFFFFF';
    }

    $red = hexdec(substr($hex, 1, 2));
    $green = hexdec(substr($hex, 3, 2));
    $blue = hexdec(substr($hex, 5, 2));
    $brightness = (($red * 299) + ($green * 587) + ($blue * 114)) / 1000;

    return $brightness > 150 ? '#111827' : '#FFFFFF';
};

$clp = static function (mixed $amount): string {
    return ExpenseFormat::clp($amount);
};

$methodLabel = static function (array $method, array $labels): string {
    $type = (string) ($method['type'] ?? 'other');
    $name = (string) ($method['name'] ?? '');
    $institution = trim((string) ($method['institution_name'] ?? ''));
    $label = $name . ' - ' . ($labels[$type] ?? 'Otro');

    return $institution !== '' ? $label . ' - ' . $institution : $label;
};

$serviceOptions = [];

foreach ($activeServices as $service) {
    $methods = [];
    $defaultPaymentId = '';

    foreach ((array) ($service['payment_methods'] ?? []) as $method) {
        if ((int) ($method['active'] ?? 0) !== 1) {
            continue;
        }

        $methodId = (string) ($method['id'] ?? '');
        $methods[] = [
            'id' => $methodId,
            'label' => $methodLabel($method, $paymentMethodTypeLabels),
        ];

        if ((int) ($method['is_default'] ?? 0) === 1) {
            $defaultPaymentId = $methodId;
        }
    }

    $serviceOptions[] = [
        'id' => (string) ($service['id'] ?? ''),
        'name' => (string) ($service['name'] ?? ''),
        'category_id' => $service['category_id'] === null ? '' : (string) $service['category_id'],
        'default_amount_clp' => $service['default_amount_clp'] === null ? '' : (string) $service['default_amount_clp'],
        'payment_methods' => $methods,
        'default_payment_method_id' => $defaultPaymentId,
    ];
}

$activePaymentOptions = array_map(
    static fn (array $method): array => [
        'id' => (string) ($method['id'] ?? ''),
        'label' => $methodLabel($method, $paymentMethodTypeLabels),
    ],
    $activePaymentMethods,
);

$jsonOptions = static function (mixed $value): string {
    return htmlspecialchars(json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR), ENT_QUOTES, 'UTF-8');
};

$filterValue = static fn (string $name): string => is_string($filters[$name] ?? null) ? (string) $filters[$name] : '';
$historyFilterValue = static fn (string $name): string => !isset($historyFilters[$name]) || $historyFilters[$name] === null ? '' : (string) $historyFilters[$name];

$monthUrl = static function (string $targetMonth, array $extra = []): string {
    $query = array_filter(['section' => 'expenses', 'month' => $targetMonth] + $extra, static fn (mixed $value): bool => $value !== '' && $value !== null);

    return '/index.php?' . http_build_query($query);
};

$statusLabels = [
    'pending' => 'Pendiente',
    'paid' => 'Pagado',
    'overdue' => 'Vencido',
    'cancelled' => 'Cancelado',
];

$dateLabel = static function (?string $date): string {
    if ($date === null || $date === '') {
        return '';
    }

    $parts = explode('-', $date);

    if (count($parts) !== 3) {
        return $date;
    }

    $months = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    $monthIndex = max(1, min(12, (int) $parts[1]));

    return ltrim($parts[2], '0') . ' ' . $months[$monthIndex - 1];
};

$relativeDueLabel = static function (array $expense) use ($today, $dateLabel): string {
    $dueOn = is_string($expense['due_on'] ?? null) ? (string) $expense['due_on'] : '';

    if ($dueOn === '' || (string) ($expense['status'] ?? '') !== 'pending') {
        return $dueOn === '' ? '' : 'Vence ' . $dateLabel($dueOn);
    }

    if ($dueOn === $today) {
        return 'Vence hoy';
    }

    $dueDate = DateTimeImmutable::createFromFormat('!Y-m-d', $dueOn);
    $todayDate = DateTimeImmutable::createFromFormat('!Y-m-d', $today);

    if ($dueDate instanceof DateTimeImmutable && $todayDate instanceof DateTimeImmutable && $dueOn < $today) {
        $days = (int) $todayDate->diff($dueDate)->format('%a');

        return 'Vencido hace ' . $days . ' ' . ($days === 1 ? 'dia' : 'dias');
    }

    return 'Vence ' . $dateLabel($dueOn);
};

$amountLabel = static fn (mixed $amount): string => $amount === null || $amount === '' ? 'Monto pendiente' : ExpenseFormat::clp($amount);

$dueTone = static function (array $expense) use ($today): string {
    $dueOn = is_string($expense['due_on'] ?? null) ? (string) $expense['due_on'] : '';

    if ($dueOn === '' || (string) ($expense['status'] ?? '') !== 'pending') {
        return 'none';
    }

    if ($dueOn < $today) {
        return 'overdue';
    }

    $dueDate = DateTimeImmutable::createFromFormat('!Y-m-d', $dueOn);
    $todayDate = DateTimeImmutable::createFromFormat('!Y-m-d', $today);

    if (!$dueDate instanceof DateTimeImmutable || !$todayDate instanceof DateTimeImmutable) {
        return 'none';
    }

    $days = (int) $todayDate->diff($dueDate)->format('%r%a');

    if ($days === 0) {
        return 'today';
    }

    if ($days === 1) {
        return 'tomorrow';
    }

    if ($days <= 3) {
        return 'soon';
    }

    if ($days <= 7) {
        return 'week';
    }

    return 'later';
};

$hasActiveFilters = $filterValue('status') !== ''
    || $filterValue('category_id') !== ''
    || $filterValue('service_id') !== ''
    || $filterValue('payment_method_id') !== ''
    || $filterValue('search') !== '';
$totalKnownLabel = (int) ($dashboardSummary['unknown_amount_count'] ?? 0) > 0 ? 'Total conocido' : 'Total del mes';
$dashboardExpenseCount = (int) ($dashboardSummary['expense_count'] ?? 0);
$paidPercentage = max(0, min(100, (int) ($dashboardSummary['paid_percentage'] ?? 0)));
$categoryBreakdown = is_array($dashboardSummary['category_breakdown'] ?? null) ? $dashboardSummary['category_breakdown'] : [];
$paymentBreakdown = is_array($dashboardSummary['payment_method_breakdown'] ?? null) ? $dashboardSummary['payment_method_breakdown'] : [];
$upcomingDue = is_array($dashboardSummary['upcoming_due'] ?? null) ? $dashboardSummary['upcoming_due'] : [];
$overdueItems = is_array($dashboardSummary['overdue_items'] ?? null) ? $dashboardSummary['overdue_items'] : [];
$historyHasActiveFilters = $historyFilterValue('category_id') !== ''
    || $historyFilterValue('service_id') !== ''
    || $historyFilterValue('payment_method_id') !== '';
$historySelectedCategory = '';

foreach ($categories as $category) {
    if ($historyFilterValue('category_id') === (string) ($category['id'] ?? '')) {
        $historySelectedCategory = (string) ($category['name'] ?? '');
        break;
    }
}

$historySelectedService = '';

foreach ($services as $service) {
    if ($historyFilterValue('service_id') === (string) ($service['id'] ?? '')) {
        $historySelectedService = (string) ($service['name'] ?? '');
        break;
    }
}

$servicePaymentIds = static function (array $service): array {
    $ids = [];

    foreach ((array) ($service['payment_methods'] ?? []) as $method) {
        $ids[] = (string) ($method['id'] ?? '');
    }

    return $ids;
};

$serviceDefaultPaymentId = static function (array $service): string {
    foreach ((array) ($service['payment_methods'] ?? []) as $method) {
        if ((int) ($method['is_default'] ?? 0) === 1) {
            return (string) ($method['id'] ?? '');
        }
    }

    return '';
};

$recurringSummary = static function (?array $rule): string {
    if ($rule === null) {
        return 'No recurrente';
    }

    $day = (int) ($rule['day_of_month'] ?? 0);
    $interval = (int) ($rule['interval_value'] ?? 1);
    $prefix = $interval === 1 ? 'Mensual' : 'Cada ' . $interval . ' meses';
    $status = (int) ($rule['active'] ?? 0) === 1 ? '' : ' (inactiva)';

    return $prefix . ' · dia ' . $day . $status;
};

$adjustmentPeriodLabel = static function (?string $periodMonth): string {
    if ($periodMonth === null || $periodMonth === '') {
        return '';
    }

    $parts = explode('-', $periodMonth);

    if (count($parts) < 2) {
        return $periodMonth;
    }

    $months = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    $monthIndex = max(1, min(12, (int) $parts[1]));

    return $months[$monthIndex - 1] . ' ' . $parts[0];
};
?>
<section
    class="expenses-page"
    aria-labelledby="page-title"
    data-expenses-page
    data-api-url="/api/expenses/configuration.php"
    data-monthly-api-url="/api/expenses/expenses.php"
    data-import-api-url="/api/expenses/import.php"
    data-csrf-token="<?= View::escape(Csrf::token()) ?>"
    data-today="<?= View::escape($today) ?>"
    data-month-value="<?= View::escape($monthValue) ?>"
    data-service-options="<?= $jsonOptions($serviceOptions) ?>"
    data-payment-options="<?= $jsonOptions($activePaymentOptions) ?>"
>
    <section class="page-heading expenses-heading">
        <div>
            <p class="eyebrow">Fase 10</p>
            <h1 id="page-title">Gastos</h1>
            <p class="muted">Registra tus gastos mensuales y configura los elementos que usas habitualmente.</p>
        </div>
    </section>

    <nav class="organization-tabs expenses-tabs" aria-label="Gastos">
        <a class="organization-tab <?= $activeMode === 'current' ? 'is-active' : '' ?>" href="/index.php?section=expenses&amp;month=<?= View::escape($monthValue) ?>" <?= $activeMode === 'current' ? 'aria-current="page"' : '' ?>>Mes actual</a>
        <a class="organization-tab <?= $activeMode === 'history' ? 'is-active' : '' ?>" href="/index.php?section=expenses&amp;tab=history" <?= $activeMode === 'history' ? 'aria-current="page"' : '' ?>>Historial</a>
        <a class="organization-tab <?= $activeMode === 'settings' && $activeTab === 'categories' ? 'is-active' : '' ?>" href="/index.php?section=expenses&amp;tab=categories" <?= $activeMode === 'settings' && $activeTab === 'categories' ? 'aria-current="page"' : '' ?>>Categor&iacute;as</a>
        <a class="organization-tab <?= $activeMode === 'settings' && $activeTab === 'payment-methods' ? 'is-active' : '' ?>" href="/index.php?section=expenses&amp;tab=payment-methods" <?= $activeMode === 'settings' && $activeTab === 'payment-methods' ? 'aria-current="page"' : '' ?>>Medios de pago</a>
        <a class="organization-tab <?= $activeMode === 'settings' && $activeTab === 'services' ? 'is-active' : '' ?>" href="/index.php?section=expenses&amp;tab=services" <?= $activeMode === 'settings' && $activeTab === 'services' ? 'aria-current="page"' : '' ?>>Servicios</a>
        <a class="organization-tab <?= $activeMode === 'settings' && $activeTab === 'import' ? 'is-active' : '' ?>" href="/index.php?section=expenses&amp;tab=import" <?= $activeMode === 'settings' && $activeTab === 'import' ? 'aria-current="page"' : '' ?>>Importar JSON</a>
    </nav>

    <p class="task-message" role="status" data-expense-message hidden></p>

    <?php if ($activeMode === 'current'): ?>
        <section class="tasks-panel expenses-panel" data-monthly-expenses-page aria-label="Gastos del mes">
            <section class="expense-payments-overview" aria-labelledby="expense-payments-overview-title">
                <div class="expense-summary-panel__header">
                    <div>
                        <p class="eyebrow">Resumen</p>
                        <h2 id="expense-payments-overview-title">Pagos de <?= View::escape(strtolower($monthLabel)) ?></h2>
                    </div>
                    <span><?= View::escape((string) count($monthlySummaryExpenses)) ?> pago<?= count($monthlySummaryExpenses) === 1 ? '' : 's' ?></span>
                </div>
                <?php if ($monthlySummaryExpenses === []): ?>
                    <p class="muted">No hay pagos registrados para este mes.</p>
                <?php else: ?>
                    <div class="expense-payments-overview__list">
                        <?php foreach ($monthlySummaryExpenses as $expense): ?>
                            <?php
                            $overviewDerivedStatus = (string) ($expense['derived_status'] ?? $expense['status'] ?? 'pending');
                            $overviewDueTone = $dueTone($expense);
                            $overviewStatusText = $overviewDueTone === 'today' ? 'Vence hoy' : ($statusLabels[$overviewDerivedStatus] ?? 'Pendiente');
                            $overviewStatusClass = $overviewDueTone === 'today' ? 'today' : $overviewDerivedStatus;
                            $overviewMeta = array_filter([
                                $relativeDueLabel($expense),
                                trim((string) ($expense['payment_method_name'] ?? '')),
                            ], static fn (string $value): bool => $value !== '');
                            ?>
                            <article class="expense-payment-row">
                                <div>
                                    <strong><?= View::escape($expense['description'] ?? '') ?></strong>
                                    <?php if ($overviewMeta !== []): ?>
                                        <span><?= View::escape(implode(' · ', $overviewMeta)) ?></span>
                                    <?php endif; ?>
                                </div>
                                <strong><?= View::escape($amountLabel($expense['amount_clp'] ?? null)) ?></strong>
                                <span class="expense-status-pill expense-status-pill--<?= View::escape($overviewStatusClass) ?>"><?= View::escape($overviewStatusText) ?></span>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <div class="expense-month-toolbar">
                <div>
                    <p class="eyebrow">Mes seleccionado</p>
                    <h2><?= View::escape($monthLabel) ?></h2>
                </div>
                <div class="expense-month-toolbar__actions">
                    <a class="button button--secondary" href="<?= View::escape($monthUrl($previousMonth)) ?>">Mes anterior</a>
                    <a class="button button--secondary" href="<?= View::escape($monthUrl($currentMonth)) ?>">Mes actual</a>
                    <a class="button button--secondary" href="<?= View::escape($monthUrl($nextMonth)) ?>">Mes siguiente</a>
                    <button class="button button--primary" type="button" data-monthly-expense-open="expense">Nuevo gasto</button>
                </div>
            </div>

            <?php if ($dashboardExpenseCount > 0): ?>
                <div class="expense-kpi-grid" aria-label="Resumen mensual">
                    <article class="expense-kpi-card">
                        <span><?= View::escape($totalKnownLabel) ?></span>
                        <strong><?= View::escape($clp($dashboardSummary['total_known_clp'] ?? 0)) ?></strong>
                        <?php if ((int) ($dashboardSummary['unknown_amount_count'] ?? 0) > 0): ?>
                            <small><?= View::escape((string) $dashboardSummary['unknown_amount_count']) ?> gasto<?= (int) ($dashboardSummary['unknown_amount_count'] ?? 0) === 1 ? '' : 's' ?> a&uacute;n sin monto</small>
                        <?php endif; ?>
                    </article>
                    <article class="expense-kpi-card expense-kpi-card--paid">
                        <span>Pagado</span>
                        <strong><?= View::escape($clp($dashboardSummary['paid_known_clp'] ?? 0)) ?></strong>
                    </article>
                    <article class="expense-kpi-card">
                        <span>Pendiente</span>
                        <strong><?= View::escape($clp($dashboardSummary['pending_known_clp'] ?? 0)) ?></strong>
                    </article>
                    <article class="expense-kpi-card expense-kpi-card--overdue">
                        <span>Vencido</span>
                        <strong><?= View::escape($clp($dashboardSummary['overdue_known_clp'] ?? 0)) ?></strong>
                    </article>
                </div>

                <section class="expense-progress-card" aria-label="Avance de pago mensual">
                    <div class="expense-progress-card__header">
                        <div>
                            <p class="eyebrow">Avance de pago</p>
                            <h3>Pagado <?= View::escape((string) $paidPercentage) ?>%</h3>
                        </div>
                        <span><?= View::escape($clp($dashboardSummary['paid_known_clp'] ?? 0)) ?> de <?= View::escape($clp($dashboardSummary['total_known_clp'] ?? 0)) ?></span>
                    </div>
                    <div class="expense-progress-bar" role="img" aria-label="Pagado <?= View::escape((string) $paidPercentage) ?> por ciento de los montos conocidos">
                        <span style="width: <?= View::escape((string) $paidPercentage) ?>%;"></span>
                    </div>
                </section>

                <div class="expense-summary-grid">
                    <section class="expense-summary-panel" aria-labelledby="expense-category-breakdown-title">
                        <div class="expense-summary-panel__header">
                            <h3 id="expense-category-breakdown-title">Distribuci&oacute;n por categor&iacute;a</h3>
                        </div>
                        <?php if ($categoryBreakdown === []): ?>
                            <p class="muted">Sin montos conocidos por categor&iacute;a.</p>
                        <?php else: ?>
                            <div class="expense-breakdown-list">
                                <?php foreach ($categoryBreakdown as $row): ?>
                                    <?php $percentage = max(0, min(100, (int) ($row['percentage'] ?? 0))); ?>
                                    <article class="expense-breakdown-row">
                                        <div class="expense-breakdown-row__top">
                                            <span><?= View::escape($row['name'] ?? 'Sin categoria') ?></span>
                                            <strong><?= View::escape($clp($row['known_amount_clp'] ?? 0)) ?></strong>
                                        </div>
                                        <p><?= View::escape((string) $percentage) ?>% · <?= View::escape((string) ($row['expense_count'] ?? 0)) ?> gasto<?= (int) ($row['expense_count'] ?? 0) === 1 ? '' : 's' ?><?= (int) ($row['unknown_amount_count'] ?? 0) > 0 ? ' · ' . View::escape((string) $row['unknown_amount_count']) . ' monto pendiente' : '' ?></p>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section class="expense-summary-panel" aria-labelledby="expense-payment-breakdown-title">
                        <div class="expense-summary-panel__header">
                            <h3 id="expense-payment-breakdown-title">Distribuci&oacute;n por medio de pago</h3>
                        </div>
                        <?php if ($paymentBreakdown === []): ?>
                            <p class="muted">Sin medios registrados este mes.</p>
                        <?php else: ?>
                            <div class="expense-breakdown-list">
                                <?php foreach ($paymentBreakdown as $row): ?>
                                    <?php $percentage = max(0, min(100, (int) ($row['percentage'] ?? 0))); ?>
                                    <article class="expense-breakdown-row">
                                        <div class="expense-breakdown-row__top">
                                            <span><?= View::escape($row['name'] ?? 'Sin medio definido') ?></span>
                                            <strong><?= View::escape($clp($row['known_amount_clp'] ?? 0)) ?></strong>
                                        </div>
                                        <p><?= View::escape((string) $percentage) ?>% · <?= View::escape((string) ($row['expense_count'] ?? 0)) ?> gasto<?= (int) ($row['expense_count'] ?? 0) === 1 ? '' : 's' ?><?= (int) ($row['unknown_amount_count'] ?? 0) > 0 ? ' · ' . View::escape((string) $row['unknown_amount_count']) . ' monto pendiente' : '' ?></p>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                </div>

                <?php if ($overdueItems !== []): ?>
                    <section class="expense-due-panel expense-due-panel--overdue" aria-labelledby="expense-overdue-title">
                        <div class="expense-summary-panel__header">
                            <h3 id="expense-overdue-title">Vencidos</h3>
                        </div>
                        <div class="expense-due-list">
                            <?php foreach ($overdueItems as $expense): ?>
                                <article class="expense-due-item">
                                    <span><?= View::escape($dateLabel(is_string($expense['due_on'] ?? null) ? (string) $expense['due_on'] : null)) ?></span>
                                    <strong><?= View::escape($expense['description'] ?? '') ?></strong>
                                    <em><?= View::escape($amountLabel($expense['amount_clp'] ?? null)) ?></em>
                                    <small><?= View::escape($relativeDueLabel($expense)) ?></small>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <section class="expense-due-panel" aria-labelledby="expense-upcoming-title">
                    <div class="expense-summary-panel__header">
                        <h3 id="expense-upcoming-title">Pr&oacute;ximos vencimientos</h3>
                    </div>
                    <?php if ($upcomingDue === []): ?>
                        <p class="muted">No quedan vencimientos pendientes con fecha en este mes.</p>
                    <?php else: ?>
                        <div class="expense-due-list">
                            <?php foreach ($upcomingDue as $expense): ?>
                                <article class="expense-due-item expense-due-item--<?= View::escape($dueTone($expense)) ?>">
                                    <span><?= View::escape($dateLabel(is_string($expense['due_on'] ?? null) ? (string) $expense['due_on'] : null)) ?></span>
                                    <strong><?= View::escape($expense['description'] ?? '') ?></strong>
                                    <em><?= View::escape($amountLabel($expense['amount_clp'] ?? null)) ?></em>
                                    <small><?= View::escape($relativeDueLabel($expense)) ?></small>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <form class="task-filters expenses-month-filters" method="get" action="/index.php">
                <input type="hidden" name="section" value="expenses">
                <input type="hidden" name="month" value="<?= View::escape($monthValue) ?>">
                <label>
                    Estado
                    <select name="status">
                        <option value="">Todos</option>
                        <?php foreach ($statusLabels as $status => $label): ?>
                            <option value="<?= View::escape($status) ?>" <?= $filterValue('status') === $status ? 'selected' : '' ?>><?= View::escape($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Categor&iacute;a
                    <select name="category_id">
                        <option value="">Todas</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= View::escape((string) ($category['id'] ?? '')) ?>" <?= $filterValue('category_id') === (string) ($category['id'] ?? '') ? 'selected' : '' ?>>
                                <?= View::escape($category['name'] ?? '') ?><?= (int) ($category['active'] ?? 0) === 1 ? '' : ' (inactiva)' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Servicio
                    <select name="service_id">
                        <option value="">Todos</option>
                        <?php foreach ($services as $service): ?>
                            <option value="<?= View::escape((string) ($service['id'] ?? '')) ?>" <?= $filterValue('service_id') === (string) ($service['id'] ?? '') ? 'selected' : '' ?>>
                                <?= View::escape($service['name'] ?? '') ?><?= (int) ($service['active'] ?? 0) === 1 ? '' : ' (inactivo)' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Medio
                    <select name="payment_method_id">
                        <option value="">Todos</option>
                        <?php foreach ($paymentMethods as $method): ?>
                            <option value="<?= View::escape((string) ($method['id'] ?? '')) ?>" <?= $filterValue('payment_method_id') === (string) ($method['id'] ?? '') ? 'selected' : '' ?>>
                                <?= View::escape($methodLabel($method, $paymentMethodTypeLabels)) ?><?= (int) ($method['active'] ?? 0) === 1 ? '' : ' (inactivo)' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Buscar
                    <input type="search" name="search" value="<?= View::escape($filterValue('search')) ?>" placeholder="Descripcion, servicio, categoria, medio o notas">
                </label>
                <label>
                    Ordenar por
                    <select name="sort">
                        <option value="due" <?= $sort === 'due' ? 'selected' : '' ?>>Vencimiento</option>
                        <option value="amount" <?= $sort === 'amount' ? 'selected' : '' ?>>Monto</option>
                        <option value="service" <?= $sort === 'service' ? 'selected' : '' ?>>Servicio</option>
                        <option value="status" <?= $sort === 'status' ? 'selected' : '' ?>>Estado</option>
                    </select>
                </label>
                <div class="task-form__actions">
                    <button class="button button--secondary" type="submit">Filtrar</button>
                    <a class="button button--secondary" href="<?= View::escape($monthUrl($monthValue)) ?>">Limpiar</a>
                </div>
            </form>

            <div class="expense-list-heading">
                <div>
                    <h3>Listado del mes</h3>
                    <p class="muted">Los filtros ajustan este listado; el resumen superior siempre muestra todo <?= View::escape(strtolower($monthLabel)) ?>.</p>
                </div>
            </div>

            <?php if ($hasActiveFilters): ?>
                <div class="expense-filter-summary">
                    <p>Resultados: <strong><?= View::escape((string) count($monthlyExpenses)) ?></strong> gasto<?= count($monthlyExpenses) === 1 ? '' : 's' ?> · <strong><?= View::escape($clp($filteredSummary['total_amount_clp'] ?? 0)) ?></strong> conocido<?= (int) ($filteredSummary['unknown_amount_count'] ?? 0) > 0 ? '' : '.' ?></p>
                <?php if ((int) ($filteredSummary['unknown_amount_count'] ?? 0) > 0): ?>
                    <p><?= View::escape((string) $filteredSummary['unknown_amount_count']) ?> gasto<?= (int) ($filteredSummary['unknown_amount_count'] ?? 0) === 1 ? '' : 's' ?> filtrado<?= (int) ($filteredSummary['unknown_amount_count'] ?? 0) === 1 ? '' : 's' ?> con monto pendiente.</p>
                <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($monthlyExpenses === []): ?>
                <div class="expenses-empty-state">
                    <h3>No tienes gastos registrados en <?= View::escape(strtolower($monthLabel)) ?>.</h3>
                    <p>Tus gastos recurrentes aparecer&aacute;n autom&aacute;ticamente cuando corresponda.</p>
                    <div class="task-form__actions">
                        <button class="button button--primary" type="button" data-monthly-expense-open="expense">Nuevo gasto</button>
                        <a class="button button--secondary" href="/index.php?section=expenses&amp;tab=categories">Configuraci&oacute;n</a>
                    </div>
                </div>
            <?php else: ?>
                <div class="expenses-card-list">
                    <?php foreach ($monthlyExpenses as $expense): ?>
                        <?php
                        $derivedStatus = (string) ($expense['derived_status'] ?? $expense['status'] ?? 'pending');
                        $expenseDueTone = $dueTone($expense);
                        $statusText = $expenseDueTone === 'today' ? 'Vence hoy' : ($statusLabels[$derivedStatus] ?? 'Pendiente');
                        $statusClass = $expenseDueTone === 'today' ? 'today' : $derivedStatus;
                        $categoryName = trim((string) ($expense['category_name'] ?? ''));
                        $categoryColor = is_string($expense['category_color'] ?? null) && $expense['category_color'] !== ''
                            ? strtoupper((string) $expense['category_color'])
                            : $defaultColor;
                        $categoryTextColor = $contrastColor($categoryColor);
                        $installment = $expense['installment_current'] !== null && $expense['installment_total'] !== null
                            ? 'Cuota ' . (int) $expense['installment_current'] . '/' . (int) $expense['installment_total']
                            : '';
                        $dueText = $relativeDueLabel($expense);
                        $paidText = is_string($expense['paid_on'] ?? null) && $expense['paid_on'] !== ''
                            ? 'Pagado ' . $dateLabel((string) $expense['paid_on'])
                            : '';
                        ?>
                        <article
                            id="expense-<?= View::escape((string) ($expense['id'] ?? '')) ?>"
                            class="expense-month-card expense-month-card--<?= View::escape($derivedStatus) ?> expense-month-card--due-<?= View::escape($expenseDueTone) ?><?= $focusedExpenseId !== '' && $focusedExpenseId === (string) ($expense['id'] ?? '') ? ' is-focused' : '' ?>"
                            data-monthly-expense-card
                            data-id="<?= View::escape($expense['id'] ?? '') ?>"
                            data-service-id="<?= View::escape($expense['service_id'] ?? '') ?>"
                            data-category-id="<?= View::escape($expense['category_id'] ?? '') ?>"
                            data-description="<?= View::escape($expense['description'] ?? '') ?>"
                            data-amount-clp="<?= View::escape($expense['amount_clp'] ?? '') ?>"
                            data-installment-current="<?= View::escape($expense['installment_current'] ?? '') ?>"
                            data-installment-total="<?= View::escape($expense['installment_total'] ?? '') ?>"
                            data-due-on="<?= View::escape($expense['due_on'] ?? '') ?>"
                            data-paid-on="<?= View::escape($expense['paid_on'] ?? '') ?>"
                            data-payment-method-id="<?= View::escape($expense['payment_method_id'] ?? '') ?>"
                            data-status="<?= View::escape($expense['status'] ?? '') ?>"
                            data-notes="<?= View::escape($expense['notes'] ?? '') ?>"
                        >
                            <div class="expense-month-card__main">
                                <div>
                                    <h3><?= View::escape($expense['description'] ?? '') ?></h3>
                                    <div class="expense-meta">
                                        <?php if ($categoryName !== ''): ?>
                                            <span class="expense-category-chip" style="--expense-chip-bg: <?= View::escape($categoryColor) ?>; --expense-chip-text: <?= View::escape($categoryTextColor) ?>;"><?= View::escape($categoryName) ?></span>
                                        <?php endif; ?>
                                        <span><?= $expense['amount_clp'] === null ? 'Monto pendiente' : View::escape($clp($expense['amount_clp'])) ?></span>
                                        <?php if ($expense['recurring_rule_id'] !== null): ?>
                                            <span>Recurrente</span>
                                        <?php endif; ?>
                                        <?php if ($installment !== ''): ?>
                                            <span><?= View::escape($installment) ?></span>
                                        <?php endif; ?>
                                        <?php if ($dueText !== ''): ?>
                                            <span><?= View::escape($dueText) ?></span>
                                        <?php endif; ?>
                                        <?php if ($paidText !== ''): ?>
                                            <span><?= View::escape($paidText) ?></span>
                                        <?php endif; ?>
                                        <?php if (trim((string) ($expense['payment_method_name'] ?? '')) !== ''): ?>
                                            <span><?= View::escape($expense['payment_method_name']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <span class="expense-status-pill expense-status-pill--<?= View::escape($statusClass) ?>"><?= View::escape($statusText) ?></span>
                                <?php if (trim((string) ($expense['notes'] ?? '')) !== ''): ?>
                                    <p class="expense-month-card__notes"><?= View::escape($expense['notes']) ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="expense-config-card__actions">
                                <button class="button button--secondary" type="button" data-monthly-expense-open="expense-edit">Editar</button>
                                <?php if ((string) ($expense['status'] ?? '') === 'pending'): ?>
                                    <button class="button button--primary" type="button" data-monthly-expense-action="mark-paid">Marcar pagado</button>
                                    <button class="button button--danger" type="button" data-monthly-expense-action="cancel">Cancelar</button>
                                <?php elseif ((string) ($expense['status'] ?? '') === 'paid'): ?>
                                    <button class="button button--secondary" type="button" data-monthly-expense-action="reopen">Volver a pendiente</button>
                                <?php else: ?>
                                    <button class="button button--secondary" type="button" data-monthly-expense-action="reactivate">Reactivar</button>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php elseif ($activeMode === 'history'): ?>
        <section class="tasks-panel expenses-panel expense-history" aria-label="Historial de gastos">
            <div class="expense-month-toolbar">
                <div>
                    <p class="eyebrow">Analisis historico</p>
                    <h2>Historial de gastos</h2>
                    <p class="muted">Revisa y compara tus gastos a lo largo del tiempo.</p>
                </div>
            </div>

            <form class="task-filters expense-history-filters" method="get" action="/index.php">
                <input type="hidden" name="section" value="expenses">
                <input type="hidden" name="tab" value="history">
                <label>
                    Periodo
                    <select name="range">
                        <?php foreach ($historyRangeOptions as $rangeValue => $rangeLabel): ?>
                            <option value="<?= View::escape((string) $rangeValue) ?>" <?= (string) ($history['range'] ?? '') === (string) $rangeValue ? 'selected' : '' ?>><?= View::escape((string) $rangeLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Categor&iacute;a
                    <select name="category_id">
                        <option value="">Todas</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= View::escape((string) ($category['id'] ?? '')) ?>" <?= $historyFilterValue('category_id') === (string) ($category['id'] ?? '') ? 'selected' : '' ?>>
                                <?= View::escape($category['name'] ?? '') ?><?= (int) ($category['active'] ?? 0) === 1 ? '' : ' (inactiva)' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Servicio
                    <select name="service_id">
                        <option value="">Todos</option>
                        <?php foreach ($services as $service): ?>
                            <option value="<?= View::escape((string) ($service['id'] ?? '')) ?>" <?= $historyFilterValue('service_id') === (string) ($service['id'] ?? '') ? 'selected' : '' ?>>
                                <?= View::escape($service['name'] ?? '') ?><?= (int) ($service['active'] ?? 0) === 1 ? '' : ' (inactivo)' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Medio
                    <select name="payment_method_id">
                        <option value="">Todos</option>
                        <?php foreach ($paymentMethods as $method): ?>
                            <option value="<?= View::escape((string) ($method['id'] ?? '')) ?>" <?= $historyFilterValue('payment_method_id') === (string) ($method['id'] ?? '') ? 'selected' : '' ?>>
                                <?= View::escape($methodLabel($method, $paymentMethodTypeLabels)) ?><?= (int) ($method['active'] ?? 0) === 1 ? '' : ' (inactivo)' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="task-form__actions">
                    <button class="button button--secondary" type="submit">Aplicar</button>
                    <a class="button button--secondary" href="/index.php?section=expenses&amp;tab=history">Limpiar</a>
                </div>
            </form>

            <?php if (!(bool) ($history['has_any_data'] ?? false)): ?>
                <div class="expenses-empty-state">
                    <h3>Todav&iacute;a no hay historial suficiente para analizar.</h3>
                    <p>Registra gastos durante varios meses para comenzar a ver tendencias.</p>
                    <div class="task-form__actions">
                        <a class="button button--primary" href="<?= View::escape($monthUrl($currentMonth)) ?>">Ir al mes actual</a>
                    </div>
                </div>
            <?php else: ?>
                <?php if ($historyHasActiveFilters): ?>
                    <div class="expense-filter-summary">
                        <p>Analisis filtrado para <?= View::escape((string) ($history['range_label'] ?? '')) ?>.</p>
                    </div>
                <?php endif; ?>

                <?php if ((int) ($history['monthly_unknown_count'] ?? 0) > 0): ?>
                    <div class="expense-history-partial">
                        <strong>Datos parciales</strong>
                        <span><?= View::escape((string) ($history['monthly_unknown_count'] ?? 0)) ?> gasto<?= (int) ($history['monthly_unknown_count'] ?? 0) === 1 ? '' : 's' ?> del periodo a&uacute;n no tiene<?= (int) ($history['monthly_unknown_count'] ?? 0) === 1 ? '' : 'n' ?> monto. Los totales usan solo montos conocidos.</span>
                    </div>
                <?php endif; ?>

                <div class="expense-kpi-grid" aria-label="Resumen historico">
                    <article class="expense-kpi-card">
                        <span>Total registrado</span>
                        <strong><?= View::escape($clp($history['total_known_clp'] ?? 0)) ?></strong>
                        <small>Montos conocidos no cancelados</small>
                    </article>
                    <article class="expense-kpi-card expense-kpi-card--paid">
                        <span>Total pagado</span>
                        <strong><?= View::escape($clp($history['total_paid_clp'] ?? 0)) ?></strong>
                    </article>
                    <article class="expense-kpi-card">
                        <span>Promedio mensual</span>
                        <strong><?= View::escape($clp($history['average_monthly_clp'] ?? 0)) ?></strong>
                        <small><?= View::escape((string) ($history['months_included'] ?? 0)) ?> meses incluidos</small>
                    </article>
                    <article class="expense-kpi-card">
                        <span>Mes con mayor gasto</span>
                        <?php $highestMonth = is_array($history['highest_month'] ?? null) ? $history['highest_month'] : null; ?>
                        <strong><?= $highestMonth === null ? 'Sin datos' : View::escape($clp($highestMonth['known_amount_clp'] ?? 0)) ?></strong>
                        <?php if ($highestMonth !== null): ?>
                            <small><?= View::escape($highestMonth['full_label'] ?? '') ?></small>
                        <?php endif; ?>
                    </article>
                    <article class="expense-kpi-card">
                        <span>Mes con menor gasto</span>
                        <?php $lowestMonth = is_array($history['lowest_month'] ?? null) ? $history['lowest_month'] : null; ?>
                        <strong><?= $lowestMonth === null ? 'Sin datos' : View::escape($clp($lowestMonth['known_amount_clp'] ?? 0)) ?></strong>
                        <?php if ($lowestMonth !== null): ?>
                            <small><?= View::escape($lowestMonth['full_label'] ?? '') ?></small>
                        <?php endif; ?>
                    </article>
                </div>

                <section class="expense-history-panel" aria-labelledby="expense-history-status-title">
                    <div class="expense-summary-panel__header">
                        <h3 id="expense-history-status-title">Pagado vs pendiente</h3>
                    </div>
                    <div class="expense-history-status-grid">
                        <div><span>Pagado</span><strong><?= View::escape($clp($historyStatusBreakdown['paid_clp'] ?? 0)) ?></strong></div>
                        <div><span>Pendiente</span><strong><?= View::escape($clp($historyStatusBreakdown['pending_clp'] ?? 0)) ?></strong></div>
                        <div><span>Vencido</span><strong><?= View::escape($clp($historyStatusBreakdown['overdue_clp'] ?? 0)) ?></strong></div>
                        <div><span>Montos pendientes</span><strong><?= View::escape((string) ($historyStatusBreakdown['unknown_amount_count'] ?? 0)) ?></strong></div>
                    </div>
                </section>

                <section class="expense-history-panel" aria-labelledby="expense-history-monthly-title">
                    <div class="expense-summary-panel__header">
                        <h3 id="expense-history-monthly-title">Evoluci&oacute;n mensual</h3>
                    </div>
                    <?php if (!(bool) ($history['has_comparison'] ?? false)): ?>
                        <p class="muted">Necesitas al menos dos meses para comparar.</p>
                    <?php endif; ?>
                    <div class="expense-history-chart">
                        <?php foreach ($historyMonthlyTotals as $row): ?>
                            <?php
                            $barWidth = max(2, (int) ($row['bar_percentage'] ?? 0));
                            $comparison = is_array($row['comparison'] ?? null) ? $row['comparison'] : null;
                            ?>
                            <article class="expense-history-bar-row">
                                <div class="expense-history-bar-row__label">
                                    <strong><?= View::escape($row['label'] ?? '') ?></strong>
                                    <span><?= View::escape($clp($row['known_amount_clp'] ?? 0)) ?></span>
                                </div>
                                <div class="expense-history-bar" role="img" aria-label="<?= View::escape(($row['full_label'] ?? '') . ' - ' . $clp($row['known_amount_clp'] ?? 0)) ?>">
                                    <span style="width: <?= View::escape((string) $barWidth) ?>%;"></span>
                                </div>
                                <p>
                                    <?= $comparison === null ? 'Sin comparaci&oacute;n disponible' : View::escape((string) ($comparison['label'] ?? '')) ?>
                                    <?php if ((int) ($row['unknown_amount_count'] ?? 0) > 0): ?>
                                        · Datos parciales
                                    <?php endif; ?>
                                </p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>

                <div class="expense-summary-grid">
                    <section class="expense-summary-panel" aria-labelledby="expense-history-category-title">
                        <div class="expense-summary-panel__header">
                            <h3 id="expense-history-category-title">Gastos por categor&iacute;a</h3>
                        </div>
                        <div class="expense-breakdown-list">
                            <?php foreach ($historyCategoryBreakdown as $row): ?>
                                <?php
                                $barColor = is_string($row['color'] ?? null) && $row['color'] !== '' ? strtoupper((string) $row['color']) : $defaultColor;
                                $percentage = max(0, min(100, (int) ($row['percentage'] ?? 0)));
                                ?>
                                <article class="expense-breakdown-row">
                                    <div class="expense-breakdown-row__top">
                                        <span><?= View::escape($row['name'] ?? 'Sin categoria') ?></span>
                                        <strong><?= View::escape($clp($row['known_amount_clp'] ?? 0)) ?></strong>
                                    </div>
                                    <div class="expense-breakdown-bar" role="img" aria-label="<?= View::escape(($row['name'] ?? 'Sin categoria') . ' - ' . $clp($row['known_amount_clp'] ?? 0) . ' - ' . $percentage . ' por ciento') ?>">
                                        <span style="width: <?= View::escape((string) $percentage) ?>%; --expense-breakdown-color: <?= View::escape($barColor) ?>;"></span>
                                    </div>
                                    <p><?= View::escape((string) $percentage) ?>%<?= (int) ($row['unknown_amount_count'] ?? 0) > 0 ? ' · ' . View::escape((string) $row['unknown_amount_count']) . ' monto pendiente' : '' ?></p>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <section class="expense-summary-panel" aria-labelledby="expense-history-payment-title">
                        <div class="expense-summary-panel__header">
                            <h3 id="expense-history-payment-title">Uso por medio de pago</h3>
                        </div>
                        <div class="expense-breakdown-list">
                            <?php foreach ($historyPaymentBreakdown as $row): ?>
                                <?php $percentage = max(0, min(100, (int) ($row['percentage'] ?? 0))); ?>
                                <article class="expense-breakdown-row">
                                    <div class="expense-breakdown-row__top">
                                        <span><?= View::escape($row['name'] ?? 'Sin medio definido') ?></span>
                                        <strong><?= View::escape($clp($row['known_amount_clp'] ?? 0)) ?></strong>
                                    </div>
                                    <div class="expense-breakdown-bar" role="img" aria-label="<?= View::escape(($row['name'] ?? 'Sin medio definido') . ' - ' . $clp($row['known_amount_clp'] ?? 0) . ' - ' . $percentage . ' por ciento') ?>">
                                        <span style="width: <?= View::escape((string) $percentage) ?>%;"></span>
                                    </div>
                                    <p><?= View::escape((string) $percentage) ?>%<?= (int) ($row['unknown_amount_count'] ?? 0) > 0 ? ' · ' . View::escape((string) $row['unknown_amount_count']) . ' monto pendiente' : '' ?></p>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </section>
                </div>

                <?php if ($historySelectedCategory !== '' && $historyCategorySeries !== []): ?>
                    <section class="expense-history-panel" aria-labelledby="expense-history-category-series-title">
                        <div class="expense-summary-panel__header">
                            <h3 id="expense-history-category-series-title">Evoluci&oacute;n de <?= View::escape($historySelectedCategory) ?></h3>
                        </div>
                        <div class="expense-history-mini-series">
                            <?php foreach ($historyCategorySeries as $row): ?>
                                <div><span><?= View::escape($row['label'] ?? '') ?></span><strong><?= View::escape($clp($row['known_amount_clp'] ?? 0)) ?></strong></div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <section class="expense-history-panel" aria-labelledby="expense-history-service-title">
                    <div class="expense-summary-panel__header">
                        <h3 id="expense-history-service-title">Servicios con mayor gasto</h3>
                    </div>
                    <?php if ($historyServiceBreakdown === []): ?>
                        <p class="muted">No hay servicios configurados en este analisis.</p>
                    <?php else: ?>
                        <div class="expense-history-service-list">
                            <?php foreach ($historyServiceBreakdown as $row): ?>
                                <article>
                                    <div>
                                        <strong><?= View::escape($row['name'] ?? '') ?></strong>
                                        <span><?= View::escape((string) ($row['expense_count'] ?? 0)) ?> gasto<?= (int) ($row['expense_count'] ?? 0) === 1 ? '' : 's' ?><?= (int) ($row['active'] ?? 0) === 1 ? '' : ' · inactivo' ?></span>
                                    </div>
                                    <strong><?= View::escape($clp($row['known_amount_clp'] ?? 0)) ?></strong>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <?php if ($historySelectedService !== '' && $historyServiceSeries !== []): ?>
                    <section class="expense-history-panel" aria-labelledby="expense-history-service-series-title">
                        <div class="expense-summary-panel__header">
                            <h3 id="expense-history-service-series-title">Evoluci&oacute;n de <?= View::escape($historySelectedService) ?></h3>
                        </div>
                        <div class="expense-history-mini-series">
                            <?php foreach ($historyServiceSeries as $row): ?>
                                <div><span><?= View::escape($row['label'] ?? '') ?></span><strong><?= View::escape($clp($row['known_amount_clp'] ?? 0)) ?></strong></div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <section class="expense-history-panel" aria-labelledby="expense-history-detail-title">
                    <div class="expense-summary-panel__header">
                        <h3 id="expense-history-detail-title">Detalle por mes</h3>
                    </div>
                    <div class="expense-history-month-list">
                        <?php foreach ($historyMonthDetails as $row): ?>
                            <article>
                                <div>
                                    <strong><?= View::escape($row['full_label'] ?? '') ?></strong>
                                    <span><?= View::escape((string) ($row['expense_count'] ?? 0)) ?> gasto<?= (int) ($row['expense_count'] ?? 0) === 1 ? '' : 's' ?> · <?= View::escape((string) ($row['pending_count'] ?? 0)) ?> pendiente<?= (int) ($row['pending_count'] ?? 0) === 1 ? '' : 's' ?></span>
                                    <?php if ((int) ($row['unknown_amount_count'] ?? 0) > 0): ?>
                                        <span>Datos parciales: <?= View::escape((string) ($row['unknown_amount_count'] ?? 0)) ?> monto<?= (int) ($row['unknown_amount_count'] ?? 0) === 1 ? '' : 's' ?> pendiente<?= (int) ($row['unknown_amount_count'] ?? 0) === 1 ? '' : 's' ?></span>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <strong><?= View::escape($clp($row['known_amount_clp'] ?? 0)) ?></strong>
                                    <a class="button button--secondary" href="<?= View::escape($monthUrl((string) ($row['month_value'] ?? $currentMonth))) ?>">Ver mes</a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        </section>
    <?php else: ?>
    <?php if ($activeTab === 'categories'): ?>
        <section class="tasks-panel expenses-panel" aria-label="Categorias de gastos">
            <div class="tasks-list-header">
                <div>
                    <h2>Categor&iacute;as</h2>
                    <p class="muted">Define los tipos de gasto que quieres usar despues al registrar pagos.</p>
                </div>
                <button class="button button--primary" type="button" data-expense-open="category">Crear categor&iacute;a</button>
            </div>

            <?php if ($categories === []): ?>
                <div class="expenses-empty-state">
                    <h3>Todav&iacute;a no tienes categor&iacute;as.</h3>
                    <button class="button button--primary" type="button" data-expense-open="category">Crear categor&iacute;a</button>
                </div>
            <?php else: ?>
                <div class="expenses-card-list">
                    <?php foreach ($categories as $category): ?>
                        <?php
                        $color = is_string($category['color'] ?? null) && $category['color'] !== '' ? strtoupper((string) $category['color']) : $defaultColor;
                        $active = (int) ($category['active'] ?? 0) === 1;
                        ?>
                        <article
                            class="expense-config-card"
                            data-expense-category-card
                            data-id="<?= View::escape($category['id'] ?? '') ?>"
                            data-name="<?= View::escape($category['name'] ?? '') ?>"
                            data-color="<?= View::escape($color) ?>"
                            data-active="<?= $active ? '1' : '0' ?>"
                        >
                            <div class="expense-config-card__main">
                                <h3>
                                    <span class="expense-color-dot" style="--expense-category-color: <?= View::escape($color) ?>;" aria-hidden="true"></span>
                                    <?= View::escape($category['name'] ?? '') ?>
                                </h3>
                                <p class="muted"><?= $active ? 'Activa' : 'Inactiva' ?></p>
                            </div>
                            <div class="expense-config-card__actions">
                                <button class="button button--secondary" type="button" data-expense-open="category-edit">Editar</button>
                                <button class="button <?= $active ? 'button--danger' : 'button--secondary' ?>" type="button" data-expense-action="<?= $active ? 'deactivate-category' : 'reactivate-category' ?>">
                                    <?= $active ? 'Desactivar' : 'Reactivar' ?>
                                </button>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php elseif ($activeTab === 'payment-methods'): ?>
        <section class="tasks-panel expenses-panel" aria-label="Medios de pago">
            <div class="tasks-list-header">
                <div>
                    <h2>Medios de pago</h2>
                    <p class="muted">Identifica formas de pago sin guardar datos bancarios sensibles.</p>
                </div>
                <button class="button button--primary" type="button" data-expense-open="payment-method">Agregar medio</button>
            </div>

            <?php if ($paymentMethods !== []): ?>
                <form class="task-filters expenses-filter-bar" data-expense-payment-filter>
                    <label>
                        Tipo
                        <select name="type">
                            <option value="">Todos</option>
                            <?php foreach ($paymentMethodTypeLabels as $type => $label): ?>
                                <option value="<?= View::escape((string) $type) ?>"><?= View::escape((string) $label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </form>
            <?php endif; ?>

            <?php if ($paymentMethods === []): ?>
                <div class="expenses-empty-state">
                    <h3>Todav&iacute;a no tienes medios de pago.</h3>
                    <button class="button button--primary" type="button" data-expense-open="payment-method">Agregar medio</button>
                </div>
            <?php else: ?>
                <div class="expenses-card-list">
                    <?php foreach ($paymentMethods as $method): ?>
                        <?php
                        $type = (string) ($method['type'] ?? 'other');
                        $active = (int) ($method['active'] ?? 0) === 1;
                        $institution = trim((string) ($method['institution_name'] ?? ''));
                        $notes = trim((string) ($method['notes'] ?? ''));
                        ?>
                        <article
                            class="expense-config-card"
                            data-expense-payment-card
                            data-id="<?= View::escape($method['id'] ?? '') ?>"
                            data-name="<?= View::escape($method['name'] ?? '') ?>"
                            data-type="<?= View::escape($type) ?>"
                            data-institution-name="<?= View::escape($institution) ?>"
                            data-notes="<?= View::escape($notes) ?>"
                            data-active="<?= $active ? '1' : '0' ?>"
                        >
                            <div class="expense-config-card__main">
                                <h3><?= View::escape($method['name'] ?? '') ?></h3>
                                <div class="expense-meta">
                                    <span><?= View::escape($paymentMethodTypeLabels[$type] ?? 'Otro') ?></span>
                                    <?php if ($institution !== ''): ?>
                                        <span><?= View::escape($institution) ?></span>
                                    <?php endif; ?>
                                    <?php if (!$active): ?>
                                        <span>Inactivo</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($notes !== ''): ?>
                                    <p class="muted"><?= View::escape($notes) ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="expense-config-card__actions">
                                <button class="button button--secondary" type="button" data-expense-open="payment-method-edit">Editar</button>
                                <button class="button <?= $active ? 'button--danger' : 'button--secondary' ?>" type="button" data-expense-action="<?= $active ? 'deactivate-payment-method' : 'reactivate-payment-method' ?>">
                                    <?= $active ? 'Desactivar' : 'Reactivar' ?>
                                </button>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php elseif ($activeTab === 'services'): ?>
        <section class="tasks-panel expenses-panel" aria-label="Servicios habituales">
            <div class="tasks-list-header">
                <div>
                    <h2>Servicios</h2>
                    <p class="muted">Configura los pagos que realizas habitualmente.</p>
                </div>
                <button class="button button--primary" type="button" data-expense-open="service">Agregar servicio</button>
            </div>

            <?php if ($services !== []): ?>
                <form class="task-filters expenses-service-filters" data-expense-service-filter>
                    <label>
                        Buscar servicio
                        <input type="search" name="search" placeholder="Buscar servicio">
                    </label>
                    <label>
                        Categor&iacute;a
                        <select name="category_id">
                            <option value="">Todas</option>
                            <option value="none">Sin categor&iacute;a</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= View::escape((string) ($category['id'] ?? '')) ?>"><?= View::escape($category['name'] ?? '') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </form>
            <?php endif; ?>

            <?php if ($services === []): ?>
                <div class="expenses-empty-state">
                    <h3>Todav&iacute;a no tienes servicios configurados.</h3>
                    <p>Agrega los pagos que realizas habitualmente para facilitar el registro mensual.</p>
                    <button class="button button--primary" type="button" data-expense-open="service">Agregar servicio</button>
                </div>
            <?php else: ?>
                <div class="expenses-card-list">
                    <?php foreach ($services as $service): ?>
                        <?php
                        $active = (int) ($service['active'] ?? 0) === 1;
                        $categoryId = $service['category_id'] === null ? '' : (string) $service['category_id'];
                        $categoryName = trim((string) ($service['category_name'] ?? ''));
                        $categoryColor = $defaultColor;
                        $categoryTextColor = '#062621';

                        foreach ($categories as $category) {
                            if ((string) ($category['id'] ?? '') === $categoryId && is_string($category['color'] ?? null) && $category['color'] !== '') {
                                $categoryColor = strtoupper((string) $category['color']);
                                $categoryTextColor = $contrastColor($categoryColor);
                                break;
                            }
                        }

                        $paymentIds = $servicePaymentIds($service);
                        $defaultPaymentId = $serviceDefaultPaymentId($service);
                        $recurringRule = is_array($service['recurring_rule'] ?? null) ? $service['recurring_rule'] : null;
                        $recurringAdjustments = is_array($service['recurring_adjustments'] ?? null) ? $service['recurring_adjustments'] : [];
                        $paymentNames = [];

                        foreach ((array) ($service['payment_methods'] ?? []) as $method) {
                            $paymentNames[] = $methodLabel($method, $paymentMethodTypeLabels) . ((int) ($method['active'] ?? 0) === 1 ? '' : ' (inactivo)');
                        }
                        ?>
                        <article
                            class="expense-config-card expense-config-card--service"
                            data-expense-service-card
                            data-id="<?= View::escape($service['id'] ?? '') ?>"
                            data-name="<?= View::escape($service['name'] ?? '') ?>"
                            data-category-id="<?= View::escape($categoryId) ?>"
                            data-default-amount-clp="<?= View::escape($service['default_amount_clp'] ?? '') ?>"
                            data-notes="<?= View::escape($service['notes'] ?? '') ?>"
                            data-active="<?= $active ? '1' : '0' ?>"
                            data-payment-method-ids="<?= View::escape(implode(',', $paymentIds)) ?>"
                            data-default-payment-method-id="<?= View::escape($defaultPaymentId) ?>"
                            data-recurring-rule-id="<?= View::escape($recurringRule['id'] ?? '') ?>"
                            data-recurring-active="<?= View::escape($recurringRule === null ? '' : ((int) ($recurringRule['active'] ?? 0) === 1 ? '1' : '0')) ?>"
                            data-recurring-frequency="<?= View::escape($recurringRule['frequency'] ?? 'monthly') ?>"
                            data-recurring-interval-value="<?= View::escape($recurringRule['interval_value'] ?? '1') ?>"
                            data-recurring-day-of-month="<?= View::escape($recurringRule['day_of_month'] ?? '') ?>"
                            data-recurring-default-amount-clp="<?= View::escape($recurringRule['default_amount_clp'] ?? '') ?>"
                            data-recurring-default-category-id="<?= View::escape($recurringRule['default_category_id'] ?? '') ?>"
                            data-recurring-default-payment-method-id="<?= View::escape($recurringRule['default_payment_method_id'] ?? '') ?>"
                            data-recurring-starts-on="<?= View::escape($recurringRule['starts_on'] ?? $today) ?>"
                            data-recurring-ends-on="<?= View::escape($recurringRule['ends_on'] ?? '') ?>"
                        >
                            <div class="expense-config-card__main">
                                <h3><?= View::escape($service['name'] ?? '') ?></h3>
                                <div class="expense-meta">
                                    <?php if ($categoryName !== ''): ?>
                                        <span class="expense-category-chip" style="--expense-chip-bg: <?= View::escape($categoryColor) ?>; --expense-chip-text: <?= View::escape($categoryTextColor) ?>;">
                                            <?= View::escape($categoryName) ?>
                                        </span>
                                    <?php else: ?>
                                        <span>Sin categor&iacute;a</span>
                                    <?php endif; ?>
                                    <?php if ($service['default_amount_clp'] !== null): ?>
                                        <span><?= View::escape($clp($service['default_amount_clp'])) ?></span>
                                    <?php endif; ?>
                                    <?php if (!$active): ?>
                                        <span>Inactivo</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($paymentNames !== []): ?>
                                    <p class="expense-service-methods"><?= View::escape(implode(' · ', $paymentNames)) ?></p>
                                <?php else: ?>
                                    <p class="muted">Sin medios configurados.</p>
                                <?php endif; ?>
                                <p class="expense-service-methods"><?= View::escape($recurringSummary($recurringRule)) ?></p>
                                <?php if ($recurringAdjustments !== []): ?>
                                    <div class="expense-recurring-adjustments">
                                        <?php foreach ($recurringAdjustments as $adjustment): ?>
                                            <?php
                                            $adjustmentAction = (string) ($adjustment['action'] ?? 'generate');
                                            $adjustmentDetails = [];

                                            if ((int) ($adjustment['amount_override'] ?? 0) === 1) {
                                                $adjustmentDetails[] = $adjustment['amount_clp'] === null ? 'Monto pendiente' : $clp($adjustment['amount_clp']);
                                            }

                                            if (is_string($adjustment['due_on'] ?? null) && $adjustment['due_on'] !== '') {
                                                $adjustmentDetails[] = 'vence ' . $dateLabel((string) $adjustment['due_on']);
                                            }

                                            if (trim((string) ($adjustment['category_name'] ?? '')) !== '') {
                                                $adjustmentDetails[] = (string) $adjustment['category_name'];
                                            }

                                            if (trim((string) ($adjustment['payment_method_name'] ?? '')) !== '') {
                                                $adjustmentDetails[] = (string) $adjustment['payment_method_name'];
                                            }
                                            ?>
                                            <article
                                                class="expense-recurring-adjustment"
                                                data-expense-recurring-adjustment-card
                                                data-id="<?= View::escape($adjustment['id'] ?? '') ?>"
                                                data-period-month="<?= View::escape(substr((string) ($adjustment['period_month'] ?? ''), 0, 7)) ?>"
                                                data-action="<?= View::escape($adjustmentAction) ?>"
                                                data-description="<?= View::escape($adjustment['description'] ?? '') ?>"
                                                data-amount-override="<?= (int) ($adjustment['amount_override'] ?? 0) === 1 ? '1' : '0' ?>"
                                                data-amount-clp="<?= View::escape($adjustment['amount_clp'] ?? '') ?>"
                                                data-due-on="<?= View::escape($adjustment['due_on'] ?? '') ?>"
                                                data-category-id="<?= View::escape($adjustment['category_id'] ?? '') ?>"
                                                data-payment-method-id="<?= View::escape($adjustment['payment_method_id'] ?? '') ?>"
                                                data-notes="<?= View::escape($adjustment['notes'] ?? '') ?>"
                                                data-active="<?= (int) ($adjustment['active'] ?? 0) === 1 ? '1' : '0' ?>"
                                            >
                                                <div>
                                                    <strong><?= View::escape($adjustmentPeriodLabel(is_string($adjustment['period_month'] ?? null) ? (string) $adjustment['period_month'] : null)) ?></strong>
                                                    <span><?= $adjustmentAction === 'skip' ? 'No generar gasto' : View::escape($adjustmentDetails === [] ? 'Generar con cambios' : implode(' · ', $adjustmentDetails)) ?></span>
                                                </div>
                                                <div class="expense-config-card__actions">
                                                    <button class="button button--secondary" type="button" data-expense-open="recurring-adjustment-edit">Editar</button>
                                                    <button class="button button--danger" type="button" data-expense-action="delete-recurring-adjustment" data-recurring-adjustment-action-id="<?= View::escape((string) ($adjustment['id'] ?? '')) ?>">Eliminar</button>
                                                </div>
                                            </article>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (trim((string) ($service['notes'] ?? '')) !== ''): ?>
                                    <p class="muted"><?= View::escape($service['notes'] ?? '') ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="expense-config-card__actions">
                                <button class="button button--secondary" type="button" data-expense-open="service-edit">Editar</button>
                                <button class="button button--secondary" type="button" data-expense-open="recurring-rule"><?= $recurringRule === null ? 'Configurar recurrencia' : 'Editar recurrencia' ?></button>
                                <?php if ($recurringRule !== null): ?>
                                    <button class="button button--secondary" type="button" data-expense-open="recurring-adjustment">Ajustar mes</button>
                                <?php endif; ?>
                                <?php if ($recurringRule !== null): ?>
                                    <button class="button <?= (int) ($recurringRule['active'] ?? 0) === 1 ? 'button--danger' : 'button--secondary' ?>" type="button" data-expense-action="<?= (int) ($recurringRule['active'] ?? 0) === 1 ? 'deactivate-recurring-rule' : 'reactivate-recurring-rule' ?>" data-recurring-rule-action-id="<?= View::escape((string) ($recurringRule['id'] ?? '')) ?>">
                                        <?= (int) ($recurringRule['active'] ?? 0) === 1 ? 'Desactivar recurrencia' : 'Reactivar recurrencia' ?>
                                    </button>
                                <?php endif; ?>
                                <button class="button <?= $active ? 'button--danger' : 'button--secondary' ?>" type="button" data-expense-action="<?= $active ? 'deactivate-service' : 'reactivate-service' ?>">
                                    <?= $active ? 'Desactivar' : 'Reactivar' ?>
                                </button>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php elseif ($activeTab === 'import'): ?>
        <section class="tasks-panel expenses-panel" aria-label="Importar gastos desde JSON">
            <div class="tasks-list-header">
                <div>
                    <h2>Importar JSON</h2>
                    <p class="muted">Carga categorias, medios de pago, servicios y gastos en una sola importacion.</p>
                </div>
            </div>

            <form class="task-form expense-import-form" data-expense-import-form>
                <label>
                    JSON
                    <textarea name="json" rows="14" spellcheck="false" data-expense-import-json placeholder="<?= View::escape($importTemplate) ?>" required></textarea>
                </label>
                <div class="task-form__actions">
                    <button class="button button--primary" type="submit">Importar</button>
                </div>
            </form>

            <section class="expense-import-template" aria-labelledby="expense-import-template-title">
                <div class="expense-summary-panel__header">
                    <h3 id="expense-import-template-title">Plantilla</h3>
                </div>
                <pre><code><?= View::escape($importTemplate) ?></code></pre>
            </section>
        </section>
    <?php endif; ?>
    <?php endif; ?>

    <section class="expense-modal" data-monthly-expense-modal="expense" role="dialog" aria-modal="true" aria-labelledby="monthly-expense-title" hidden>
        <div class="expense-modal__panel expense-modal__panel--wide">
            <div class="expense-modal__header">
                <div>
                    <h2 id="monthly-expense-title" data-monthly-expense-modal-title>Nuevo gasto</h2>
                    <p class="muted">Periodo: <?= View::escape($monthLabel) ?></p>
                </div>
                <button class="button button--secondary" type="button" data-monthly-expense-close aria-label="Cerrar">X</button>
            </div>
            <p class="task-message" data-monthly-expense-modal-message hidden></p>
            <form class="task-form" data-monthly-expense-form>
                <input type="hidden" name="id" value="">
                <input type="hidden" name="period_month" value="<?= View::escape($monthValue) ?>-01">
                <div class="task-form__grid task-form__grid--monthly-expense">
                    <label>
                        Servicio
                        <select name="service_id" data-monthly-expense-service>
                            <option value="">Sin servicio / Pago puntual</option>
                            <?php foreach ($services as $service): ?>
                                <?php $serviceActive = (int) ($service['active'] ?? 0) === 1; ?>
                                <option value="<?= View::escape((string) ($service['id'] ?? '')) ?>" data-active="<?= $serviceActive ? '1' : '0' ?>">
                                    <?= View::escape($service['name'] ?? '') ?><?= $serviceActive ? '' : ' (inactivo)' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        Descripci&oacute;n *
                        <input name="description" type="text" maxlength="180" required placeholder="Aguas Andinas">
                    </label>
                    <label>
                        Categor&iacute;a
                        <select name="category_id" data-monthly-expense-category>
                            <option value="">Sin categor&iacute;a</option>
                            <?php foreach ($categories as $category): ?>
                                <?php $categoryActive = (int) ($category['active'] ?? 0) === 1; ?>
                                <option value="<?= View::escape((string) ($category['id'] ?? '')) ?>" data-active="<?= $categoryActive ? '1' : '0' ?>">
                                    <?= View::escape($category['name'] ?? '') ?><?= $categoryActive ? '' : ' (inactiva)' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        Monto opcional
                        <input name="amount_clp" type="text" inputmode="numeric" placeholder="19.994">
                    </label>
                    <label class="expense-toggle-option task-form__wide">
                        <input name="has_installments" type="checkbox" value="1" data-monthly-installment-toggle>
                        <span>Es un pago en cuotas</span>
                    </label>
                    <div class="expense-installment-fields task-form__wide" data-monthly-installment-fields hidden>
                        <label>
                            Cuota actual
                            <input name="installment_current" type="number" min="1" step="1" placeholder="3">
                        </label>
                        <label>
                            Total cuotas
                            <input name="installment_total" type="number" min="1" step="1" placeholder="12">
                        </label>
                        <p class="muted">Para pagos mensuales normales deja esta opci&oacute;n desactivada.</p>
                    </div>
                    <label>
                        Fecha l&iacute;mite
                        <input name="due_on" type="date">
                    </label>
                    <label>
                        Estado
                        <select name="status" data-monthly-expense-status>
                            <option value="pending">Pendiente</option>
                            <option value="paid">Pagado</option>
                            <option value="cancelled">Cancelado</option>
                        </select>
                    </label>
                    <label>
                        Fecha de pago
                        <input name="paid_on" type="date" data-monthly-expense-paid-on>
                    </label>
                    <label>
                        Medio de pago
                        <select name="payment_method_id" data-monthly-expense-payment>
                            <option value="">Sin medio</option>
                            <?php foreach ($paymentMethods as $method): ?>
                                <?php $methodActive = (int) ($method['active'] ?? 0) === 1; ?>
                                <option value="<?= View::escape((string) ($method['id'] ?? '')) ?>" data-active="<?= $methodActive ? '1' : '0' ?>">
                                    <?= View::escape($methodLabel($method, $paymentMethodTypeLabels)) ?><?= $methodActive ? '' : ' (inactivo)' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="task-form__wide">
                        Observaciones
                        <textarea name="notes" rows="3" maxlength="5000" placeholder="Descuento, boleta atrasada u otra nota."></textarea>
                    </label>
                </div>
                <div class="task-form__actions">
                    <button class="button button--secondary" type="button" data-monthly-expense-close>Cancelar</button>
                    <button class="button button--primary" type="submit">Guardar gasto</button>
                </div>
            </form>
        </div>
    </section>

    <section class="expense-modal" data-expense-modal="category" role="dialog" aria-modal="true" aria-labelledby="expense-category-title" hidden>
        <div class="expense-modal__panel">
            <div class="expense-modal__header">
                <h2 id="expense-category-title" data-expense-modal-title>Categor&iacute;a</h2>
                <button class="button button--secondary" type="button" data-expense-close aria-label="Cerrar">X</button>
            </div>
            <p class="task-message" data-expense-modal-message hidden></p>
            <form class="task-form" data-expense-form="category">
                <input type="hidden" name="id" value="">
                <div class="task-form__grid task-form__grid--labels">
                    <label>
                        Nombre *
                        <input name="name" type="text" maxlength="120" required placeholder="Servicios basicos">
                    </label>
                    <label>
                        Color
                        <input name="color" type="color" value="<?= View::escape($defaultColor) ?>">
                    </label>
                </div>
                <div class="task-form__actions">
                    <button class="button button--secondary" type="button" data-expense-close>Cancelar</button>
                    <button class="button button--primary" type="submit">Guardar</button>
                </div>
            </form>
        </div>
    </section>

    <section class="expense-modal" data-expense-modal="payment-method" role="dialog" aria-modal="true" aria-labelledby="expense-payment-title" hidden>
        <div class="expense-modal__panel">
            <div class="expense-modal__header">
                <div>
                    <h2 id="expense-payment-title" data-expense-modal-title>Medio de pago</h2>
                    <p class="muted">No ingreses n&uacute;meros de tarjeta, CVV, PIN ni credenciales bancarias.</p>
                </div>
                <button class="button button--secondary" type="button" data-expense-close aria-label="Cerrar">X</button>
            </div>
            <p class="task-message" data-expense-modal-message hidden></p>
            <form class="task-form" data-expense-form="payment-method">
                <input type="hidden" name="id" value="">
                <div class="task-form__grid task-form__grid--expense-payment">
                    <label>
                        Nombre *
                        <input name="name" type="text" maxlength="120" required placeholder="Visa Santander">
                    </label>
                    <label>
                        Tipo *
                        <select name="type" required>
                            <?php foreach ($paymentMethodTypeLabels as $type => $label): ?>
                                <option value="<?= View::escape((string) $type) ?>"><?= View::escape((string) $label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        Instituci&oacute;n opcional
                        <input name="institution_name" type="text" maxlength="120" placeholder="Santander">
                    </label>
                    <label class="task-form__wide">
                        Notas opcionales
                        <textarea name="notes" rows="3" maxlength="5000" placeholder="Identificacion conceptual del medio de pago."></textarea>
                    </label>
                </div>
                <div class="task-form__actions">
                    <button class="button button--secondary" type="button" data-expense-close>Cancelar</button>
                    <button class="button button--primary" type="submit">Guardar</button>
                </div>
            </form>
        </div>
    </section>

    <section class="expense-modal" data-expense-modal="service" role="dialog" aria-modal="true" aria-labelledby="expense-service-title" hidden>
        <div class="expense-modal__panel expense-modal__panel--wide">
            <div class="expense-modal__header">
                <h2 id="expense-service-title" data-expense-modal-title>Servicio</h2>
                <button class="button button--secondary" type="button" data-expense-close aria-label="Cerrar">X</button>
            </div>
            <p class="task-message" data-expense-modal-message hidden></p>
            <form class="task-form" data-expense-form="service">
                <input type="hidden" name="id" value="">
                <div class="task-form__grid task-form__grid--expense-service">
                    <label>
                        Nombre *
                        <input name="name" type="text" maxlength="160" required placeholder="Aguas Andinas">
                    </label>
                    <label>
                        Categor&iacute;a
                        <select name="category_id" data-expense-service-category>
                            <option value="">Sin categor&iacute;a</option>
                            <?php foreach ($categories as $category): ?>
                                <?php $categoryActive = (int) ($category['active'] ?? 0) === 1; ?>
                                <option
                                    value="<?= View::escape((string) ($category['id'] ?? '')) ?>"
                                    data-active="<?= $categoryActive ? '1' : '0' ?>"
                                >
                                    <?= View::escape($category['name'] ?? '') ?><?= $categoryActive ? '' : ' (inactiva)' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        Monto habitual opcional
                        <input name="default_amount_clp" type="text" inputmode="numeric" placeholder="8.250">
                    </label>
                    <label class="task-form__wide">
                        Observaciones opcionales
                        <textarea name="notes" rows="3" maxlength="5000" placeholder="El monto cambia algunos meses."></textarea>
                    </label>
                </div>

                <fieldset class="expense-method-picker">
                    <legend>Medios de pago disponibles</legend>
                    <p class="muted">Selecciona las posibles maneras de pagar este servicio. Al registrar cada gasto mensual eliges el medio usado realmente.</p>
                    <?php if ($paymentMethods === []): ?>
                        <p class="muted">A&uacute;n no tienes medios de pago. Puedes guardar el servicio sin medios configurados.</p>
                    <?php else: ?>
                        <div class="expense-method-picker__options">
                            <?php foreach ($paymentMethods as $method): ?>
                                <?php $methodActive = (int) ($method['active'] ?? 0) === 1; ?>
                                <label
                                    class="expense-method-option"
                                    data-expense-method-option
                                    data-active="<?= $methodActive ? '1' : '0' ?>"
                                >
                                    <input
                                        type="checkbox"
                                        name="payment_method_ids[]"
                                        value="<?= View::escape((string) ($method['id'] ?? '')) ?>"
                                        data-expense-method-checkbox
                                    >
                                    <span><?= View::escape($methodLabel($method, $paymentMethodTypeLabels)) ?><?= $methodActive ? '' : ' (inactivo)' ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <label class="expense-default-method">
                            Predeterminado
                            <select name="default_payment_method_id" data-expense-default-method>
                                <option value="">Sin predeterminado</option>
                                <?php foreach ($paymentMethods as $method): ?>
                                    <option value="<?= View::escape((string) ($method['id'] ?? '')) ?>">
                                        <?= View::escape($methodLabel($method, $paymentMethodTypeLabels)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    <?php endif; ?>
                </fieldset>

                <div class="task-form__actions">
                    <button class="button button--secondary" type="button" data-expense-close>Cancelar</button>
                    <button class="button button--primary" type="submit">Guardar</button>
                </div>
            </form>
        </div>
    </section>

    <section class="expense-modal" data-expense-modal="recurring-rule" role="dialog" aria-modal="true" aria-labelledby="expense-recurring-title" hidden>
        <div class="expense-modal__panel expense-modal__panel--wide">
            <div class="expense-modal__header">
                <h2 id="expense-recurring-title" data-expense-modal-title>Recurrencia</h2>
                <button class="button button--secondary" type="button" data-expense-close aria-label="Cerrar">X</button>
            </div>
            <p class="task-message" data-expense-modal-message hidden></p>
            <form class="task-form" data-expense-form="recurring-rule">
                <input type="hidden" name="id" value="">
                <input type="hidden" name="service_id" value="">
                <div class="task-form__grid task-form__grid--expense-recurring">
                    <label class="expense-toggle-option">
                        <input name="active" type="checkbox" value="1" checked>
                        <span>Activa</span>
                    </label>
                    <label>
                        Frecuencia
                        <select name="frequency">
                            <option value="monthly">Mensual</option>
                        </select>
                    </label>
                    <label>
                        Cada X meses
                        <input name="interval_value" type="number" min="1" step="1" value="1" required>
                    </label>
                    <label>
                        D&iacute;a de vencimiento
                        <input name="day_of_month" type="number" min="1" max="31" step="1" required placeholder="14">
                    </label>
                    <label>
                        Monto predeterminado opcional
                        <input name="default_amount_clp" type="text" inputmode="numeric" placeholder="Usar monto del servicio">
                    </label>
                    <label>
                        Categor&iacute;a predeterminada opcional
                        <select name="default_category_id">
                            <option value="">Usar categor&iacute;a del servicio</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= View::escape((string) ($category['id'] ?? '')) ?>">
                                    <?= View::escape($category['name'] ?? '') ?><?= (int) ($category['active'] ?? 0) === 1 ? '' : ' (inactiva)' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        Medio predeterminado opcional
                        <select name="default_payment_method_id">
                            <option value="">Usar medio del servicio</option>
                            <?php foreach ($paymentMethods as $method): ?>
                                <option value="<?= View::escape((string) ($method['id'] ?? '')) ?>">
                                    <?= View::escape($methodLabel($method, $paymentMethodTypeLabels)) ?><?= (int) ($method['active'] ?? 0) === 1 ? '' : ' (inactivo)' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        Comienza
                        <input name="starts_on" type="date" required value="<?= View::escape($today) ?>">
                    </label>
                    <label>
                        Termina opcional
                        <input name="ends_on" type="date">
                    </label>
                </div>
                <div class="task-form__actions">
                    <button class="button button--secondary" type="button" data-expense-close>Cancelar</button>
                    <button class="button button--primary" type="submit">Guardar recurrencia</button>
                </div>
            </form>
        </div>
    </section>

    <section class="expense-modal" data-expense-modal="recurring-adjustment" role="dialog" aria-modal="true" aria-labelledby="expense-recurring-adjustment-title" hidden>
        <div class="expense-modal__panel expense-modal__panel--wide">
            <div class="expense-modal__header">
                <div>
                    <h2 id="expense-recurring-adjustment-title" data-expense-modal-title>Ajuste mensual</h2>
                    <p class="muted">Aplica solo a un mes futuro de una recurrencia.</p>
                </div>
                <button class="button button--secondary" type="button" data-expense-close aria-label="Cerrar">X</button>
            </div>
            <p class="task-message" data-expense-modal-message hidden></p>
            <form class="task-form" data-expense-form="recurring-adjustment">
                <input type="hidden" name="id" value="">
                <input type="hidden" name="service_id" value="">
                <input type="hidden" name="recurring_rule_id" value="">
                <div class="task-form__grid task-form__grid--expense-recurring-adjustment">
                    <label>
                        Mes
                        <input name="period_month" type="month" value="<?= View::escape($monthValue) ?>" required>
                    </label>
                    <label>
                        Acci&oacute;n
                        <select name="adjustment_action" data-recurring-adjustment-action>
                            <option value="generate">Generar con cambios</option>
                            <option value="skip">Saltar ese mes</option>
                        </select>
                    </label>
                    <label class="expense-toggle-option" data-recurring-adjustment-field>
                        <input name="active" type="checkbox" value="1" checked>
                        <span>Activo</span>
                    </label>
                    <label data-recurring-adjustment-field>
                        Descripci&oacute;n opcional
                        <input name="description" type="text" maxlength="180" placeholder="Nombre especial para ese mes">
                    </label>
                    <label class="expense-toggle-option" data-recurring-adjustment-field>
                        <input name="amount_override" type="checkbox" value="1" data-recurring-adjustment-amount-toggle>
                        <span>Cambiar monto</span>
                    </label>
                    <label data-recurring-adjustment-amount-field hidden>
                        Monto del mes
                        <input name="amount_clp" type="text" inputmode="numeric" placeholder="0, 8.250 o vacio">
                    </label>
                    <label data-recurring-adjustment-field>
                        Fecha l&iacute;mite opcional
                        <input name="due_on" type="date">
                    </label>
                    <label data-recurring-adjustment-field>
                        Categor&iacute;a opcional
                        <select name="category_id">
                            <option value="">Usar categor&iacute;a normal</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= View::escape((string) ($category['id'] ?? '')) ?>">
                                    <?= View::escape($category['name'] ?? '') ?><?= (int) ($category['active'] ?? 0) === 1 ? '' : ' (inactiva)' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label data-recurring-adjustment-field>
                        Medio opcional
                        <select name="payment_method_id">
                            <option value="">Usar medio normal</option>
                            <?php foreach ($paymentMethods as $method): ?>
                                <option value="<?= View::escape((string) ($method['id'] ?? '')) ?>">
                                    <?= View::escape($methodLabel($method, $paymentMethodTypeLabels)) ?><?= (int) ($method['active'] ?? 0) === 1 ? '' : ' (inactivo)' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="task-form__wide">
                        Nota opcional
                        <textarea name="notes" rows="3" maxlength="5000" placeholder="Oferta, cambio de plan, congelacion o motivo."></textarea>
                    </label>
                </div>
                <div class="task-form__actions">
                    <button class="button button--secondary" type="button" data-expense-close>Cancelar</button>
                    <button class="button button--primary" type="submit">Guardar ajuste</button>
                </div>
            </form>
        </div>
    </section>
</section>
