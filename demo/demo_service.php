<?php
/**
 * Created by PhpStorm.
 * User: zhxia
 * Date: 5/24/15
 * Time: 2:19 PM
 */

require_once dirname(__FILE__) . '/../src/proxy.php';

function fork_and_exec($cmd, $args = array())
{
    $pid = pcntl_fork();
    if ($pid > 0) {
        return $pid;
    } else if ($pid == 0) {
        pcntl_exec($cmd, $args);
        exit(0);
    } else {
        // TODO:
        die('could not fork');
    }
}

$frontend = 'ipc:///tmp/frontend.ipc';
$backend = 'ipc:///tmp/backend.ipc';
$context = new ZMQContext();
$proxy = new RpcProxy($context, $frontend, $backend);
$worker = dirname(__FILE__) . '/demo_worker.php';
$worker_num = 5;
if ($argc > 1) {
    $worker_num = (int)$argv[1];
}
$pids = array();
for ($i = 0; $i < $worker_num; $i++) {
    $pids[] = fork_and_exec('/usr/bin/env', array('php', $worker));
}
register_shutdown_function(function ($pids) {
    foreach ($pids as $pid) {
        posix_kill($pid, SIGTERM);
    }
});
$proxy->run();

