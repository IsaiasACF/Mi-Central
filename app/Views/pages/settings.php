<?php
declare(strict_types=1);

use App\Http\Csrf;
use App\Support\View;

$settingsUsers = is_array($settingsUsers ?? null) ? $settingsUsers : [];
$settingsBenefits = is_array($settingsBenefits ?? null) ? $settingsBenefits : [];
$activeTab = is_string($activeTab ?? null) ? (string) $activeTab : 'benefits';
$isAdmin = (bool) ($settingsUsers['is_admin'] ?? false);
$users = is_array($settingsUsers['users'] ?? null) ? $settingsUsers['users'] : [];
$programs = is_array($settingsBenefits['programs'] ?? null) ? $settingsBenefits['programs'] : [];
$selectedProgramIds = is_array($settingsBenefits['selected_program_ids'] ?? null) ? $settingsBenefits['selected_program_ids'] : [];
$typeLabels = is_array($settingsBenefits['type_labels'] ?? null) ? $settingsBenefits['type_labels'] : [];
$benefitFilters = is_array($settingsBenefits['filters'] ?? null) ? $settingsBenefits['filters'] : [];
$message = $activeTab === 'users'
    ? (is_string($settingsUsers['message'] ?? null) ? (string) $settingsUsers['message'] : '')
    : (is_string($settingsBenefits['message'] ?? null) ? (string) $settingsBenefits['message'] : '');
$error = $activeTab === 'users'
    ? (is_string($settingsUsers['error'] ?? null) ? (string) $settingsUsers['error'] : '')
    : (is_string($settingsBenefits['error'] ?? null) ? (string) $settingsBenefits['error'] : '');
$currentUserId = (int) ($currentUserId ?? 0);
$searchFilter = is_string($benefitFilters['search'] ?? null) ? trim((string) $benefitFilters['search']) : '';
$providerFilter = is_string($benefitFilters['provider'] ?? null) ? trim((string) $benefitFilters['provider']) : '';
$typeFilter = is_string($benefitFilters['benefit_type'] ?? null) ? trim((string) $benefitFilters['benefit_type']) : '';

$labelForProgram = static function (array $program): string {
    $name = trim((string) ($program['name'] ?? ''));
    $product = trim((string) ($program['product_name'] ?? ''));

    return $product !== '' && $product !== $name ? $name . ' - ' . $product : $name;
};

$providers = [];
$filteredPrograms = [];

foreach ($programs as $program) {
    $provider = trim((string) ($program['provider_name'] ?? 'Otros'));
    $type = (string) ($program['benefit_type'] ?? '');
    $haystack = strtolower($provider . ' ' . (string) ($program['name'] ?? '') . ' ' . (string) ($program['product_name'] ?? ''));

    $providers[$provider] = true;

    if ($providerFilter !== '' && $provider !== $providerFilter) {
        continue;
    }

    if ($typeFilter !== '' && $type !== $typeFilter) {
        continue;
    }

    if ($searchFilter !== '' && !str_contains($haystack, strtolower($searchFilter))) {
        continue;
    }

    $filteredPrograms[] = $program;
}

$providers = array_keys($providers);
sort($providers);

$programsByProvider = [];

foreach ($filteredPrograms as $program) {
    $provider = trim((string) ($program['provider_name'] ?? 'Otros'));
    $programsByProvider[$provider !== '' ? $provider : 'Otros'][] = $program;
}

$dateTime = static function (mixed $value): string {
    if (!is_string($value) || $value === '') {
        return 'Nunca';
    }

    return substr($value, 0, 16);
};

$accountStatus = static function (array $user): string {
    return (int) ($user['is_active'] ?? 0) === 1 ? 'Activa' : 'Desactivada';
};
?>
<section class="page-heading settings-heading" aria-labelledby="page-title">
    <div>
        <p class="eyebrow">Configuracion</p>
        <h1 id="page-title">Configuracion</h1>
        <p class="muted">Ajustes internos de Mi Central.</p>
    </div>
</section>

<nav class="quick-filters" aria-label="Secciones de configuracion">
    <a class="quick-filter <?= $activeTab === 'benefits' ? 'is-active' : '' ?>" href="/index.php?section=settings&amp;tab=benefits" <?= $activeTab === 'benefits' ? 'aria-current="page"' : '' ?>>Mis tarjetas y beneficios</a>
    <?php if ($isAdmin): ?>
        <a class="quick-filter <?= $activeTab === 'users' ? 'is-active' : '' ?>" href="/index.php?section=settings&amp;tab=users" <?= $activeTab === 'users' ? 'aria-current="page"' : '' ?>>Usuarios</a>
    <?php endif; ?>
</nav>

<?php if ($message !== ''): ?>
    <p class="task-message task-message--success" role="status"><?= View::escape($message) ?></p>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <p class="task-message task-message--error" role="alert"><?= View::escape($error) ?></p>
<?php endif; ?>

<?php if ($activeTab === 'benefits'): ?>
    <section
        class="tasks-panel settings-panel"
        aria-label="Mis tarjetas y beneficios"
        data-settings-benefits
        data-api-url="/api/discounts/user-benefits.php"
        data-csrf-token="<?= View::escape(Csrf::token()) ?>"
    >
        <div class="tasks-list-header">
            <div>
                <h2>Mis tarjetas y beneficios</h2>
                <p class="muted">Selecciona solo los productos que tienes. No guardamos numeros de tarjeta ni datos bancarios.</p>
            </div>
            <span class="task-count"><?= count(array_filter($selectedProgramIds)) ?></span>
        </div>

        <form class="task-filters settings-benefit-filters" method="get" action="/index.php">
            <input type="hidden" name="section" value="settings">
            <input type="hidden" name="tab" value="benefits">
            <label>
                Buscar
                <input name="benefit_search" type="search" value="<?= View::escape($searchFilter) ?>" placeholder="Buscar tarjeta, banco o beneficio">
            </label>
            <label>
                Proveedor
                <select name="benefit_provider">
                    <option value="">Todos</option>
                    <?php foreach ($providers as $provider): ?>
                        <option value="<?= View::escape($provider) ?>" <?= $providerFilter === $provider ? 'selected' : '' ?>><?= View::escape($provider) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Tipo
                <select name="benefit_type">
                    <option value="">Todos</option>
                    <?php foreach ($typeLabels as $type => $label): ?>
                        <option value="<?= View::escape((string) $type) ?>" <?= $typeFilter === (string) $type ? 'selected' : '' ?>><?= View::escape((string) $label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="button button--secondary" type="submit">Filtrar</button>
        </form>

        <p class="task-message" role="status" data-settings-benefits-message hidden></p>

        <?php if ($programsByProvider === []): ?>
            <div class="empty-state"><span aria-hidden="true"></span><p>No hay beneficios disponibles con esos filtros.</p></div>
        <?php else: ?>
            <div class="settings-benefit-groups">
                <?php foreach ($programsByProvider as $provider => $providerPrograms): ?>
                    <section class="settings-benefit-group" aria-labelledby="settings-benefit-provider-<?= View::escape(preg_replace('/[^a-z0-9]+/i', '-', strtolower($provider)) ?? 'provider') ?>">
                        <h3 id="settings-benefit-provider-<?= View::escape(preg_replace('/[^a-z0-9]+/i', '-', strtolower($provider)) ?? 'provider') ?>"><?= View::escape($provider) ?></h3>
                        <div class="settings-benefit-list">
                            <?php foreach ($providerPrograms as $program): ?>
                                <?php
                                $programId = (int) ($program['id'] ?? 0);
                                $selected = isset($selectedProgramIds[$programId]);
                                $type = (string) ($program['benefit_type'] ?? '');
                                ?>
                                <label class="settings-benefit-option">
                                    <input
                                        type="checkbox"
                                        <?= $selected ? 'checked' : '' ?>
                                        aria-label="<?= View::escape($labelForProgram($program)) ?>"
                                        data-benefit-toggle
                                        data-benefit-program-id="<?= View::escape((string) $programId) ?>"
                                    >
                                    <div>
                                        <span class="settings-benefit-option__name"><?= View::escape($labelForProgram($program)) ?></span>
                                        <span class="settings-benefit-option__meta">
                                            <?php if (isset($typeLabels[$type])): ?>
                                                <span><?= View::escape((string) $typeLabels[$type]) ?></span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
<?php elseif ($isAdmin): ?>
    <section class="tasks-panel settings-panel" aria-label="Usuarios">
        <div class="tasks-list-header">
            <div>
                <h2>Usuarios</h2>
                <p class="muted">Cuentas registradas y su estado operativo.</p>
            </div>
            <span class="task-count"><?= count($users) ?></span>
        </div>
        <?php if ($users === []): ?>
            <div class="empty-state"><span aria-hidden="true"></span><p>No hay usuarios registrados.</p></div>
        <?php else: ?>
            <div class="settings-user-list">
                <?php foreach ($users as $user): ?>
                    <?php $isSelf = (int) ($user['id'] ?? 0) === $currentUserId; ?>
                    <article class="settings-user-card">
                        <div>
                            <h3>
                                <?= View::escape($user['username'] ?? '') ?>
                                <?php if ((int) ($user['is_admin'] ?? 0) === 1): ?>
                                    <span class="settings-user-role">Admin</span>
                                <?php endif; ?>
                            </h3>
                            <div class="task-meta">
                                <span><?= View::escape($accountStatus($user)) ?></span>
                                <span>Creada: <?= View::escape($dateTime($user['created_at'] ?? null)) ?></span>
                                <span>Ultimo login: <?= View::escape($dateTime($user['last_login_at'] ?? null)) ?></span>
                            </div>
                        </div>
                        <div class="task-actions">
                            <?php if ((int) ($user['is_active'] ?? 0) === 1): ?>
                                <form method="post" action="/index.php?section=settings&amp;tab=users" onsubmit="return confirm('Desactivar este usuario?');">
                                    <?= Csrf::input() ?>
                                    <input type="hidden" name="user_id" value="<?= View::escape($user['id'] ?? '') ?>">
                                    <input type="hidden" name="action" value="deactivate">
                                    <button class="button button--danger" type="submit" <?= $isSelf ? 'disabled' : '' ?>>Desactivar</button>
                                </form>
                            <?php else: ?>
                                <form method="post" action="/index.php?section=settings&amp;tab=users">
                                    <?= Csrf::input() ?>
                                    <input type="hidden" name="user_id" value="<?= View::escape($user['id'] ?? '') ?>">
                                    <input type="hidden" name="action" value="reactivate">
                                    <button class="button button--secondary" type="submit">Reactivar</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
