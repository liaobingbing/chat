<?php
/**
    好友相关操作
*/
 // require 'event_user_info.php';

class Event_Friend {
/**
    添加新增好友请求记录
    @param $from_client_id
    @param $to_client_Id
*/
    static function addNewFrind($from_client_id,$to_client_Id){
        $db2 = Event_data::getdb();
        //判断是否已有记录
        $sql = "select * from ".DB_PREFIX."contacts_friends where ".
                // " ((acceptorId='$from_client_id' and originatorId='$to_client_Id') or ". 
               //20170821当前请求只能是发起人
                " (acceptorId='$to_client_Id' and originatorId='$from_client_id') ".
                " and OperationStatus>=0";
        $result = $db2->fetch_first($sql);
        if(!$result){
            //无记录，即非好友
            $array = array(
                'originatorId'=>$from_client_id,
                'acceptorId'=>$to_client_Id,
                'OperationStatus'=>0,//0,
                'MsgStatus'=>0,//0,
                'TimeStamp'=>time(),
                'UpdateTime'=>date("Y-m-d H:i:s",time()),
                'InsertTime'=>date("Y-m-d H:i:s",time()),
                );
            $result = $db2->insert(DB_PREFIX.'contacts_friends',$array);
        }
        else{ 
        } 
    }

/**
    删除好友
    @param $from_client_id
    @param $to_client_Id
*/
    static function delFriend($from_client_id,$to_client_Id){
        $db2 = Event_data::getdb();
        //与对方是好友关系
        $sql = " ((acceptorId='".$from_client_id."' and originatorId='".$to_client_Id."') or".
                "(originatorId='".$from_client_id."' and acceptorId='".$to_client_Id."') )".
                " and OperationStatus = 1";
        $updatearray = array(
            'OperationStatus' => -3, 
            'MsgStatus' => 1, 
            'UpdateTime'=>date("Y-m-d H:i:s",time()), 
        );
        $db2->update(DB_PREFIX."contacts_friends",$updatearray,$sql);
    }
/**
    获取新增好友请求
    @param client_Id 当前用户id
*/
   static function getNewFriendRequest($client_Id){
        $db = Event_data::getdb();


        //获取他人申请添加好友的请求记录
        //未有操作 未下发给客户端
        $sql = " select OriginatorId,InsertTime,`TimeStamp` as timestamp from ".
            DB_PREFIX."contacts_friends where AcceptorId = '$client_Id' and OperationStatus = 0 and MsgStatus = 0";
        $result = $db->getall($sql); 
        if(!empty($result)){ 

            //$db是全局的变量，非静态，需要再次赋值，以传递给验证类进行数据库访问
            // global $db;
            // if(empty($db)){ 
            //     $db= Event_data::getdb();
            // }
            //查找每一个id的信息
            foreach ($result as $key => $value) {    
                $uid =$value['OriginatorId'] ;
                $tsp = $value['timestamp'] ;
                $uInfo = Authentication::QueryUserDetailInfo($uid); 
                $data = array(
                    'originatorId'=>$uid,
                    'name' => $uInfo['name'],
                    'timestamp' => $tsp
                    );
                $re[] = $data;
            }    

            //更新好友表的 MsgStatus为1 ，表示系统已下发过通知
            self::updateFriendOperation($client_Id);
        }
        else{
            $re = '';
        }

        return $re;
   }

/**
    更新好友操作
*/
   static function updateFriendOperation($client_Id){
        $db2 = Event_data::getdb();

        //更新好友表的 MsgStatus为1 ，表示系统已下发过通知
        $updatearray = array(  
            'MsgStatus' => 1, 
            'UpdateTime'=>date("Y-m-d H:i:s",time()), 
        );
        $db2->update(DB_PREFIX."contacts_friends",$updatearray," AcceptorId = '$client_Id' and OperationStatus = 0 and msgStatus = 0");
   }

/**
    更新好友请求
*   @param $from_client_id  申请加好友的用户ID/发起者Originator
*   @param $to_client_id    被加好友的用户ID/接受者Acceptor
*   @param $status          请求状态 1通过，-1拒绝
*/
    static function updateFriendRequest($from_client_id,$to_client_id,$status){
        $db2 = Event_data::getdb();
        $from_uInfo = Event_User_Info::getUserInfo($from_client_id);
        $to_uInfo = Event_User_Info::getUserInfo($to_client_id);


        $updatearray = array(
            'OperationStatus' => $status, 
            'OriginatorName' => $from_uInfo['name'], 
            'AcceptorName' => $to_uInfo['name'], 
            'MsgStatus' => 1, 
            'UpdateTime'=>date("Y-m-d H:i:s",time()), 
        );

        //echo ' updateFriendRequest- status -> '.$status.PHP_EOL;
        //echo " AcceptorId = '$to_client_id' and OriginatorId = '$from_client_id' and OperationStatus = 0 ".PHP_EOL;
        $db2->update(DB_PREFIX."contacts_friends",$updatearray," AcceptorId = '$to_client_id' and OriginatorId = '$from_client_id' and OperationStatus = 0 ");    
    }

  /**
  检测双方是否为好友关系
  */
    static function checkFriend($from_client_Id,$to_client_Id){
        $db2 = Event_data::getdb();
        $sql = "select * from ".DB_PREFIX."contacts_friends where ".
                " ((acceptorId='$from_client_Id' and originatorId='$to_client_Id') ".
                " or (acceptorId='$to_client_Id' and originatorId='$from_client_Id') )".
                " and OperationStatus=1";
        $result = $db2->fetch_first($sql);
        if(isset($result) && !empty($result)){
            return true;
        }
        else{
            return false;
        }
    } 

/**
    获取好友列表
*/
    static function getFriendList($client_Id){ 
        $sql ="select acceptorId,originatorId from ".DB_PREFIX."contacts_friends ".
            " where OperationStatus=1 and ( acceptorId='$client_Id' or originatorId='$client_Id' )";
        $db = Event_data::getdb();
        $result = $db->getall($sql); 

        if(!$result){ 
            $re = array();
        }
        else{ 
            $data = array();
            for($i = 0;$i < count($result);$i++){ 
                if($result[$i]['acceptorId'] == $client_Id){
                    //被申请人是当前用户
                    $friendId = $result[$i]['originatorId'];
                }
                else{
                    //好友申请人是当前用户
                    $friendId = $result[$i]['acceptorId'];
                }
                //echo $friendId.PHP_EOL;
                $uInfo = Authentication::QueryUserDetailInfo($friendId);
                $uName = $uInfo['name'];
                $gp = new GetPin();
                $pinyin = $gp->Pinyin(strtolower($uName));

                //20170112 zhanghaohuang新增
                //判断账号是否存在、判断账号是否已经在返回数组中-避免重复用户
                if(!isset($uInfo['id']) || empty($uInfo['id'])){
                    continue;
                }
                if( isset($tmp)){
                    if(in_array($uInfo['id'], $tmp)){
                        continue;
                    }
                }

                $uDetailInfo = Event_User_Info::getUserInfo($uInfo['id']);

                $tmp[] = $uInfo['id'];
                $data = array(
                    'name_id' => $uInfo['id'],
                    'spell' => $pinyin,
                    'name' => $uName,
                    'icon' => $uInfo['icon'],
                    'mobile'=>$uDetailInfo['mobile'],
                    'telephone'=>$uDetailInfo['telephone'],
                    'email'=>$uDetailInfo['email'],
                    'title'=>$uDetailInfo['title']
                    );
                $re[] = $data;
            }
        }

        return $re;
    } 
 
   /**
    模糊搜索获取用户列表
   */
    function QueryUserListByUid($search_text,$from_client_id){ 
        $db = Event_data::getdb();

        $uList = Authentication::QueryUserListByUid($search_text);
        if(isset($uList) && !empty($uList) && count($uList)>0 ){
            for($i=0;$i<count($uList); $i++ ) {
                $uid = $uList[$i]['id'];
                if($uid == $from_client_id) continue;//不搜索自己 
                $data['name_id'] = $uid;//20170420 zhh 修改为name_id 解决ios解析id的冲突
                $data['name'] = $uList[$i]['name'];
                  
                //获取头像
                $uInfo = $db->fetch_first("SELECT ID,UIconPath as UIconPathCut ".
                    " from ".DB_PREFIX."user_info WHERE UserId ='$uid' order by UpdateTime desc");

                if(!empty($uInfo)){
                    $uIcon = Server_Uri.Attachement_OutPath.$uInfo['UIconPathCut'];
                }
                else{
                    $uIcon = null;
                }

                $data['icon'] = $uIcon;
                $re[] = $data;
            } 
        }
        else{ 
            $re = null;
        }  
        return $re;
    }
}