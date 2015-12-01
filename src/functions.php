<?php
define('LOG_LEVEL', LOG_WARNING);
function rpc_pack_message($unpacked_message)
{
//    return json_encode($unpacked_message);
    return msgpack_pack($unpacked_message);
}

function rpc_unpack_message($packed_message)
{
//    return json_decode($packed_message);
    return msgpack_unpack($packed_message);
}

function rpc_log($message, $level = LOG_DEBUG, $log_path = '/tmp')
{
    if($level<=LOG_LEVEL) {
        error_log('[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, 3, $log_path . '/proxy.log');
    }
}

function rpc_millitime()
{
    return round(microtime(true) * 1000);
}

function rpc_send_frames($socket, $frames)
{
    $last_frame = array_pop($frames);
    foreach ($frames as $frame) {
        $socket->send($frame, ZMQ::MODE_SNDMORE);
    }
    $socket->send($last_frame);
}

function rpc_receive_frames($socket)
{
    $frames = array();
    do {
        $frames[] = $socket->recv();
    } while ($socket->getsockopt(ZMQ::SOCKOPT_RCVMORE));
    return $frames;
}

function rpc_warp_message($envelope, $message)
{
    return array_merge(
        $envelope,
        array(''),
        $message
    );
}

function rpc_unwarp_message($frames)
{
    $idx = array_search('', $frames, true);
    if ($idx === false) {
        return array(array(), $frames);
    }
    return array(array_slice($frames, 0, $idx), array_slice($frames, $idx + 1));
}