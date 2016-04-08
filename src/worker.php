<?php
/**
 * Created by PhpStorm.
 * User: zhxia
 * Date: 5/23/15
 * Time: 9:40 PM
 */

require dirname(__FILE__) . '/functions.php';

class RpcWorker
{
    const VERSION = '10';
    private $socket;
    private $interval;
    private $interrupted;
    private $delegate;

    public function set_delegate($delegate)
    {
        $this->delegate=$delegate;
    }

    public function __construct($context, $endpoint)
    {
        if(function_exists('cli_set_process_title')){
            cli_set_process_title('ARPC: worker process');
        }
        $socket = new ZMQSocket($context, ZMQ::SOCKET_DEALER);
        $socket->setSockOpt(ZMQ::SOCKOPT_LINGER, 0);
        $socket->setSockOpt(ZMQ::SOCKOPT_IDENTITY, strval(posix_getpid()));
        $socket->connect($endpoint);
        $this->socket = $socket;
        $this->interval = 1000 * 1000;
        $this->interrupted = false;
    }

    public function run()
    {
        rpc_log('worker is running...');
        $this->send_heartbeat();
        $poll = new ZMQPoll();
        $poll->add($this->socket, ZMQ::POLL_IN);
        while (!$this->interrupted) {
            $readable = $writable = array();
            $events = $poll->poll($readable, $writable, $this->interval);
            if (posix_getppid() == 1) {
                break;
            }
            if ($events) {
                $this->process();
            } else {
                $this->send_heartbeat();
            }
        }
    }

    protected function process()
    {
        rpc_log('get task from client');
        $frames = rpc_receive_frames($this->socket);
        list($envelope, $message) = rpc_unwrap_message($frames);
        $version = array_shift($message);
        $command = array_shift($message);
        if ($command == 0x00) {
            $this->process_request($message);
        }
    }

    protected function process_request($message)
    {
        list($envelope, $message) = rpc_unwrap_message($message);
        list($seq, $timestamp, $expiry) = rpc_unpack_message(array_shift($message));
        $now = rpc_millitime();
        if ($now > $timestamp + $expiry) {
            $this->send_reply($envelope, $seq, $now, 503, null);
            return;
        }
        $method = array_shift($message);
        if ($method === null) {
            $this->send_reply($envelope, $seq, $now, 400, null);
            return;
        }

        if(!method_exists($this->delegate,$method)){
            $this->send_reply($envelope, $seq, $now, 404, null);
            return;
        }
        $params = array_shift($message);
        if ($params !== null) {
            $params = rpc_unpack_message($params);
            if(!is_array($params)){
                $params=(array)$params;
            }
        }
        $response=call_user_func_array(array($this->delegate,$method),$params);
        $now = rpc_millitime();
        $this->send_reply($envelope, $seq, $now, 200, $response);
    }

    protected function send_reply($envelope, $sequence, $timestamp, $status, $response)
    {
        $frames = array_merge(array('', self::VERSION, chr(0x00)), $envelope);
        $frames[] = '';
        $frames[] = rpc_pack_message(array($sequence, $timestamp, $status));
        if ($response !== null) {
            $frames[] = rpc_pack_message($response);
        }
        rpc_send_frames($this->socket, $frames);
    }

    protected function send_heartbeat()
    {
        rpc_send_frames($this->socket, array('', self::VERSION, chr(0x01)));
    }


}
