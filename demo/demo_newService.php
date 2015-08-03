<?php
/**
 * Created by PhpStorm.
 * User: zhxia
 * Date: 6/13/15
 * Time: 9:27 PM
 */
require_once dirname(__FILE__) . '/../src/functions.php';

class NewProxy
{
    const VERSION='10';
    const ALIVE_TIME = 600;  //worker 进程的生存时间
    const MAX_WORKERS_NUM = 5; //最大worker数量
    const MIN_WORKERS_NUM = 1; // 最小worker数量
    const MAINTAIN_INTERVAL = 10;
    private $workers;
    private $workerQueue;
    private $taskQueue;
    private $socketClient;
    private $socketWorker;
    private $interval;
    private $interrupted;
    private $workerScript;
    private $lastMaintainTime = 0;
    private $backendPoint;


    public function __construct($context, $frontend, $backend)
    {
        $this->workers = array(); //element:array('wid'=>array('creteTime','flag'))
        $this->workerQueue = array();
        $this->taskQueue = new SplQueue();
        $this->workerScript = dirname(__FILE__) . '/demo_worker.php';

        $socket = new ZMQSocket($context, ZMQ::SOCKET_ROUTER);
        $socket->setsockopt(ZMQ::SOCKOPT_LINGER, 0);
        $socket->bind($frontend);
        $this->socketClient = $socket;

        $socket = new ZMQSocket($context, ZMQ::SOCKET_ROUTER);
        $socket->setsockopt(ZMQ::SOCKOPT_LINGER, 0);
        $socket->bind($backend);
        $this->socketWorker = $socket;
        $this->backendPoint=$backend;

        $this->interval = 1000 * 600;
        $this->interrupted = false;
    }

    public function run()
    {
        rpc_log('proxy is running...');
        register_shutdown_function(array($this,'shutdown'));
        while (!$this->interrupted) {
            $this->maintain();
            $poll = new ZMQPoll();
            if (!empty($this->workers)) {
                $poll->add($this->socketClient, ZMQ::POLL_IN);
            }
            $poll->add($this->socketWorker, ZMQ::POLL_IN);
            $readable = $writable = array();
            $events = $poll->poll($readable, $writable, $this->interval);
            if ($events == 0) {
                continue;
            }
            foreach ($readable as $socket) {
                if ($socket === $this->socketWorker) {
                    rpc_log('get message from worker');
                    $this->workerProcess();
                } elseif ($socket === $this->socketClient) {
                    rpc_log('get message from client');
                    $this->clientProcess();
                }
            }
        }
    }

    protected function maintain()
    {
        if (time() - $this->lastMaintainTime < self::MAINTAIN_INTERVAL) {
            return;
        }
        if ($this->workers) {
            $this->cleanupWorkers();
        }
        $workersNum = count($this->workers);
        rpc_log('>>>>workerNum:'.$workersNum.';'.var_export($this->workers,true));
        if ($workersNum < self::MIN_WORKERS_NUM) {
            $maxNum = self::MIN_WORKERS_NUM - $workersNum;
            for ($i = 0; $i < $maxNum; $i++) {
                $this->forkWorker();
            }
        }
        rpc_log('<<<<workerNum:'.$workersNum.';'.var_export($this->workers,true));
        $this->lastMaintainTime = time();
    }

    public function shutdown(){
        $pids=array_keys($this->workers);
        foreach($pids as $pid){
            posix_kill($pid,SIGTERM);
        }
    }

    protected function borrowWorker()
    {
        if (!empty($this->workerQueue)) {
            $wid = array_shift($this->workerQueue);
            $this->workers[$wid][1] = false;
            return $wid;
        }
        return false;
    }

    protected function addWorker($wid)
    {
        if (!array_key_exists($wid, $this->workers)) {
            $this->workers[$wid] = array(time(), true);
        } else {
            $this->workers[$wid][1] = true;
        }
        array_unshift($this->workerQueue, $wid);
    }

    private function forkAndExec($cmd, $args = array())
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

    private function cleanupWorkers()
    {
        if ($this->workers) {
            foreach ($this->workers as $wid => $wInfo) {
                list($createTime, $status) = $wInfo;
                if ($createTime + self::ALIVE_TIME < time() && $status) {
                    if (posix_kill($wid, SIGTERM)) {
                        unset($this->workers[$wid]);
                        $idx = array_search($wid, $this->workerQueue);
                        unset($this->workerQueue[$idx]);
                    }
                }
            }
        }
    }

    private function handlePending()
    {
        rpc_log('task queue length:'.$this->taskQueue->count());
        while(!$this->taskQueue->isEmpty()){
            $frames=$this->taskQueue->dequeue();
            rpc_log('process pending...'.var_export($frames,true));
            if(!$this->forwardToWorker($frames)){
                break;
            }
        }
    }


    protected function forwardToWorker($frames)
    {
        list($envelope, $message) = rpc_unwarp_message($frames);
        $version = array_shift($message);
        //get a worker
        $wid = $this->borrowWorker();
        if (empty($wid)) {
            $this->taskQueue->enqueue($frames);
            $this->forkWorker();
            return false;
        }
        $command = chr(0x00);
        $frames = array($wid, '', self::VERSION, $command);
        $frames = array_merge($frames, $envelope, array(''), $message);
        rpc_send_frames($this->socketWorker, $frames);
        return $wid;
    }

    protected function forkWorker()
    {
        if (count($this->workers) >= self::MAX_WORKERS_NUM) {
            return false;
        }
        $pid = $this->forkAndExec('/usr/bin/env', array('php', $this->workerScript,$this->backendPoint));
        return $pid;
    }

    protected function clientProcess()
    {
        $frames = rpc_receive_frames($this->socketClient);
        $this->forwardToWorker($frames);
    }

    protected function workerProcess()
    {
        $frames = rpc_receive_frames($this->socketWorker);
        list($envelope, $message) = rpc_unwarp_message($frames);
        $wid = $envelope[0];
        $version = array_shift($message);
        $command = ord(array_shift($message));
        rpc_log('version:' . $version . ',command:' . $command);
        if ($command == 0x00) { //send back response
            list($envelope, $message) = rpc_unwarp_message($message);
            array_unshift($message, self::VERSION);
            $frames = rpc_warp_message($envelope, $message);
            rpc_send_frames($this->socketClient, $frames);
            $this->addWorker($wid);
            $this->handlePending();
        } elseif ($command == 0x01) { //heartbeat
            $this->addWorker($wid);
            $this->handlePending();
        } else {
            //@todo
        }
    }
}

$frontend = 'ipc:///tmp/frontend.ipc';
$backend = 'ipc:///tmp/backend.ipc';
if(isset($argv[1])){
    $frontend=$argv[1];
}
if(isset($argv[2])){
    $backend=$argv[2];
}
$context = new ZMQContext();
$proxy=new NewProxy($context,$frontend,$backend);
$proxy->run();