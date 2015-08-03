<?php

/**
 * Created by PhpStorm.
 * User: zhxia
 * Date: 5/24/15
 * Time: 4:58 PM
 */
class demo_model
{
    public function get_md5_string($str){
        return $str.':'.md5($str.microtime(true));
    }

    public function get_time(){
        return date('Y-m-d H:i:s').'|'.microtime(true);
    }

}