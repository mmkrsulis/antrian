<?php
declare(strict_types=1);
namespace App\Core;

final class View
{
    public static function render(string $view, array $data = [], bool $layout = true): void
    {
        extract($data, EXTR_SKIP);
        $viewFile = dirname(__DIR__) . '/Views/' . $view . '.php';
        if (!$layout) { require $viewFile; return; }
        ob_start();
        require $viewFile;
        $content = ob_get_clean();
        require dirname(__DIR__) . '/Views/layout.php';
    }
}

