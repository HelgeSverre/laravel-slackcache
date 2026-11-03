<?php

/**
 * A queue worker in its own PHP process, for the live queue-worker test:
 *
 *     php tests/Live/worker.php /path/to/shared.sqlite
 *
 * Works the `slackcache` queue until it is empty, then exits with the worker's status.
 */

use HelgeSverre\SlackCache\SlackCacheServiceProvider;
use Illuminate\Contracts\Console\Kernel;
use Orchestra\Testbench\Foundation\Application;

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/support.php';

$app = Application::create(options: ['extra' => ['providers' => [SlackCacheServiceProvider::class], 'dont-discover' => ['*']]]);

foreach (slackcacheLiveWorkerConfig($argv[1]) as $key => $value) {
    $app['config']->set($key, $value);
}

$kernel = $app->make(Kernel::class);
$status = $kernel->call('queue:work', ['connection' => 'live', '--queue' => 'slackcache', '--stop-when-empty' => true, '--tries' => 3, '--sleep' => 0]);

fwrite(STDOUT, $kernel->output());
exit($status);
