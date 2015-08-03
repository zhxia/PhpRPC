<?php

/**
 * Created by PhpStorm.
 * User: zhxia
 * Date: 7/25/15
 * Time: 10:24 AM
 */
class demo_model_mysql
{
    /**
     * @var mysqli
     */
    protected $mysqlConnection=null;
    function __construct()
    {
        $this->mysqlConnection=new mysqli('127.0.0.1','root','admin','jobs');
        if($this->mysqlConnection->connect_errno){
            die('connect error:'.$this->mysqlConnection->error);
        }
    }

    public function executeSql($sql){
        $result=$this->mysqlConnection->query($sql);
        if($result){
            $data=$result->fetch_array();
            $result->close();
            return $data;
        }
        return false;
    }

    public function getDateTime(){
        return date('Y-m-d H:i:s');
    }

    public function longTimeWork(){
        usleep(500000);
        return 'take long time';
    }

    function __destruct(){
        if($this->mysqlConnection!=null) {
            $this->mysqlConnection->close();
        }
    }
}

//(new demo_model_mysql())->executeSql('select * from jobs');