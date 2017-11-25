<?php 
/**
  数据操作相关
*/
 
class Event_data{
  static function getData($sql){
      $db2 = self::getdb();
      $result = $db2->getall($sql);
      return $result;
  }

  static function updateData($table,$updatearray,$whereSql){
      $db2 = self::getdb();
      $db2->update($table,$updatearray,$whereSql);
  }

  /**
  *获取数据库连接
  */
  static function getdb(){ 
      $db2 = new chency_mysql;
      $db2->connect(DB_HOST, DB_USER, DB_PASS, DB_DATA, DB_CHARSET, DB_PCONNECT, true); 
      return $db2;
  }
   static function save_uuid($uuid){
       // session_start();
        //error_reporting(E_ALL&E_NOTICE);
        //$uuid=$_GET['uuid'];
        $total=50;
        $limit=5;
        $middle=ceil($total/2);
        $count=@count($_SESSION['message_list'])+1;
        //echo $count;echo "</br>";
        if($count<$total){
            $_SESSION['message_list'][]=array('uuid'=>$uuid,'time'=>time());
        }
        if($count>=$total){
            $time=$_SESSION['message_list'][24]['time'];
            $now_time=time();
            $tm=$now_time-$time;
            //echo $tm;die;
            if($tm>$limit){
                for($i=0;$i<$middle;$i++){
                    array_shift($_SESSION['message_list']);
                }
            }
        }
        /*else{
            $_SESSION['message_list'][]=array('uuid'=>$uuid,'time'=>time());
        }*/
}
}