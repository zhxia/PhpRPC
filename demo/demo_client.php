<?php
/**
 * Created by PhpStorm.
 * User: zhxia
 * Date: 5/24/15
 * Time: 2:18 PM
 */

require_once dirname(__FILE__) . '/../src/client.php';

$context = new ZMQContext();
//$endpoints = array('ipc:///tmp/frontend1.ipc','ipc:///tmp/frontend2.ipc');
$endpoints='ipc:///tmp/frontend.ipc';
$client = new RpcClient($context, $endpoints);
while(true){
    $sql='select * from jobs';
    $client->start_request('executeSql',$sql,function($resp,$status){
        if($status==200){
            print_r($resp);
        }
        else{
            echo 'failed!'.PHP_EOL;
        }
    });

    $client->start_request('getDateTime',null,function($resp,$status){
        if($status==200){
            echo $resp.PHP_EOL;
        }
        elseif($status==404){
            echo 'remote method not found!'.PHP_EOL;
        }

    });

    $client->start_request('longTimeWork',null,function($resp,$status){
        if($status==200){
            echo $resp.PHP_EOL;
        }
        else{
            echo 'failed,status:'.$status.PHP_EOL;
        }
    });
    $client->wait_for_response();
    break;
//    sleep(1);
}