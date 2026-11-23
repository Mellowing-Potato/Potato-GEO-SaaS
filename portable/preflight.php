<?php

declare(strict_types=1);

use Yanji\Portable\Instance;

require __DIR__.'/src/Instance.php';
try {
    $instance = new Instance($argv[2]);
    $program = Instance::programPath($argv[1], $instance);
    echo $program."\n".$instance->root."\n";
} catch (RuntimeException $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
