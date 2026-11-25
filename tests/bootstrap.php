<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// Fallback PSR-4 loader for App namespace (ensures composer autoload regeneration doesn't block tests)
spl_autoload_register(function(string $class): void {
    if (strpos($class, 'App\\') !== 0) {
        return;
    }
    $relative = substr($class, 4);
    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Stop here, with the three lines that fix it, when wunderkind_test is behind the entities —
// the alternative is ~200 failures that read as a broken change. Skips itself if the database
// is unreachable; WK_SKIP_SCHEMA_CHECK=1 turns it off. See the class docblock.
App\Tests\Support\TestSchemaGuard::run();
