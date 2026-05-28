<?php
$resources = [
    'UserResource',
    'InstallationResource',
    'PoolResource',
    'ProductResource',
    'StockWarehouseResource',
];

foreach ($resources as $resource) {
    $path = __DIR__ . "/app/Filament/Resources/{$resource}.php";
    $content = file_get_contents($path);
    $content = str_replace('public static function form(Form ): Form', 'public static function form(Form $form): Form', $content);
    file_put_contents($path, $content);
    echo "Fixed $resource\n";
}
echo "Done.\n";
