<?php
declare(strict_types=1);

use App\Support\View;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

function organization_ui_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function organization_ui_render(array $overrides): string
{
    $base = [
        'tasks' => [],
        'projects' => [],
        'notes' => [],
        'reminders' => [],
        'calendar' => null,
        'labels' => [],
        'taskPrefill' => null,
        'selectedProject' => null,
        'selectedProjectTasks' => [],
        'spaces' => [
            ['id' => 1, 'name' => 'Universidad', 'slug' => 'universidad'],
        ],
        'filters' => [
            'tab' => 'tasks',
            'status' => 'pending',
            'space' => 'all',
            'label' => 'all',
            'sort' => 'default',
            'time' => 'all',
            'view' => 'month',
            'date' => '',
        ],
    ];

    ob_start();
    View::render('pages/organization-tasks', array_replace($base, $overrides));
    $html = ob_get_clean();

    if (!is_string($html)) {
        throw new RuntimeException('Could not render organization page.');
    }

    return $html;
}

try {
    $css = file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/app.css');
    $js = file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/app.js');

    organization_ui_assert(is_string($css), 'Could not read app.css.');
    organization_ui_assert(is_string($js), 'Could not read app.js.');
    organization_ui_assert(str_contains($css, 'background: var(--label-color);'), 'Label chip does not use the saved HEX as its real background.');
    organization_ui_assert(str_contains($css, 'color: var(--label-text-color);'), 'Label chip does not use computed contrast text color.');
    organization_ui_assert(str_contains($css, '.organization-labels') && str_contains($css, 'flex-wrap: wrap;') && str_contains($css, 'gap: var(--space-2);'), 'Label container is not configured to wrap with gaps.');
    organization_ui_assert(!str_contains($css, '.task-meta span {'), 'Generic task-meta span selector can override nested labels.');
    organization_ui_assert(str_contains($css, '.deadline-urgency--warning') && str_contains($css, '.deadline-urgency--critical') && str_contains($css, '.deadline-urgency--overdue'), 'Deadline urgency visual scale is incomplete.');
    organization_ui_assert(str_contains($css, '.calendar-day--today .calendar-day__header time'), 'Today marker does not target the calendar day number.');
    organization_ui_assert(str_contains($css, '.calendar-day--selected.calendar-day--today'), 'Selected and today calendar states cannot be visually combined.');
    organization_ui_assert(str_contains($css, '.calendar-detail') && str_contains($css, '.calendar-detail--sheet'), 'Calendar detail popover or mobile sheet CSS is missing.');
    organization_ui_assert(str_contains($css, 'color-scheme: dark;'), 'Dark mode color scheme is not declared.');
    organization_ui_assert(str_contains($css, '.calendar-grid') && str_contains($css, 'grid-template-columns: 1fr;'), 'Calendar responsive single-column rule is missing.');
    organization_ui_assert(str_contains($js, 'function labelTextColor') && str_contains($js, "--label-text-color"), 'Dynamic labels do not compute text contrast.');
    organization_ui_assert(str_contains($js, "status === 'completed' || (kind === 'project' && status === 'archived')"), 'Dynamic urgency is not suppressed for completed items.');
    organization_ui_assert(str_contains($js, 'data-calendar-item') && str_contains($js, 'getBoundingClientRect') && str_contains($js, 'calendar-detail--sheet'), 'Calendar detail JS delegation or positioning is missing.');
    organization_ui_assert(str_contains($js, "event.key === 'Escape' && calendarDetail") && str_contains($js, 'selectedCalendarItem'), 'Calendar detail close/focus state is missing.');

    $taskHtml = organization_ui_render([
        'labels' => [
            ['id' => 1, 'name' => 'Arquitectura de SW', 'color' => '#FACC15'],
            ['id' => 2, 'name' => 'INF295', 'color' => '#1E3A8A'],
        ],
        'tasks' => [
            [
                'id' => 1,
                'title' => 'Tarea 1 Arquitectura de SW',
                'description' => null,
                'status' => 'pending',
                'space_id' => 1,
                'project_id' => null,
                'parent_task_id' => null,
                'starts_at_local' => null,
                'ends_at_local' => null,
                'due_at_local' => '2026-08-17 23:59:00',
                'due_at_input' => '2026-08-17T23:59',
                'labels' => [
                    ['id' => 1, 'name' => 'Arquitectura de SW', 'color' => '#FACC15'],
                    ['id' => 2, 'name' => 'INF295', 'color' => '#1E3A8A'],
                ],
                'urgency' => ['level' => 'warning', 'label' => 'Faltan 6 dias', 'deadline_input' => '2026-08-17T23:59'],
            ],
            [
                'id' => 2,
                'title' => 'Bloque con periodo real',
                'description' => null,
                'status' => 'pending',
                'space_id' => 1,
                'project_id' => null,
                'parent_task_id' => null,
                'starts_at_local' => '2026-08-12 18:00:00',
                'ends_at_local' => '2026-08-12 20:00:00',
                'due_at_local' => null,
                'labels' => [],
                'urgency' => null,
            ],
            [
                'id' => 3,
                'title' => 'Completada sin urgencia activa',
                'description' => null,
                'status' => 'completed',
                'space_id' => 1,
                'project_id' => null,
                'parent_task_id' => null,
                'starts_at_local' => null,
                'ends_at_local' => null,
                'due_at_local' => '2026-08-10 10:00:00',
                'labels' => [],
                'urgency' => ['level' => 'overdue', 'label' => 'Vencida hace 2 dias', 'deadline_input' => '2026-08-10T10:00'],
            ],
        ],
    ]);

    organization_ui_assert(str_contains($taskHtml, '--label-color: #FACC15; --label-text-color: #101418'), 'Light label did not keep its HEX with dark contrast text.');
    organization_ui_assert(str_contains($taskHtml, '--label-color: #1E3A8A; --label-text-color: #FFFFFF'), 'Dark label did not keep its HEX with light contrast text.');
    organization_ui_assert(str_contains($taskHtml, 'class="organization-labels"'), 'Labels were not placed in their wrapping container.');
    organization_ui_assert(str_contains($taskHtml, 'class="deadline-urgency deadline-urgency--warning"'), 'Five-to-seven-day deadline did not render warning urgency.');
    organization_ui_assert(str_contains($taskHtml, 'Limite: 17 ago 2026 · 23:59'), 'Deadline did not use the friendly Santiago display format.');
    organization_ui_assert(!str_contains($taskHtml, 'Inicio Sin fecha') && !str_contains($taskHtml, 'Fin Sin fecha'), 'Empty start/end placeholders were rendered.');
    organization_ui_assert(!str_contains($taskHtml, '>Pendiente</span>'), 'Pending badge was rendered.');
    organization_ui_assert(str_contains($taskHtml, 'Inicio: 12 ago 2026 · 18:00'), 'Start date was not rendered when present.');
    organization_ui_assert(str_contains($taskHtml, 'Fin: 12 ago 2026 · 20:00'), 'End date was not rendered when present.');
    organization_ui_assert(str_contains($taskHtml, 'Completada'), 'Completed state was not rendered discretely.');
    organization_ui_assert(!str_contains($taskHtml, 'Vencida hace 2 dias'), 'Completed task rendered active urgency.');

    $calendarHtml = organization_ui_render([
        'filters' => [
            'tab' => 'calendar',
            'status' => 'all',
            'space' => 'all',
            'label' => 'all',
            'sort' => 'default',
            'time' => 'all',
            'view' => 'month',
            'date' => '2026-08-12',
        ],
        'calendar' => [
            'view' => 'month',
            'title' => 'Agosto 2026',
            'anchor_date' => '2026-08-12',
            'today_date' => '2026-08-12',
            'previous_date' => '2026-07-01',
            'next_date' => '2026-09-01',
            'days' => [
                ['date' => '2026-08-11', 'day' => '11', 'weekday' => 'Mar', 'in_current_month' => true, 'is_today' => false, 'is_selected' => false],
                ['date' => '2026-08-12', 'day' => '12', 'weekday' => 'Mie', 'in_current_month' => true, 'is_today' => true, 'is_selected' => true],
            ],
            'items_by_date' => [],
        ],
    ]);

    organization_ui_assert(substr_count($calendarHtml, 'calendar-day--today') === 1, 'More than one monthly day was marked as today.');
    organization_ui_assert(str_contains($calendarHtml, 'calendar-day--today calendar-day--selected'), 'Today and selected states did not coexist.');
    organization_ui_assert(str_contains($calendarHtml, 'data-calendar-detail') && str_contains($calendarHtml, 'role="dialog"'), 'Calendar detail dialog was not rendered.');

    $calendarItemHtml = organization_ui_render([
        'filters' => [
            'tab' => 'calendar',
            'status' => 'all',
            'space' => 'all',
            'label' => 'all',
            'sort' => 'default',
            'time' => 'all',
            'view' => 'month',
            'date' => '2026-08-12',
        ],
        'calendar' => [
            'view' => 'month',
            'title' => 'Agosto 2026',
            'anchor_date' => '2026-08-12',
            'today_date' => '2026-08-12',
            'previous_date' => '2026-07-01',
            'next_date' => '2026-09-01',
            'days' => [
                ['date' => '2026-08-12', 'day' => '12', 'weekday' => 'Mie', 'in_current_month' => true, 'is_today' => true, 'is_selected' => false],
            ],
            'items_by_date' => [
                '2026-08-12' => [
                    ['type' => 'task-due', 'entity_type' => 'task', 'entity_id' => 10, 'label' => '23:59 Vence: Entrega', 'label_summary' => 'INF295', 'url' => '/index.php?section=organization&tab=tasks&status=all&edit_task=10'],
                    ['type' => 'project', 'entity_type' => 'project', 'entity_id' => 20, 'label' => 'Proyecto Redes', 'label_summary' => '', 'url' => '/index.php?section=organization&tab=projects&project=20'],
                ],
            ],
        ],
    ]);

    organization_ui_assert(str_contains($calendarItemHtml, '<button') && str_contains($calendarItemHtml, 'data-calendar-item'), 'Calendar item was not rendered as an interactive button.');
    organization_ui_assert(str_contains($calendarItemHtml, 'data-entity-type="task"') && str_contains($calendarItemHtml, 'data-entity-id="10"'), 'Task calendar item lacks entity identifiers.');
    organization_ui_assert(str_contains($calendarItemHtml, 'data-entity-type="project"') && str_contains($calendarItemHtml, 'data-entity-id="20"'), 'Project calendar item lacks entity identifiers.');

    $weekHtml = organization_ui_render([
        'filters' => [
            'tab' => 'calendar',
            'status' => 'all',
            'space' => 'all',
            'label' => 'all',
            'sort' => 'default',
            'time' => 'all',
            'view' => 'week',
            'date' => '2026-08-12',
        ],
        'calendar' => [
            'view' => 'week',
            'title' => '10/08/2026 - 16/08/2026',
            'anchor_date' => '2026-08-12',
            'today_date' => '2026-08-12',
            'previous_date' => '2026-08-03',
            'next_date' => '2026-08-17',
            'days' => [
                ['date' => '2026-08-12', 'day' => '12', 'weekday' => 'Mie', 'in_current_month' => true, 'is_today' => true, 'is_selected' => false],
            ],
            'items_by_date' => [],
        ],
    ]);

    organization_ui_assert(str_contains($weekHtml, 'calendar-grid calendar-grid--week') && str_contains($weekHtml, 'calendar-day calendar-day--today'), 'Weekly calendar did not mark today.');

    echo "Organization UI rendering: OK\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, "Organization UI rendering: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
