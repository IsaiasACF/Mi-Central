<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    $spaces = [
        ['name' => 'Universidad', 'slug' => 'universidad'],
        ['name' => 'Amigos', 'slug' => 'amigos'],
        ['name' => 'Personal', 'slug' => 'personal'],
        ['name' => 'Trabajo', 'slug' => 'trabajo'],
    ];

    $users = $pdo->query('SELECT id FROM users WHERE is_active = 1')->fetchAll(\PDO::FETCH_COLUMN);
    $statement = $pdo->prepare(
        'INSERT INTO organization_spaces (user_id, name, slug)
         SELECT :user_id, :name, :slug
         WHERE NOT EXISTS (
             SELECT 1 FROM organization_spaces WHERE user_id = :user_id_exists AND slug = :slug_exists
         )'
    );

    foreach ($users as $userId) {
        foreach ($spaces as $space) {
            $statement->execute([
                'user_id' => (int) $userId,
                'name' => $space['name'],
                'slug' => $space['slug'],
                'user_id_exists' => (int) $userId,
                'slug_exists' => $space['slug'],
            ]);
        }
    }
};
