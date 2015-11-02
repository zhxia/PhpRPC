<?php
/**
 * Created by PhpStorm.
 * User: zhxia
 * Date: 5/24/15
 * Time: 2:19 PM
 */

require_once dirname(__FILE__) . '/../src/worker.php';
require_once 'demo_model_mysql.php';
$context = new ZMQContext();
if ($argc < 1) {
    die('need one parameter!');
}
if (isset($argv[1]) && $argv[1]) {
    $endpoint = $argv[1];
}
$worker = new RpcWorker($context, $endpoint);
$model = new demo_model_mysql();
$worker->set_delegate($model);
$worker->run();