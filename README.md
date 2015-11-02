# PhpRPC

基于ZMQ的异步RPC框架，可用于将单个PHP进程中的串行调用转换为非阻塞的异步调用，缩短整个请求耗时

使用示例：
开启服务端：
    php demo_service.php -f ipc:///tmp/frontend.ipc -b ipc:///tmp/backend.ipc -m 2 -n 1
```php
require_once dirname(__FILE__) . '/../src/client.php';

$context = new ZMQContext();
//$endpoints = array('ipc:///tmp/frontend1.ipc','ipc:///tmp/frontend2.ipc');
$endpoints='ipc:///tmp/frontend.ipc';
$client = new RpcClient($context, $endpoints);
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
$client->wait_for_responses(0.1);
```
