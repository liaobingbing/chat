<?php 

class Event_Department{

	static function getChildDepartmentAndUserInfo($dept_id){	 
	 	$db = Event_data::getdb(); 

		//传入的参数 顶级为-1或者空
		//非顶级部门 OU=华北大区,OU=华北大区分区二 
		//AD情况的参数 OU=yxcloud,OU=testOU  OU=上级部门,OU=子级部门,OU=子子级部门
		$ous = Authentication::QueryUserInfoAndChildOUByOU($dept_id); 

		if(!empty($ous)){
			foreach ($ous as $key1 => $tmpOU) {
				 $tmpOU['dept_id'] = $tmpOU['id'];
				 unset($tmpOU['id']);$ous[$key1] = $tmpOU;//20170420zhanghh 修改返回部门信息的id为dept_id，避免跟ios解析冲突
			}
			return $ous;
		}
		else{
			return null;
		} 
	}
}