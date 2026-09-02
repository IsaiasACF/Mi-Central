<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    $pdo->exec(
        "ALTER TABLE organization_reminders
            ADD COLUMN recurrence_type VARCHAR(24) NOT NULL DEFAULT 'none' AFTER status,
            ADD COLUMN recurrence_interval INT UNSIGNED NOT NULL DEFAULT 1 AFTER recurrence_type,
            ADD COLUMN recurrence_until DATETIME NULL AFTER recurrence_interval,
            ADD COLUMN next_remind_at DATETIME NULL AFTER recurrence_until,
            ADD INDEX organization_reminders_next_remind_at_index (next_remind_at),
            ADD CONSTRAINT organization_reminders_recurrence_type_check
                CHECK (recurrence_type IN ('none', 'daily', 'weekly', 'monthly', 'yearly')),
            ADD CONSTRAINT organization_reminders_recurrence_interval_check
                CHECK (recurrence_interval >= 1)"
    );

    $pdo->exec('UPDATE organization_reminders SET next_remind_at = remind_at WHERE next_remind_at IS NULL');
};
