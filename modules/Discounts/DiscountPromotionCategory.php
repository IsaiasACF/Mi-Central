<?php
declare(strict_types=1);

namespace Modules\Discounts;

final class DiscountPromotionCategory
{
    public const CATEGORIES = [
        'food',
        'restaurants',
        'cafes',
        'fashion',
        'beauty',
        'perfumes',
        'technology',
        'travel',
        'entertainment',
        'health',
        'home',
        'supermarket',
        'services',
        'automotive',
        'pets',
        'other',
    ];

    private const LABELS = [
        'food' => 'Comida',
        'restaurants' => 'Restaurantes',
        'cafes' => 'Cafes',
        'fashion' => 'Ropa y moda',
        'beauty' => 'Belleza',
        'perfumes' => 'Perfumes',
        'technology' => 'Tecnologia',
        'travel' => 'Viajes',
        'entertainment' => 'Entretencion',
        'health' => 'Salud',
        'home' => 'Hogar',
        'supermarket' => 'Supermercado',
        'services' => 'Servicios',
        'automotive' => 'Automotriz',
        'pets' => 'Mascotas',
        'other' => 'Otros',
    ];

    private const ALIASES = [
        'food' => ['comida', 'alimentos', 'gastronomia', 'sabores', 'delivery', 'hamburguesa', 'pizza', 'sushi', 'pasteleria'],
        'restaurants' => ['restaurant', 'restaurante', 'restaurantes', 'bar', 'bares', 'parrilla', 'sandwich', 'cocina', 'papa johns', 'juan maestro', 'doggis', 'kfc', 'mcdonalds', 'burger king'],
        'cafes' => ['cafe', 'cafes', 'cafeteria', 'cafeterias', 'coffee'],
        'fashion' => ['ropa', 'moda', 'vestuario', 'calzado', 'zapatos', 'zapatillas', 'outfit', 'fashion'],
        'beauty' => ['belleza', 'cosmetica', 'cosmeticos', 'maquillaje', 'skincare', 'dermocosmetica', 'cuidado personal', 'peluqueria'],
        'perfumes' => ['perfume', 'perfumes', 'perfumeria', 'fragancia', 'fragancias', 'aromas'],
        'technology' => ['tecnologia', 'electronica', 'computacion', 'celulares', 'smartphone', 'notebook', 'gaming', 'tech'],
        'travel' => ['viaje', 'viajes', 'turismo', 'hotel', 'hoteles', 'vuelo', 'vuelos', 'aerolinea', 'aerolineas', 'rent a car'],
        'entertainment' => ['entretencion', 'entretenimiento', 'cine', 'teatro', 'show', 'concierto', 'panorama', 'parque'],
        'health' => ['salud', 'farmacia', 'farmacias', 'clinica', 'dental', 'optica', 'bienestar'],
        'home' => ['hogar', 'casa', 'muebles', 'decoracion', 'jardin', 'ferreteria'],
        'supermarket' => ['supermercado', 'supermercados', 'mercado', 'abarrotes'],
        'services' => ['servicio', 'servicios', 'suscripcion', 'educacion', 'curso', 'app'],
        'automotive' => ['auto', 'autos', 'automotriz', 'bencina', 'combustible', 'neumaticos', 'lubricentro'],
        'pets' => ['mascota', 'mascotas', 'pet', 'pets', 'veterinaria'],
    ];

    private const EXACT = [
        'sabores' => 'restaurants',
        'vestuario' => 'fashion',
        'moda' => 'fashion',
        'belleza' => 'beauty',
        'perfumeria' => 'perfumes',
        'tecnologia' => 'technology',
        'viajes' => 'travel',
        'entretencion' => 'entertainment',
        'entretenimiento' => 'entertainment',
        'hogar' => 'home',
        'salud' => 'health',
        'servicios' => 'services',
    ];

    public function __construct(private readonly DiscountTextNormalizer $text = new DiscountTextNormalizer())
    {
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return self::LABELS;
    }

    public static function isValid(?string $category): bool
    {
        return is_string($category) && in_array($category, self::CATEGORIES, true);
    }

    public static function label(string $category): string
    {
        return self::LABELS[$category] ?? $category;
    }

    /**
     * @return array<int, string>
     */
    public static function filterCategories(string $category): array
    {
        if (!self::isValid($category)) {
            return [];
        }

        $related = match ($category) {
            'food' => ['food', 'restaurants', 'cafes'],
            'beauty' => ['beauty', 'perfumes'],
            default => [$category],
        };

        return array_values(array_unique(array_filter($related, [self::class, 'isValid'])));
    }

    /**
     * @return array<int, string>
     */
    public function categoriesForSearch(string $query): array
    {
        $query = $this->text->comparable($query);

        if ($query === '') {
            return [];
        }

        $matches = [];

        foreach (self::LABELS as $category => $label) {
            if ($this->text->comparable($label) === $query || $category === $query) {
                $matches[] = $category;
            }
        }

        foreach (self::ALIASES as $category => $aliases) {
            foreach ($aliases as $alias) {
                $alias = $this->text->comparable($alias);

                if ($query === $alias || str_contains($query, $alias) || str_contains($alias, $query)) {
                    $matches[] = $category;
                    break;
                }
            }
        }

        if (in_array('perfumes', $matches, true)) {
            $matches[] = 'beauty';
        }

        if (in_array('food', $matches, true)) {
            array_push($matches, 'restaurants', 'cafes');
        }

        if (in_array('fashion', $matches, true)) {
            $matches[] = 'beauty';
        }

        return array_values(array_unique(array_filter($matches, [self::class, 'isValid'])));
    }

    public function normalize(?string $raw, ?string $fallbackText = null): ?string
    {
        $rawComparable = $this->text->comparable($raw);

        if ($rawComparable !== '') {
            if (isset(self::EXACT[$rawComparable])) {
                return self::EXACT[$rawComparable];
            }

            foreach (self::ALIASES as $category => $aliases) {
                foreach ($aliases as $alias) {
                    if ($rawComparable === $this->text->comparable($alias)) {
                        return $category;
                    }
                }
            }
        }

        $context = $this->text->comparable(trim(($raw ?? '') . ' ' . ($fallbackText ?? '')));

        if ($context === '') {
            return null;
        }

        foreach ([
            'perfumes',
            'beauty',
            'restaurants',
            'cafes',
            'food',
            'fashion',
            'technology',
            'travel',
            'entertainment',
            'health',
            'home',
            'supermarket',
            'automotive',
            'pets',
            'services',
        ] as $category) {
            foreach (self::ALIASES[$category] ?? [] as $alias) {
                if (preg_match('/\b' . preg_quote($this->text->comparable($alias), '/') . '\b/u', $context) === 1) {
                    return $category;
                }
            }
        }

        return null;
    }
}
