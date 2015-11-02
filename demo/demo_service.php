<?php
/**
 * Created by PhpStorm.
 * User: zhxia
 * Date: 6/13/15
 * Time: 9:27 PM
 */
require_once dirname(__FILE__) . '/../src/proxy.php';

$shortOption = 'hf:b:m:n:s:dt::';
$longOption = array('help', 'frontend:', 'backend:', 'max-workers:', 'min-workers:', 'workerscript:', 'daemon', 'timeout::');
$params = getopt($shortOption, $longOption);
if (isset($params['h'])) {
    echo 'usage:' . $argv[0] . ' options' . PHP_EOL;
    exit(0);
}
if (isset($params['f']) && $params['f']) {
    $frontend = $params['f'];
}
if (isset($params['b']) && $params['b']) {
    $backend = $params['b'];
}
if (isset($params['m']) && $params['m']) {
    $maxWorkers = $params['m'];
}
if (isset($params['n']) && $params['n']) {
    $minWorkers = $params['n'];
}
$daemon = false;
if (isset($params['d'])) {
    $daemon = true;
}
if ($daemon) {
    if (pcntl_fork() === 0) {
        posix_setsid();
        if (pcntl_fork() === 0) {
            startProxy($frontend, $backend, $minWorkers, $maxWorkers);
        } else {
            exit;
        }
    } else {
        pcntl_wait($status);
    }
} else {
    startProxy($frontend, $backend, $minWorkers, $maxWorkers);
}

function startProxy($frontend, $backend, $minWorkers, $maxWorkers)
{
    $context = new ZMQContext();
    $proxy = new RpcProxy($context, $frontend, $backend);
    $proxy->setMaxWorkerNum($maxWorkers);
    $proxy->setMinWorkerNum($minWorkers);
    $proxy->setWorkerScript('demo_worker.php');
    $proxy->run();
}



