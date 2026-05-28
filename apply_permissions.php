<?php
$resources = [
    'UserResource' => "return auth()->user()->hasRole('admin');",
    'InstallationResource' => "return auth()->user()->hasRole('admin');",
    'PoolResource' => "return auth()->user()->hasRole('admin');",
    'ProductResource' => "return auth()->user()->hasAnyRole(['admin', 'tecnico']);",
    'StockWarehouseResource' => "return auth()->user()->hasAnyRole(['admin', 'tecnico']);",
    // Os restantes acedem todos por defeito (true) então não precisamos de adicionar canAccess para bloquear.
];

foreach ($resources as $resource => $logic) {
    $path = __DIR__ . "/app/Filament/Resources/{$resource}.php";
    $content = file_get_contents($path);
    
    if (!str_contains($content, 'public static function canAccess()')) {
        $method = "\n    public static function canAccess(): bool\n    {\n        $logic\n    }\n";
        // Insert before public static function form(Form $form)
        $content = str_replace('public static function form(Form $form): Form', $method . "\n    public static function form(Form $form): Form", $content);
        file_put_contents($path, $content);
        echo "Updated $resource\n";
    }
}
echo "Done.\n";
