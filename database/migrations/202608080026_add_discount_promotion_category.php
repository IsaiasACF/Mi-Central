<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    if (!discount_promotion_category_column_exists($pdo)) {
        $pdo->exec(
            "ALTER TABLE discount_promotions
             ADD COLUMN category VARCHAR(32) NULL AFTER created_by_user_id"
        );
    }

    if (!discount_promotion_category_index_exists($pdo)) {
        $pdo->exec(
            'ALTER TABLE discount_promotions
             ADD INDEX discount_promotions_category_index (category)'
        );
    }

    $pdo->exec(
        "UPDATE discount_promotions p
         LEFT JOIN discount_merchants m ON m.id = p.merchant_id
         SET p.category = CASE
             WHEN LOWER(CONCAT_WS(' ', m.name, p.title, p.description, p.terms, m.category)) REGEXP 'perfume|perfumeria|fragancia' THEN 'perfumes'
             WHEN LOWER(CONCAT_WS(' ', m.name, p.title, p.description, p.terms, m.category)) REGEXP 'belleza|cosmetic|maquillaje|skincare|dermocosmetica|peluqueria' THEN 'beauty'
             WHEN LOWER(CONCAT_WS(' ', m.name, p.title, p.description, p.terms, m.category)) REGEXP 'restaurante|restaurant|pizza|sushi|hamburguesa|bar|sandwich|parrilla' THEN 'restaurants'
             WHEN LOWER(CONCAT_WS(' ', m.name, p.title, p.description, p.terms, m.category)) REGEXP 'caf[eé]|cafeteria|coffee|pasteleria' THEN 'cafes'
             WHEN LOWER(CONCAT_WS(' ', m.name, p.title, p.description, p.terms, m.category)) REGEXP 'comida|gastronomia|sabores|delivery' THEN 'food'
             WHEN LOWER(CONCAT_WS(' ', m.name, p.title, p.description, p.terms, m.category)) REGEXP 'ropa|moda|vestuario|calzado|zapato|zapatilla' THEN 'fashion'
             WHEN LOWER(CONCAT_WS(' ', m.name, p.title, p.description, p.terms, m.category)) REGEXP 'tecnologia|electronica|computacion|celular|smartphone|notebook|gaming' THEN 'technology'
             WHEN LOWER(CONCAT_WS(' ', m.name, p.title, p.description, p.terms, m.category)) REGEXP 'viaje|viajes|turismo|hotel|vuelo|aerolinea|rent a car' THEN 'travel'
             WHEN LOWER(CONCAT_WS(' ', m.name, p.title, p.description, p.terms, m.category)) REGEXP 'entretencion|entretenimiento|cine|teatro|concierto|parque|show' THEN 'entertainment'
             WHEN LOWER(CONCAT_WS(' ', m.name, p.title, p.description, p.terms, m.category)) REGEXP 'salud|farmacia|clinica|dental|optica|bienestar' THEN 'health'
             WHEN LOWER(CONCAT_WS(' ', m.name, p.title, p.description, p.terms, m.category)) REGEXP 'hogar|mueble|decoracion|jardin|ferreteria' THEN 'home'
             WHEN LOWER(CONCAT_WS(' ', m.name, p.title, p.description, p.terms, m.category)) REGEXP 'supermercado|mercado|abarrotes' THEN 'supermarket'
             WHEN LOWER(CONCAT_WS(' ', m.name, p.title, p.description, p.terms, m.category)) REGEXP 'auto|automotriz|bencina|combustible|neumatico' THEN 'automotive'
             WHEN LOWER(CONCAT_WS(' ', m.name, p.title, p.description, p.terms, m.category)) REGEXP 'mascota|veterinaria|\\bpet\\b|\\bpets\\b' THEN 'pets'
             ELSE p.category
         END
         WHERE p.category IS NULL"
    );
};

function discount_promotion_category_column_exists(\PDO $pdo): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
           AND column_name = :column_name'
    );
    $statement->execute([
        'table_name' => 'discount_promotions',
        'column_name' => 'category',
    ]);

    return (int) $statement->fetchColumn() === 1;
}

function discount_promotion_category_index_exists(\PDO $pdo): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
           AND index_name = :index_name'
    );
    $statement->execute([
        'table_name' => 'discount_promotions',
        'index_name' => 'discount_promotions_category_index',
    ]);

    return (int) $statement->fetchColumn() > 0;
}
