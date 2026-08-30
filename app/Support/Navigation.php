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
                    self::item('friends', 'Horarios', 'Horarios', 'Gestion de amigos y horarios universitarios.', 'Fase 4'),
                    self::item('discounts', 'Descuentos', 'Descuentos', 'Beneficios personales para encontrar promociones compatibles.', 'Fase 7'),
                    self::item('expenses', 'Gastos', 'Gastos', 'Configuracion de gastos habituales.', 'Fase 10'),
                    self::item('video', 'Video', 'Video', 'Editor e historial de procesamientos.', 'Fase 6'),
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
            return self::item('notifications', 'Notificaciones', 'Notificaciones', 'Centro general de actividad.', 'Fase 3');
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
            'icon' => self::iconFor($key),
        ];
    }

    private static function iconFor(string $key): string
    {
        return match ($key) {
            'home' => 'home',
            'organization' => 'check-square',
            'friends' => 'users',
            'discounts' => 'percent',
            'expenses' => 'wallet',
            'video' => 'play',
            'settings' => 'settings',
            'notifications' => 'bell',
            default => 'circle',
        };
    }
}
