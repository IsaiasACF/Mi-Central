<?php
declare(strict_types=1);

namespace App\Support;

final class Navigation
{
    public static function groups(): array
    {
        return [
            [
                'label' => null,
                'items' => [
                    self::item('home', 'Inicio', 'Mi Central', 'Dashboard en preparacion.', 'Fase 1'),
                    self::item('organization', 'Organizacion', 'Organizacion', 'Tareas y Bandeja en una sola vista.', 'Fase 2'),
                    self::item('friends', 'Amigos en la U', 'Amigos en la U', 'Gestion de amigos y futuros horarios universitarios.', 'Fase 4'),
                ],
            ],
            [
                'label' => 'Descuentos',
                'items' => [
                    self::item('discounts-for-me', 'Para mi', 'Descuentos', 'Funcionalidad pendiente de la Fase 7.', 'Fase 7'),
                    self::item('discounts-today', 'Hoy', 'Descuentos', 'Funcionalidad pendiente de la Fase 7.', 'Fase 7'),
                    self::item('discounts-all', 'Todos', 'Descuentos', 'Funcionalidad pendiente de la Fase 7.', 'Fase 7'),
                    self::item('discounts-benefits', 'Mis beneficios', 'Descuentos', 'Funcionalidad pendiente de la Fase 7.', 'Fase 7'),
                    self::item('discounts-favorites', 'Favoritos', 'Descuentos', 'Funcionalidad pendiente de la Fase 7.', 'Fase 7'),
                    self::item('discounts-sources', 'Fuentes', 'Descuentos', 'Funcionalidad pendiente de la Fase 8.', 'Fase 8'),
                ],
            ],
            [
                'label' => 'Video',
                'items' => [
                    self::item('video-editor', 'Editor', 'Video', 'Funcionalidad pendiente de la Fase 6.', 'Fase 6'),
                    self::item('video-processing', 'Procesamientos', 'Video', 'Funcionalidad pendiente de la Fase 6.', 'Fase 6'),
                ],
            ],
            [
                'label' => null,
                'items' => [
                    self::item('settings', 'Configuracion', 'Configuracion', 'Funcionalidad pendiente de implementar.', 'Fase futura'),
                ],
            ],
        ];
    }

    public static function resolve(?string $section): ?array
    {
        $section = $section === null || $section === '' ? 'home' : $section;

        if ($section === 'notifications') {
            return self::item('notifications', 'Notificaciones', 'Notificaciones', 'Centro de notificaciones internas.', 'Fase 3');
        }

        foreach (self::items() as $item) {
            if ($item['key'] === $section) {
                return $item;
            }
        }

        return null;
    }

    public static function items(): array
    {
        $items = [];

        foreach (self::groups() as $group) {
            foreach ($group['items'] as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    public static function url(string $section): string
    {
        return $section === 'home' ? '/index.php' : '/index.php?section=' . rawurlencode($section);
    }

    private static function item(
        string $key,
        string $label,
        string $title,
        string $description,
        string $phase
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'title' => $title,
            'description' => $description,
            'phase' => $phase,
        ];
    }
}
