<?php

/**
    群组相关操作
*/

// require 'event_data_controller.php';

Class Event_Group{
    /**
    保存群组成员信息
    @param string $groupType  群类型 
    @param string $groupName  群名 
    @param string $from_client_Id   操作人id 
    @param string $groupId  群号，默认为0表示创建操作
*/
    static function saveGroupInfo($groupType,$groupName,$from_client_Id,$groupId=0){
        $db2 = Event_data::getdb(); 

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

/**
    获取群列表
*/
    static function getGroupList($client_id){
        $db = Event_data::getdb();

        $sql = "select t1.groupID,groupName,groupType
            from ".DB_PREFIX."chat_group as t1
            join ".DB_PREFIX."chat_group_member as t2
            on t1.GroupID = t2.GroupID
            where UserID = '$client_id'";
        $result = $db->getall($sql);

        if(!empty($result)){
            return $result;
        }
        else{
            // if(IsDebug){
                //echo $sql;
            // }
            return null;
        } 
    }

    /**
    根据群号获取群信息
    */
    static function getGroupInfo($groupId){ 
        $db = Event_data::getdb();
        $countsql = " SELECT COUNT(ID) from ".DB_PREFIX."chat_group_member where GroupID='$groupId' ";
        $total      = $db->fetch_count($countsql);

        $sql = " SELECT t2.GroupName,t1.UserID from ".DB_PREFIX."chat_group_member as t1".
            " join ".DB_PREFIX."chat_group as t2 on t1.GroupID = t2.GroupID where t1.GroupID = '$groupId'";
        $result = $db->getall($sql);
        if(!empty($result)){
            $groupName = $result[0]['GroupName'];
            for($i = 0;$i < count($result);$i++){ 

                $uid = $result[$i]['UserID'];
                $uInfo = Authentication::QueryUserDetailInfo($uid);
                $uName = $uInfo['name'];
                require_once CHENCY_ROOT."./source/core/GetPin.class.php";//取汉字拼音
                $gp = new GetPin();
                $pinyin = $gp->Pinyin(strtolower($uName));
     
                $data = array(
                    'name' => $uName,
                    'name_id' => $uid,
                    'spell'=>$pinyin,
                    'icon' => $uInfo['icon']
                    );
                $re[] = $data; 
            }

            $dataAry = array(
                'groupId' => $groupId,
                'groupName' => $groupName,
                'groupUserCount' => intval($total),
                'groupUsersInfo' => $re
            ); 
        }
        else{
            $dataAry = array(
                'groupId' => $groupId,
                'groupName' => null,
                'groupUserCount' => 0,
                'groupUsersInfo' => null
            );
        } 

        return $dataAry;
    }
}