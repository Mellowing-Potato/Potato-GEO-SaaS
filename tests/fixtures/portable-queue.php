<?php

// Local disposable-instance acceptance fixture. Its database is provisioned only by the test runner.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\Queue;
use Yanji\Portable\Instance;

[, $instancePath, $action] = $argv;
$release = realpath($instancePath.'/current');
require $release.'/app/portable/src/Instance.php';
$instance = new Instance($instancePath);
foreach ($instance->environment() as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
require $release.'/app/vendor/autoload.php';
$app = require $release.'/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$tag = getenv('YANJI_TEST_RUN') ?: 'control';
if (!preg_match('/^[a-z0-9-]+$/D', $tag)) {
    throw new RuntimeException('Invalid fixture identifier');
}
if ($action === 'rotation') {
    for ($n=0; $n < 501; $n++) {
        Queue::push(CallQueuedClosure::create(static function () use ($n, $tag): void {
            file_put_contents(storage_path('app/private/portable-rotation-'.$tag), $n.' '.getmypid()."\n", FILE_APPEND | LOCK_EX);
        }));
    }
} elseif ($action === 'timeout') {
    // The loopback fixture counts accepted requests and deliberately withholds the response.
    $port = (int) $argv[3];
    $job = CallQueuedClosure::create(static function () use ($port, $tag): void {
        $client = stream_socket_client('tcp://127.0.0.1:'.$port, $errno, $error, 2);
        fwrite($client, "POST /fixture HTTP/1.1\r\nHost: localhost\r\nContent-Length: 0\r\n\r\n");
        fread($client, 64);
        fclose($client);
        file_put_contents(storage_path('app/private/portable-timeout-finished-'.$tag), 'unexpected');
    });
    Queue::createPayloadUsing(static fn () => ['timeout'=>2, 'maxTries'=>1, 'failOnTimeout'=>true]);
    Queue::push($job);
} elseif ($action === 'after-timeout' || $action === 'drain') {
    $wait = $action === 'drain' ? 3 : 0;
    Queue::push(CallQueuedClosure::create(static function () use ($action, $wait, $tag): void {
        file_put_contents(storage_path('app/private/portable-'.$action.'-started-'.$tag), (string) getmypid());
        sleep($wait);
        file_put_contents(storage_path('app/private/portable-'.$action.'-finished-'.$tag), 'complete');
    }));
} else {
    throw new RuntimeException('Unknown test fixture action');
}
