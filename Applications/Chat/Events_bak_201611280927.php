<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link http://www.workerman.net/
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

/**
 * 用于检测业务代码死循环或者长时间阻塞等问题
 * 如果发现业务卡死，可以将下面declare打开（去掉//注释），并执行php start.php reload
 * 然后观察一段时间workerman.log看是否有process_timeout异常
 */
//declare(ticks=1);

/**
 * 聊天主逻辑
 * 主要是处理 onMessage onClose 
 */
use \GatewayWorker\Lib\Gateway; 

if(!defined('CHENCY_ROOT')){
    define('CHENCY_ROOT', substr(dirname(__FILE__), 0, -55));
}
require_once CHENCY_ROOT.'./source/conf/set.inc.php';  
require_once CHENCY_ROOT.'./source/conf/db.inc.php'; 
require_once CHENCY_ROOT.'./source/conf/config.inc.php';
require_once CHENCY_ROOT.'./source/core/class.mysql.php';
require_once CHENCY_ROOT.'./admin/UserAuthentication.php';  
$db = new chency_mysql;
$db->connect(DB_HOST, DB_USER, DB_PASS, DB_DATA, DB_CHARSET, DB_PCONNECT, true); 

class Events
{
   /**
    * 有消息时
    * @param int $client_id
    * @param mixed $message
    */
   public static function onMessage($client_id, $message)
   {
        // debug
        echo "client:{$_SERVER['REMOTE_ADDR']}:{$_SERVER['REMOTE_PORT']} gateway:{$_SERVER['GATEWAY_ADDR']}:{$_SERVER['GATEWAY_PORT']}  client_id:$client_id session:".json_encode($_SESSION)." onMessage:".$message."\n";
        
        // 客户端传递的是json数据
        $message_data = json_decode($message, true);
        if(!$message_data)
        {
            echo " message is not json ";
            return ;
        }

        $to_clientId = $message_data['to_id']?$message_data['to_id']:'';
        $from_client_id = $message_data['from_id']?$message_data['from_id']:''; 
        $from_client_name = $message_data['from_name']?$message_data['from_name']:'';
        $content = $message_data['content']?$message_data['content']:'';
        $tStamp = $message_data['time']?$message_data['time']:'';


        
        // 根据类型执行不同的业务
        switch($message_data['type'])
        {
            // 客户端回应服务端的心跳
            case 'pong':  
                return;
            // 客户端登录 message格式: {type:login, name:xx, room_id:1} ，添加到客户端，广播给所有客户端xx进入聊天室
            case 'login':
                // 判断是否有房间号
                if(!isset($message_data['room_id']))
                {
                    throw new \Exception("\$message_data['room_id'] not set. client_ip:{$_SERVER['REMOTE_ADDR']} \$message:$message");
                }
                
                // 把房间号昵称放到session中
                $room_id = $message_data['room_id'];
               // $client_name = htmlspecialchars($message_data['client_name']);
                $_SESSION['room_id'] = $room_id;
               // $_SESSION['client_name'] = $client_name;

                $_SESSION['clientID'] = $message_data['clientID'];                
                $clientId = $message_data['clientID'];
               // $clientId = $message_data['client_name'];
                // 获取房间内所有用户列表 
                $clients_list = Gateway::getClientInfoByGroup($room_id);
                foreach($clients_list as $tmp_client_id=>$item)
                {
                    $clients_list[$tmp_client_id] = $item['clientID'];
                }
                $clients_list[$client_id] = $clientId;
                
                // 转播给当前房间的所有客户端，xx进入聊天室 message {type:login, client_id:xx, name:xx} 
                $new_message = array('type'=>$message_data['type'], 'client_id'=>$clientId, 'time'=>time());
                //Gateway::sendToGroup($room_id, json_encode($new_message));
                //Gateway::joinGroup($client_id, $room_id);
                //Gateway::sendToCurrentClient(json_encode($new_message));
               

                //绑定用户名跟clientid，以便通过用户名发送消息
                Gateway::bindUid($client_id,$clientId); 

                // 给当前用户发送用户列表 
                $new_message['client_list'] = $clients_list;
                //Gateway::sendToCurrentClient(json_encode($new_message));

                //获取当前用户的离线消息并发送
                $data = self::doWithLogin($clientId);
                if(!empty($data)){ 
                    //离线消息格式 
                    //message: {type:type, from_client_id:dwguanhongbiao, from_client_name:代维关, to_client_id:dwceshi,content:你好,time:linux时间戳}   
                    for($i=0;$i<count($data);$i++){ 
                        $msgType = $data[$i]['msgType'];  
                        $offLineMsg = array(
                            'type'=>$data[$i]['msgType'],
                            'from_id'=>$data[$i]['fromClientId'],  
                            'from_name'=>$data[$i]['fromClientName'], 
                            'to_id'=>$clientId,
                            'content'=>$data[$i]['content'],
                            'time'=>$data[$i]['timestamp'],
                        );     

                        if(isset($data[$i]['groupID']) && !empty($data[$i]['groupID'])){
                            $offLineMsg['groupID'] = $data[$i]['groupID'];
                        }        
 
                        Gateway::sendToCurrentClient(json_encode($offLineMsg));
                    } 
                }

                //判断是否有新的好友请求
                $friendData = self::getNewFriendRequest($clientId);
                if(!empty($friendData)){
                    for($i=0;$i<count($friendData);$i++){ 
                        $friendReqMsg = array(
                            'type'=>'newFriend',
                            'from_client_id'=>$friendData[$i]['originatorId'], 
                            'from_client_name' =>$friendData[$i]['name'],
                            'to_client_id'=>$clientId,
                            'content'=>'',
                            'time'=>$friendData[$i]['timestamp'],
                        ); 
                        Gateway::sendToCurrentClient(json_encode($friendReqMsg));
                    } 
                } 

                return;
                
            // 客户端发言 message: {type:say, to_client_id:xx, content:xx}
            case 'say': 
                return;
            case "alone": 
                //判断是否好友关系
                $isFriend = self::checkFriend($from_client_id,$to_clientId);
                if(!$isFriend){
                    $err_message = array(
                        'type'=>'alone',
                        'from_id'=>'@YXPORTAL_SYS#1001',  
                        'to_id'=>$from_client_id,
                        'content'=>'您需要添加对方为好友才能发送消息!',
                        'time'=>time(),
                    );
                    Gateway::sendToCurrentClient(json_encode($err_message));
                    return;
                }  

                //给发起用户发送响应
                //Gateway::sendToCurrentClient(json_encode($new_message))
                self::sendResMsg('alone',$from_client_id,"",$from_client_id,$content,time());
               
                self::sendMsg('alone',$from_client_id,"",$to_clientId, $content,$tStamp); 
 
                return ; 
            case "newFriendReq": 
                //新增好友请求

                //在数据库中增加好友申请记录
                self::addNewFrind($from_client_id,$to_clientId);

                $res_message = array(
                        'type'=>'newFriendRes',
                        'from_id'=>'@YXPORTAL_SYS#1001',  
                        'from_name'=>'系统1001',
                        'to_id'=>$from_client_id,
                        'content'=>'已发送邀请',
                        'time'=>time(),
                    );
                //给发起用户发送响应 
                self::sendResMsg('newFriendRes','@YXPORTAL_SYS#1001','系统1001',$from_client_id,'已发送邀请',time());
                //给目标用户发送请求
                self::sendMsg('newFriend',$from_client_id,$from_client_name,$to_clientId,"$from_client_name 申请加您为好友",$tStamp);
                return;
            case "confirmNFReq":
                //向发起好友请求的用户告知 确认好友请求  

                //给发起用户发送响应
                self::sendResMsg('confirmNFRes','@YXPORTAL_SYS#1001','系统1001',$from_client_id,'已发送请求',time());
                if($content =="1"){
                    //通过好友请求
                    //给目标用户发送请求
                    self::sendMsg('confirmNF',$from_client_id,$from_client_name,$to_clientId,$content,$tStamp);
                }
                else{
                    $content = "-1";
                } 
                //更新好友请求到好友表
                self::updateFriendRequest($to_clientId,$from_client_id,$content);

                return;  
            case "delFriendReq":
                //删除好友

                self::delFriend($from_client_id,$to_clientId);
                //给发起用户发送响应
                self::sendResMsg('delFriendRes','@YXPORTAL_SYS#1001','系统1001',$from_client_id,$to_clientId,time());
                //给目标用户发送请求
                self::sendMsg('delFriend',$from_client_id,$from_client_name,$to_clientId,$content,$tStamp);                 
                return; 

            /**
            20160824 zhanghaohuang 群聊相关功能
            */   
            case "creGroupReq":
                //创建群组  
                $groupId = 0;
                if(is_array($content)){
                    //保存建群信息
                    $groupType = $content['groupType'];
                    $groupName = $content['groupName'];
                    $groupId = self::saveGroupInfo($groupType,$groupName,$from_client_id,$groupId);
                    $msg =array(
                        'groupName'=>$groupName,
                        'groupID'=>$groupId
                    ); 
                }
                else{
                    return;
                }
                //给来源用户回复建群响应信息
                self::sendResMsg('creGroupRes','@YXPORTAL_SYS#1001','系统1001',$from_client_id,$groupName,time(),$groupId); 

                //服务器向邀请的人下发建群邀请
                self::sendMsg("inviteToGroup",$from_client_id,$from_client_name,$to_clientId,$groupName,$tStamp,true,$groupId);
                return;
            case "inviteToGroupReq":
                //已有群的情况下，邀请入群 
                $groupId = $to_clientId;
                if(is_array($content)){ 
                    $groupName = $content['groupName'];  
                    $invitedUsers = $content['invitedUsers'];

                    //获取群成员
                    $sql = "SELECT UserID from ".DB_PREFIX."chat_group_member where GroupID = '$groupId' and UserID != '$from_client_id'";
                        $members = self::getdata($sql);

                    foreach ($invitedUsers as $key => $value) {
                        //向被邀请用户发送邀请信息
                        $toId = $value['id'];
                        $toName = $value['name'];
                        $msg = "$from_client_name 邀请您加入群 $groupName";
                        //向被邀请用户发送邀请信息
                        self::sendMsg("inviteToGroup",$from_client_id,$from_client_name,$toId,$groupName,$tStamp,true,$groupId); 

                        //给来源用户回复建群响应信息
                        self::sendResMsg('inviteToGroupRes','@YXPORTAL_SYS#1001','系统1001',$from_client_id,$groupName,time(),$groupId);
                
                        //向群成员(不含发起邀请人)发送通知 xxx 邀请了 xx 入群                         
                        if(isset($members) && !empty($members)){
                            foreach ($members as $key => $value) {
                                $membr[] = $value['UserID'];
                            }
                            //服务器向群组中的所有人下发 
                            $msg2="$from_client_name 邀请了 $toName 进群";
                            self::sendMsg("inviteToGroupNotice",$from_client_id,$from_client_name,$membr,$msg2,$tStamp,true,$groupId);
                        } 
                    }//~foreach
                }//~if
                else{
                    return;
                }
                return;
            case "groupMsg":
                //群消息 
                $groupId = $to_clientId;
                $sql = "SELECT UserID from ".DB_PREFIX."chat_group_member where GroupID = '$groupId'";
                $members = self::getdata($sql); 
                if(isset($members) && !empty($members)){
                    foreach ($members as $key => $value) {
                        $membr[] = $value['UserID'];
                    }
                    //服务器向群组中的所有人下发 
                    self::sendMsg("groupMsg",$from_client_id,$from_client_name,$membr,$content,$tStamp,true,$groupId);
                } 
                return;  
            case "confirmGroupReq":
                //确认入群
                $groupId = $to_clientId;
                $opType = $content;
                if($opType == '1'){ 
 
                    //避免重复插入
                    $checkSql = " select * from ".DB_PREFIX."chat_group_member where GroupID = '$groupId' and UserID = '$from_client_id'";
                    $exist = self::getData($checkSql);
                    if(!$exist){
                        $insql = " insert into ".DB_PREFIX."chat_group_member (GroupID,UserID,InsertTime)".
                            "  values('$groupId','$from_client_id','".date('Y-m-d H:i:s')."')";
                        self::getData($insql);
                    }      

                    //给来源用户回复入群响应信息
                    self::sendResMsg('confirmGroupRes','@YXPORTAL_SYS#1001','系统1001',$from_client_id,'您已加入群',time(),$groupId);
                 
                    $sql = "SELECT UserID from ".DB_PREFIX."chat_group_member where GroupID = '$groupId'";
                    $members = self::getdata($sql);
                    if(isset($members) && !empty($members)){
                        foreach ($members as $key => $value) {
                            $membr[] = $value['UserID'];
                        }
                        //服务器向群组中的所有人下发 
                        self::sendMsg("confirmGroup",$from_client_id,$from_client_name,$membr,"$from_client_name 加入了群",$tStamp,true,$groupId);
                    }
              
                }
                else{
                     
                }
                return; 
            case "quitGroupReq":  
                //退出群
                $groupId = $to_clientId;

                $delSql = " delete from ".DB_PREFIX."chat_group_member where GroupID = '$groupId' and UserID = '$from_client_id'";
                self::getData($delSql);

                //给来源用户回复退群响应信息
                $msg2 = "您已退出群";
                self::sendMsg("quitGroupRes",'@YXPORTAL_SYS#1001','系统1001',$from_client_id,$msg2,time(),true,$groupId);
                

                $sql = "SELECT UserID from ".DB_PREFIX."chat_group_member where GroupID = '$groupId'";
                $members = self::getdata($sql);
                $msg = "$from_client_name 退出了群";

                if(isset($members) && !empty($members)){
                    foreach ($members as $key => $value) {
                        $membr[] = $value['UserID'];
                    }
                    //服务器向群组中的所有人下发                    
                    self::sendMsg("quitGroup",$from_client_id,$from_client_name,$membr,$msg,$tStamp,true,$groupId);
                } 
                return;
            case "chgGroupNameReq":
                //修改群名
                $groupId = $to_clientId; 

                $updateArray = array(
                    'GroupName' => $content  ,
                    'UpdateUser' =>  $from_client_id,
                    'UpdateTime' => date("Y-m-d H:i:s",time()),
                );
                //更新数据库 
                self::updateData(DB_PREFIX.'chat_group',$updateArray," GroupID = '$groupId'");
                
                //给来源用户回复修改群名响应信息
                $msg2 = "修改群名成功";
                self::sendMsg("chgGroupNameRes",'@YXPORTAL_SYS#1001','系统1001',$from_client_id,$msg2,time(),true,$groupId);
                
                $sql = "SELECT UserID from ".DB_PREFIX."chat_group_member where GroupID = '$groupId'";
                $members = self::getdata($sql);
                if(isset($members) && !empty($members)){
                    foreach ($members as $key => $value) {
                        $membr[] = $value['UserID'];
                    }
                    //服务器向群组中的所有人下发
                    $msg = "$content";
                    self::sendMsg("chgGroupName",$from_client_id,$from_client_name,$membr,$msg,$tStamp,true,$groupId);
                } 

                
                return;
            case "appNoticeRes":
                //处理 客户端反馈收到appNotice的情况

                //标记消息为已读(已发送)
                $updateArray = array(
                    'Tag' => 1  , 
                );
                //更新数据库 
                self::updateData(DB_PREFIX.'msgsrc_msg_info',$updateArray," ID = '$content' and UserName = '$from_client_id'");

                return;
        }
   }

   /**
    zhanghaohuang 20160824 消息发送方法
    @param string $type  消息的类型
    @param string $fromClientId  消息的来源用户ID
    @param string $fromClientName  消息的来源用户名
    @param string/array $toClientId  消息的去往用户ID(一个或多个)
    @param string $content  消息的内容
    @param int $time  消息的时间戳
    @param bool $offLineSave  离线保存消息
    @param string $groupId  群号(如有则需要在发送的消息中增加)
   */
   static function sendMsg($type,$fromClientId,$fromClientName="",$toClientId,$content,$time,$offLineSave=true,$groupId=null){
        if(is_array($toClientId)){
            //发给多个人
            foreach ($toClientId as $key => $toId) {   
                $msg = array(
                    'type'=>$type, 
                    'from_name' =>$fromClientName,
                    'from_id'=>$fromClientId,
                    'to_id'=>$toId,
                    'content'=>$content,
                    'time'=>$time,
                );   
                if($groupId){
                    $msg['groupID'] = $groupId;
                }   

                $isOnline = Gateway::isUidOnline($toId); 
                //在线的情况，直接发消息
                if(isset($isOnline) && !empty($isOnline) && $isOnline == 1){ 
                   Gateway::sendToUid($toId, json_encode($msg)); 
                }
                else if($offLineSave){
                    //保存离线消息
                    if($groupId){ 
                        self::saveOffLineMsg($fromClientId,$fromClientName,$toId,$content,'offLineGroupMsg',$time,$groupId);
                    }
                    else{ 
                        //不在线的情况，保存记录到数据库
                        self::saveOffLineMsg($fromClientId,$fromClientName,$toId,$content,$type,$time);
                    }  
                } 

                if(IsDebug) {
                    print_r($msg); 
                }             
            }//~foreach
        }
        else{  
            //发给单个人
            $msg = array(
                'type'=>$type, 
                'from_name' =>$fromClientName,
                'from_id'=>$fromClientId,
                'to_id'=>$toClientId,
                'content'=>$content,
                'time'=>$time,
            ); 
            if($groupId){
                $msg['groupID'] = $groupId; 
            }
  
            $isOnline = Gateway::isUidOnline($toClientId);
            //在线的情况，直接发消息
            if(isset($isOnline) && !empty($isOnline) && $isOnline == 1){
               Gateway::sendToUid($toClientId, json_encode($msg)); 
            }
            else if($offLineSave){
                //保存离线消息
                if($groupId){
                    self::saveOffLineMsg($fromClientId,$fromClientName,$toClientId,$content,'offLineGroupMsg',$time,$groupId);
                }
                else{ 
                    self::saveOffLineMsg($fromClientId,$fromClientName,$toClientId,$content,$type,$time);
                }                
            } 

            if(IsDebug) {
                print_r($msg); 
            }               
        } 
   }

/**
    发送响应信息
*/
   static function sendResMsg($type,$fromClientId,$fromClientName="",$toClientId,$content,$time,$groupId=null){
        $msg = array(
            'type'=>$type, 
            'from_name' =>$fromClientName,
            'from_id'=>$fromClientId,
            'to_id'=>$toClientId,
            'content'=>$content,
            'time'=>$time,
        );   
        if($groupId){
            $msg['groupID'] = $groupId;
        }

            if(IsDebug) {
                echo " debug:\r\n";
                print_r($msg); 
            }       
        Gateway::sendToUid($toClientId, json_encode($msg)); 
   }
   
   /**
    * 当客户端断开连接时
    * @param integer $client_id 客户端id
    */
   public static function onClose($client_id)
   {
       $clientID = $_SESSION['clientID']; 
       // debug
       echo "client:{$_SERVER['REMOTE_ADDR']}:{$_SERVER['REMOTE_PORT']} gateway:{$_SERVER['GATEWAY_ADDR']}:{$_SERVER['GATEWAY_PORT']}  client_id:$client_id __ $clientID onClose:''\n";
       
       // 从房间的客户端列表中删除
       if(isset($_SESSION['room_id']))
       {
           $room_id = $_SESSION['room_id'];
           $new_message = array('type'=>'logout', 'from_client_id'=>$clientID,  'time'=>date('Y-m-d H:i:s'));
           Gateway::sendToGroup($room_id, json_encode($new_message));
       }
   }

   /**
    login的时候需要做的事情
    1.当前登录用户是否有离线消息
    @param client_name 客户端名称
   */
   static function doWithLogin($client_Id){
        $db2 = self::getdb();
         
        $sql = " SELECT Id, FromClientId,FromClientName,ToClientId,Content,MsgType,`TimeStamp` as Tstamp from ".DB_PREFIX."contact_msg_offline ".
            " where ToClientId = '$client_Id' and MsgStatus = 0";
        $result = $db2->getall($sql);

        if(!empty($result)){  
            //查找每一个id的信息
            $updIDs = "";
            foreach ($result as $key => $value) {    
                $uid = $value['FromClientId'] ;
                $uName = $value['FromClientName'] ;
                $msgType = $value['MsgType'] ;
                $tsp = $value['Tstamp'] ;
                $content = $value['Content'] ;  
                $data = array(
                    'fromClientId'=>$uid,
                    'fromClientName' => $uName,
                    'msgType'=>$msgType,
                    'content'=> $content, 
                    'timestamp' => $tsp
                    );
                $re[] = $data;

                $updIDs .= $value['Id'].',';
            }  

            if(isset($updIDs) && !empty($updIDs)){
                $updIDs = substr($updIDs, 0,strlen($updIDs)-1);//去掉最后的逗号
                //更新消息状态为已发送
                $updateArray= array(
                    'MsgStatus' => 1, 
                    'UpdateTime' => date('Y-m-d H:i:s'),
                );
                $db2->update(DB_PREFIX."contact_msg_offline",$updateArray," Id in ($updIDs)");
            }
        }
        else{
            $re = '';
        }

        //获取离线群消息
        $sql2 = " SELECT Id,FromClientId,FromClientName,ToClientId,GroupID,Content,MsgType,`TimeStamp` as Tstamp from ".DB_PREFIX."chat_group_msg_offline ".
            " where ToClientId = '$client_Id' and MsgStatus = 0";
        $result2 = $db2->getall($sql2);

        if(!empty($result2)){  
            //查找每一个id的信息
            $updIDs = "";
            foreach ($result2 as $key => $value) {    
                $uid = $value['FromClientId'] ;
                $uName = $value['FromClientName'] ;
                $msgType = $value['MsgType'] ;
                $tsp = $value['Tstamp'] ;
                $content = $value['Content'] ;  
                $groupId = $value['GroupID'] ; 
                $data = array(
                    'fromClientId'=>$uid,
                    'fromClientName' => $uName,
                    'msgType'=>$msgType,
                    'content'=>$content,
                    'groupID'=>$groupId,
                    'timestamp' => $tsp
                    );
                $re[] = $data;
                $updIDs .= $value['Id'].',';
            }  

            if(isset($updIDs) && !empty($updIDs)){
                $updIDs = substr($updIDs, 0,strlen($updIDs)-1);//去掉最后的逗号
                //更新消息状态为已发送
                $updateArray= array(
                    'MsgStatus' => 1, 
                    'UpdateTime' => date('Y-m-d H:i:s'),
                );
                $db2->update(DB_PREFIX."chat_group_msg_offline",$updateArray," Id in ($updIDs)");
            }
        }
        else{
            if(empty($re)){
                $re = '';
            } 
        }

        $sql3 = " SELECT ID,MessageType,Tag,Title,Context,URL,UserName,CreateTime,UNIX_TIMESTAMP(CreateTime) as tsTamp ".
            " FROM cms_msgsrc_msg_info where Tag = 0 and UserName = '$client_Id'";
        $result3 = $db2->getall($sql3); 

        if(!empty($result3)){ 
            //查找每一个id的信息
            $updIDs = "";
            foreach ($result3 as $key => $value) {  
                $id = $value['ID'] ;  
                $ty = $value['MessageType'] ;
                $tag = $value['Tag'] ;
                $title = $value['Title'] ;
                $context = $value['Context'] ;
                $url = $value['URL'] ;  
                $creTime = $value['CreateTime'] ; 
                $tsTamp =  $value['tsTamp'];

                switch ($ty) {
                    case 'todo':
                        $uid = '@YXPORTAL_SYS#1002';
                        $uName = '应用代办信息';
                        break;
                    case 'notice':
                        $uid = '@YXPORTAL_SYS#1003';
                        $uName = '应用通知信息';
                        break;
                    case 'news':
                        $uid = '@YXPORTAL_SYS#1004';
                        $uName = '应用新闻信息';
                        break;                    
                    default:
                        continue;
                }   

                //$content = "{\"id\": \"$id\",\"title\": \"$title\",\"context\": \"$context\",\"url\": \"$url\",\"createtime\": \"$creTime\",\"tag\": \"0\"";
                //$content = "{'id':'$id','title':'$title','context':'$context','url':'$url','createtime':'$creTime','tag':0 ";
                $content = '{"id":"'.$id.'","title":"'.$title.'","context":"'.$context.'","url":"'.$url.'","createtime":"'.$creTime.'","tag":"0"}';
                $data = array(
                    'fromClientId'=>$uid,
                    'fromClientName' => $uName,
                    'msgType'=>'appNotice',
                    'content'=> $content, 
                    'timestamp' => $tsTamp
                    );
                $re[] = $data;

                $updIDs .= $id.',';
            }//~for
            if(isset($updIDs) && !empty($updIDs)){
                $updIDs = substr($updIDs, 0,strlen($updIDs)-1);//去掉最后的逗号
                //更新消息状态为已发送
                $updateArray = array(
                    'Tag' => 1  , 
                );
                //更新数据库 
                self::updateData(DB_PREFIX.'msgsrc_msg_info',$updateArray," ID in ($updIDs)");
            }
        }//~if

        return $re;
   }


/**
    添加新增好友请求记录
    @param $from_client_id
    @param $to_client_Id
*/
    static function addNewFrind($from_client_id,$to_client_Id){
        $db2 = self::getdb();
        //判断是否已有记录
        $sql = "select * from ".DB_PREFIX."contacts_friends where ".
                " ((acceptorId='$from_client_id' and originatorId='$to_client_Id') ".
                " or (acceptorId='$to_client_Id' and originatorId='$from_client_id') )".
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
            //已有记录，需区分已通过，还未通过
            // if($result['OperationStatus'] == 1){
            //     $msg = array('stateCode'=>'001','msg'=>'对方已经是您的好友');
            // }
            // else {
            //     //有记录，未通过的情况
            //     //要区分是自己发起的请求，还是对方发起的请求
            //     if($result['OriginatorId'] == $userId){
            //         //自己发起的请求
            //         $msg = array('stateCode'=>'001','msg'=>'您已申请对方为好友,请耐心等候对方通过');
            //     }
            //     else{
            //         //对方发起的请求
            //         $msg = array('stateCode'=>'001','msg'=>'对方已申请加您为好友，请先通过');
            //     }
            // }
        } 
    }

/**
    删除好友
    @param $from_client_id
    @param $to_client_Id
*/
    static function delFriend($from_client_id,$to_client_Id){
        $db2 = self::getdb();
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
        $db2 = self::getdb();


        //获取他人申请添加好友的请求记录
        //未有操作 未下发给客户端
        $sql = " select OriginatorId,InsertTime,`TimeStamp` as timestamp from ".
            DB_PREFIX."contacts_friends where AcceptorId = '$client_Id' and OperationStatus = 0 and MsgStatus = 0";
        $result = $db2->getall($sql); 
        if(!empty($result)){ 

            //$db是全局的变量，非静态，需要再次赋值，以传递给验证类进行数据库访问
            global $db;
            if(empty($db)){ 
            $db= self::getdb();
            }
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
        $db2 = self::getdb();

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
        $db2 = self::getdb();

        $updatearray = array(
            'OperationStatus' => $status, 
            'MsgStatus' => 1, 
            'UpdateTime'=>date("Y-m-d H:i:s",time()), 
        );
        $db2->update(DB_PREFIX."contacts_friends",$updatearray," AcceptorId = '$to_client_id' and OriginatorId = '$from_client_id'");    
    }

  /**
  检测双方是否为好友关系
  */
  static function checkFriend($from_client_Id,$to_client_Id){
        $db2 = self::getdb();
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
    保存离线消息
  */
  static function saveOffLineMsg($from_client_Id,$from_client_Name,$to_client_Id,$content,$msgType,$timestamp,$groupId=null){
        $db2 = self::getdb(); 
        $array  = array( 
            'FromClientName'=>$from_client_Name,
            'FromClientId'=>$from_client_Id,
            'ToClientId' => $to_client_Id,
            'Content' => $content, 
            'MsgStatus' => 0,
            'MsgType'=>$msgType,
            'TimeStamp'=>$timestamp,
            'UpdateTime'=>date("Y-m-d H:i:s",time()), 
        ); 

        if($groupId){
            $array['GroupID'] = $groupId;  
            $result = $db2->insert(DB_PREFIX."chat_group_msg_offline",$array);  
            if(!$result){ 
                echo (" saveOffLineMsg Error:from_client_Id->$from_client_Id,to_client_Id->$to_client_Id,".
                    " content->$content,msgType->$msgType,timestamp->$timestamp,groupId->$groupId");
            }        
        }
        else{
            $result = $db2->insert(DB_PREFIX."contact_msg_offline",$array);
            if(!$result){ 
                echo (" saveOffLineMsg Error:from_client_Id->$from_client_Id,to_client_Id->$to_client_Id,".
                    " content->$content,msgType->$msgType,timestamp->$timestamp");
            }
        }        
   }

/**
    保存群组成员信息
    @param string $groupType  群类型 
    @param string $groupName  群名 
    @param string $from_client_Id   操作人id 
    @param string $groupId  群号，默认为0表示创建操作
*/
   static function saveGroupInfo($groupType,$groupName,$from_client_Id,$groupId=0){
        $db2 = self::getdb(); 

        if($groupId ==0){
            //新建群
            while(strlen($randNm)<3){
                $randNm .= rand(0,9);
            } 
            $groupId = $groupType=="manyPeople"?"MP":"G"; 
            $groupId = $groupId.date("YmdHis",time()).$randNm; 
            $array  = array( 
                'GroupID'=>$groupId,
                'GroupType'=>$groupType,
                'GroupName' => $groupName,
                'CreateUser' => $from_client_Id,
                'UpdateUser' => $from_client_Id, 
                'UpdateTime'=>date("Y-m-d H:i:s",time()), 
            ); 

            $result = $db2->insert(DB_PREFIX."chat_group",$array);

            //把创建人加入群成员
            $array  = array( 
                'GroupID'=>$groupId,
                'UserID'=>$from_client_Id, 
            ); 
            $result = $db2->insert(DB_PREFIX."chat_group_member",$array);

        }
        else{
            //修改群名
            $array  = array(    
                'GroupName' => $groupName,
                'UpdateUser' => $from_client_Id, 
                'UpdateTime'=>date("Y-m-d H:i:s",time()), 
            ); 

            $result = $db2->update(DB_PREFIX."chat_group",$array," GroupID='$groupId'");
        }
        
        
        if(!$result){ 
            echo (" saveGroupInfo Error:from_client_Id->$from_client_Id,groupId->$groupId,".
                " GroupName->$groupName");
        }
        return $groupId;
   }

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
  
}
