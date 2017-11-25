<html><head>
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
  <title>workerman-chat PHP聊天室 Websocket(HTLM5/Flash)+PHP多进程socket实时推送技术</title>
  <script type="text/javascript">
  //WebSocket = null;
  </script>
  <link href="./css/bootstrap.min.css" rel="stylesheet">
  <link href="./css/style.css" rel="stylesheet">
  <!-- Include these three JS files: -->
  <script type="text/javascript" src="./js/swfobject.js"></script>
  <script type="text/javascript" src="./js/web_socket.js"></script>
  <script type="text/javascript" src="./js/jquery.min.js"></script>

  <script type="text/javascript">
    if (typeof console == "undefined") {    this.console = { log: function (msg) {  } };}
    WEB_SOCKET_SWF_LOCATION = "./swf/WebSocketMain.swf";
    WEB_SOCKET_DEBUG = true;
    var ws, name, client_list={},token,isLogout;

    var reconnectNums = 0;
    // 连接服务端
    function connect() {
       // 创建websocket
       //ws = new WebSocket("ws://192.168.2.200:7272");
       //ws = new WebSocket("ws://a2m-saasportal.mountec.net:7272");
        ws = new WebSocket("ws://127.0.0.1:7272");
       // 当socket连接打开时，输入用户名
       ws.onopen = onopen;
       // 当有消息时根据消息类型显示不同信息
       ws.onmessage = onmessage; 
       ws.onclose = function() {
    	  console.log("连接关闭，定时重连");
        if(!isLogout){ 
          reconnectNums++;
          if(reconnectNums>3){
            console.log("重连超过3次，终止...");
            return;
          }
          connect();
        } 
       };
       ws.onerror = function() {
     	  console.log("出现错误" +reconnectNums);
       };
    }

    // 连接建立时发送登录信息
    function onopen()
    {
        if(!name)
        {
            show_prompt();
        }
        var tstamp = '' +Date.parse(new Date())+'';
        // 登录
        // var login_data = '{"type":"login","clientID":"'+name.replace(/"/g, '\\"')+'","content":{"content_type":"text","content":{"pwd":"123456"}},"room_id":"<?php echo isset($_GET['room_id']) ? $_GET['room_id'] : 1?>"}';
        var login_data = '{"type":"login","from_id":"'+name.replace(/"/g, '\\"')+'","from_name":"'+name.replace(/"/g, '\\"')+
          '","to_id":"","content":{"room_id":"<?php echo isset($_GET['room_id']) ? $_GET['room_id'] : 1?>","name_id":"'+name.replace(/"/g, '\\"')+'","password":"123456","device":{"dev_name":"Cai M1 Note","imei":"867348020792929218","mac":"EC:26:AC:E6:2A:23:FA","dev_type":"m1 note","dev_os_version":"android 4.4.4"}},"time":' + tstamp.substring(0,10) +',"token":""}';
        console.log("websocket握手成功，发送登录数据:"+login_data);
        ws.send(login_data);
    }

    // 服务端发来消息时
    function onmessage(e)
    {
        console.log(e.data);
        var data = eval("("+e.data+")"); 
        switch(data['type']){
            // 服务端ping客户端
            case 'ping':
                ws.send('{"type":"pong","token":"'+token+'"}');
                break;;
            // 登录 更新用户列表
            case 'login':
                //{"type":"login","client_id":xxx,"client_name":"xxx","client_list":"[...]","time":"xxx"}
                var client_name = data['content']['data']['user_info']['name'];
                token = data['content']['data']['user_info']['token'];
                
                stateCode = data['content']['state'];
                if(stateCode !='0000'){
                  console.log(client_name+" 登录失败"+stateCode);
                  break;
                }

                say(data['to_id'], client_name, client_name +' 加入了聊天室', data['time']);
                if(data['client_list'])
                {
                    client_list = data['client_list'];
                }
                else
                {
                    client_list[data['client_id']] = data['client_name']; 
                }
                flush_client_list();
                console.log(client_name+" 登录成功");
                break;
            // 发言
            case 'say':
                //{"type":"say","from_client_id":xxx,"to_id":"all/client_id","content":"xxx","time":"xxx"}
                say(data['from_id'], data['from_name'], data['content'], data['time']);
                break;
            // 用户退出 更新用户列表
            case 'logout':
                //{"type":"logout","client_id":xxx,"time":"xxx"}
                say(data['from_id'], data['from_name'], data['from_name']+' 退出了', data['time']);
                delete client_list[data['from_id']];
                flush_client_list();
                isLogout = true;
                break;
            case 'friendList':
                var list = data['content']['data'];
                console.log(' receive friendList ');
                console.log(list);
                break;
            case 'groupList':
                var list = data['content']['data'];
                console.log(' receive groupList ');
                console.log(list);
                break;
        }
    }

    // 输入姓名
    function show_prompt(){  
        name = prompt('输入你的名字：', '');
        if(!name || name=='null'){  
            name = '游客';
        }
    }  

    // 提交对话
    function onSubmit() {
      var inType = $("#typeInput").val();
      if(inType){
        var toId = $("#toIdInput").val();
        var fromName = $("#fromNameInput").val();
        var ct_type = $("#content_type").val();
        var ct_data = $("#content_data").val();
        var input = document.getElementById("textarea");
          
        if(inType == 'creGroupReq'){
          ct_data = '{'+ input.value +'}';
          //toId = '["'+toId.replace(',','","')+'"]';
           
          // console.log( ' 2 2 ');
          // console.log( input.value);
          // console.log(ct_data);
          input.value = '';
          toId = '"' + toId +'"';
        }
        else{
          ct_data = '"' + ct_data +'"';
          toId = '"' + toId +'"';
        }
        var tstamp = '' +Date.parse(new Date())+'';//1472724865000 
        var tmp_name = name;
        if(fromName){
          tmp_name = fromName;
        }
        var send_msg = '{"type":"' + inType +'","from_id":"' + tmp_name +'","from_name":"'
          +fromName +'","to_id":' + toId +',"content":{"content_type":"'+ct_type+'","content": "' + input.value + '","data":' + ct_data + '},"time":' + tstamp.substring(0,10) +',"token":"'+token+'"}';

        console.log(send_msg);
        ws.send(send_msg);
        input.value = "";
        input.focus();
      }
      else{
        var input = document.getElementById("textarea");
        var to_client_id = $("#client_list option:selected").attr("value");
        var to_client_name = $("#client_list option:selected").text();
        ws.send('{"type":"say","to_id":"'+to_client_id+'","to_client_name":"'+to_client_name+'","content":"'+input.value.replace(/"/g, '\\"').replace(/\n/g,'\\n').replace(/\r/g, '\\r')+'"}');
        input.value = "";
        input.focus();
      }
      
    }

    // 刷新用户列表框
    function flush_client_list(){
    	var userlist_window = $("#userlist");
    	var client_list_slelect = $("#client_list");
    	userlist_window.empty();
    	client_list_slelect.empty();
    	userlist_window.append('<h4>在线用户</h4><ul>');
    	client_list_slelect.append('<option value="all" id="cli_all">所有人</option>');
    	for(var p in client_list){
            userlist_window.append('<li id="'+p+'">'+client_list[p]+'</li>');
            client_list_slelect.append('<option value="'+p+'">'+client_list[p]+'</option>');
        }
    	$("#client_list").val(select_client_id);
    	userlist_window.append('</ul>');
    }

    // 发言
    function say(from_client_id, from_client_name, content, time){
    	$("#dialog").append('<div class="speech_item"><img src="http://lorempixel.com/38/38/?'+from_client_id+'" class="user_icon" /> '+from_client_name+' <br> '+time+'<div style="clear:both;"></div><p class="triangle-isosceles top">'+content+'</p> </div>');
    }

    $(function(){
    	select_client_id = 'all';
	    $("#client_list").change(function(){
	         select_client_id = $("#client_list option:selected").attr("value");
	    });
    });
  </script>
</head>
<body onload="connect();">
    <div class="container">
	    <div class="row clearfix">
	        <div class="col-md-1 column">
	        </div>
	        <div class="col-md-6 column">
	           <div class="thumbnail">
	               <div class="caption" id="dialog"></div>
	           </div>
	           <form onsubmit="onSubmit(); return false;">
	                <select style="margin-bottom:8px" id="client_list">
                        <option value="all">所有人</option>
                    </select>
                    <input id="typeInput">type</input>
                    <input id="toIdInput">to</input>
                    <input id="fromNameInput">from</input>
                    <input id="content_type">ct_type</input>
                    <input id="content_data">data</input>
                    <div class="say-btn"><input id="submitBtn" type="submit" class="btn btn-default" value="发送消息" /></div>
                    <textarea class="textarea thumbnail" id="textarea"></textarea>
                    <div class="say-btn"><input type="submit" class="btn btn-default" value="发表" /></div>
               </form>
               <div>
               &nbsp;&nbsp;&nbsp;&nbsp;<b>房间列表:</b>（当前在&nbsp;房间<?php echo isset($_GET['room_id'])&&intval($_GET['room_id'])>0 ? intval($_GET['room_id']):1; ?>）<br>
               &nbsp;&nbsp;&nbsp;&nbsp;<a href="./?room_id=1">房间1</a>&nbsp;&nbsp;&nbsp;&nbsp;<a href="./?room_id=2">房间2</a>&nbsp;&nbsp;&nbsp;&nbsp;<a href="./?room_id=3">房间3</a>&nbsp;&nbsp;&nbsp;&nbsp;<a href="./?room_id=4">房间4</a>
               <br><br>
               </div>
               <p class="cp">PHP多进程+Websocket(HTML5/Flash)+PHP Socket实时推送技术&nbsp;&nbsp;&nbsp;&nbsp;Powered by <a href="http://www.workerman.net/workerman-chat" target="_blank">workerman-chat</a></p>
	        </div>
	        <div class="col-md-3 column">
	           <div class="thumbnail">
                   <div class="caption" id="userlist"></div>
               </div>
               <a href="http://workerman.net:8383" target="_blank"><img style="width:252px;margin-left:5px;" src="./img/workerman-todpole.png"></a>
	        </div>
	    </div>
    </div>
    <script type="text/javascript">var _bdhmProtocol = (("https:" == document.location.protocol) ? " https://" : " http://");document.write(unescape("%3Cscript src='" + _bdhmProtocol + "hm.baidu.com/h.js%3F7b1919221e89d2aa5711e4deb935debd' type='text/javascript'%3E%3C/script%3E"));</script>
</body>
</html>
