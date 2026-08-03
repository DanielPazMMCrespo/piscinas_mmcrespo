<?php

use App\Filament\Resources\CustomActivitylogResource;

return [
    'resources' => [
        'label' => 'Registo de Auditoria',
        'plural_label' => 'Auditoria',
        'hide_restore_action' => false,
        'restore_action_label' => 'Restaurar',
        'hide_resource_action' => false,
        'hide_restore_model_action' => true,
        'resource_action_label' => 'Ver',
        'navigation_item' => true,
        'navigation_group' => null,
        'navigation_icon' => 'heroicon-o-shield-check',
        'navigation_sort' => null,
        'default_sort_column' => 'id',
        'default_sort_direction' => 'desc',
        'navigation_count_badge' => false,
        'resource' => CustomActivitylogResource::class,
    ],
    'date_format' => 'd/m/Y',
    'datetime_format' => 'd/m/Y H:i:s',
];
