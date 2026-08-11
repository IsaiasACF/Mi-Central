<?php
declare(strict_types=1);

use App\Http\Csrf;
use App\Support\View;

$tasks = is_array($tasks ?? null) ? $tasks : [];
$projects = is_array($projects ?? null) ? $projects : [];
$notes = is_array($notes ?? null) ? $notes : [];
$reminders = is_array($reminders ?? null) ? $reminders : [];
$calendar = is_array($calendar ?? null) ? $calendar : null;
$taskPrefill = is_array($taskPrefill ?? null) ? $taskPrefill : null;
$selectedProject = is_array($selectedProject ?? null) ? $selectedProject : null;
$selectedProjectTasks = is_array($selectedProjectTasks ?? null) ? $selectedProjectTasks : [];
$spaces = is_array($spaces ?? null) ? $spaces : [];
$filters = array_merge([
    'tab' => 'tasks',
    'status' => 'pending',
    'space' => 'all',
    'priority' => 'all',
    'time' => 'all',
    'view' => 'month',
    'date' => '',
], is_array($filters ?? null) ? $filters : []);
$filterError = is_string($filterError ?? null) ? $filterError : null;
$activeTab = $selectedProject !== null ? 'projects' : (string) $filters['tab'];
$spaceNames = [];
$spaceIdsBySlug = [];

foreach ($spaces as $space) {
    $spaceNames[(string) $space['id']] = (string) $space['name'];
    $spaceIdsBySlug[(string) $space['slug']] = (string) $space['id'];
}

$priorityLabel = static function (?string $priority): string {
    return match ($priority) {
        'low' => 'Baja',
        'high' => 'Alta',
        default => 'Normal',
    };
};

$taskStatusLabel = static function (?string $status): string {
    return $status === 'completed' ? 'Completada' : 'Pendiente';
};

$projectStatusLabel = static function (?string $status): string {
    return match ($status) {
        'completed' => 'Completado',
        'archived' => 'Archivado',
        default => 'Activo',
    };
};

$reminderStatusLabel = static function (?string $status): string {
    return match ($status) {
        'completed' => 'Completado',
        'dismissed' => 'Descartado',
        default => 'Pendiente',
    };
};

$reminderRecurrenceLabel = static function (array $reminder): string {
    $type = (string) ($reminder['recurrence_type'] ?? 'none');
    $interval = max(1, (int) ($reminder['recurrence_interval'] ?? 1));

    if ($type === 'none') {
        return 'No repetir';
    }

    $singular = match ($type) {
        'daily' => 'dia',
        'weekly' => 'semana',
        'monthly' => 'mes',
        'yearly' => 'ano',
        default => '',
    };
    $plural = match ($type) {
        'daily' => 'dias',
        'weekly' => 'semanas',
        'monthly' => 'meses',
        'yearly' => 'anos',
        default => '',
    };

    return $interval === 1 ? 'Cada ' . $singular : 'Cada ' . $interval . ' ' . $plural;
};


$spaceLabel = static function (mixed $spaceId) use ($spaceNames): string {
    if ($spaceId === null || $spaceId === '') {
        return 'Bandeja';
    }

    return $spaceNames[(string) $spaceId] ?? 'Espacio no disponible';
};

$noteSpaceLabel = static function (mixed $spaceId) use ($spaceNames): string {
    if ($spaceId === null || $spaceId === '') {
        return 'Sin espacio';
    }

    return $spaceNames[(string) $spaceId] ?? 'Espacio no disponible';
};

$dateTimeLabel = static function (mixed $value): string {
    if (!is_string($value) || $value === '') {
        return 'Sin fecha';
    }

    return substr($value, 0, 16);
};

$dateTimeInput = static function (mixed $value): string {
    if (!is_string($value) || $value === '') {
        return '';
    }

    return str_replace(' ', 'T', substr($value, 0, 16));
};

$reminderTargetUrl = static function (array $reminder): ?string {
    if (($reminder['task_id'] ?? null) !== null) {
        $taskId = (int) $reminder['task_id'];

        if (($reminder['target_project_id'] ?? null) !== null) {
            return '/index.php?section=organization&tab=projects&project=' . (int) $reminder['target_project_id'] . '&edit_task=' . $taskId;
        }

        return '/index.php?section=organization&tab=tasks&status=all&edit_task=' . $taskId;
    }

    if (($reminder['project_id'] ?? null) !== null) {
        return '/index.php?section=organization&tab=projects&project=' . (int) $reminder['project_id'];
    }

    return null;
};

$reminderTargetLabel = static function (array $reminder): string {
    if (($reminder['task_id'] ?? null) !== null) {
        return 'Tarea: ' . (string) ($reminder['target_title'] ?? $reminder['task_title'] ?? 'sin titulo');
    }

    if (($reminder['project_id'] ?? null) !== null) {
        return 'Proyecto: ' . (string) ($reminder['target_title'] ?? $reminder['project_title'] ?? 'sin titulo');
    }

    return 'Independiente';
};

$dateLabel = static function (mixed $value): string {
    return is_string($value) && $value !== '' ? $value : 'sin fecha';
};

$taskHasFilters = $filters['status'] !== 'pending'
    || $filters['space'] !== 'all'
    || $filters['priority'] !== 'all'
    || $filters['time'] !== 'all';
$taskEmptyText = $taskHasFilters ? 'No hay tareas que coincidan con estos filtros.' : 'No tienes tareas independientes todavia.';
$projectEmptyText = $filters['status'] !== 'active' || $filters['space'] !== 'all'
    ? 'No hay proyectos que coincidan con estos filtros.'
    : 'No tienes proyectos todavia.';
$noteEmptyText = $filters['space'] !== 'all'
    ? 'No hay notas que coincidan con este filtro.'
    : 'No tienes notas todavia.';
$reminderEmptyText = match ($filters['status']) {
    'done' => 'No hay recordatorios completados o descartados.',
    'all' => 'No tienes recordatorios todavia.',
    default => 'No hay recordatorios pendientes.',
};
$quickFilters = [
    'all' => 'Todos',
    'today' => 'Hoy',
    'week' => 'Esta semana',
    'overdue' => 'Vencidos',
];
$taskPrefillJson = $taskPrefill === null
    ? ''
    : (json_encode($taskPrefill, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
$organizationUrl = static function (array $overrides = []) use ($filters): string {
    $query = array_merge([
        'section' => 'organization',
        'tab' => $filters['tab'],
        'space' => $filters['space'],
        'status' => $filters['status'],
        'priority' => $filters['priority'],
        'time' => $filters['time'],
        'view' => $filters['view'],
        'date' => $filters['date'],
    ], $overrides);

    foreach ($query as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        }
    }

    $defaults = [
        'tab' => 'tasks',
        'space' => 'all',
        'status' => ($query['tab'] ?? 'tasks') === 'projects' ? 'active' : ((($query['tab'] ?? 'tasks') === 'notes' || ($query['tab'] ?? 'tasks') === 'calendar') ? 'all' : 'pending'),
        'priority' => 'all',
        'time' => 'all',
        'view' => 'month',
        'date' => '',
    ];

    foreach ($defaults as $key => $default) {
        if (($query[$key] ?? null) === $default) {
            unset($query[$key]);
        }
    }

    return '/index.php?' . http_build_query($query);
};
$tabUrl = static function (string $tab) use ($organizationUrl): string {
    return $organizationUrl([
        'tab' => $tab,
        'status' => $tab === 'projects' ? 'active' : ($tab === 'tasks' || $tab === 'reminders' ? 'pending' : 'all'),
        'priority' => 'all',
        'time' => 'all',
        'project' => null,
        'view' => $tab === 'calendar' ? 'month' : null,
        'date' => null,
    ]);
};
$renderTask = static function (array $task, bool $isSubtask = false) use ($spaceLabel, $priorityLabel, $dateTimeLabel, $dateTimeInput, $taskStatusLabel): void {
    $taskId = (string) $task['id'];
    $status = (string) $task['status'];
    $spaceId = $task['space_id'] === null ? '' : (string) $task['space_id'];
    $projectId = $task['project_id'] === null ? '' : (string) $task['project_id'];
    $parentTaskId = $task['parent_task_id'] === null ? '' : (string) $task['parent_task_id'];
    $startsAtLocal = $task['starts_at_local'] ?? ($task['starts_at'] ?? null);
    $endsAtLocal = $task['ends_at_local'] ?? ($task['ends_at'] ?? null);
    $dueAtLocal = $task['due_at_local'] ?? ($task['due_at'] ?? null);
    ?>
    <article
        class="task-item<?= $status === 'completed' ? ' is-completed' : '' ?><?= $isSubtask ? ' task-item--subtask' : '' ?>"
        data-task-id="<?= View::escape($taskId) ?>"
        data-title="<?= View::escape($task['title']) ?>"
        data-description="<?= View::escape($task['description'] ?? '') ?>"
        data-space-id="<?= View::escape($spaceId) ?>"
        data-project-id="<?= View::escape($projectId) ?>"
        data-parent-task-id="<?= View::escape($parentTaskId) ?>"
        data-priority="<?= View::escape($task['priority']) ?>"
        data-starts-at="<?= View::escape($task['starts_at_input'] ?? $dateTimeInput($startsAtLocal)) ?>"
        data-ends-at="<?= View::escape($task['ends_at_input'] ?? $dateTimeInput($endsAtLocal)) ?>"
        data-due-at="<?= View::escape($task['due_at_input'] ?? $dateTimeInput($dueAtLocal)) ?>"
        data-status="<?= View::escape($status) ?>"
    >
        <div class="task-item__main">
            <h3><?= $isSubtask ? 'Subtarea: ' : '' ?><?= View::escape($task['title']) ?></h3>
            <?php if (($task['description'] ?? null) !== null): ?>
                <p><?= View::escape($task['description']) ?></p>
            <?php endif; ?>
            <div class="task-meta">
                <span><?= View::escape($spaceLabel($task['space_id'] ?? null)) ?></span>
                <span>Prioridad <?= View::escape($priorityLabel((string) $task['priority'])) ?></span>
                <span>Inicio <?= View::escape($dateTimeLabel($startsAtLocal)) ?></span>
                <span>Fin <?= View::escape($dateTimeLabel($endsAtLocal)) ?></span>
                <span>Limite <?= View::escape($dateTimeLabel($dueAtLocal)) ?></span>
                <span><?= View::escape($taskStatusLabel($status)) ?></span>
            </div>
        </div>
        <div class="task-actions">
            <button class="button button--secondary" type="button" data-task-action="<?= $status === 'completed' ? 'reopen' : 'complete' ?>">
                <?= $status === 'completed' ? 'Reabrir' : 'Completar' ?>
            </button>
            <?php if (!$isSubtask && $projectId !== ''): ?>
                <button class="button button--secondary" type="button" data-task-action="subtask">Agregar subtarea</button>
            <?php endif; ?>
            <button class="button button--secondary" type="button" data-task-action="reminder">Agregar recordatorio</button>
            <button class="button button--secondary" type="button" data-task-action="edit">Editar</button>
            <button class="button button--danger" type="button" data-task-action="delete">Eliminar</button>
        </div>
    </article>
    <?php
};
?>
<section
    class="tasks-page"
    aria-labelledby="page-title"
    data-tasks-page
    data-task-page-mode="organization"
    data-active-tab="<?= View::escape($activeTab) ?>"
    data-api-url="/api/organization/tasks.php"
    data-csrf-token="<?= View::escape(Csrf::token()) ?>"
    data-default-status-filter="<?= View::escape($filters['time'] === 'all' ? $filters['status'] : 'pending') ?>"
    data-default-space-filter="<?= View::escape($filters['space'] === 'inbox' ? 'none' : ($spaceIdsBySlug[$filters['space']] ?? 'all')) ?>"
    data-default-priority-filter="<?= View::escape($filters['priority']) ?>"
    data-default-due-from="<?= View::escape($filters['due_from'] ?? '') ?>"
    data-default-due-to="<?= View::escape($filters['due_to'] ?? '') ?>"
    data-default-due-before="<?= View::escape($filters['due_before'] ?? '') ?>"
    data-task-prefill="<?= View::escape($taskPrefillJson) ?>"
    data-independent-tasks="<?= $activeTab === 'tasks' ? 'true' : 'false' ?>"
    data-empty-default="No tienes tareas independientes todavia."
    data-empty-filtered="No hay tareas que coincidan con estos filtros."
>
    <div class="page-heading tasks-heading">
        <div>
            <p class="eyebrow">Organizacion</p>
            <h1 id="page-title">Organizacion</h1>
            <p class="muted">Tareas, proyectos y calendario preparados como areas separadas.</p>
        </div>
        <?php if ($activeTab === 'tasks' || $activeTab === 'calendar'): ?>
            <button class="button button--primary" type="button" data-task-new>+ Nueva tarea</button>
        <?php elseif ($activeTab === 'projects' && $selectedProject === null): ?>
            <button class="button button--primary" type="button" data-project-new>+ Nuevo proyecto</button>
        <?php elseif ($activeTab === 'notes'): ?>
            <button class="button button--primary" type="button" data-note-new>+ Nueva nota</button>
        <?php elseif ($activeTab === 'reminders'): ?>
            <button class="button button--primary" type="button" data-reminder-new>+ Nuevo recordatorio</button>
        <?php endif; ?>
    </div>

    <nav class="organization-tabs" aria-label="Navegacion de Organizacion">
        <a class="organization-tab<?= $activeTab === 'tasks' ? ' is-active' : '' ?>" href="<?= View::escape($tabUrl('tasks')) ?>" <?= $activeTab === 'tasks' ? 'aria-current="page"' : '' ?>>Tareas</a>
        <a class="organization-tab<?= $activeTab === 'projects' ? ' is-active' : '' ?>" href="<?= View::escape($tabUrl('projects')) ?>" <?= $activeTab === 'projects' ? 'aria-current="page"' : '' ?>>Proyectos</a>
        <a class="organization-tab<?= $activeTab === 'notes' ? ' is-active' : '' ?>" href="<?= View::escape($tabUrl('notes')) ?>" <?= $activeTab === 'notes' ? 'aria-current="page"' : '' ?>>Notas</a>
        <a class="organization-tab organization-tab--reminders<?= $activeTab === 'reminders' ? ' is-active' : '' ?>" href="<?= View::escape($tabUrl('reminders')) ?>" <?= $activeTab === 'reminders' ? 'aria-current="page"' : '' ?>>Recordatorios</a>
        <a class="organization-tab organization-tab--calendar<?= $activeTab === 'calendar' ? ' is-active' : '' ?>" href="<?= View::escape($tabUrl('calendar')) ?>" <?= $activeTab === 'calendar' ? 'aria-current="page"' : '' ?>>Calendario</a>
    </nav>

    <?php if ($filterError !== null): ?>
        <p class="task-message task-message--error" role="alert"><?= View::escape($filterError) ?></p>
    <?php endif; ?>
    <p class="task-message" role="status" aria-live="polite" data-task-message hidden></p>

    <?php if ($activeTab !== 'notes'): ?>
        <section class="task-editor" data-task-form-panel hidden>
            <form class="task-form" data-task-form>
                <input type="hidden" name="task_id" value="">
                <input type="hidden" name="project_id" value="">
                <input type="hidden" name="parent_task_id" value="">
                <div class="task-form__header">
                    <h2 data-task-form-title>Nueva tarea</h2>
                    <button class="button button--secondary" type="button" data-task-cancel>Cancelar</button>
                </div>
                <label>
                    <span>Titulo</span>
                    <input name="title" type="text" maxlength="180" required autocomplete="off">
                </label>
                <label>
                    <span>Descripcion</span>
                    <textarea name="description" rows="3"></textarea>
                </label>
                <div class="task-form__grid">
                    <label data-task-space-field>
                        <span>Espacio</span>
                        <select name="space_id">
                            <option value="">Bandeja / sin espacio</option>
                            <?php foreach ($spaces as $space): ?>
                                <option value="<?= View::escape($space['id']) ?>"><?= View::escape($space['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <p class="task-inherited-space" data-project-space-note hidden></p>
                    <label>
                        <span>Prioridad</span>
                        <select name="priority">
                            <option value="normal">Normal</option>
                            <option value="low">Baja</option>
                            <option value="high">Alta</option>
                        </select>
                    </label>
                    <label>
                        <span>Inicio</span>
                        <input name="starts_at" type="datetime-local">
                    </label>
                    <label>
                        <span>Fin</span>
                        <input name="ends_at" type="datetime-local">
                    </label>
                    <label>
                        <span>Fecha limite</span>
                        <input name="due_at" type="datetime-local">
                    </label>
                </div>
                <div class="task-form__actions">
                    <button class="button button--primary" type="submit">Guardar tarea</button>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($activeTab === 'projects' || $selectedProject !== null): ?>
        <section class="task-editor" data-project-form-panel hidden>
            <form class="task-form" data-project-form>
                <input type="hidden" name="project_id" value="">
                <div class="task-form__header">
                    <h2 data-project-form-title>Nuevo proyecto</h2>
                    <button class="button button--secondary" type="button" data-project-cancel>Cancelar</button>
                </div>
                <label>
                    <span>Titulo</span>
                    <input name="title" type="text" maxlength="180" required autocomplete="off">
                </label>
                <label>
                    <span>Descripcion</span>
                    <textarea name="description" rows="3"></textarea>
                </label>
                <div class="task-form__grid">
                    <label>
                        <span>Espacio</span>
                        <select name="space_id" required>
                            <?php foreach ($spaces as $space): ?>
                                <option value="<?= View::escape($space['id']) ?>"><?= View::escape($space['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <span>Estado</span>
                        <select name="status">
                            <option value="active">Activo</option>
                            <option value="completed">Completado</option>
                            <option value="archived">Archivado</option>
                        </select>
                    </label>
                    <label>
                        <span>Inicio</span>
                        <input name="starts_on" type="date">
                    </label>
                    <label>
                        <span>Fecha limite</span>
                        <input name="due_on" type="date">
                    </label>
                </div>
                <div class="task-form__actions">
                    <button class="button button--primary" type="submit">Guardar proyecto</button>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($activeTab === 'notes'): ?>
        <section class="task-editor" data-note-form-panel hidden>
            <form class="task-form" data-note-form>
                <input type="hidden" name="note_id" value="">
                <div class="task-form__header">
                    <h2 data-note-form-title>Nueva nota</h2>
                    <button class="button button--secondary" type="button" data-note-cancel>Cancelar</button>
                </div>
                <label>
                    <span>Titulo</span>
                    <input name="title" type="text" maxlength="180" required autocomplete="off">
                </label>
                <label>
                    <span>Contenido</span>
                    <textarea name="content" rows="7"></textarea>
                </label>
                <div class="task-form__grid task-form__grid--notes">
                    <label>
                        <span>Espacio</span>
                        <select name="space_id">
                            <option value="">Sin espacio</option>
                            <?php foreach ($spaces as $space): ?>
                                <option value="<?= View::escape($space['id']) ?>"><?= View::escape($space['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <div class="task-form__actions">
                    <button class="button button--primary" type="submit">Guardar nota</button>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($activeTab !== 'notes' && $activeTab !== 'calendar'): ?>
        <section class="task-editor" data-reminder-form-panel hidden>
            <form class="task-form" data-reminder-form>
                <input type="hidden" name="reminder_id" value="">
                <input type="hidden" name="task_id" value="">
                <input type="hidden" name="project_id" value="">
                <div class="task-form__header">
                    <h2 data-reminder-form-title>Nuevo recordatorio</h2>
                    <button class="button button--secondary" type="button" data-reminder-cancel>Cancelar</button>
                </div>
                <p class="task-inherited-space reminder-target-note" data-reminder-target-note hidden></p>
                <label>
                    <span>Titulo</span>
                    <input name="title" type="text" maxlength="180" required autocomplete="off">
                </label>
                <label>
                    <span>Descripcion</span>
                    <textarea name="description" rows="3"></textarea>
                </label>
                <div class="task-form__grid task-form__grid--reminders">
                    <label>
                        <span>Fecha</span>
                        <input name="remind_date" type="date" required>
                    </label>
                    <label>
                        <span>Hora</span>
                        <input name="remind_time" type="time" required>
                    </label>
                    <label>
                        <span>Estado</span>
                        <select name="status">
                            <option value="pending">Pendiente</option>
                            <option value="completed">Completado</option>
                            <option value="dismissed">Descartado</option>
                        </select>
                    </label>
                    <label>
                        <span>Repetir</span>
                        <select name="recurrence_type">
                            <option value="none">No repetir</option>
                            <option value="daily">Diario</option>
                            <option value="weekly">Semanal</option>
                            <option value="monthly">Mensual</option>
                            <option value="yearly">Anual</option>
                        </select>
                    </label>
                    <label>
                        <span>Cada</span>
                        <input name="recurrence_interval" type="number" min="1" max="999" step="1" value="1">
                    </label>
                    <label>
                        <span>Repetir hasta</span>
                        <input name="recurrence_until" type="date">
                    </label>
                </div>
                <div class="task-form__actions">
                    <button class="button button--primary" type="submit">Guardar recordatorio</button>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($activeTab === 'tasks'): ?>
        <section class="tasks-panel" aria-label="Filtros de tareas">
            <nav class="quick-filters" aria-label="Filtros rapidos">
                <?php foreach ($quickFilters as $timeKey => $label): ?>
                    <?php $quickOverrides = $timeKey === 'all' ? ['tab' => 'tasks', 'time' => $timeKey] : ['tab' => 'tasks', 'time' => $timeKey, 'status' => 'pending']; ?>
                    <a
                        class="quick-filter<?= $filters['time'] === $timeKey ? ' is-active' : '' ?>"
                        href="<?= View::escape($organizationUrl($quickOverrides)) ?>"
                        <?= $filters['time'] === $timeKey ? 'aria-current="page"' : '' ?>
                    >
                        <?= View::escape($label) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <form class="task-filters" action="/index.php" method="get" data-task-filters>
                <input type="hidden" name="section" value="organization">
                <input type="hidden" name="tab" value="tasks">
                <input type="hidden" name="time" value="<?= View::escape($filters['time']) ?>">
                <label>
                    <span>Espacio</span>
                    <select name="space">
                        <option value="all" <?= $filters['space'] === 'all' ? 'selected' : '' ?>>Todos</option>
                        <option value="inbox" data-space-id="none" <?= $filters['space'] === 'inbox' ? 'selected' : '' ?>>Bandeja</option>
                        <?php foreach ($spaces as $space): ?>
                            <?php $spaceSlug = (string) $space['slug']; ?>
                            <option
                                value="<?= View::escape($spaceSlug) ?>"
                                data-space-id="<?= View::escape($space['id']) ?>"
                                <?= $filters['space'] === $spaceSlug ? 'selected' : '' ?>
                            >
                                <?= View::escape($space['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>Estado</span>
                    <select name="status">
                        <option value="pending" <?= $filters['status'] === 'pending' ? 'selected' : '' ?>>Pendientes</option>
                        <option value="completed" <?= $filters['status'] === 'completed' ? 'selected' : '' ?>>Completadas</option>
                        <option value="all" <?= $filters['status'] === 'all' ? 'selected' : '' ?>>Todas</option>
                    </select>
                </label>
                <label>
                    <span>Prioridad</span>
                    <select name="priority">
                        <option value="all" <?= $filters['priority'] === 'all' ? 'selected' : '' ?>>Todas</option>
                        <option value="low" <?= $filters['priority'] === 'low' ? 'selected' : '' ?>>Baja</option>
                        <option value="normal" <?= $filters['priority'] === 'normal' ? 'selected' : '' ?>>Normal</option>
                        <option value="high" <?= $filters['priority'] === 'high' ? 'selected' : '' ?>>Alta</option>
                    </select>
                </label>
                <button class="button button--secondary" type="submit">Filtrar</button>
            </form>
        </section>

        <section class="tasks-panel" aria-label="Lista de tareas">
            <div class="tasks-list-header">
                <h2>Tareas</h2>
                <span class="task-count" data-task-count><?= count($tasks) ?></span>
            </div>
            <div class="tasks-list" data-task-list>
                <?php if ($tasks === []): ?>
                    <?php View::render('components/empty-state', ['text' => $taskEmptyText]); ?>
                <?php endif; ?>

                <?php foreach ($tasks as $task): ?>
                    <?php $renderTask($task); ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php elseif ($activeTab === 'notes'): ?>
        <section class="tasks-panel" aria-label="Filtros de notas">
            <form class="task-filters task-filters--notes" action="/index.php" method="get" data-note-filters>
                <input type="hidden" name="section" value="organization">
                <input type="hidden" name="tab" value="notes">
                <input type="hidden" name="status" value="all">
                <label>
                    <span>Espacio</span>
                    <select name="space">
                        <option value="all" <?= $filters['space'] === 'all' ? 'selected' : '' ?>>Todos</option>
                        <option value="inbox" data-space-id="none" <?= $filters['space'] === 'inbox' ? 'selected' : '' ?>>Sin espacio</option>
                        <?php foreach ($spaces as $space): ?>
                            <?php $spaceSlug = (string) $space['slug']; ?>
                            <option
                                value="<?= View::escape($spaceSlug) ?>"
                                data-space-id="<?= View::escape($space['id']) ?>"
                                <?= $filters['space'] === $spaceSlug ? 'selected' : '' ?>
                            >
                                <?= View::escape($space['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="button button--secondary" type="submit">Filtrar</button>
            </form>
        </section>

        <section class="tasks-panel" aria-label="Lista de notas" data-notes-panel data-api-url="/api/organization/notes.php">
            <div class="tasks-list-header">
                <h2>Notas</h2>
                <span class="task-count" data-note-count><?= count($notes) ?></span>
            </div>
            <div class="notes-list" data-note-list>
                <?php if ($notes === []): ?>
                    <?php View::render('components/empty-state', ['text' => $noteEmptyText]); ?>
                <?php endif; ?>

                <?php foreach ($notes as $note): ?>
                    <article
                        class="note-item"
                        data-note-id="<?= View::escape($note['id']) ?>"
                        data-title="<?= View::escape($note['title']) ?>"
                        data-content="<?= View::escape($note['content']) ?>"
                        data-space-id="<?= View::escape($note['space_id'] ?? '') ?>"
                    >
                        <div class="task-item__main">
                            <h3><?= View::escape($note['title']) ?></h3>
                            <?php if ((string) ($note['content'] ?? '') !== ''): ?>
                                <p><?= nl2br(View::escape($note['content']), false) ?></p>
                            <?php endif; ?>
                            <div class="task-meta">
                                <span><?= View::escape($noteSpaceLabel($note['space_id'] ?? null)) ?></span>
                            </div>
                        </div>
                        <div class="task-actions">
                            <button class="button button--secondary" type="button" data-note-action="edit">Editar</button>
                            <button class="button button--danger" type="button" data-note-action="delete">Eliminar</button>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php elseif ($activeTab === 'reminders'): ?>
        <section class="tasks-panel" aria-label="Filtros de recordatorios">
            <nav class="quick-filters" aria-label="Filtros de recordatorios">
                <a class="quick-filter<?= $filters['status'] === 'pending' ? ' is-active' : '' ?>" href="<?= View::escape($organizationUrl(['tab' => 'reminders', 'status' => 'pending'])) ?>" <?= $filters['status'] === 'pending' ? 'aria-current="page"' : '' ?>>Pendientes</a>
                <a class="quick-filter<?= $filters['status'] === 'all' ? ' is-active' : '' ?>" href="<?= View::escape($organizationUrl(['tab' => 'reminders', 'status' => 'all'])) ?>" <?= $filters['status'] === 'all' ? 'aria-current="page"' : '' ?>>Todos</a>
                <a class="quick-filter<?= $filters['status'] === 'done' ? ' is-active' : '' ?>" href="<?= View::escape($organizationUrl(['tab' => 'reminders', 'status' => 'done'])) ?>" <?= $filters['status'] === 'done' ? 'aria-current="page"' : '' ?>>Completados/Descartados</a>
            </nav>
        </section>

        <section
            class="tasks-panel"
            aria-label="Lista de recordatorios"
            data-reminders-panel
            data-api-url="/api/organization/reminders.php"
            data-reminder-status-filter="<?= View::escape($filters['status']) ?>"
        >
            <div class="tasks-list-header">
                <h2>Recordatorios</h2>
                <span class="task-count" data-reminder-count><?= count($reminders) ?></span>
            </div>
            <div class="reminders-list" data-reminder-list>
                <?php if ($reminders === []): ?>
                    <?php View::render('components/empty-state', ['text' => $reminderEmptyText]); ?>
                <?php endif; ?>

                <?php foreach ($reminders as $reminder): ?>
                    <?php
                    $reminderId = (string) $reminder['id'];
                    $taskId = $reminder['task_id'] === null ? '' : (string) $reminder['task_id'];
                    $projectId = $reminder['project_id'] === null ? '' : (string) $reminder['project_id'];
                    $targetUrl = $reminderTargetUrl($reminder);
                    ?>
                    <article
                        class="reminder-item<?= !empty($reminder['is_overdue']) ? ' is-overdue' : '' ?>"
                        data-reminder-id="<?= View::escape($reminderId) ?>"
                        data-title="<?= View::escape($reminder['title']) ?>"
                        data-description="<?= View::escape($reminder['description'] ?? '') ?>"
                        data-task-id="<?= View::escape($taskId) ?>"
                        data-project-id="<?= View::escape($projectId) ?>"
                        data-remind-date="<?= View::escape($reminder['remind_date_local'] ?? '') ?>"
                        data-remind-time="<?= View::escape($reminder['remind_time_local'] ?? '') ?>"
                        data-recurrence-type="<?= View::escape($reminder['recurrence_type'] ?? 'none') ?>"
                        data-recurrence-interval="<?= View::escape((string) ($reminder['recurrence_interval'] ?? 1)) ?>"
                        data-recurrence-until="<?= View::escape($reminder['recurrence_until_local'] ?? '') ?>"
                        data-status="<?= View::escape($reminder['status']) ?>"
                    >
                        <div class="task-item__main">
                            <h3><?= View::escape($reminder['title']) ?></h3>
                            <?php if (($reminder['description'] ?? null) !== null): ?>
                                <p><?= nl2br(View::escape($reminder['description']), false) ?></p>
                            <?php endif; ?>
                            <div class="task-meta">
                                <span><?= View::escape(substr((string) ($reminder['occurrence_at_local'] ?? $reminder['remind_at_local'] ?? ''), 0, 16)) ?></span>
                                <?php if ($targetUrl !== null): ?>
                                    <a href="<?= View::escape($targetUrl) ?>"><?= View::escape($reminderTargetLabel($reminder)) ?></a>
                                <?php else: ?>
                                    <span><?= View::escape($reminderTargetLabel($reminder)) ?></span>
                                <?php endif; ?>
                                <span><?= View::escape($reminderStatusLabel((string) $reminder['status'])) ?></span>
                                <?php if (($reminder['recurrence_type'] ?? 'none') !== 'none'): ?>
                                    <span><?= View::escape($reminderRecurrenceLabel($reminder)) ?></span>
                                    <?php if (($reminder['recurrence_until_local'] ?? null) !== null): ?>
                                        <span>Hasta <?= View::escape($reminder['recurrence_until_local']) ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if (!empty($reminder['is_overdue'])): ?>
                                    <span class="reminder-overdue">Atrasado</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="task-actions">
                            <?php if ((string) $reminder['status'] === 'pending'): ?>
                                <button class="button button--secondary" type="button" data-reminder-action="complete">Completar</button>
                                <button class="button button--secondary" type="button" data-reminder-action="dismiss">Descartar</button>
                            <?php endif; ?>
                            <?php if (($reminder['recurrence_type'] ?? 'none') !== 'none'): ?>
                                <button class="button button--secondary" type="button" data-reminder-action="stop">Detener recurrencia</button>
                            <?php endif; ?>
                            <button class="button button--secondary" type="button" data-reminder-action="edit">Editar</button>
                            <button class="button button--danger" type="button" data-reminder-action="delete">Eliminar</button>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php elseif ($activeTab === 'calendar'): ?>
        <?php
        $calendar = $calendar ?? ['view' => 'month', 'title' => '', 'days' => [], 'items_by_date' => []];
        $calendarItemsByDate = is_array($calendar['items_by_date'] ?? null) ? $calendar['items_by_date'] : [];
        $calendarView = (string) ($calendar['view'] ?? 'month');
        ?>
        <section class="tasks-panel calendar-panel" aria-label="Calendario">
            <div class="calendar-toolbar">
                <div>
                    <h2><?= View::escape($calendar['title'] ?? 'Calendario') ?></h2>
                    <p class="muted">Vista en America/Santiago sobre tareas y proyectos existentes.</p>
                </div>
                <div class="calendar-actions">
                    <a class="button button--secondary" href="<?= View::escape($organizationUrl(['tab' => 'calendar', 'date' => $calendar['previous_date'] ?? '', 'view' => $calendarView, 'status' => 'all'])) ?>">Anterior</a>
                    <a class="button button--secondary" href="<?= View::escape($organizationUrl(['tab' => 'calendar', 'date' => '', 'view' => $calendarView, 'status' => 'all'])) ?>">Hoy</a>
                    <a class="button button--secondary" href="<?= View::escape($organizationUrl(['tab' => 'calendar', 'date' => $calendar['next_date'] ?? '', 'view' => $calendarView, 'status' => 'all'])) ?>">Siguiente</a>
                </div>
            </div>
            <nav class="quick-filters" aria-label="Vistas de calendario">
                <a class="quick-filter<?= $calendarView === 'month' ? ' is-active' : '' ?>" href="<?= View::escape($organizationUrl(['tab' => 'calendar', 'view' => 'month', 'status' => 'all'])) ?>" <?= $calendarView === 'month' ? 'aria-current="page"' : '' ?>>Mes</a>
                <a class="quick-filter<?= $calendarView === 'week' ? ' is-active' : '' ?>" href="<?= View::escape($organizationUrl(['tab' => 'calendar', 'view' => 'week', 'status' => 'all'])) ?>" <?= $calendarView === 'week' ? 'aria-current="page"' : '' ?>>Semana</a>
            </nav>
            <div class="calendar-grid calendar-grid--<?= View::escape($calendarView) ?>">
                <?php foreach (($calendar['days'] ?? []) as $day): ?>
                    <?php
                    $dayItems = $calendarItemsByDate[(string) $day['date']] ?? [];
                    $dayClasses = 'calendar-day' . (!($day['in_current_month'] ?? true) ? ' calendar-day--muted' : '');
                    ?>
                    <section class="<?= View::escape($dayClasses) ?>" aria-label="<?= View::escape((string) $day['date']) ?>">
                        <header class="calendar-day__header">
                            <span><?= View::escape($day['weekday'] ?? '') ?></span>
                            <time datetime="<?= View::escape($day['date']) ?>"><?= View::escape($day['day'] ?? '') ?></time>
                        </header>
                        <div class="calendar-day__items">
                            <?php foreach ($dayItems as $item): ?>
                                <a class="calendar-item calendar-item--<?= View::escape($item['type'] ?? '') ?>" href="<?= View::escape($item['url'] ?? '#') ?>">
                                    <?= View::escape($item['label'] ?? '') ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
        </section>
    <?php elseif ($activeTab === 'projects' && $selectedProject === null): ?>
        <section class="tasks-panel" aria-label="Filtros de proyectos">
            <form class="task-filters task-filters--projects" action="/index.php" method="get">
                <input type="hidden" name="section" value="organization">
                <input type="hidden" name="tab" value="projects">
                <label>
                    <span>Espacio</span>
                    <select name="space">
                        <option value="all" <?= $filters['space'] === 'all' ? 'selected' : '' ?>>Todos</option>
                        <?php foreach ($spaces as $space): ?>
                            <?php $spaceSlug = (string) $space['slug']; ?>
                            <option value="<?= View::escape($spaceSlug) ?>" <?= $filters['space'] === $spaceSlug ? 'selected' : '' ?>>
                                <?= View::escape($space['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>Estado</span>
                    <select name="status">
                        <option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>Activos</option>
                        <option value="completed" <?= $filters['status'] === 'completed' ? 'selected' : '' ?>>Completados</option>
                        <option value="archived" <?= $filters['status'] === 'archived' ? 'selected' : '' ?>>Archivados</option>
                        <option value="all" <?= $filters['status'] === 'all' ? 'selected' : '' ?>>Todos</option>
                    </select>
                </label>
                <button class="button button--secondary" type="submit">Filtrar</button>
            </form>
        </section>

        <section class="tasks-panel" aria-label="Lista de proyectos">
            <div class="tasks-list-header">
                <h2>Proyectos</h2>
                <span class="task-count"><?= count($projects) ?></span>
            </div>
            <?php if ($projects === []): ?>
                <?php View::render('components/empty-state', ['text' => $projectEmptyText]); ?>
            <?php else: ?>
                <div class="projects-list" data-project-list>
                    <?php foreach ($projects as $project): ?>
                        <article
                            class="project-card"
                            data-project-id="<?= View::escape($project['id']) ?>"
                            data-title="<?= View::escape($project['title']) ?>"
                            data-description="<?= View::escape($project['description'] ?? '') ?>"
                            data-space-id="<?= View::escape($project['space_id']) ?>"
                            data-status="<?= View::escape($project['status']) ?>"
                            data-starts-on="<?= View::escape($project['starts_on'] ?? '') ?>"
                            data-due-on="<?= View::escape($project['due_on'] ?? '') ?>"
                        >
                            <div class="project-card__header">
                                <div>
                                    <h3><?= View::escape($project['title']) ?></h3>
                                    <p><?= (int) $project['tasks_completed'] ?> de <?= (int) $project['tasks_total'] ?> tareas completadas<?= (int) $project['tasks_total'] > 0 ? ' - ' . (int) $project['progress_percent'] . '%' : ' - 0 tareas' ?></p>
                                </div>
                                <span><?= View::escape($projectStatusLabel((string) $project['status'])) ?></span>
                            </div>
                            <div class="task-meta">
                                <span><?= View::escape($spaceLabel($project['space_id'] ?? null)) ?></span>
                                <span>Inicio <?= View::escape($dateLabel($project['starts_on'] ?? null)) ?></span>
                                <span>Limite <?= View::escape($dateLabel($project['due_on'] ?? null)) ?></span>
                            </div>
                            <div class="task-actions">
                                <a class="button button--secondary" href="/index.php?section=organization&tab=projects&project=<?= View::escape($project['id']) ?>">Ver</a>
                                <button class="button button--secondary" type="button" data-project-action="edit">Editar</button>
                                <button class="button button--secondary" type="button" data-project-action="reminder">Agregar recordatorio</button>
                                <button class="button button--secondary" type="button" data-project-action="complete">Completar</button>
                                <button class="button button--secondary" type="button" data-project-action="archive">Archivar</button>
                                <button class="button button--danger" type="button" data-project-action="delete">Eliminar</button>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php elseif ($selectedProject !== null): ?>
        <section
            class="tasks-panel project-detail"
            data-project-detail
            data-project-id="<?= View::escape($selectedProject['id']) ?>"
            data-project-space-id="<?= View::escape($selectedProject['space_id']) ?>"
            data-project-space-label="<?= View::escape($spaceLabel($selectedProject['space_id'] ?? null)) ?>"
            data-title="<?= View::escape($selectedProject['title']) ?>"
        >
            <a class="widget-link" href="/index.php?section=organization&tab=projects">Volver a proyectos</a>
            <div class="project-card__header">
                <div>
                    <p class="eyebrow">Proyecto</p>
                    <h2><?= View::escape($selectedProject['title']) ?></h2>
                    <?php if (($selectedProject['description'] ?? null) !== null): ?>
                        <p class="muted"><?= View::escape($selectedProject['description']) ?></p>
                    <?php endif; ?>
                </div>
                <span class="task-count"><?= View::escape($projectStatusLabel((string) $selectedProject['status'])) ?></span>
            </div>
            <div class="task-meta">
                <span><?= View::escape($spaceLabel($selectedProject['space_id'] ?? null)) ?></span>
                <span>Inicio <?= View::escape($dateLabel($selectedProject['starts_on'] ?? null)) ?></span>
                <span>Limite <?= View::escape($dateLabel($selectedProject['due_on'] ?? null)) ?></span>
            </div>
            <p class="project-progress">
                <?= (int) $selectedProject['tasks_completed'] ?> de <?= (int) $selectedProject['tasks_total'] ?> tareas completadas
                <?php if ((int) $selectedProject['tasks_total'] > 0): ?>
                    - <?= (int) $selectedProject['progress_percent'] ?>%
                <?php else: ?>
                    - 0 tareas
                <?php endif; ?>
            </p>
            <div class="task-actions task-actions--start">
                <button class="button button--primary" type="button" data-project-task-new>+ Tarea del proyecto</button>
                <button class="button button--secondary" type="button" data-project-reminder-new>Agregar recordatorio</button>
            </div>
        </section>

        <section class="tasks-panel" aria-label="Tareas del proyecto">
            <div class="tasks-list-header">
                <h2>Tareas del proyecto</h2>
                <span class="task-count" data-task-count><?= count($selectedProjectTasks) ?></span>
            </div>
            <div class="tasks-list" data-task-list>
                <?php if ($selectedProjectTasks === []): ?>
                    <?php View::render('components/empty-state', ['text' => 'Este proyecto todavia no tiene tareas.']); ?>
                <?php endif; ?>

                <?php
                $childrenByParent = [];
                $parentTasks = [];
                foreach ($selectedProjectTasks as $task) {
                    if (($task['parent_task_id'] ?? null) !== null) {
                        $childrenByParent[(string) $task['parent_task_id']][] = $task;
                        continue;
                    }
                    $parentTasks[] = $task;
                }
                foreach ($parentTasks as $task) {
                    $renderTask($task);
                    foreach ($childrenByParent[(string) $task['id']] ?? [] as $childTask) {
                        $renderTask($childTask, true);
                    }
                }
                ?>
            </div>
        </section>
    <?php endif; ?>
</section>
