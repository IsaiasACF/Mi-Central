<?php
declare(strict_types=1);

use App\Http\Csrf;
use App\Support\View;

$friends = is_array($friends ?? null) ? $friends : [];
$activeFriends = is_array($activeFriends ?? null) ? $activeFriends : [];
$scheduleEntries = is_array($scheduleEntries ?? null) ? $scheduleEntries : [];
$nowItems = is_array($nowItems ?? null) ? $nowItems : [];
$todayItems = is_array($todayItems ?? null) ? $todayItems : [];
$week = is_array($week ?? null) ? $week : null;
$coincidences = is_array($coincidences ?? null) ? $coincidences : null;
$filters = array_merge([
    'status' => 'active',
    'tab' => 'now',
    'target_type' => 'user',
    'friend_id' => '',
    'target' => 'user',
    'show' => 'active',
    'week' => '',
    'coincidence_date' => '',
    'coincidence_friend_id' => '',
    'same_campus_only' => '0',
], is_array($filters ?? null) ? $filters : []);
$activeStatus = in_array($filters['status'], ['active', 'all', 'inactive'], true) ? (string) $filters['status'] : 'active';
$activeTab = in_array($filters['tab'], ['now', 'today', 'week', 'coincidences', 'friends', 'schedules'], true) ? (string) $filters['tab'] : 'now';
$targetType = $filters['target_type'] === 'friend' ? 'friend' : 'user';
$targetFriendId = (string) ($filters['friend_id'] ?? '');
$targetValue = $targetType === 'friend' && $targetFriendId !== '' ? 'friend:' . $targetFriendId : 'user';
$showMode = $filters['show'] === 'all' ? 'all' : 'active';
$days = [
    1 => 'Lun',
    2 => 'Mar',
    3 => 'Mie',
    4 => 'Jue',
    5 => 'Vie',
    6 => 'Sab',
    7 => 'Dom',
];
$dayLong = [
    1 => 'Lunes',
    2 => 'Martes',
    3 => 'Miercoles',
    4 => 'Jueves',
    5 => 'Viernes',
    6 => 'Sabado',
    7 => 'Domingo',
];
$today = new DateTimeImmutable('now', new DateTimeZone('America/Santiago'));
$todayWeekday = (int) $today->format('N');
$weekStart = $today->modify('-' . ($todayWeekday - 1) . ' days');
$monthNames = [
    1 => 'enero',
    2 => 'febrero',
    3 => 'marzo',
    4 => 'abril',
    5 => 'mayo',
    6 => 'junio',
    7 => 'julio',
    8 => 'agosto',
    9 => 'septiembre',
    10 => 'octubre',
    11 => 'noviembre',
    12 => 'diciembre',
];
$dayDateLabels = [];
foreach ($days as $weekday => $_label) {
    $dayDate = $weekStart->modify('+' . ($weekday - 1) . ' days');
    $dayDateLabels[$weekday] = $dayDate->format('j') . ' de ' . $monthNames[(int) $dayDate->format('n')];
}
$selectedTargetLabel = 'Mi horario';
foreach ($activeFriends as $friend) {
    if ($targetValue === 'friend:' . (int) $friend['id']) {
        $selectedTargetLabel = (string) ($friend['name'] ?? 'Amigo');
        break;
    }
}
$friendUrl = static function (string $status) use ($activeTab): string {
    $query = [
        'section' => 'friends',
        'tab' => $activeTab,
        'status' => $status,
    ];

    if ($status === 'active') {
        unset($query['status']);
    }

    if ($activeTab === 'friends') {
        unset($query['tab']);
    }

    return '/index.php?' . http_build_query($query);
};
$tabUrl = static function (string $tab) use ($activeStatus, $targetValue, $showMode): string {
    $query = [
        'section' => 'friends',
        'tab' => $tab,
        'status' => $activeStatus,
        'target' => $targetValue,
        'show' => $showMode,
    ];

    if ($tab === 'now') {
        unset($query['tab'], $query['target'], $query['show']);
    }

    if ($tab === 'friends' || $tab === 'today') {
        unset($query['target'], $query['show']);
    }

    if ($activeStatus === 'active') {
        unset($query['status']);
    }

    return '/index.php?' . http_build_query($query);
};
$scheduleUrl = static function (array $overrides = []) use ($targetValue, $showMode): string {
    $query = array_merge([
        'section' => 'friends',
        'tab' => 'schedules',
        'target' => $targetValue,
        'show' => $showMode,
    ], $overrides);

    if (($query['show'] ?? '') === 'active') {
        unset($query['show']);
    }

    return '/index.php?' . http_build_query($query);
};
$weekUrl = static function (array $overrides = []) use ($targetValue, $filters): string {
    $query = array_merge([
        'section' => 'friends',
        'tab' => 'week',
        'target' => $targetValue,
        'week' => (string) ($filters['week'] ?? ''),
    ], $overrides);

    if (($query['week'] ?? '') === '') {
        unset($query['week']);
    }

    return '/index.php?' . http_build_query($query);
};
$coincidenceUrl = static function (array $overrides = []) use ($filters): string {
    $query = array_merge([
        'section' => 'friends',
        'tab' => 'coincidences',
        'date' => (string) ($filters['coincidence_date'] ?? ''),
        'friend_id' => (string) ($filters['coincidence_friend_id'] ?? ''),
        'same_campus_only' => (string) ($filters['same_campus_only'] ?? '0'),
    ], $overrides);

    foreach (['date', 'friend_id'] as $key) {
        if (($query[$key] ?? '') === '') {
            unset($query[$key]);
        }
    }

    if (($query['same_campus_only'] ?? '') !== '1') {
        unset($query['same_campus_only']);
    }

    return '/index.php?' . http_build_query($query);
};
$coincidenceTaskUrl = static function (array $item, string $type = 'individual') use ($coincidenceUrl): string {
    $query = [
        'section' => 'organization',
        'tab' => 'tasks',
        'from' => 'coincidence',
        'type' => $type,
        'date' => (string) ($item['date'] ?? ''),
        'starts_at' => substr((string) ($item['starts_at'] ?? ''), 0, 5),
        'ends_at' => substr((string) ($item['ends_at'] ?? ''), 0, 5),
        'return_to' => $coincidenceUrl(),
    ];

    if ($type === 'group') {
        $friendIds = [];

        foreach (is_array($item['friends'] ?? null) ? $item['friends'] : [] as $friend) {
            $friendIds[] = (int) ($friend['id'] ?? 0);
        }

        $query['friend_ids'] = implode(',', array_values(array_filter($friendIds)));
    } else {
        $query['friend_id'] = (int) ($item['friend_id'] ?? 0);
    }

    return '/index.php?' . http_build_query($query);
};
$statusLabel = static function (mixed $active): string {
    return (int) $active === 1 ? 'Activo' : 'Inactivo';
};
$field = static function (mixed $value, string $fallback): string {
    $value = trim((string) ($value ?? ''));

    return $value === '' ? $fallback : $value;
};
$stateSlug = static function (mixed $value): string {
    return str_replace(' ', '-', strtolower((string) $value));
};
$timeInput = static function (mixed $value): string {
    return is_string($value) && $value !== '' ? substr($value, 0, 5) : '';
};
$timeMinutes = static function (mixed $value): int {
    $time = is_string($value) && $value !== '' ? substr($value, 0, 5) : '08:00';
    [$hours, $minutes] = array_map('intval', explode(':', $time));

    return ($hours * 60) + $minutes;
};
$durationLabel = static function (mixed $minutes): string {
    $minutes = max(0, (int) $minutes);
    $hours = intdiv($minutes, 60);
    $remaining = $minutes % 60;

    if ($hours > 0 && $remaining > 0) {
        return $hours . ' h ' . $remaining . ' min';
    }

    if ($hours > 0) {
        return $hours . ' h';
    }

    return $remaining . ' min';
};
$minutesUntilLabel = static function (mixed $minutes) use ($durationLabel): string {
    $minutes = max(0, (int) $minutes);

    return $minutes === 0 ? 'Ahora' : 'En ' . $durationLabel($minutes);
};
$campusState = static function (array $item, string $friendName): string {
    $userCampus = trim((string) ($item['user_campus'] ?? ''));
    $friendCampus = trim((string) ($item['friend_campus'] ?? ''));

    if (($item['same_campus'] ?? false) === true && $userCampus !== '') {
        return 'Mismo campus segun horario · Campus probable: ' . $userCampus;
    }

    if ($userCampus !== '' && $friendCampus !== '') {
        return 'Campus distintos segun horario · Tu: ' . $userCampus . ' · ' . $friendName . ': ' . $friendCampus;
    }

    return 'Campus no determinado';
};
$blockCourseLabel = static function (array $block): string {
    $code = trim((string) ($block['course_code'] ?? ''));
    $name = trim((string) ($block['course_name'] ?? ''));

    return $code !== '' ? $code : ($name !== '' ? $name : 'Actividad academica');
};
$blockDetailLabel = static function (array $block) use ($blockCourseLabel): string {
    $parts = [$blockCourseLabel($block)];
    $room = trim((string) ($block['room'] ?? ''));

    if ($room !== '') {
        $parts[] = $room;
    }

    return implode(' · ', $parts);
};
$overlapLabel = static function (array $item): string {
    return match ((string) ($item['overlap_type'] ?? 'same_time')) {
        'exact_course' => 'Coincidencia exacta',
        'partial' => 'Coincidencia parcial',
        default => 'Coincidencia de horario',
    };
};
$sameCourseLabel = static function (array $item) use ($blockCourseLabel): string {
    if (($item['same_course'] ?? false) !== true || !is_array($item['user_block'] ?? null)) {
        return '';
    }

    return 'Mismo ramo · ' . $blockCourseLabel($item['user_block']);
};
$entriesByDay = [];
foreach ($days as $weekday => $_label) {
    $entriesByDay[$weekday] = [];
}
foreach ($scheduleEntries as $entry) {
    $weekday = (int) ($entry['weekday'] ?? 0);
    if (isset($entriesByDay[$weekday])) {
        $entriesByDay[$weekday][] = $entry;
    }
}
foreach ($entriesByDay as &$dayEntries) {
    usort($dayEntries, static function (array $left, array $right) use ($timeMinutes): int {
        return $timeMinutes($left['starts_at'] ?? null) <=> $timeMinutes($right['starts_at'] ?? null);
    });
}
unset($dayEntries);
?>
<section
    class="tasks-page"
    data-friends-page
    data-api-url="/api/friends/friends.php"
    data-schedule-api-url="/api/friends/schedule.php"
    data-csrf-token="<?= View::escape(Csrf::token()) ?>"
    data-friend-status-filter="<?= View::escape($activeStatus) ?>"
    data-schedule-target-type="<?= View::escape($targetType) ?>"
    data-schedule-friend-id="<?= View::escape($targetFriendId) ?>"
    data-schedule-show="<?= View::escape($showMode) ?>"
    data-schedule-current-weekday="<?= (int) $todayWeekday ?>"
    aria-labelledby="page-title"
>
    <div class="tasks-heading">
        <div>
            <p class="eyebrow">Fase 4</p>
            <h1 id="page-title">Amigos en la U</h1>
            <p class="muted">Gestiona amigos y horarios academicos semanales ingresados manualmente.</p>
        </div>
        <?php if ($activeTab === 'friends'): ?>
            <button class="button button--primary" type="button" data-friend-new>Nuevo amigo</button>
        <?php elseif ($activeTab === 'schedules'): ?>
            <div class="task-actions">
                <button class="button button--secondary" type="button" data-schedule-import-open>Importar horario</button>
                <button class="button button--primary" type="button" data-schedule-new>Agregar bloque</button>
            </div>
        <?php endif; ?>
    </div>

    <nav class="organization-tabs" aria-label="Navegacion interna de Amigos en la U">
        <a class="organization-tab<?= $activeTab === 'now' ? ' is-active' : '' ?>" href="<?= View::escape($tabUrl('now')) ?>" <?= $activeTab === 'now' ? 'aria-current="page"' : '' ?>>Ahora</a>
        <a class="organization-tab<?= $activeTab === 'today' ? ' is-active' : '' ?>" href="<?= View::escape($tabUrl('today')) ?>" <?= $activeTab === 'today' ? 'aria-current="page"' : '' ?>>Hoy</a>
        <a class="organization-tab<?= $activeTab === 'week' ? ' is-active' : '' ?>" href="<?= View::escape($weekUrl()) ?>" <?= $activeTab === 'week' ? 'aria-current="page"' : '' ?>>Semana</a>
        <a class="organization-tab<?= $activeTab === 'coincidences' ? ' is-active' : '' ?>" href="<?= View::escape($coincidenceUrl()) ?>" <?= $activeTab === 'coincidences' ? 'aria-current="page"' : '' ?>>Coincidencias</a>
        <a class="organization-tab<?= $activeTab === 'friends' ? ' is-active' : '' ?>" href="<?= View::escape($tabUrl('friends')) ?>" <?= $activeTab === 'friends' ? 'aria-current="page"' : '' ?>>Amigos</a>
        <a class="organization-tab<?= $activeTab === 'schedules' ? ' is-active' : '' ?>" href="<?= View::escape($tabUrl('schedules')) ?>" <?= $activeTab === 'schedules' ? 'aria-current="page"' : '' ?>>Horarios</a>
    </nav>

    <p class="task-message" data-friend-message hidden></p>

    <?php if ($activeTab === 'now'): ?>
        <section class="tasks-panel friends-status-panel" aria-label="Amigos ahora">
            <div class="tasks-list-header">
                <h2>Ahora</h2>
                <span class="task-count"><?= count($nowItems) ?></span>
            </div>
            <p class="muted">Estados calculados segun horarios ingresados manualmente. No es ubicacion real.</p>
            <div class="tasks-list">
                <?php if ($nowItems === []): ?>
                    <div class="empty-state">
                        <span aria-hidden="true"></span>
                        <p>No hay amigos activos para mostrar.</p>
                    </div>
                <?php endif; ?>
                <?php foreach ($nowItems as $item): ?>
                    <?php
                    $friend = is_array($item['friend'] ?? null) ? $item['friend'] : [];
                    $entry = is_array($item['entry'] ?? null) ? $item['entry'] : null;
                    ?>
                    <article class="task-item friend-status-card">
                        <div class="task-item__main">
                            <p class="dashboard-card__eyebrow">Segun horario</p>
                            <h3><?= View::escape($friend['name'] ?? 'Amigo') ?></h3>
                            <p><?= View::escape($item['summary'] ?? '') ?></p>
                            <div class="task-meta">
                                <span><?= View::escape($item['status'] ?? '') ?></span>
                                <?php if ($entry !== null): ?>
                                    <span><?= View::escape(($item['starts_at'] ?? '') . ' - ' . ($item['ends_at'] ?? '')) ?></span>
                                    <span><?= View::escape($entry['course_name'] ?? '') ?></span>
                                    <?php if (($entry['room'] ?? null) !== null && $entry['room'] !== ''): ?>
                                        <span><?= View::escape($entry['room']) ?></span>
                                    <?php endif; ?>
                                    <?php if (($entry['effective_campus'] ?? null) !== null && $entry['effective_campus'] !== ''): ?>
                                        <span><?= View::escape($entry['effective_campus']) ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php elseif ($activeTab === 'today'): ?>
        <section class="tasks-panel friends-status-panel" aria-label="Horarios de hoy">
            <div class="tasks-list-header">
                <h2>Hoy</h2>
                <span class="task-count"><?= count($todayItems) ?></span>
            </div>
            <div class="tasks-list">
                <?php if ($todayItems === []): ?>
                    <div class="empty-state">
                        <span aria-hidden="true"></span>
                        <p>No hay amigos activos para mostrar.</p>
                    </div>
                <?php endif; ?>
                <?php foreach ($todayItems as $item): ?>
                    <?php
                    $friend = is_array($item['friend'] ?? null) ? $item['friend'] : [];
                    $blocks = is_array($item['blocks'] ?? null) ? $item['blocks'] : [];
                    ?>
                    <article class="project-card friend-day-card">
                        <div class="project-card__header">
                            <div>
                                <h3><?= View::escape($friend['name'] ?? 'Amigo') ?></h3>
                                <p>Segun horario ingresado</p>
                            </div>
                        </div>
                        <?php if ($blocks === []): ?>
                            <p class="muted">Sin clases vigentes hoy.</p>
                        <?php else: ?>
                            <div class="friend-day-list">
                                <?php foreach ($blocks as $block): ?>
                                    <div class="friend-day-block friend-day-block--<?= View::escape($stateSlug($block['state'] ?? '')) ?>">
                                        <strong><?= View::escape($block['time_label'] ?? '') ?></strong>
                                        <span><?= View::escape($block['course_name'] ?? '') ?></span>
                                        <?php if (($block['room'] ?? null) !== null && $block['room'] !== ''): ?>
                                            <span><?= View::escape($block['room']) ?></span>
                                        <?php endif; ?>
                                        <?php if (($block['effective_campus'] ?? null) !== null && $block['effective_campus'] !== ''): ?>
                                            <span><?= View::escape($block['effective_campus']) ?></span>
                                        <?php endif; ?>
                                        <?php if (($block['exception_label'] ?? null) !== null && $block['exception_label'] !== ''): ?>
                                            <em><?= View::escape($block['exception_label']) ?></em>
                                        <?php endif; ?>
                                        <em><?= View::escape($block['state'] ?? '') ?></em>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php elseif ($activeTab === 'week'): ?>
        <?php
        $week = is_array($week) ? $week : ['days' => [], 'label' => '', 'previous' => '', 'next' => '', 'today' => ''];
        $weekDays = is_array($week['days'] ?? null) ? $week['days'] : [];
        $weekBlockCount = array_sum(array_map(static fn (array $day): int => count(is_array($day['blocks'] ?? null) ? $day['blocks'] : []), $weekDays));
        ?>
        <section class="tasks-panel schedule-panel" data-friends-week aria-label="Semana por fechas">
            <form class="task-filters task-filters--schedule" method="get">
                <input type="hidden" name="section" value="friends">
                <input type="hidden" name="tab" value="week">
                <input type="hidden" name="week" value="<?= View::escape((string) ($week['start'] ?? '')) ?>">
                <label>
                    Horario
                    <select name="target">
                        <option value="user"<?= $targetValue === 'user' ? ' selected' : '' ?>>Mi horario</option>
                        <?php foreach ($activeFriends as $friend): ?>
                            <?php $friendOption = 'friend:' . (int) $friend['id']; ?>
                            <option value="<?= View::escape($friendOption) ?>"<?= $targetValue === $friendOption ? ' selected' : '' ?>>
                                <?= View::escape($friend['name'] ?? '') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="task-actions task-actions--start">
                    <button class="button button--secondary" type="submit">Aplicar</button>
                </div>
            </form>

            <div class="calendar-toolbar">
                <div>
                    <p class="dashboard-card__eyebrow">Semana real</p>
                    <h2><?= View::escape((string) ($week['label'] ?? '')) ?></h2>
                </div>
                <div class="calendar-actions">
                    <a class="button button--secondary" href="<?= View::escape($weekUrl(['week' => (string) ($week['previous'] ?? '')])) ?>">Semana anterior</a>
                    <a class="button button--secondary" href="<?= View::escape($weekUrl(['week' => (string) ($week['today'] ?? '')])) ?>">Hoy</a>
                    <a class="button button--secondary" href="<?= View::escape($weekUrl(['week' => (string) ($week['next'] ?? '')])) ?>">Semana siguiente</a>
                </div>
            </div>

            <div class="tasks-list-header">
                <h2>Bloques efectivos</h2>
                <span class="task-count"><?= (int) $weekBlockCount ?></span>
            </div>

            <div class="schedule-mobile-days" role="tablist" aria-label="Dias de esta semana">
                <?php foreach ($weekDays as $day): ?>
                    <?php
                    $date = (string) ($day['date'] ?? '');
                    $isToday = $date === (string) ($week['today'] ?? '');
                    ?>
                    <button class="quick-filter<?= $isToday ? ' is-active is-today' : '' ?>" type="button" role="tab" aria-selected="<?= $isToday ? 'true' : 'false' ?>" data-week-day-tab="<?= View::escape($date) ?>">
                        <?= View::escape($days[(int) ($day['weekday'] ?? 1)] ?? '') ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <div class="schedule-week schedule-week--dated">
                <div class="schedule-week__corner" aria-hidden="true"></div>
                <?php foreach ($weekDays as $day): ?>
                    <div class="schedule-week__head"><?= View::escape(($days[(int) ($day['weekday'] ?? 1)] ?? '') . ' ' . (string) ($day['label'] ?? '')) ?></div>
                <?php endforeach; ?>
                <div class="schedule-time-axis" aria-hidden="true">
                    <?php for ($hour = 8; $hour <= 21; $hour++): ?>
                        <span><?= str_pad((string) $hour, 2, '0', STR_PAD_LEFT) ?>:00</span>
                    <?php endfor; ?>
                </div>
                <?php foreach ($weekDays as $day): ?>
                    <?php
                    $date = (string) ($day['date'] ?? '');
                    $isToday = $date === (string) ($week['today'] ?? '');
                    $blocks = is_array($day['blocks'] ?? null) ? $day['blocks'] : [];
                    ?>
                    <div class="schedule-day-column<?= $isToday ? ' is-mobile-active is-today' : '' ?>" data-week-day="<?= View::escape($date) ?>" aria-label="<?= View::escape(($dayLong[(int) ($day['weekday'] ?? 1)] ?? '') . ' ' . $date) ?>">
                        <div class="schedule-day-column__mobile-heading">
                            <span><?= View::escape($dayLong[(int) ($day['weekday'] ?? 1)] ?? '') ?></span>
                            <small><?= View::escape($date) ?></small>
                        </div>
                        <?php if ($blocks === []): ?>
                            <p class="muted schedule-empty-day">Sin bloques.</p>
                        <?php endif; ?>
                        <?php foreach ($blocks as $block): ?>
                            <?php
                            $exceptionType = (string) ($block['exception_type'] ?? '');
                            $warnings = is_array($block['warnings'] ?? null) ? $block['warnings'] : [];
                            ?>
                            <article
                                class="schedule-block schedule-block--static<?= $exceptionType !== '' ? ' schedule-block--exception schedule-block--' . View::escape($exceptionType) : '' ?><?= !empty($block['has_overlap']) ? ' has-warning' : '' ?>"
                                data-starts-at="<?= View::escape($timeInput($block['starts_at'] ?? null)) ?>"
                                data-ends-at="<?= View::escape($timeInput($block['ends_at'] ?? null)) ?>"
                            >
                                <strong><?= View::escape($block['course_name'] ?? '') ?></strong>
                                <span><?= View::escape($timeInput($block['starts_at'] ?? null) . ' - ' . $timeInput($block['ends_at'] ?? null)) ?></span>
                                <?php if (($block['room'] ?? null) !== null && $block['room'] !== ''): ?>
                                    <span><?= View::escape($block['room']) ?></span>
                                <?php endif; ?>
                                <?php if (($block['effective_campus'] ?? null) !== null && $block['effective_campus'] !== ''): ?>
                                    <span><?= View::escape($block['effective_campus']) ?></span>
                                <?php endif; ?>
                                <?php if ($exceptionType !== ''): ?>
                                    <em><?= View::escape($block['exception_label'] ?? '') ?></em>
                                <?php endif; ?>
                                <?php if ($warnings !== []): ?>
                                    <em><?= View::escape($warnings[0]) ?></em>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php elseif ($activeTab === 'coincidences'): ?>
        <?php
        $coincidences = is_array($coincidences) ? $coincidences : [
            'date' => (string) ($filters['coincidence_date'] ?? $today->format('Y-m-d')),
            'date_label' => '',
            'previous' => '',
            'next' => '',
            'today' => $today->format('Y-m-d'),
            'same_campus_only' => (string) ($filters['same_campus_only'] ?? '0') === '1',
            'friend_id' => (string) ($filters['coincidence_friend_id'] ?? ''),
            'has_own_schedule' => false,
            'has_active_friends' => $activeFriends !== [],
            'individual' => [],
            'groups' => [],
            'next_item' => null,
            'friend_count' => 0,
            'error' => null,
        ];
        $individual = is_array($coincidences['individual'] ?? null) ? $coincidences['individual'] : [];
        $groups = array_values(array_filter(
            is_array($coincidences['groups'] ?? null) ? $coincidences['groups'] : [],
            static fn (array $group): bool => count(is_array($group['friends'] ?? null) ? $group['friends'] : []) >= 2
        ));
        $individualByFriend = [];
        foreach ($individual as $item) {
            $friendName = (string) ($item['friend_name'] ?? 'Amigo');
            $individualByFriend[$friendName][] = $item;
        }
        $selectedCoincidenceDate = (string) ($coincidences['date'] ?? '');
        $selectedCoincidenceFriend = (string) ($coincidences['friend_id'] ?? '');
        $selectedSameCampus = !empty($coincidences['same_campus_only']);
        $nextItem = is_array($coincidences['next_item'] ?? null) ? $coincidences['next_item'] : null;
        ?>
        <section class="tasks-panel coincidences-panel" aria-label="Coincidencias de disponibilidad">
            <form class="task-filters task-filters--coincidences" method="get">
                <input type="hidden" name="section" value="friends">
                <input type="hidden" name="tab" value="coincidences">
                <label>
                    Fecha
                    <input type="date" name="date" value="<?= View::escape($selectedCoincidenceDate) ?>" required>
                </label>
                <label>
                    Amigos
                    <select name="friend_id">
                        <option value="">Todos</option>
                        <?php foreach ($activeFriends as $friend): ?>
                            <?php $friendId = (string) ($friend['id'] ?? ''); ?>
                            <option value="<?= View::escape($friendId) ?>"<?= $selectedCoincidenceFriend === $friendId ? ' selected' : '' ?>>
                                <?= View::escape($friend['name'] ?? '') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Campus
                    <select name="same_campus_only">
                        <option value="0"<?= !$selectedSameCampus ? ' selected' : '' ?>>Todos</option>
                        <option value="1"<?= $selectedSameCampus ? ' selected' : '' ?>>Mismo campus</option>
                    </select>
                </label>
                <div class="task-actions task-actions--start">
                    <button class="button button--primary" type="submit">Consultar</button>
                </div>
            </form>

            <div class="calendar-toolbar">
                <div>
                    <p class="dashboard-card__eyebrow">Disponibilidad inferida</p>
                    <h2>Coincidencias para <?= View::escape((string) ($coincidences['date_label'] ?: $selectedCoincidenceDate)) ?></h2>
                </div>
                <div class="calendar-actions">
                    <?php if (($coincidences['previous'] ?? '') !== ''): ?>
                        <a class="button button--secondary" href="<?= View::escape($coincidenceUrl(['date' => (string) $coincidences['previous']])) ?>">Dia anterior</a>
                    <?php endif; ?>
                    <?php if (($coincidences['today'] ?? '') !== ''): ?>
                        <a class="button button--secondary" href="<?= View::escape($coincidenceUrl(['date' => (string) $coincidences['today']])) ?>">Hoy</a>
                    <?php endif; ?>
                    <?php if (($coincidences['next'] ?? '') !== ''): ?>
                        <a class="button button--secondary" href="<?= View::escape($coincidenceUrl(['date' => (string) $coincidences['next']])) ?>">Dia siguiente</a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (($coincidences['error'] ?? null) !== null): ?>
                <p class="task-message task-message--error"><?= View::escape($coincidences['error']) ?></p>
            <?php endif; ?>

            <div class="coincidence-summary">
                <div>
                    <strong><?= (int) ($coincidences['friend_count'] ?? 0) ?></strong>
                    <span>amigos con coincidencias</span>
                </div>
                <div>
                    <strong><?= count($individual) ?></strong>
                    <span>intervalos encontrados</span>
                </div>
            </div>
            <p class="muted">Calculado segun intersecciones entre bloques academicos efectivos; no representa ubicacion en tiempo real.</p>

            <?php if (empty($coincidences['has_own_schedule'])): ?>
                <div class="empty-state">
                    <span aria-hidden="true"></span>
                        <p>Necesitas configurar Mi horario para calcular coincidencias.</p>
                    <a class="button button--primary" href="<?= View::escape($scheduleUrl(['target' => 'user'])) ?>">Configurar Mi horario</a>
                </div>
            <?php elseif (empty($coincidences['has_active_friends'])): ?>
                <div class="empty-state">
                    <span aria-hidden="true"></span>
                        <p>No tienes amigos activos para comparar horarios.</p>
                    <a class="button button--primary" href="<?= View::escape($tabUrl('friends')) ?>">Agregar amigo</a>
                </div>
            <?php else: ?>
                <?php if ($selectedCoincidenceDate === (string) ($coincidences['today'] ?? '') && $nextItem !== null): ?>
                    <?php
                    $nextFriend = (string) ($nextItem['friend_name'] ?? 'Amigo');
                    $nextCampus = trim((string) ($nextItem['user_campus'] ?? ''));
                    ?>
                    <article class="coincidence-next">
                        <p class="dashboard-card__eyebrow"><?= !empty($nextItem['is_now']) ? 'Coincidencia ahora' : 'Proxima coincidencia' ?></p>
                        <h3>
                            <?php if (!empty($nextItem['is_now'])): ?>
                                hasta <?= View::escape((string) ($nextItem['ends_at'] ?? '')) ?>
                            <?php else: ?>
                                <?= View::escape((string) ($nextItem['starts_at'] ?? '') . ' - ' . (string) ($nextItem['ends_at'] ?? '')) ?>
                            <?php endif; ?>
                        </h3>
                        <p><?= View::escape($nextFriend . ' · ' . $durationLabel($nextItem['duration_minutes'] ?? 0) . ($nextCampus !== '' ? ' · ' . $nextCampus : '')) ?></p>
                        <span><?= View::escape($minutesUntilLabel($nextItem['minutes_until'] ?? 0)) ?></span>
                    </article>
                <?php endif; ?>

                <?php if ($individual === []): ?>
                    <div class="empty-state">
                        <span aria-hidden="true"></span>
                        <p>No encontramos bloques academicos simultaneos para este dia.</p>
                        <p class="muted">Si un amigo no tiene horario suficiente, no se interpreta como disponible todo el dia.</p>
                    </div>
                <?php else: ?>
                    <div class="coincidence-layout">
                        <section class="coincidence-section" aria-label="Coincidencias grupales">
                            <div class="tasks-list-header">
                                <h2>Coincidencias grupales</h2>
                                <span class="task-count"><?= count($groups) ?></span>
                            </div>
                            <?php if ($groups === []): ?>
                                <p class="notification-empty">No hay intervalos simultaneos con dos o mas amigos.</p>
                            <?php endif; ?>
                            <?php foreach ($groups as $group): ?>
                                <?php
                                $groupFriends = is_array($group['friends'] ?? null) ? $group['friends'] : [];
                                $friendNames = array_map(static fn (array $friend): string => (string) ($friend['name'] ?? 'Amigo'), $groupFriends);
                                $groupCampus = trim((string) ($group['campus'] ?? ''));
                                $groupUserBlock = is_array($group['user_block'] ?? null) ? $group['user_block'] : [];
                                ?>
                                <article class="coincidence-card coincidence-card--group">
                                    <div class="coincidence-card__header">
                                        <strong><?= View::escape((string) ($group['starts_at'] ?? '') . ' - ' . (string) ($group['ends_at'] ?? '')) ?></strong>
                                        <span><?= View::escape($durationLabel($group['duration_minutes'] ?? 0)) ?></span>
                                    </div>
                                    <p><?= count($groupFriends) + 1 ?> personas con actividad simultanea</p>
                                    <p class="coincidence-campus"><?= $groupCampus !== '' ? View::escape($groupCampus . ' · mismo campus probable') : 'Campus no determinado' ?></p>
                                    <div class="coincidence-people">
                                        <?php if ($groupUserBlock !== []): ?>
                                            <span><strong>Tu</strong> <?= View::escape($blockCourseLabel($groupUserBlock)) ?></span>
                                        <?php endif; ?>
                                        <?php foreach ($groupFriends as $friend): ?>
                                            <?php $friendBlock = is_array($friend['block'] ?? null) ? $friend['block'] : []; ?>
                                            <span><strong><?= View::escape($friend['name'] ?? 'Amigo') ?></strong> <?= View::escape($friendBlock !== [] ? $blockCourseLabel($friendBlock) : '') ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="coincidence-card__actions">
                                        <a class="button button--secondary" href="<?= View::escape($coincidenceTaskUrl($group, 'group')) ?>">Crear tarea con este grupo</a>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </section>

                        <section class="coincidence-section" aria-label="Coincidencias individuales">
                            <div class="tasks-list-header">
                                <h2>Coincidencias individuales</h2>
                                <span class="task-count"><?= count($individual) ?></span>
                            </div>
                            <?php foreach ($individualByFriend as $friendName => $items): ?>
                                <article class="project-card coincidence-friend-card">
                                    <div class="project-card__header">
                                        <div>
                                            <h3><?= View::escape($friendName) ?></h3>
                                            <p><?= count($items) ?> intervalo<?= count($items) === 1 ? '' : 's' ?></p>
                                        </div>
                                    </div>
                                    <div class="coincidence-list">
                                        <?php foreach ($items as $item): ?>
                                            <?php
                                            $userBlock = is_array($item['user_block'] ?? null) ? $item['user_block'] : [];
                                            $friendBlock = is_array($item['friend_block'] ?? null) ? $item['friend_block'] : [];
                                            $sameCourseText = $sameCourseLabel($item);
                                            ?>
                                            <div class="coincidence-card<?= !empty($item['is_finished']) ? ' is-finished' : '' ?>">
                                                <div class="coincidence-card__header">
                                                    <strong><?= View::escape((string) ($item['starts_at'] ?? '') . ' - ' . (string) ($item['ends_at'] ?? '')) ?></strong>
                                                    <span><?= View::escape($durationLabel($item['duration_minutes'] ?? 0)) ?></span>
                                                </div>
                                                <div class="coincidence-tags">
                                                    <span><?= View::escape($overlapLabel($item)) ?></span>
                                                    <?php if ($sameCourseText !== ''): ?>
                                                        <span><?= View::escape($sameCourseText) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <p><?= View::escape($campusState($item, $friendName)) ?></p>
                                                <div class="coincidence-people">
                                                    <?php if ($userBlock !== []): ?>
                                                        <span><strong>Tu</strong> <?= View::escape($blockDetailLabel($userBlock)) ?></span>
                                                    <?php endif; ?>
                                                    <?php if ($friendBlock !== []): ?>
                                                        <span><strong><?= View::escape($friendName) ?></strong> <?= View::escape($blockDetailLabel($friendBlock)) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if (!empty($item['is_finished'])): ?>
                                                    <em>Finalizado</em>
                                                <?php endif; ?>
                                                <div class="coincidence-card__actions">
                                                    <a class="button button--secondary" href="<?= View::escape($coincidenceTaskUrl($item)) ?>">Crear tarea</a>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </section>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php elseif ($activeTab === 'friends'): ?>
        <section class="task-editor" data-friend-form-panel hidden aria-label="Formulario de amigo">
            <form class="task-form" data-friend-form>
                <div class="task-form__header">
                    <h2 data-friend-form-title>Nuevo amigo</h2>
                    <button class="button button--secondary" type="button" data-friend-cancel>Cancelar</button>
                </div>
                <input type="hidden" name="friend_id" value="">
                <div class="task-form__grid task-form__grid--friends">
                    <label>
                        Nombre
                        <input type="text" name="name" maxlength="180" required>
                    </label>
                    <label>
                        Universidad
                        <input type="text" name="university" maxlength="180">
                    </label>
                    <label>
                        Campus habitual
                        <input type="text" name="default_campus" maxlength="180">
                    </label>
                    <label class="toggle-field">
                        <input type="checkbox" name="is_active" value="1" checked>
                        <span>Activo</span>
                    </label>
                    <label class="task-form__wide">
                        Notas
                        <textarea name="notes" rows="4" maxlength="5000"></textarea>
                    </label>
                </div>
                <div class="task-form__actions">
                    <button class="button button--primary" type="submit">Guardar amigo</button>
                </div>
            </form>
        </section>

        <section
            class="tasks-panel"
            data-friends-panel
            data-api-url="/api/friends/friends.php"
            data-friend-status-filter="<?= View::escape($activeStatus) ?>"
            aria-label="Listado de amigos"
        >
            <form class="quick-filters" data-friend-filters>
                <?php foreach (['active' => 'Activos', 'all' => 'Todos', 'inactive' => 'Inactivos'] as $status => $label): ?>
                    <a
                        class="quick-filter<?= $activeStatus === $status ? ' is-active' : '' ?>"
                        href="<?= View::escape($friendUrl($status)) ?>"
                        <?= $activeStatus === $status ? 'aria-current="page"' : '' ?>
                    >
                        <?= View::escape($label) ?>
                    </a>
                <?php endforeach; ?>
            </form>

            <div class="tasks-list-header">
                <h2>Amigos</h2>
                <span class="task-count" data-friend-count><?= count($friends) ?></span>
            </div>

            <div class="tasks-list friends-list" data-friend-list>
                <?php if ($friends === []): ?>
                    <div class="empty-state">
                        <span aria-hidden="true"></span>
                        <p>No hay amigos para mostrar.</p>
                    </div>
                <?php endif; ?>

                <?php foreach ($friends as $friend): ?>
                    <?php
                    $friendId = (string) $friend['id'];
                    $active = (int) ($friend['is_active'] ?? 0) === 1;
                    $scheduleCount = (int) ($friend['schedule_entries_count'] ?? 0);
                    $exceptionCount = (int) ($friend['schedule_exceptions_count'] ?? 0);
                    ?>
                    <article
                        class="task-item friend-item<?= $active ? '' : ' is-inactive' ?>"
                        data-friend-id="<?= View::escape($friendId) ?>"
                        data-name="<?= View::escape($friend['name'] ?? '') ?>"
                        data-university="<?= View::escape($friend['university'] ?? '') ?>"
                        data-default-campus="<?= View::escape($friend['default_campus'] ?? '') ?>"
                        data-notes="<?= View::escape($friend['notes'] ?? '') ?>"
                        data-is-active="<?= $active ? '1' : '0' ?>"
                        data-schedule-count="<?= View::escape((string) $scheduleCount) ?>"
                        data-exception-count="<?= View::escape((string) $exceptionCount) ?>"
                    >
                        <div class="task-item__main">
                            <h3><?= View::escape($friend['name'] ?? '') ?></h3>
                            <?php if (($friend['notes'] ?? null) !== null && trim((string) $friend['notes']) !== ''): ?>
                                <p><?= View::escape($friend['notes']) ?></p>
                            <?php endif; ?>
                            <div class="task-meta">
                                <span><?= View::escape('Universidad: ' . $field($friend['university'] ?? null, 'sin dato')) ?></span>
                                <span><?= View::escape('Campus: ' . $field($friend['default_campus'] ?? null, 'sin dato')) ?></span>
                                <span><?= View::escape($statusLabel($friend['is_active'] ?? 0)) ?></span>
                            </div>
                        </div>
                        <div class="task-actions">
                            <button class="button button--secondary" type="button" data-friend-action="edit">Editar</button>
                            <button class="button button--secondary" type="button" data-friend-action="<?= $active ? 'deactivate' : 'activate' ?>">
                                <?= $active ? 'Desactivar' : 'Activar' ?>
                            </button>
                            <button class="button button--danger" type="button" data-friend-action="delete">Eliminar</button>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php else: ?>
        <section class="task-editor" data-schedule-form-panel hidden aria-label="Formulario de bloque horario">
            <form class="task-form" data-schedule-form>
                <div class="task-form__header">
                    <h2 data-schedule-form-title>Nuevo bloque</h2>
                    <button class="button button--secondary" type="button" data-schedule-cancel>Cancelar</button>
                </div>
                <input type="hidden" name="entry_id" value="">
                <div class="task-form__grid task-form__grid--schedule">
                    <label>
                        Dia
                        <select name="weekday" required>
                            <?php foreach ($dayLong as $value => $label): ?>
                                <option value="<?= (int) $value ?>"><?= View::escape($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        Hora inicio
                        <input type="time" name="starts_at" required>
                    </label>
                    <label>
                        Hora fin
                        <input type="time" name="ends_at" required>
                    </label>
                    <label>
                        Nombre ramo
                        <input type="text" name="course_name" maxlength="180" required>
                    </label>
                    <label>
                        Codigo ramo
                        <input type="text" name="course_code" maxlength="80">
                    </label>
                    <label>
                        Sala
                        <input type="text" name="room" maxlength="120">
                    </label>
                    <label>
                        Campus
                        <input type="text" name="campus" maxlength="180">
                    </label>
                    <label>
                        Vigente desde
                        <input type="date" name="valid_from">
                    </label>
                    <label>
                        Vigente hasta
                        <input type="date" name="valid_until">
                    </label>
                </div>
                <div class="task-form__actions">
                    <button class="button button--primary" type="submit">Guardar bloque</button>
                </div>
            </form>
        </section>

        <section class="task-editor" data-schedule-import-panel hidden aria-label="Importar horario JSON">
            <form class="task-form" data-schedule-import-form>
                <div class="task-form__header">
                    <h2>Importar horario</h2>
                    <button class="button button--secondary" type="button" data-schedule-import-cancel>Cancelar</button>
                </div>
                <div class="task-form__grid task-form__grid--schedule-import">
                    <label>
                        Destino
                        <select name="target" data-schedule-import-target>
                            <option value="user"<?= $targetValue === 'user' ? ' selected' : '' ?>>Mi horario</option>
                            <?php foreach ($activeFriends as $friend): ?>
                                <?php $friendOption = 'friend:' . (int) $friend['id']; ?>
                                <option value="<?= View::escape($friendOption) ?>"<?= $targetValue === $friendOption ? ' selected' : '' ?>>
                                    <?= View::escape($friend['name'] ?? '') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        Archivo .json
                        <input type="file" name="json_file" accept="application/json,.json" data-schedule-import-file>
                    </label>
                    <label class="task-form__wide">
                        JSON
                        <textarea name="json" rows="10" data-schedule-import-json placeholder='{"version":1,"schedule":[]}'></textarea>
                    </label>
                </div>
                <div class="task-form__actions">
                    <button class="button button--secondary" type="button" data-schedule-import-preview>Previsualizar</button>
                    <button class="button button--primary" type="submit" data-schedule-import-confirm disabled>Importar</button>
                </div>
            </form>
            <div class="schedule-import-preview" data-schedule-import-preview-panel hidden></div>
        </section>

        <section class="task-editor" data-schedule-exception-panel hidden aria-label="Formulario de excepcion de horario">
            <form class="task-form" data-schedule-exception-form>
                <div class="task-form__header">
                    <h2 data-schedule-exception-title>Nueva excepcion</h2>
                    <button class="button button--secondary" type="button" data-schedule-exception-cancel>Cancelar</button>
                </div>
                <input type="hidden" name="exception_id" value="">
                <input type="hidden" name="schedule_entry_id" value="">
                <div class="task-form__grid task-form__grid--schedule">
                    <label>
                        Fecha de excepcion
                        <input type="date" name="exception_date" required>
                    </label>
                    <label>
                        Tipo
                        <select name="type" required>
                            <option value="cancelled">Clase cancelada</option>
                            <option value="absent">No asistira</option>
                            <option value="modified">Modificar solo este dia</option>
                        </select>
                    </label>
                    <label>
                        Inicio
                        <input type="time" name="starts_at">
                    </label>
                    <label>
                        Termino
                        <input type="time" name="ends_at">
                    </label>
                    <label>
                        Ramo
                        <input type="text" name="course_name" maxlength="180">
                    </label>
                    <label>
                        Codigo
                        <input type="text" name="course_code" maxlength="80">
                    </label>
                    <label>
                        Sala
                        <input type="text" name="room" maxlength="120">
                    </label>
                    <label>
                        Campus
                        <input type="text" name="campus" maxlength="180">
                    </label>
                    <label class="task-form__wide">
                        Notas
                        <textarea name="notes" rows="3" maxlength="5000"></textarea>
                    </label>
                </div>
                <div class="task-actions">
                    <button class="button button--primary" type="submit">Guardar excepcion</button>
                    <button class="button button--secondary" type="button" data-schedule-exception-cancel>Cancelar</button>
                </div>
            </form>
        </section>

        <section class="tasks-panel schedule-panel" data-schedule-panel aria-label="Editor semanal de horarios">
            <form class="task-filters task-filters--schedule" method="get" data-schedule-controls>
                <input type="hidden" name="section" value="friends">
                <input type="hidden" name="tab" value="schedules">
                <label>
                    Horario
                    <select name="target" data-schedule-target>
                        <option value="user"<?= $targetValue === 'user' ? ' selected' : '' ?>>Mi horario</option>
                        <?php foreach ($activeFriends as $friend): ?>
                            <?php $friendOption = 'friend:' . (int) $friend['id']; ?>
                            <option value="<?= View::escape($friendOption) ?>"<?= $targetValue === $friendOption ? ' selected' : '' ?>>
                                <?= View::escape($friend['name'] ?? '') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Vigencia
                    <select name="show" data-schedule-show>
                        <option value="active"<?= $showMode === 'active' ? ' selected' : '' ?>>Bloques vigentes</option>
                        <option value="all"<?= $showMode === 'all' ? ' selected' : '' ?>>Ver todos los bloques</option>
                    </select>
                </label>
                <div class="task-actions task-actions--start">
                    <button class="button button--secondary" type="submit">Aplicar</button>
                    <a class="button button--secondary" href="<?= View::escape($scheduleUrl(['show' => 'all'])) ?>">Ver todos los bloques</a>
                </div>
            </form>

            <div class="tasks-list-header">
                <h2>Semana</h2>
                <span class="task-count" data-schedule-count><?= count($scheduleEntries) ?></span>
            </div>

            <div class="schedule-mobile-days" role="tablist" aria-label="Dias de la semana">
                <?php foreach ($days as $weekday => $label): ?>
                    <button
                        class="quick-filter<?= $weekday === $todayWeekday ? ' is-active' : '' ?><?= $weekday === $todayWeekday ? ' is-today' : '' ?>"
                        type="button"
                        role="tab"
                        aria-selected="<?= $weekday === $todayWeekday ? 'true' : 'false' ?>"
                        data-schedule-day-tab="<?= (int) $weekday ?>"
                    ><?= View::escape($label) ?></button>
                <?php endforeach; ?>
            </div>

            <div class="schedule-week" data-schedule-week>
                <div class="schedule-week__corner" aria-hidden="true"></div>
                <?php foreach ($days as $weekday => $label): ?>
                    <div class="schedule-week__head"><?= View::escape($label) ?></div>
                <?php endforeach; ?>
                <div class="schedule-time-axis" aria-hidden="true">
                    <?php for ($hour = 8; $hour <= 21; $hour++): ?>
                        <span><?= str_pad((string) $hour, 2, '0', STR_PAD_LEFT) ?>:00</span>
                    <?php endfor; ?>
                </div>
                <?php foreach ($days as $weekday => $label): ?>
                    <div class="schedule-day-column<?= $weekday === $todayWeekday ? ' is-mobile-active' : '' ?><?= $weekday === $todayWeekday ? ' is-today' : '' ?>" data-schedule-day="<?= (int) $weekday ?>" aria-label="<?= View::escape($dayLong[$weekday]) ?>">
                        <div class="schedule-day-column__mobile-heading">
                            <span><?= View::escape($dayLong[$weekday]) ?></span>
                            <small><?= View::escape($dayDateLabels[$weekday]) ?></small>
                        </div>
                        <?php foreach ($entriesByDay[$weekday] as $entry): ?>
                            <?php
                            $warnings = is_array($entry['warnings'] ?? null) ? $entry['warnings'] : [];
                            ?>
                            <button
                                class="schedule-block<?= !empty($entry['has_overlap']) ? ' has-warning' : '' ?>"
                                type="button"
                                aria-expanded="false"
                                aria-haspopup="dialog"
                                data-schedule-entry-id="<?= View::escape((string) $entry['id']) ?>"
                                data-weekday="<?= View::escape((string) $entry['weekday']) ?>"
                                data-starts-at="<?= View::escape($timeInput($entry['starts_at'] ?? null)) ?>"
                                data-ends-at="<?= View::escape($timeInput($entry['ends_at'] ?? null)) ?>"
                                data-course-name="<?= View::escape($entry['course_name'] ?? '') ?>"
                                data-course-code="<?= View::escape($entry['course_code'] ?? '') ?>"
                                data-room="<?= View::escape($entry['room'] ?? '') ?>"
                                data-campus="<?= View::escape($entry['campus'] ?? '') ?>"
                                data-effective-campus="<?= View::escape($entry['effective_campus'] ?? '') ?>"
                                data-valid-from="<?= View::escape($entry['valid_from'] ?? '') ?>"
                                data-valid-until="<?= View::escape($entry['valid_until'] ?? '') ?>"
                                data-owner-label="<?= View::escape($selectedTargetLabel) ?>"
                            >
                                <strong><?= View::escape($entry['course_name'] ?? '') ?></strong>
                                <span><?= View::escape($timeInput($entry['starts_at'] ?? null) . '-' . $timeInput($entry['ends_at'] ?? null)) ?></span>
                                <?php if (($entry['room'] ?? null) !== null && $entry['room'] !== ''): ?>
                                    <span><?= View::escape($entry['room']) ?></span>
                                <?php endif; ?>
                                <?php if (($entry['effective_campus'] ?? null) !== null && $entry['effective_campus'] !== ''): ?>
                                    <span><?= View::escape($entry['effective_campus']) ?></span>
                                <?php endif; ?>
                                <?php if ($warnings !== []): ?>
                                    <em><?= View::escape($warnings[0]) ?></em>
                                <?php endif; ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="schedule-detail" data-schedule-detail role="dialog" aria-modal="false" aria-labelledby="schedule-detail-title" tabindex="-1" hidden>
                <div>
                    <p class="dashboard-card__eyebrow">Bloque seleccionado</p>
                    <h3 id="schedule-detail-title" data-schedule-detail-title></h3>
                    <p data-schedule-detail-meta></p>
                    <p class="schedule-warning" data-schedule-detail-warning hidden></p>
                </div>
                <div class="task-actions">
                    <button class="button button--secondary" type="button" data-schedule-detail-edit>Editar</button>
                    <button class="button button--secondary" type="button" data-schedule-detail-duplicate>Duplicar bloque</button>
                    <button class="button button--secondary" type="button" data-schedule-detail-exception>Crear excepcion</button>
                    <button class="button button--danger" type="button" data-schedule-detail-delete>Eliminar</button>
                    <button class="button button--secondary" type="button" data-schedule-detail-close>Cerrar</button>
                </div>
                <div class="schedule-exceptions-list" data-schedule-detail-exceptions hidden></div>
            </div>
        </section>
    <?php endif; ?>
</section>
