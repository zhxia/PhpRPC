<?php
/**
 * Created by PhpStorm.
 * User: zhxia
 * Date: 5/23/15
 * Time: 10:45 PM
 */

require_once dirname(__FILE__) . '/functions.php';

class RpcClient
{
    const VERSION = 10;
    private $socket;
    private static $sequence = 0;
    private static $pending_requests = array();
    private static $responses = array();
    private static $sockets = array();
    private $default_callback;
    private $expiry;

    public function __construct($context, $endpoints)
    {
        $socket = new ZMQSocket($context, ZMQ::SOCKET_DEALER);
        $socket->setSockOpt(ZMQ::SOCKOPT_LINGER, 0);
        $socket->setsockopt(ZMQ::SOCKOPT_HWM, 1000);
        if (!is_array($endpoints)) {
            $endpoints = (array)$endpoints;
        }
        foreach ($endpoints as $endpoint) {
            $socket->connect($endpoint);
        }
        $this->socket = $socket;
        self::$sockets[] = $socket;
        $this->expiry = 1000;
    }

    public function set_expiry($expiry)
    {
        $this->expiry=$expiry;
    }

    public function set_default_callback($callback)
    {
        $this->default_callback = $callback;
    }

    public function start_request($method, $params, $callback = null, $expiry = null)
    {
        if($expiry===null){
            $expiry=$this->expiry;
        }
        $seq = ++self::$sequence;
        $timestamp = rpc_millitime();
        $frames[] = '';
        $frames[] = self::VERSION;
        $frames[] = rpc_pack_message(array($seq, $timestamp, $expiry));
        $frames[] = $method;
        $frames[] = rpc_pack_message($params);
        rpc_send_frames($this->socket, $frames);
        self::$pending_requests[$seq] = array($this, $callback);
        return $seq;
    }

    public function wait_for_responses($timeout = null)
    {
        $poll = new ZMQPoll();
        foreach (self::$sockets as $socket) {
            $poll->add($socket, ZMQ::POLL_IN);
        }
        if ($timeout !== null) {
            $beginTime = rpc_millitime();
            $timeout_micro = $timeout * 1000;
        } else {
            $timeout_micro = -1;
        }
        while (count(self::$pending_requests) > 0) {
            $readable = $writable = array();
            $events = $poll->poll($readable, $writable, $timeout_micro);
            if ($events == 0) {
                break;
            }
            foreach ($readable as $socket) {
                self::process_reply($socket);
            }
            if ($timeout !== null) {
                if (rpc_millitime() - $beginTime >=$timeout_micro) {
                    break;
                }
            }
        }
        return count(self::$pending_requests);
    }

    public static function fetch_response($seq, $keep = false)
    {
        if (!isset(self::$responses[$seq])) {
            return array(101, null);
        }
        $result = self::$responses[$seq];
        if (!$keep) {
            unset(self::$responses[$seq]);
        }
        return $result;
    }

    protected static function process_reply($socket)
    {
        $frames = rpc_receive_frames($socket);
        list($envelope, $message) = rpc_unwarp_message($frames);
        $version = array_shift($message);
        list($seq, $timestamp, $status) = rpc_unpack_message(array_shift($message));
        $response = array_shift($message);
        if ($response !== null) {
            $response = rpc_unpack_message($response);
        }
        list($obj, $callback) = self::$pending_requests[$seq];
        unset(self::$pending_requests[$seq]);
        if (!$callback) {
            $callback = $obj->default_callback;
        }
        if ($callback) {
            call_user_func_array($callback, array($response,$status));
        } else {
            self::store_response($seq, $status, $response);
        }
    }

    protected static function store_response($seq, $status, $response)
    {
        self::$responses[$seq] = array($response,$status);
    }

}
