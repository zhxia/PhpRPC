<?php
require_once dirname(__FILE__) . '/functions.php';

class RpcProxy
{
    const VERSION = '10';
    private $timeout = 600;  //worker 进程的生存时间
    private $maxWorkerNum = 5; //最大worker数量
    private $minWorkerNum = 1; // 最小worker数量
    const MAINTAIN_INTERVAL = 60; //定时维护worker进程的时间间隔
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


    function __construct($context, $frontend, $backend)
    {
        if (function_exists('cli_set_process_title')) {
            cli_set_process_title('ARPC: Proxy process');
        }
        $this->workers = array(); //element:array('wid'=>array('creteTime','flag'))
        $this->workerQueue = array();
        $this->taskQueue = new SplQueue();

        $socket = new ZMQSocket($context, ZMQ::SOCKET_ROUTER);
        $socket->setsockopt(ZMQ::SOCKOPT_LINGER, 0);
        $socket->bind($frontend);
        $this->socketClient = $socket;

        $socket = new ZMQSocket($context, ZMQ::SOCKET_ROUTER);
        $socket->setsockopt(ZMQ::SOCKOPT_LINGER, 0);
        $socket->bind($backend);
        $this->socketWorker = $socket;
        $this->backendPoint = $backend;

        $this->interval = 1000 * 600;
        $this->interrupted = false;
    }

    /**
     * @param mixed $maxWorkerNum
     */
    public function setMaxWorkerNum($maxWorkerNum)
    {
        $this->maxWorkerNum = $maxWorkerNum;
    }

    /**
     * @param string $workerScript
     */
    public function setWorkerScript($workerScript)
    {
        $this->workerScript = $workerScript;
    }

    /**
     * @param int $minWorkerNum
     */
    public function setMinWorkerNum($minWorkerNum)
    {
        $this->minWorkerNum = $minWorkerNum;
    }


    /**
     * @param int $timeout
     */
    public function setTimeout($timeout)
    {
        $this->timeout = $timeout;
    }


    public function run()
    {
        rpc_log('proxy is running...', LOG_INFO);
        register_shutdown_function(array($this, 'shutdown'));
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
        if ($this->lastMaintainTime > 0 && (time() - $this->lastMaintainTime < self::MAINTAIN_INTERVAL)) {
            rpc_log('no need to maintain,last maintain:' . date('Y-m-d H:i:s', $this->lastMaintainTime), LOG_INFO);
            return;
        }
        $workersNum = count($this->workers);
        if ($workersNum >= $this->minWorkerNum) {
            $this->cleanupWorker();
        }
        rpc_log('>>>>workerNum:' . $workersNum);
        if ($workersNum < $this->minWorkerNum) {
            $stepNum = $this->minWorkerNum - $workersNum;
            for ($i = 0; $i < $stepNum; $i++) {
                $this->forkWorker();
            }
        }
        rpc_log('<<<<workerNum:' . $workersNum);
        $this->lastMaintainTime = time();
    }

    protected function shutdown()
    {
        $pids = array_keys($this->workers);
        foreach ($pids as $pid) {
            posix_kill($pid, SIGTERM);
        }
    }

    protected function borrowWorker()
    {
        print_r($this->workers);
        print_r($this->workerQueue);
        $wid = array_shift($this->workerQueue);
        if (isset($this->workers[$wid])) {
            $this->workers[$wid][1] = false;
        }
        return $wid;
    }

    protected function addWorker($wid)
    {
        if (!isset($this->workers[$wid])) {
            $this->workers[$wid] = array(time(), true);
        } else {
            $this->workers[$wid][1] = true;
        }
        array_push($this->workerQueue, $wid);
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
            rpc_log('could not fork', LOG_ERR);
            exit(-1);
        }
    }

    private function cleanupWorker()
    {
        foreach ($this->workers as $wid => $wInfo) {
            list($createTime, $status) = $wInfo;
            if ($createTime + $this->timeout < time() && $status) {
                if (posix_kill($wid, SIGTERM)) {
                    unset($this->workers[$wid]);
                    $idx = array_search($wid, $this->workerQueue);
                    unset($this->workerQueue[$idx]);
                }
            }
        }
    }

    private function handlePending()
    {
        rpc_log('task queue length:' . $this->taskQueue->count());
        while (!$this->taskQueue->isEmpty()) {
            $frames = $this->taskQueue->dequeue();
            rpc_log('process pending...');
            if (!$this->forwardToWorker($frames)) {
                break;
            }
        }
    }

    protected function forwardToWorker($frames)
    {
        list($envelope, $message) = rpc_unwrap_message($frames);
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
        if (count($this->workers) >= $this->maxWorkerNum) {
            return false;
        }
        $pid = $this->forkAndExec('/usr/bin/env', array('php', $this->workerScript, $this->backendPoint));
        if ($pid) {
            $this->addWorker($pid);
        }
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
        list($envelope, $message) = rpc_unwrap_message($frames);
        $wid = $envelope[0];
        $version = array_shift($message);
        $command = ord(array_shift($message));
        rpc_log('version:' . $version . ',command:' . $command);
        if ($command == 0x00) { //send back response
            list($envelope, $message) = rpc_unwrap_message($message);
            array_unshift($message, self::VERSION);
            $frames = rpc_wrap_message($envelope, $message);
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

