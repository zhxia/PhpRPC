
<?php
require_once dirname(__FILE__) . '/functions.php';
class RpcProxy {
	const VERSION='10';
	private $socket_worker;
	private $socket_client;
	private $workers;
	private $interval;
	private $interrupted;

	public function __construct($context,$frontend,$backend){
		$socket=new ZMQSocket($context,ZMQ::SOCKET_ROUTER);
		$socket->setsockopt(ZMQ::SOCKOPT_LINGER, 0);
		$socket->bind($frontend);
		$this->socket_client=$socket;

		$socket=new ZMQSocket($context,ZMQ::SOCKET_ROUTER);
		$socket->setsockopt(ZMQ::SOCKOPT_LINGER, 0);
		$socket->bind($backend);
		$this->socket_worker=$socket;

        $this->interval=1000*1000;
        $this->workers=array();
        $this->interrupted=false;
	}

	public function run(){
        rpc_log('proxy is running...');
		while (!$this->interrupted) {
			$poll = new ZMQPoll();
            if (!empty($this->workers)) {
                $poll->add($this->socket_client, ZMQ::POLL_IN);
            }
            $poll->add($this->socket_worker,ZMQ::POLL_IN);
            $readable=$writable=array();
            $events = $poll->poll($readable, $writable, $this->interval);
            if ($events == 0) {
                continue;
            }
            foreach($readable as $socket){
            	if($socket===$this->socket_worker){
                    rpc_log('get message from worker');
            		$this->worker_process();
            	}
            	elseif ($socket===$this->socket_client) {
                    rpc_log('get message from client');
            		$this->client_process();
            	}
            }
		}
	}

	protected function client_process(){
        $frames=rpc_receive_frames($this->socket_client);
        list($envelope,$message)=rpc_unwarp_message($frames);
        $version=array_shift($message);
        //get a worker
        $worker=array_shift($this->workers);
        $command=chr(0x00);
        $frames=array($worker,'',self::VERSION,$command);
        $frames=array_merge($frames,$envelope,array(''),$message);
        rpc_send_frames($this->socket_worker,$frames);
	}

	protected function worker_process(){
        $frames=rpc_receive_frames($this->socket_worker);
        list($envelope,$message)=rpc_unwarp_message($frames);
        $worker=$envelope[0];
        $version=array_shift($message);
        $command=ord(array_shift($message));
        rpc_log('version:'.$version.',command:'.$command);
        if($command==0x00){ //send back response
            list($envelope,$message)=rpc_unwarp_message($message);
            array_unshift($message,self::VERSION);
            $frames=rpc_warp_message($envelope,$message);
            rpc_send_frames($this->socket_client,$frames);
            array_push($this->workers,$worker);
        }
        elseif($command==0x01){ //heartbeat
            if(array_search($worker,$this->workers,true)===false){
                array_push($this->workers,$worker);
            }
        }
        else{
            //@todo
        }
	}


}


