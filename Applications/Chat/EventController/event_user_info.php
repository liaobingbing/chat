<?php
    /**
        用户信息相关
        包括 token、硬件信息、MDM命令、用户详细信息
    */
require_once CHENCY_ROOT.'./source/core/class.encrypt.php';
require_once CHENCY_ROOT.'./admin/GToken.php';
require_once CHENCY_ROOT.'./source/core/core.func.php';

Class Event_User_Info{
    /**
        获取用户信息(含头像信息)
    */
   static function getUserInfo($userId){ 
        $db = Event_data::getdb();
        // if(empty($db)){ 
        //     $db= self::getdb();
        // }
        $uInfo = Authentication::QueryUserDetailInfo($userId);  

        return $uInfo;
   }

   /**
    保存设备信息
    */
   static function saveDevice($userId,$device){
        $db = Event_data::getdb();
        $dev_name = isset($device['dev_name']) ? $device['dev_name'] : null;
        $dev_imei = isset($device['imei']) ? $device['imei'] : null;
        $dev_mac = isset($device['mac']) ? $device['mac'] : null;
        $dev_type = isset($device['dev_type']) ? $device['dev_type'] : null;
        $dev_os_version = isset($device['dev_os_version']) ? $device['dev_os_version'] : null;
         $dev_device = isset($device['dev_device']) ? $device['dev_device'] : null;
        if($dev_device=="phone"){
            $sql = "SELECT * FROM ".DB_PREFIX."user_device_info where UserId = '$userId' and IMEI = '$dev_imei' ";

        }
        else{
            $sql = "SELECT * FROM ".DB_PREFIX."user_device_info where UserId = '$userId' and MAC= '$dev_mac' ";
        }
        
        $result = $db->fetch_first($sql);
        if($result && isset($result['Id'])){
            $id = $result['Id'];
            //已有记录 更新
            $array_update = array(
                'DevName' =>$dev_name,
                'IMEI'=>$dev_imei,
                'Dev_Device'=>$dev_device,
                'MAC' =>$dev_mac,
                'DevType' => $dev_type,
                'OSVersion' => $dev_os_version ,
                'UpdateTime' => date('Y-m-d H:i:s',time())
                );
            $db->update(DB_PREFIX."user_device_info",$array_update," Id = $id ");
        }
        else{
            //新记录
            $array_insert = array(
                'UserId' =>$userId,
                'DevName' =>$dev_name,
                'Dev_Device'=>$dev_device,
                'IMEI' =>$dev_imei,
                'MAC' =>$dev_mac,
                'DevType' => $dev_type,
                'OSVersion' => $dev_os_version 
                );
            $db->insert(DB_PREFIX."user_device_info",$array_insert);
        }
   }

/**
获取MDM命令信息，根据用户id跟设备信息(imei)
*/
   static function getMDMInfoWithLogin($userId,$device){
        $db = Event_data::getdb();

        if(!empty($device) && isset($device['imei'])){
            $imei = $device['imei'];
            $_SESSION['uimei'] = $imei;
            $sql1 = "SELECT * FROM ".DB_PREFIX."mdm_device where Dev_IMEI = '$imei' order by UpdateTime desc ";
            $result1 = $db->fetch_first($sql1);

            if($result1 && isset($result1['Id'])){
                //清除数据  默认0
                if(isset($result1['R_wipe_data']) && $result1['R_wipe_data'] == 1){ 
                    $array['value'] = 1;
                }
                else{ 
                    $array['value'] = 0;
                }
                $array['order'] = 'R_wipe_data'; 
                $re[] = $array;

                //访问权限 默认1
                if(isset($result1['R_visit']) && $result1['R_visit'] == 1){ 
                    $Rvisit = 1;
                }
                else{ 
                    $Rvisit = 0;
                } 
                if($result2['OrderSendState'] == '2'){
                    $array_update = array(
                        'OrderSendState' => 1
                        ); 
                    $db->update(DB_PREFIX."mdm_device",$array_update," Dev_IMEI = '$imei' ");
                }
            }
            else{
                //清除数据 默认0
                $array['order'] = 'R_wipe_data'; 
                $array['value'] = 0; 
                $re[] = $array;
            }

        }
        else{
            //清除数据 默认0
            $array['order'] = 'R_wipe_data'; 
            $array['value'] = 0; 
            $re[] = $array;
        }

        $sql2 = "SELECT * FROM ".DB_PREFIX."mdm_user where UserId = '$userId' order by UpdateTime desc ";
        $result2 = $db->fetch_first($sql2);
        
        if($result2 && isset($result2['Id'])){
            //复制权限 默认0
            if(isset($result2['R_copy']) && $result2['R_copy'] == 1){ 
                $array['value'] = 1;
            }
            else{ 
                $array['value'] = 0;
            }
            $array['order'] = 'R_copy';
            $re[] = $array;
            
            //截屏权限 默认0
            if(isset($result2['R_screenshot']) && $result2['R_screenshot'] == 1){
               
                $array['value'] = 1;
            }
            else{ 
                $array['value'] = 0;
            }
            $array['order'] = 'R_screenshot'; 
            $re[] = $array;

            //使用root/越狱设备的权限 默认0  测试时默认为1
            if(isset($result2['R_root']) && $result2['R_root'] == 1){ 
                $array['value'] = 1;
            }
            else{ 
                $array['value'] = 0;
            }
            $array['order'] = 'R_root'; 
            $re[] = $array;

            //访问权限 默认1
            if(isset($result2['R_visit']) && $result2['R_visit'] == 1){ 
                $Rvisit =  1;
            }
            else{ 
                $Rvisit =  0;
            } 

            if($result2['OrderSendState'] == '2'){
                $array_update = array(
                    'OrderSendState' => 1
                    );
                $id2 = $result2['Id'];
                $db->update(DB_PREFIX."mdm_user",$array_update," Id = '$id2'");
            }
            
        }
        else{
            $array['order'] = 'R_copy'; 
            $array['value'] = 0; 
            $re[] = $array;
            $array['order'] = 'R_screenshot'; 
            $array['value'] = 0; 
            $re[] = $array;
            $array['order'] = 'R_root'; 
            $array['value'] = 0; 
            $re[] = $array;   
        }

        //设置访问权限 以用户的权限为准 
        $array['order'] = 'R_visit'; 
        $array['value'] = isset($Rvisit) ? $Rvisit : 1; 
        $re[] = $array;   
        
        return $re;
   }

   static function getMsgWithPong(){
        $userId = $_SESSION['client_name'];
        $db = Event_data::getdb();
        $sql1 = "SELECT * FROM ".DB_PREFIX."mdm_user where UserId = '$userId' and OrderSendState = 2 ";

        $result1 = $db->fetch_first($sql1);
        if($result1 && isset($result1['Id'])){
            $id1 = $result1['Id'];
            //复制权限 默认0
            if(isset($result1['R_copy']) && $result1['R_copy'] == 1){ 
                $array['value'] = 1;
            }
            else{ 
                $array['value'] = 0;
            }
            $array['order'] = 'R_copy';
            $re[] = $array;
            
            //截屏权限 默认0
            if(isset($result1['R_screenshot']) && $result1['R_screenshot'] == 1){
               
                $array['value'] = 1;
            }
            else{ 
                $array['value'] = 0;
            }
            $array['order'] = 'R_screenshot'; 
            $re[] = $array;

            //使用root/越狱设备的权限 默认0  测试时默认为1
            if(isset($result1['R_root']) && $result1['R_root'] == 1){ 
                $array['value'] = 1;
            }
            else{ 
                $array['value'] = 0;
            }
            $array['order'] = 'R_root'; 
            $re[] = $array;

            //访问权限 默认1
            if(isset($result1['R_visit']) && $result1['R_visit'] == 1){ 
                $Rvisit =  1;
            }
            else{ 
                $Rvisit =  0;
            } 
            $array['order'] = 'R_visit'; 
            $array['value'] = $Rvisit; 
            $re[] = $array;

            $array_update = array(
                'OrderSendState' => 1
                );
            $db->update(DB_PREFIX."mdm_user",$array_update," Id = $id1");
        }

        if(isset($_SESSION['uimei']) && !empty($_SESSION['uimei'])){
            $imei = $_SESSION['uimei'];
            $sql2 = "SELECT * FROM ".DB_PREFIX."mdm_device where Dev_IMEI = '$imei' and OrderSendState = 2 ";

            $result2 = $db->fetch_first($sql2);
                if($result2 && isset($result2['Id'])){
                if(isset($result1['R_wipe_data']) && $result1['R_wipe_data'] == 1){ 
                    $array['value'] = 1;
                }
                else{ 
                    $array['value'] = 0;
                }
                $array['order'] = 'R_wipe_data'; 
                $re[] = $array;

                $array_update = array(
                    'OrderSendState' => 1
                    );
                $db->update(DB_PREFIX."mdm_device",$array_update," Dev_IMEI = '$imei' ");
            }
        }
        
        return $re;
   }

   /**
    根据token，提出其它客户端的登陆状态
   * @param $token string 当前登陆客户端的token信息
   * @param $client_Id string 当前登陆客户端被分配的唯一标识id
   * @param $userId string 当前登陆客户端对应的用户信息
   */
   static function logoutOtherClient($token,$client_Id,$userId){
        $db = Event_data::getdb();

        //获取除当前登陆外的其它客户端的有效token
        $sql = " SELECT ClientID FROM ".DB_PREFIX."user_token where IsLogout = 0 ".
            " and userID = '$userId' and UserToken != '$token' ";
        
        $result = $db->getall($sql); 

        //将当前登陆的token跟客户端唯一标识关联起来
        $array1 = array(
            'ClientID' => $client_Id
            );
        $db->update(DB_PREFIX."user_token ",$array1," UserToken = '$token' ");

        //将其余有效的登陆信息置为无效
        $array1 = array(
            'IsValid' => false,
            'IsLogout' => 1
            );
        $db->update(DB_PREFIX."user_token ",$array1," UserID = '$userId' and ClientID != '$client_Id'  and IsLogout = 0 ");

        foreach ($result as $key => $value) {
            $clients[] = $value['ClientID'];
        }
        return $clients;
   }
   /*****这个是验证登录token的******/
	function checkLogin($userToken,$clientId){  
    $gencrypt = new GEncrypt();  
    $uInfo = $gencrypt->decode($userToken,$key=ENCRYPT_KEY.'XY',$cipher = 'rijndael-128'); 
    
    //对token进行解密
    if(!empty($uInfo)){  
        //解析token
        //$d1 = base64_decode($uInfo);
        $d2 = json_decode($uInfo);
        $uarray = (array)($d2);

        //分析token的组成是否预期，及使用token
        if(!empty($uarray) && count($uarray)>=5){
            $user = $uarray['u'];
            $ou = $uarray['ou'];
            $sessionId = $uarray['ses']; 

            
            $gtk = new GToken();
            $msg = $gtk->checkOrUpdateToken($user,$userToken);//返回值中data为userid
           if($msg['stateCode']=="000"){
                return "true";
           }
           else{
             $result = array(
                        'type' => 'login',
                        'from_id' => '@YXPORTAL_SYS#1001',
                        'from_name' => '系统1001',
                        'to_id' => $clientId,
                        'content' =>  array('stateCode'=>'004','msg'=>'登录异常','data'=>null),
                        'time' => time()
                        );
             return $result; 
           }
        }
        else{ 
            if(IsDebug){
                Core_Fun::writeExlog('debug','token异常');
                Core_Fun::writeExlog('debug',$uarray);
                //zhanghaohuang 20170515 因客户端解析要求成功与失败一致 data返回值从array()修改为null
                $result = array(
                        'type' => 'login',
                        'from_id' => '@YXPORTAL_SYS#1001',
                        'from_name' => '系统1001',
                        'to_id' => $clientId,
                        'content' =>  array('stateCode'=>'004','msg'=>'非法请求,账号失效','data'=>null),
                        'time' => time()
                        );
            }           
            else{
                $result = array(
                        'type' => 'login',
                        'from_id' => '@YXPORTAL_SYS#1001',
                        'from_name' => '系统1001',
                        'to_id' => $clientId,
                        'content' =>  array('stateCode'=>'004','msg'=>'非法请求,账号失效','data'=>null),
                        'time' => time()
                        );
            }
        }       
    }
    else
    {
        $result =  array(
                        'type' => 'login',
                        'from_id' => '@YXPORTAL_SYS#1001',
                        'from_name' => '系统1001',
                        'to_id' => $clientId,
                        'content' =>  array('stateCode'=>'004','msg'=>'非法请求,账号失效','data'=>null),
                        'time' => time()
                        );
        if(IsDebug){ 
            Core_Fun::writeExlog('debug','解析出的token异常');
            Core_Fun::writeExlog('debug', $uInfo);
        }
    }

    return $result;
    }
	function IsBind($token,$clientId){
	$gencrypt = new GEncrypt();  
    $uInfo = $gencrypt->decode($token); 
    
    //对token进行解密
    if(!empty($uInfo)){  
        //解析token
        //$d1 = base64_decode($uInfo);
        $d2 = json_decode($uInfo);
        $uarray = (array)($d2);

        //分析token的组成是否预期，及使用token
        if(!empty($uarray) && count($uarray)>=5){
            $user = $uarray['u'];
            $ou = $uarray['ou'];
            $sessionId = $uarray['ses']; 

            
            $gtk = new GToken();
		$res= $gtk->checkIsBind($user,$token);//返回数据库中的IsBind 值
		return $res;
		
	}
	function UpBind($token,$clientId){
		$gencrypt = new GEncrypt();  
    $uInfo = $gencrypt->decode($token); 
    
    //对token进行解密
    if(!empty($uInfo)){  
        //解析token
        //$d1 = base64_decode($uInfo);
        $d2 = json_decode($uInfo);
        $uarray = (array)($d2);

        //分析token的组成是否预期，及使用token
        if(!empty($uarray) && count($uarray)>=5){
            $user = $uarray['u'];
            $ou = $uarray['ou'];
            $sessionId = $uarray['ses']; 

            
            $gtk = new GToken();
		$res= $gtk->Bind($user,$userToken);//返回数据库中的IsBind 值
	}
	function flagBind($token,$clientID){
		$gencrypt = new GEncrypt();  
    $uInfo = $gencrypt->decode($token);
	 //对token进行解密
    if(!empty($uInfo)){  
        //解析token
        //$d1 = base64_decode($uInfo);
        $d2 = json_decode($uInfo);
        $uarray = (array)($d2);

        //分析token的组成是否预期，及使用token
        if(!empty($uarray) && count($uarray)>=5){
            $user = $uarray['u'];
            $ou = $uarray['ou'];
            $sessionId = $uarray['ses']; 

            
            $gtk = new GToken();
		$res= $gtk->flagBind($user,$token);//返回数据库中的IsBind 值
	}
}
}
