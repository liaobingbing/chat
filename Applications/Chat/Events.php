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
    define('CHENCY_ROOT', substr(dirname(__FILE__), 0, -55));//加载到根目录下的所有路径
}
require_once CHENCY_ROOT.'./source/core/class.encrypt.php';//引入加密文件
require_once CHENCY_ROOT.'./source/conf/db.inc.php';
require_once CHENCY_ROOT.'./source/conf/config.inc.php';
require_once CHENCY_ROOT.'./source/core/class.mysql.php';
require_once CHENCY_ROOT.'./admin/UserAuthentication.php';
require dirname(__FILE__).'/EventController/event_data_controller.php';
require dirname(__FILE__).'/EventController/event_department.php';
require dirname(__FILE__).'/EventController/event_friend.php';
require dirname(__FILE__).'/EventController/event_group.php';
require dirname(__FILE__).'/EventController/event_user_info.php';
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
        $encrypt=new GEncrypt();//实例化

        //解密客户端传送过来的数据
        $message = $encrypt->decode($message,$key=ENCRYPT_KEY.'XY',$cipher = 'rijndael-128');//对传送过来的数据进行加密
        // debug
        echo "client:{$_SERVER['REMOTE_ADDR']}:{$_SERVER['REMOTE_PORT']}".
            " gateway:{$_SERVER['GATEWAY_ADDR']}:{$_SERVER['GATEWAY_PORT']} time:".date('Y-m-d H:i:s',time()).'('.time().')'.
            " client_id:$client_id ". // session:".json_encode($_SESSION)." 
            " onMessage:".$message."\n";

        // if(IsDebug){
        //     echo $message;
        // }
        // 客户端传递的是json数据
        $message_data = json_decode($message, true);
        if(!$message_data)
        {
            $errStr = "message is not json";
            echo $errStr ;
            Gateway::sendToCurrentClient($errStr);
            return ;
        }

        if(empty($_SESSION) || !isset($_SESSION)){
            if($message_data['type'] !='login'){
                Gateway::closeClient($client_id); //与客户端的连接断开$client_id是唯一标志的客户端id
                $errStr = "no login and session null,closed...";
                echo $errStr.$client_id.PHP_EOL;
                Gateway::sendToCurrentClient($errStr);
                return;
            }
        }

        $to_clientId = $message_data['to_id']?$message_data['to_id']:'';
        $from_client_id = $message_data['from_id']?$message_data['from_id']:'';
        $from_client_name = $message_data['from_name']?$message_data['from_name']:'';
        $tStamp = $message_data['time']?$message_data['time']:'';//客户端从送过来的时间戳
        $content = $message_data['content']?$message_data['content']:array('content_type'=>'text','content'=>'','data'=>'');

        if(!empty($content)){
            $content_tmp = (array)$content;
            if(isset($content_tmp['content_type'])){
                $content_type = $content_tmp['content_type'];
            }

            if(isset($content_tmp['content'])){
                $content_info = $content_tmp['content'];
            }

            if(isset($content_tmp['data'])){
                $data = $content_tmp['data'];
            }
            if(isset($content_tmp['resend'])){
                $resend= $content_tmp['resend'];
            }
        }

        $token = $message_data['token'];
		$_SESSION['token']=$token;
        $clientId = $content['name_id']; 
        

        //根据消息类型判断是否需要验证token
            $res=Event_User_Info::checkLogin($token,$clientId); 
            if($res!="true"){
                 $uinfo_msg = array(
                            'type' => 'login',
                            'from_id' => '@YXPORTAL_SYS#1001',
                            'from_name' => '系统1001',
                            'to_id' => $clientId,
                            'content' => array('state' =>"004",'content' =>"token过期",'data' =>null),
                            'time' => time()
                        );
                    $str=$encrypt->encode(json_encode($uinfo_msg),$key=ENCRYPT_KEY.'XY',$cipher = 'rijndael-128');
                    Gateway::sendToCurrentClient($str);/////////加密 Gateway::closeClient($client_id,$clientId );
                  exit;
            }
			/*else{
				$res=Event_User_Info::IsBind($token,$clientId);
				if($res==0||$res==""){
				Gateway::bindUid($client_id,$ $to_clientId);
				Event_User_Info::UpBind($token,$clientId);
				}
				
			}*/
        

        // 根据类型执行不同的业务
        switch($message_data['type'])
        {
            // 客户端回应服务端的心跳
            case 'pong':
                $re_mdm = Event_User_Info::getMsgWithPong();

                if(!empty($re_mdm)){
                    $mdm_msg = array(
                        'type' => 'mdm_order',
                        'from_id' => '@YXPORTAL_SYS#1001',
                        'from_name' => '系统1001',
                        'to_id' => $_SESSION['client_name'],
                        'content' => array('state' => '0000','data' =>$re_mdm),
                        'time' => time()
                    );
                    Gateway::sendToCurrentClient($encrypt->encode(json_encode($mdm_msg),$key=ENCRYPT_KEY.'XY',$cipher = 'rijndael-128'));//对数据加密
                }

                return;
			case "authenticate":				
				Gateway::bindUid($client_id,$from_client_id);												
			
			break;
			
            // 客户端登录 message格式: {type:login, name:xx, room_id:1} ，添加到客户端，广播给所有客户端xx进入聊天室
            case 'login':             
                $room_id = 1;
                $_SESSION['room_id'] = $room_id;

               
                //echo ' u->'.$content['name_id'].' p->'.$content['password'].PHP_EOL;
                if(isset($content['name_id']) && !empty($content['name_id'])
                    && isset($content['password']) && !empty($content['password'])){
                    // $_SESSION['name'] = $message_data['name'];
                    $clientId = $content['name_id'];
                    $pwd = $content['password'];
                    $_SESSION['client_name'] = $clientId;

                    //绑定用户名跟client_id，以便通过用户名发送消息
                    Gateway::bindUid($client_id,$clientId);
                    // 把房间号昵称放到session中
                    // $room_id = $message_data['room_id'];

                    //保存/更新设备信息
                    if(isset($content['device']) && !empty($content['device'])){
                        $device = $content['device'];
                        Event_User_Info::saveDevice($clientId,$device);
                    }

                    global $db;
                    if(empty($db)){
                        $db= Event_data::getdb();
                    } 
                    $result = Authentication::UserADAuthentication($clientId,$pwd);

                    if($result['stateCode'] == '000'){
                        $tk = $result['data'];
                    }
                    else{
                        $tk = null;
                        $stateCode = '0'.$result['stateCode'];
                        $err=$result['msg']." 账号密码错误，client_id-".$clientId." pwd-".$pwd;
                    }
                }
                else{
                    $stateCode = '0004';
                    $tk = null;
                    $err="需要验证信息";
                }

                //获取mdm信息
                $mdm_msg = Event_User_Info::getMDMInfoWithLogin($clientId,$device);
                // $mdm_msg = array(
                //     'type' => 'mdm_order',
                //     'from_id' => '@YXPORTAL_SYS#1001',
                //     'from_name' => '系统1001',
                //     'to_id' => $clientId,
                //     'content' => array('state' => '0000','data' =>$mdm_info),
                //     'time' => time()
                //     );

                if($tk){
                    $uinfo = Event_User_Info::getUserInfo($clientId);
                    //yxhome需要name_id字段
                    $uinfo['name_id'] = $clientId;
                    unset($uinfo['id']);

                    require_once CHENCY_ROOT."./source/core/GetPin.class.php";//取汉字拼音
                    $gp = new GetPin();
                    $pinyin = $gp->Pinyin(strtolower(str_replace('-','',$uinfo['name'])));
                    $uinfo['spell'] = $pinyin;
                    $uinfo['token'] = $tk;

                    $uinfo_msg = array(
                        'type' => 'login',
                        'from_id' => '@YXPORTAL_SYS#1001',
                        'from_name' => '系统1001',
                        'to_id' => $clientId,
                        'content' => array('state' => '0000','content' => '登陆成功','data' => array('user_info'=>$uinfo,'mdm_order'=>$mdm_msg)),
                        'time' => time()
                    );
                }
                else{
                    $uinfo_msg = array(
                        'type' => 'login',
                        'from_id' => '@YXPORTAL_SYS#1001',
                        'from_name' => '系统1001',
                        'to_id' => $clientId,
                        'content' => array('state' => $stateCode,'content' => $err,'data' =>null),
                        'time' => time()
                    );
                }
                $str=$encrypt->encode(json_encode($uinfo_msg),$key=ENCRYPT_KEY.'XY',$cipher = 'rijndael-128');
                Gateway::sendToCurrentClient($str);/////////加密

//                Gateway::sendToCurrentClient($encrypt->encode(json_encode($uinfo_msg),$key=ENCRYPT_KEY.'XY',$cipher = 'rijndael-128'));/////////加密

                //未生成token信息-可能是账号密码错误导致登录失败，断开本次连接
                if(empty($tk) || !isset($tk)){
                    Gateway::closeClient($client_id);
                    echo ' login false and close '.$client_id.PHP_EOL;
                    echo ' auth result=> '.PHP_EOL;
                    var_dump($uinfo_msg);
                    echo PHP_EOL;
                    return;
                }
                //踢出其余有效的登陆，保持最新的登陆
                $clients = Event_User_Info::logoutOtherClient($tk,$client_id,$clientId);

                foreach ($clients as $key => $value) {
                    $logout_msg = array(
                        'type' => 'cancelled',
                        'from_id' => '@YXPORTAL_SYS#1001',
                        'from_name' => '系统1001',
                        'to_id' => $from_client_id,
                        'content' => array('content_type' => 'text','content' =>'当前账号在其它客户端登陆'),
                        'time' => time()
                    );
                    // Gateway::sendToCurrentClient(json_encode($mdm_msg));

                    if(!empty($value) && $value != $client_id){
                        //若跟当前$client_id相同，则不退出
                        Gateway::sendToClient($value, $encrypt->encode(json_encode($logout_msg),$key=ENCRYPT_KEY.'XY',$cipher = 'rijndael-128'));

                        Gateway::closeClient($value);
                        echo ' closeClient '.$value.PHP_EOL;
                    }
                }
                return;

            case 'get_offline_msg':
                $clientId = $from_client_id;
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
                            'uuid'=>$data[$i]['uuid']
                        );

                        if(isset($data[$i]['groupID']) && !empty($data[$i]['groupID'])){
                            $offLineMsg['to_id'] = $data[$i]['groupID'];
                            $offLineMsg['content'] = (array)$data[$i]['content'];
                        }

                        Gateway::sendToCurrentClient($encrypt->encode(json_encode($offLineMsg),$key=ENCRYPT_KEY.'XY',$cipher = 'rijndael-128'));///////加密
                    }
                }

                //判断是否有新的好友请求
                $friendData = Event_Friend::getNewFriendRequest($clientId);

                //为避免重复发送，以下代码注释，新增好友请求获取离线消息的时候会自动下发。
                // if(!empty($friendData)){
                //     for($i=0;$i<count($friendData);$i++){
                //         $friendReqMsg = array(
                //             'type'=>'newFriend',
                //             'from_id'=>$friendData[$i]['originatorId'],
                //             'from_name' =>$friendData[$i]['name'],
                //             'to_id'=>$clientId,
                //             'content'=>array('content_type' => 'text','content' => '','data'=>''),
                //             'time'=>$friendData[$i]['timestamp'],
                //         );
                //         Gateway::sendToCurrentClient(json_encode($friendReqMsg));
                //     }
                // }
                return;
            // 客户端发言 message: {type:say, to_client_id:xx, content:xx}
            case 'say':
                return;
            case "alone":
                //判断是否好友关系
                $isFriend = Event_Friend::checkFriend($from_client_id,$to_clientId);
                if(!$isFriend){
                      $err_message = array(
                        'type'=>'noFriend',
                        'from_id'=>'@YXPORTAL_SYS#1001',
                        'to_id'=>$from_client_id,
                        'content'=>array('content' =>$to_clientId),
                        'time'=>time(),
                        'uuid'=>$message_data['uuid']
                    );
                    Gateway::sendToCurrentClient($encrypt->encode(json_encode($err_message),$key=ENCRYPT_KEY.'XY',$cipher = 'rijndael-128'));//////加密
                    return;
                }

                //给发起用户发送响应
                //Gateway::sendToCurrentClient(json_encode($new_message))
                if($content_type == 'text'){
                    //20161207 聊天时不需要再发送聊天内容给来源客户端,只发送响应信息
                    //20170327 恢复发送
                    if ($resend == 1) {
                        if (in_array($message_data['uuid'], $_SESSION['message_list'])) {
                            self::sendResMsg('alone', $from_client_id, $from_client_name, $from_client_id, $content, $tStamp,$message_data['uuid'],null, 'text', $to_clientId);
                        } else {
                            self::sendResMsg('alone', $from_client_id, $from_client_name, $from_client_id, $content, $tStamp,$message_data['uuid'], null, 'text', $to_clientId);

                            self::sendMsg('alone', $from_client_id, $from_client_name, $to_clientId, $content, $tStamp,$message_data['uuid']);
                            Event_data::save_uuid($message_data['uuid']);/////消息重发机制
                        }
                    } else {
                        self::sendResMsg('alone', $from_client_id, $from_client_name, $from_client_id, $content, $tStamp,$message_data['uuid'],null, 'text', $to_clientId);

                        self::sendMsg('alone', $from_client_id, $from_client_name, $to_clientId, $content, $tStamp,$message_data['uuid']);
                        Event_data::save_uuid($message_data['uuid']);/////消息重发机制
                    }
                }
                else if($content_type == 'pic'){
                    //对图片进行操作
                    if ($resend == 1) {
                        if (in_array($message_data['uuid'], $_SESSION['message_list'])) {
                            self::sendResMsg('alone', $from_client_id, $from_client_name, $from_client_id, $content, $tStamp,$message_data['uuid'], null, 'text', $to_clientId);
                        } else {
                            self::sendResMsg('alone', $from_client_id, $from_client_name, $from_client_id, $content, $tStamp,$message_data['uuid'], null, 'text', $to_clientId);

                            self::sendMsg('alone', $from_client_id, $from_client_name, $to_clientId, $content, $tStamp,$message_data['uuid']);
                            Event_data::save_uuid($message_data['uuid']);/////消息重发机制
                        }
                    } else {
                        self::sendResMsg('alone', $from_client_id, $from_client_name, $from_client_id, $content, $tStamp,$message_data['uuid'], null, 'text', $to_clientId);

                        self::sendMsg('alone', $from_client_id, $from_client_name, $to_clientId, $content, $tStamp,$message_data['uuid']);
                        Event_data::save_uuid($message_data['uuid']);/////消息重发机制
                    }
                }
                else if($content_type == 'voice'){
                    //对语音进行操作
                    if ($resend == 1) {
                        if (in_array($message_data['uuid'], $_SESSION['message_list'])) {
                            self::sendResMsg('alone', $from_client_id, $from_client_name, $from_client_id, $content, $tStamp,$message_data['uuid'], null, 'text', $to_clientId);
                        } else {
                            self::sendResMsg('alone', $from_client_id, $from_client_name, $from_client_id, $content, $tStamp,$message_data['uuid'], null, 'text', $to_clientId);

                            self::sendMsg('alone', $from_client_id, $from_client_name, $to_clientId, $content, $tStamp,$message_data['uuid']);
                            Event_data::save_uuid($message_data['uuid']);/////消息重发机制
                        }
                    } else {
                        self::sendResMsg('alone', $from_client_id, $from_client_name, $from_client_id, $content, $tStamp,$message_data['uuid'], null, 'text', $to_clientId);

                        self::sendMsg('alone', $from_client_id, $from_client_name, $to_clientId, $content, $tStamp,$message_data['uuid']);
                        Event_data::save_uuid($message_data['uuid']);/////消息重发机制
                    }
                }
                else if($content_type == 'video'){
                    //对录像进行操作
                    if ($resend == 1) {
                        if (in_array($message_data['uuid'], $_SESSION['message_list'])) {
                            self::sendResMsg('alone', $from_client_id, $from_client_name, $from_client_id, $content, $tStamp,$message_data['uuid'], null, 'text', $to_clientId);
                        } else {
                            self::sendResMsg('alone', $from_client_id, $from_client_name, $from_client_id, $content, $tStamp,$message_data['uuid'], null, 'text', $to_clientId);

                            self::sendMsg('alone', $from_client_id, $from_client_name, $to_clientId, $content, $tStamp,$message_data['uuid']);
                            Event_data::save_uuid($message_data['uuid']);/////消息重发机制
                        }
                    } else {
                        self::sendResMsg('alone', $from_client_id, $from_client_name, $from_client_id, $content, $tStamp,$message_data['uuid'], null, 'text', $to_clientId);

                        self::sendMsg('alone', $from_client_id, $from_client_name, $to_clientId, $content, $tStamp,$message_data['uuid']);
                        Event_data::save_uuid($message_data['uuid']);/////消息重发机制
                    }
                }

                return ;
            case "getFriendList":
                $list = Event_Friend::getFriendList($from_client_id);
                $content = array(
                    'state' => '0000',
                    'content_type' => 'text',
                    'content' => '',
                    'data'=> $list
                );
                self::sendResMsg('friendList','@YXPORTAL_SYS#1001',"系统1001",$from_client_id, $content,time());

                return;
            case "getGroupList":
                $group_list = Event_Group::getGroupList($from_client_id);
                $content = array(
                    'state' => '0000',
                    'content_type' => 'text',
                    'content' =>'',
                    'data'=> $group_list
                );
                self::sendResMsg('groupList','@YXPORTAL_SYS#1001',"系统1001",$from_client_id, $content,time());

                return;
            case "getGroupInfo":
                $groupId = $data;
                $group_info = Event_Group::getGroupInfo($groupId);
                if(!empty($group_info) && !empty($group_info['groupName'])
                    && !empty($group_info['groupUsersInfo'])){
                    $content = array(
                        'state' => '0000',
                        'content_type' => 'text',
                        'content' => '',
                        'data'=> $group_info
                    );
                }
                else{
                    $content = array(
                        'state' => '0003',
                        'content_type' => 'text',
                        'content' => '',
                        'data'=> $group_info
                    );
                }
                self::sendResMsg('getGroupInfo','@YXPORTAL_SYS#1001',"系统1001",$from_client_id, $content,time());

                return;
            case "getUserInfo":
                $userId = $data;
                $user_info = Event_User_Info::getUserInfo($userId);

                if($user_info && !empty($user_info['id'] )){
                    $user_info['name_id'] = $userId;
                    unset($user_info['id']);

                    require_once CHENCY_ROOT."./source/core/GetPin.class.php";//取汉字拼音
                    $gp = new GetPin();
                    $pinyin = $gp->Pinyin(strtolower(str_replace('-','',$user_info['name'])));
                    $user_info['spell'] = $pinyin;

                    $content = array(
                        'state' => '0000',
                        'content_type' => 'text',
                        'content' => '',
                        'data'=> $user_info
                    );
                }
                else{
                    $content = array(
                        'state' => '0003',
                        'content_type' => 'text',
                        'content' => '',
                        'data'=> null
                    );
                }
                self::sendResMsg('getUserInfo','@YXPORTAL_SYS#1001',"系统1001",$from_client_id, $content,time());
                return;
            case "getChildDepartmentAndUserInfo":
                $dept_id = $data;
                $group_list = Event_Department::getChildDepartmentAndUserInfo($dept_id);
                $content = array(
                    'state' => '0000',
                    'content_type' => 'text',
                    'content' => '',
                    'data'=> array('depart'=>$dept_id,'info'=>$group_list)
                );
                self::sendResMsg('getChildDepartmentAndUserInfo','@YXPORTAL_SYS#1001',"系统1001",$from_client_id, $content,time());
                return;
            case "newFriendReq":
                //新增好友请求

                //在数据库中增加好友申请记录
                Event_Friend::addNewFrind($from_client_id,$to_clientId);

                $content_msg1 = array('content_type'=>'text','content'=>'已发送邀请','data' =>'');
                //给发起用户发送响应
                self::sendResMsg('newFriendRes','@YXPORTAL_SYS#1001','系统1001',$from_client_id,$content_msg1,time());

                $uinfo = Event_User_Info::getUserInfo($from_client_id);
                $uinfo['name_id'] = $from_client_id;
                unset($uinfo['id']);
                $icon = $uinfo['icon'];
                //给目标用户发送请求
                $content_msg2 = array('content_type'=>'text','content'=>" $from_client_name 申请加您为好友",'data' => $uinfo);
                self::sendMsg('newFriend',$from_client_id,$from_client_name,$to_clientId,$content_msg2,$tStamp);
                return;
            case "confirmNFReq":
                //向发起好友请求的用户告知 确认好友请求

                //给发起用户发送响应
                if($content_info =="1"){
                    //通过好友请求
                    //给目标用户发送请求
                    $status = 1;

                    $uinfo1 = Event_User_Info::getUserInfo($to_clientId);
                    $uinfo1['name_id'] = $to_clientId;
                    unset($uinfo1['id']);
                    require_once CHENCY_ROOT."./source/core/GetPin.class.php";//取汉字拼音
                    $gp = new GetPin();
                    $pinyin = $gp->Pinyin(strtolower(str_replace('-','',$uinfo1['name'])));
                    $uinfo1['spell'] = $pinyin;

                    $content_msg1 = array('content_type'=>'text','content'=>'1','data'=>$uinfo1);
                    self::sendResMsg('confirmNFRes',$to_clientId,'',$from_client_id,$content_msg1,time());

                    $uinfo2 = Event_User_Info::getUserInfo($from_client_id);
                    $uinfo2['name_id'] = $from_client_id;
                    unset($uinfo2['id']);
                    $gp = new GetPin();
                    $pinyin = $gp->Pinyin(strtolower(str_replace('-','',$uinfo2['name'])));
                    $uinfo2['spell'] = $pinyin;

                    $content_msg2 = array('content_type'=>'text','content'=>'1','data'=>$uinfo2);
                    self::sendMsg('confirmNF',$from_client_id,$from_client_name,$to_clientId,$content_msg2,$tStamp);
                }
                else{
                    $content_msg1 = array('content_type'=>'text','content'=>'0','data' =>'');
                    self::sendResMsg('confirmNFRes',$to_clientId,'',$from_client_id,$content_msg1,time());

                    $status = -1;
                }
                //更新好友请求到好友表
                Event_Friend::updateFriendRequest($to_clientId,$from_client_id,$status);

                return;
            case "delFriendReq":
                //删除好友

                Event_Friend::delFriend($from_client_id,$to_clientId);
                $content = array(
                    'content_type' => 'text',
                    'content' => $to_clientId,
                    'data'=> ''
                );
                //给发起用户发送响应
                self::sendResMsg('delFriendRes','@YXPORTAL_SYS#1001','系统1001',$from_client_id,$content,time());
                //给目标用户发送请求
                self::sendMsg('delFriend',$from_client_id,$from_client_name,$to_clientId,$content,$tStamp);
                return;
            case "searchUser":
                //根据关键字搜索联系人好友表id及名称字段
                $searchText = $data;

                $list = Event_Friend::QueryUserListByUid($searchText,$from_client_id);
                $content = array(
                    'state' => '0000',
                    'content_type' => 'text',
                    'content' => '',
                    'data'=> $list
                );
                self::sendResMsg('searchFriendList','@YXPORTAL_SYS#1001',"系统1001",$from_client_id, $content,time());

                return;

            /**
            20160824 zhanghaohuang 群聊相关功能
             */
            case "creGroupReq":
                //创建群组
                $groupId = 0;
                if(is_array($data)){
                    //保存建群信息
                    $groupType = $data['groupType'];
                    $groupName = $data['groupName'];
                    $to_clientIds = $data['member'];
                    $groupId = self::saveGroupInfo($groupType,$groupName,$from_client_id,$groupId,$to_clientIds);
                    $msg =array(
                        'groupName'=>$groupName,
                        'groupID'=>$groupId
                    );
                }
                else{
                    return;
                }
                $content_msg = array('content_type'=>'text','content'=>'群已建立','data'=>$msg);
                //给来源用户回复建群响应信息
                self::sendResMsg('creGroupRes','@YXPORTAL_SYS#1001','系统1001',$from_client_id,$content_msg,time(),$groupId);

                //服务器向邀请的人下发建群邀请
                self::sendMsg("inviteToGroup",$from_client_id,$from_client_name,$to_clientIds,$content_msg,$tStamp,true,$groupId);
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
                        $content_msg = array('content_type'=>'text','content'=>$groupName,'data' => '');
                        self::sendMsg("inviteToGroup",$from_client_id,$from_client_name,$toId,$content_msg,$tStamp,true,$groupId);

                        //给来源用户回复建群响应信息
                        self::sendResMsg('inviteToGroupRes','@YXPORTAL_SYS#1001','系统1001',$from_client_id,$content_msg,time(),$groupId);

                        //向群成员(不含发起邀请人)发送通知 xxx 邀请了 xx 入群
                        if(isset($members) && !empty($members)){
                            foreach ($members as $key => $value) {
                                $membr[] = $value['UserID'];
                            }
                            //服务器向群组中的所有人下发
                            $msg2="$from_client_name 邀请了 $toName 进群";
                            $content_msg2 = array('content_type'=>'text','content'=>$msg2,'data' =>'');
                            self::sendMsg("inviteToGroupNotice",$from_client_id,$from_client_name,$membr,$content_msg2,$tStamp,true,$groupId);
                        }
                    }//~foreach
                }//~if
                else{
                    return;
                }
                return;
            case "group":
                //群消息
                $groupId = $to_clientId;
//              echo "----12345678--------";
//              print_r ($groupId);
//              echo "----12345678--------";
                $sql = "SELECT UserID from ".DB_PREFIX."chat_group_member where GroupID = '$groupId'";
                $members = self::getdata($sql);
                if(isset($members) && !empty($members)){
                    foreach ($members as $key => $value) {
                        $membr[] = $value['UserID'];
                    }
                    //服务器向群组中的所有人下发
                    //self::sendMsg("group",$from_client_id,$from_client_name,$membr,$content,$tStamp,//true,$groupId);
                    if($resend==1) {
                        self::sendResMsg('group',$from_client_id,$from_client_name,$from_client_id,$content,time(),$message_data['uuid'],$groupId);  
                        //sendResMsg($type,$fromClientId,$fromClientName="",$toClientId,$content,$time,$groupId=null,$content_type='text',$to_obj=null);
                    }

                    else{
                        self::sendMsg("group",$from_client_id,$from_client_name,$membr,$content,$tStamp,$message_data['uuid'],true,$groupId);

                        Event_data::save_uuid($message_data['uuid']);
                    }
                    // if($content_type == 'text'){
                    //    self::sendMsg("group",$from_client_id,$from_client_name,$membr,$content,$tStamp,true,$groupId);
                    // }
                    // else if($content_type == 'pic'){
                    //     //对图片进行操作
                    //     self::sendMsg("group",$from_client_id,$from_client_name,$membr,$content,$tStamp,true,$groupId);
                    // }
                    // else if($content_type == 'voice'){
                    //     //对图片进行操作
                    //     self::sendMsg("group",$from_client_id,$from_client_name,$membr,$content,$tStamp,true,$groupId);
                    // }
                }
                return;
            case "confirmGroupReq":
                //确认入群
                $groupId = $to_clientId;
                $opType = $content_info;
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
                    $content_msg = array('content_type'=>'text','content'=>"您已加入群",'data' =>'');
                    self::sendResMsg('confirmGroupRes','@YXPORTAL_SYS#1001','系统1001',$from_client_id,$content_msg,time(),$groupId);

                    $sql = "SELECT UserID from ".DB_PREFIX."chat_group_member where GroupID = '$groupId'";
                    $members = self::getdata($sql);
                    if(isset($members) && !empty($members)){
                        foreach ($members as $key => $value) {
                            $membr[] = $value['UserID'];
                        }
                        //服务器向群组中的所有人下发
                        $content_msg2 = array('content_type'=>'text','content'=>"$from_client_name 加入了群",'data'=>'');
                        self::sendMsg("confirmGroup",$from_client_id,$from_client_name,$membr,$content_msg2,$tStamp,true,$groupId);
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
                $msg = "您已退出群";
                $content_msg = array('content_type'=>'text','content'=>$groupId,'data'=>'');
                self::sendMsg("quitGroupRes",'@YXPORTAL_SYS#1001','系统1001',$from_client_id,$content_msg,time(),true,$groupId);


                $sql = "SELECT UserID from ".DB_PREFIX."chat_group_member where GroupID = '$groupId'";
                $members = self::getdata($sql);
                // $msg2 = "$from_client_name 退出了群";
                // $content_msg = array('content_type'=>'text','content'=>$msg2);

                if(isset($members) && !empty($members)){
                    foreach ($members as $key => $value) {
                        $membr[] = $value['UserID'];
                    }
                    //服务器向群组中的所有人下发
                    self::sendMsg("quitGroup",$from_client_id,$from_client_name,$membr,$content_msg,$tStamp,true,$groupId);
                }
                return;
            case "chgGroupNameReq":
                //修改群名
                $groupId = $to_clientId;

                $updateArray = array(
                    'GroupName' => $content_info  ,
                    'UpdateUser' =>  $from_client_id,
                    'UpdateTime' => date("Y-m-d H:i:s",time()),
                );
                //更新数据库
                self::updateData(DB_PREFIX.'chat_group',$updateArray," GroupID = '$groupId'");

                //给来源用户回复修改群名响应信息
                $msg = "修改群名成功";
                $content_msg = array('content_type'=>'text','content'=>$msg,'data' =>'');
                self::sendMsg("chgGroupNameRes",'@YXPORTAL_SYS#1001','系统1001',$from_client_id,$content_msg,time(),true,$groupId);

                $sql = "SELECT UserID from ".DB_PREFIX."chat_group_member where GroupID = '$groupId'";
                $members = self::getdata($sql);
                if(isset($members) && !empty($members)){
                    foreach ($members as $key => $value) {
                        $membr[] = $value['UserID'];
                    }
                    //服务器向群组中的所有人下发
                    $msg2 = "$content_info";
                    $content_msg2 = array('content_type'=>'text','content'=>$msg2,'data'=>'');
                    self::sendMsg("chgGroupName",$from_client_id,$from_client_name,$membr,$content_msg2,$tStamp,true,$groupId);
                }


                return;
            case "pushRes":
                //处理 客户端反馈收到push的情况

                //标记消息为已读(已发送)
                $updateArray = array(
                    'Tag' => 1  ,
                );
                //更新数据库
                self::updateData(DB_PREFIX.'msgsrc_msg_info',$updateArray," ID = '$content' and UserName = '$from_client_id'");

                return;

            //消息撤回接口
            case "revoke";
                $content["content"] = $message_data["uuid"];
                if($content_type=='alone'){

                    self::sendMsg("revoke", $from_client_id, $from_client_name,$to_clientId, $content, $tStamp, $message_data["uuid"]);
                }
                if($content_type=='group'){
                    $groupId = $to_clientId;
                    $sql = "SELECT UserID from " . DB_PREFIX . "chat_group_member where GroupID = '$groupId'";
                    $members = self::getdata($sql);
                    if (isset($members) && !empty($members)) {
                        foreach ($members as $key => $value) {
                            $membr[] = $value['UserID'];
                        }
                        self::sendMsg("revoke", $from_client_id, $from_client_name, $membr, $content, $tStamp,$message_data["uuid"], true, $groupId);
                    }
                }
                self::sendResMsg('revoke', $from_client_id, $from_client_name, $from_client_id, $content, $tStamp, $message_data["uuid"],null, 'text', $to_clientId);
                return ;
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
    static function sendMsg($type,$fromClientId,$fromClientName="",$toClientId,$content,$time,$uuid=null,$offLineSave=true,$groupId=null,$content_type='text'){
        $encrypt=new GEncrypt();//实例化
        $iswin = false;
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
                    'uuid'=>$uuid
                );
                echo "----12345678--------";
                print_r ($groupId);
                echo "----12345678--------";
                if($groupId){
                    $msg['to_id'] = $groupId;
                }

                //20161207 聊天时不需要再发送聊天内容给来源客户端
                // if($fromClientId == $toId){
                //     $msg['content'] = null;
                // }


                $isOnline = Gateway::isUidOnline($toId);
                if($iswin) $isOnline = true;
                //在线的情况，直接发消息
                if(isset($isOnline) && !empty($isOnline) && $isOnline == 1){
                    echo ' to->'.$toId.PHP_EOL;
                    Gateway::sendToUid($toId,  $encrypt->encode(json_encode($msg),$key=ENCRYPT_KEY.'XY',$cipher = 'rijndael-128'));
                }
                else if($offLineSave){
                    //保存离线消息
                    if($groupId){
                        self::saveOffLineMsg($fromClientId,$fromClientName,$toId,$content,$type,$time,$groupId);
                    }
                    else{
                        //不在线的情况，保存记录到数据库
                        self::saveOffLineMsg($fromClientId,$fromClientName,$toId,$content,$type,$time);
                    }
                }

                if(IsDebug) {
                    echo "  debug SendMsg-group: (from) $fromClientId => (to) $toClientId ".PHP_EOL;
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
                'uuid'=>$uuid
            );
            if($groupId){
                $msg['to_id'] = $groupId;
            }

            if($type == 'alone'  || $type == 'revoke'){
                $msg['to_id'] = $fromClientId;
            }

            //20161207 聊天时不需要再发送聊天内容给来源客户端
            if($fromClientId == $toClientId){
                $msg['content'] = null;
            }

            $isOnline = Gateway::isUidOnline($toClientId);
            if($iswin) $isOnline = true;
            //在线的情况，直接发消息
            if(isset($isOnline) && !empty($isOnline) && $isOnline == 1){
                Gateway::sendToUid($toClientId,  $encrypt->encode(json_encode($msg),$key=ENCRYPT_KEY.'XY',$cipher = 'rijndael-128'));
            }
            else if($offLineSave){
                //保存离线消息
                if($groupId){
                    self::saveOffLineMsg($fromClientId,$fromClientName,$toClientId,$content,$type,$time,$groupId);
                }
                else{
                    self::saveOffLineMsg($fromClientId,$fromClientName,$toClientId,$content,$type,$time);
                }
            }

            if(IsDebug) {
                echo "  debug SendMsg-alone: (from) $fromClientId => (to) $toClientId ".PHP_EOL;
                print_r($msg);
            }
        }
    }

    /**
    发送响应信息
     */
    static function sendResMsg($type,$fromClientId,$fromClientName="",$toClientId,$content,$time,$uuid=null,$groupId=null,$content_type='text',$to_obj=null){
        $encrypt=new GEncrypt();//实例化
        //$content=$encrypt->encode($content,$key=ENCRYPT_KEY.'XY',$cipher = 'rijndael-128');/////将内容加密
        $msg = array(
            'type'=>$type,
            'from_name' =>$fromClientName,
            'from_id'=>$fromClientId,
            'to_id'=>$toClientId,
            'content'=>$content,
            'time'=>$time,
            'uuid'=>$uuid
        );
        if($groupId){
            $msg['to_id'] = $groupId;
        }

        if($type == 'alone'|| $type == 'revoke'){
            $msg['to_id'] = $to_obj;
        }

        if(IsDebug) {
            echo "  debug ResMsg: (from) $fromClientId => (to) $toClientId ".PHP_EOL;
            print_r($msg);
        }
        Gateway::sendToUid($toClientId,  $encrypt->encode(json_encode($msg),$key=ENCRYPT_KEY.'XY',$cipher = 'rijndael-128'));
    }

    /**
     * 当客户端断开连接时
     * @param integer $client_id 客户端id
     */
    public static function onClose($client_id)
    {
		
		$token=$_SESSION['token'];
        $clientID = $_SESSION['client_name'];
		Event_User_Info::flagBind($token,$clientID);
        // debug
        echo "client:{$_SERVER['REMOTE_ADDR']}:{$_SERVER['REMOTE_PORT']} gateway:{$_SERVER['GATEWAY_ADDR']}:{$_SERVER['GATEWAY_PORT']} time：".date('Y-m-d H:i:s',time()).'('.time().')'." client_id:$client_id __ $clientID onClose:''\n";

        // 从房间的客户端列表中删除
        if(isset($clientID) && isset($_SESSION['room_id']))
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
                    'content'=> json_decode($content),
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
                    'content'=>(array)json_decode($content),
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
                    'msgType'=>'push',
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
            'Content' => json_encode($content),
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
    static function saveGroupInfo($groupType,$groupName,$from_client_Id,$groupId=0,$to_client_id = null){
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

            //20170327 佘超要求先直接添加进入群聊
            if(!empty($to_client_id) && isset($to_client_id)){
                foreach ($to_client_id as $key => $userid) {
                    $array = null;
                    $array  = array(
                        'GroupID'=>$groupId,
                        'UserID'=>$userid,
                    );
                    $result = $db2->insert(DB_PREFIX."chat_group_member",$array);
                }
            }
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

    /**
    获取用户信息(含头像信息)
     */
    static function getUserInfo($userId){
        global $db;
        if(empty($db)){
            $db= self::getdb();
        }
        $uInfo = Authentication::QueryUserDetailInfo($userId);

        return $uInfo;
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
