<?php

namespace app\index\controller;

use library\Controller;
use think\Db;
use think\facade\Cache;


class Index extends Base
{
    public function index(){
        include 'MsgBuilder.php';
        $do = isset($_REQUEST['do']) ? trim($_REQUEST['do']) : 'index';
        //如果在机器人本机运行，修改为127.0.0.1或者localhost，若外网访问改为运行机器人的服务器外网ip
        $robot = Robot::init(config('remote_communication'),config('remote_communication_port'));
        if(!in_array($do,['index','remote','down']))
            exit(json_encode(['success'=>false,'meg'=>'do error']));
        $robot->$do();
    }
    
    public function test(){
        $msg='送39';
        $send_api='https://duodkandian.com/test/send_dd?uid='.str_replace('送','',$msg);
        $resp=file_get_contents($send_api);
        echo $resp;
        exit();
        readRobotKeyword('wxid_rqn31uwmjug922','123');
        exit();
        echo(date('Y-m-d H:i:s'));
        exit();
        $robot_wxid='wxid_rqn31uwmjug922';
        $serviced_group=readRobotForGroup($robot_wxid);
        foreach ($serviced_group as $group){
            $params=[
                "event" => 'SendTextMsg',
                "robot_wxid" => $robot_wxid,
                "to_wxid" => $group,
                "msg" => '测试群发'
            ];
            
            sleep(3);
            $resp =post_json_data(config('intranet_communication'),$params);
            echo '群发成功';
       }
    }
    
    
}

/*-------下面是逻辑功能开发区域------*/
class Robot{

    private $host;
    private $port;
    private $authorization_file = './authorization.txt';//通信鉴权密钥存储路径
    private $authorization;

    private $robot_master = [//机器人主人 后面的程序 你可以自由判断是否必须主人才可操作 自行发挥
        'sundreamer',
    ];
    private $events = [//开发了新功能，就需要在对应的事件下面加入进去例如【'music' => 1】指的是点歌插件=>开启(1 开启 0 关闭)
        'EventLogin' => [//新的账号登录成功/下线时

        ],
        'EventGroupMsg'=> [//群消息事件（收到群消息时，运行这里）
            'music' => 1,
            'douyin' =>1,
            'replay_text'=>1,
            'replay_video'=>1,
            'keyword_reply'=>1,
        ],
        'EventFriendMsg'=> [//私聊消息事件（收到私聊消息时，运行这里）
            'music' => 1,
            'douyin' =>1,
            'replay_text'=>1,
            'replay_img'=>1,
            'replay_video'=>1,
            'replay_file_msg'=>1,
            'replay_emjoy_msg'=>1,
            'replay_link_msg'=>1,
            'replay_music_msg'=>1,
            'keyword_reply'=>1,
            'duoduo_send'=>1
        ],
        'EventReceivedTransfer'=> [//收到转账事件（收到好友转账时，运行这里）
        ],
        'EventScanCashMoney'=> [//面对面收款（二维码收款时，运行这里）

        ],
        'EventFriendVerify'=> [//好友请求事件（插件3.0版本及以上）
        ],
        'EventContactsChange'=> [//朋友变动事件（插件4.0版本及以上，当前为测试版，还未启用，留以备用）

        ],
        'EventGroupMemberAdd'=> [//群成员增加事件（新人进群）
        ],
        'EventGroupMemberDecrease'=> [//群成员减少事件（群成员退出）
        ],
        'EventSysMsg'=> [//系统消息事件

        ],
    ];

    /**
     * @param string $host
     * @param int $port
     * @return object
     */
    public static function init($host = '127.0.0.1', $port = 8090)
    {
        return new static($host, $port);
    }

    /**
     * @param string $host
     * @param int $port
     */
    public function __construct($host = '127.0.0.1', $port = 8090)
    {
        $this->host = $host;
        $this->port = $port;
        if(!is_file($this->authorization_file))
            $this->setAuthorization();
        $this->authorization = $this->getAuthorization();
    }

    /**
     * 程序入口，返回空白Json或具有操作命令的数据
     * 该方法不需要动
     * @return string 符合可爱猫|http-sdk插件的操作数据结构json
     */
    public function index(){
        header("Content-type: text/html; charset=utf-8");
        date_default_timezone_set("PRC");//设置下时区
        $data = file_get_contents('php://input');//接收原始数据;
        //file_put_contents('./wxmsg.log',$data."\r\n",FILE_APPEND);//记录接收消息log
        $rec_arr = json_decode($data,true);//把接收的json转为数组
        $this->checkAuthorization();//检测通信鉴权，并维护其值
        // file_put_contents("resp.txt",'rec_arr-----'.json_encode($rec_arr).PHP_EOL, FILE_APPEND);
        echo json_encode($this->response($rec_arr));
    }

    /**
     * 控制机器人接口
     * 该方法不需要动
     * @return string 符合openHttpApi插件的操作数据结构json
     */
    public function remote(){
        header("Content-type: text/html; charset=utf-8");
        date_default_timezone_set("PRC");//设置下时区
        // file_put_contents("resp.txt",'进入remote'.PHP_EOL, FILE_APPEND);
        $param = [//若想使用同步处理，也就是你接收完事件要如何处理，那么你就要完善下面这个数组
            "event" => isset($_REQUEST['event']) ? trim($_REQUEST['event']) : "SendTextMsg",
            "robot_wxid" => isset($_REQUEST['robot_wxid']) ? trim($_REQUEST['robot_wxid']) : 'wxid_rqn31uwmjug922',
            "group_wxid" => isset($_REQUEST['group_wxid']) ? trim($_REQUEST['group_wxid']) : '18221469840@chatroom',
            "member_wxid" => isset($_REQUEST['member_wxid']) ? trim($_REQUEST['member_wxid']) : '',
            "member_name" => isset($_REQUEST['member_name']) ? trim($_REQUEST['member_name']) : '',
            "to_wxid" => isset($_REQUEST['to_wxid']) ? trim($_REQUEST['to_wxid']) : '18221469840@chatroom',
            "msg" => isset($_REQUEST['msg']) ? trim($_REQUEST['msg']) : "你好啊！"
        ];
        echo json_encode($this->request($param));
    }

    /**
     * 将收到的图片转化为下载连接(直连文件)
     * 只有该文件和可爱猫在同一台服务器时可用
     * 并且运行该文件的用户必须拥目标文件的读取权限
     * @param string $filepath 收到的图片、视频、文件消息里的路径地址(其实就是msg的值)
     */
    public function down()
    {
        ob_clean();
        $filepath = $_REQUEST['filepath']?$_REQUEST['filepath']:'./favicon.ico';
        if (!file_exists($filepath)) {
            exit(json_encode(['success'=>false,'message'=>'file not found!']));
        }

        $fp = fopen($filepath, "r");
        $filesize = filesize($filepath);

        header("Content-type:application/octet-stream");
        header("Accept-Ranges:bytes");
        header("Accept-Length:" . $filesize);
        header("Content-Disposition: attachment; filename=" . basename($filepath));

        $buffer = 1024;
        $buffer_count = 0;
        while (!feof($fp) && $filesize - $buffer_count > 0) {
            $data = fread($fp, $buffer);
            $buffer_count += $buffer;
            echo $data;
        }
        fclose($fp);
    }

    /**
     * 命令机器人去做某事
     * @param array $param
     * @param string $authorization
     * @return string
     *
     * param
     * >>>  event 事件名称
     * >>>  robot_wxid 机器人id
     * >>>  group_wxid 群id
     * >>>  member_wxid 群艾特人id
     * >>>  member_name 群艾特人昵称
     * >>>  to_wxid 接收方(群/好友)
     * >>>  msg 消息体(str/json)
     *
     * param.event
     * >>> SendTextMsg 发送文本消息 robot_wxid to_wxid(群/好友) msg---已封装
     * >>> 下面的几个文件类型的消息path为服务器里的路径如"D:/a.jpg"，会优先使用，文件不存在则使用 url(网络地址)
     * >>> SendImageMsg 发送图片消息 robot_wxid to_wxid(群/好友) msg(name[md5值或其他唯一的名字，包含扩展名例如1.jpg], url,patch)---已封装
     * >>> SendVideoMsg 发送视频消息 robot_wxid to_wxid(群/好友) msg(name[md5值或其他唯一的名字，包含扩展名例如1.mp4], url,patch)---已封装
     * >>> SendFileMsg 发送文件消息 robot_wxid to_wxid(群/好友) msg(name[md5值或其他唯一的名字，包含扩展名例如1.txt], url,patch)---已封装
     * >>> SendEmojiMsg 发送动态表情 robot_wxid to_wxid(群/好友) msg(name[md5值或其他唯一的名字，包含扩展名例如1.gif], url,patch)---已封装
     * >>> SendGroupMsgAndAt 发送群消息并艾特(4.4只能艾特一人) robot_wxid, group_wxid, member_wxid, member_name, msg
     * >>> SendLinkMsg 发送分享链接 robot_wxid, to_wxid(群/好友), msg(title, text, target_url, pic_url, icon_url)---已封装
     * >>> SendMusicMsg 发送音乐分享 robot_wxid, to_wxid(群/好友), msg(music_name, type)---已封装
     * >>> SendCardMsg 发送名片消息(被禁用) robot_wxid to_wxid(群/好友) msg(微信号)---已禁用
     * >>> SendMiniApp 发送小程序 robot_wxid to_wxid(群/好友) msg(小程序消息的xml内容)---skip
     * >>> GetRobotName 取登录账号昵称 robot_wxid---已测试，success
     * >>> GetRobotHeadimgurl 取登录账号头像 robot_wxid---已失效
     * >>> GetLoggedAccountList 取登录账号列表 不需要参数---已测试，success
     * >>> GetFriendList 取好友列表 robot_wxid msg(is_refresh,out_rawdata)//是否更新缓存 是否原始数据---已测试，success
     * >>> GetGroupList 取群聊列表 robot_wxid(不传返回全部机器人的)，msg(is_refresh)---已测试，success
     * >>> GetGroupMemberList 取群成员列表 robot_wxid, group_wxid msg(is_refresh)---已测试，success
     * >>> GetGroupMemberInfo 取群成员详细 robot_wxid, group_wxid, member_wxid msg(is_refresh)---已测试，success
     * >>> AcceptTransfer 接收好友转账 robot_wxid, to_wxid, msg---已失效
     * >>> AgreeGroupInvite 同意群聊邀请 robot_wxid, msg
     * >>> AgreeFriendVerify 同意好友请求 robot_wxid, msg
     * >>> EditFriendNote 修改好友备注 robot_wxid, to_wxid, msg---已测试，success
     * >>> DeleteFriend 删除好友 robot_wxid, to_wxid---已测试，success
     * >>> GetAppInfo 取插件信息 无参数
     * >>> GetAppDir 取应用目录 无
     * >>> AddAppLogs 添加日志 msg
     * >>> ReloadApp 重载插件 无
     * >>> RemoveGroupMember 踢出群成员 robot_wxid, group_wxid, member_wxid
     * >>> EditGroupName 修改群名称 robot_wxid, group_wxid, msg---已测试，success
     * >>> EditGroupNotice 修改群公告 robot_wxid, group_wxid, msg---已测试，success
     * >>> BuildNewGroup 建立新群 robot_wxid, msg(好友Id用"|"分割)
     * >>> QuitGroup 退出群聊 robot_wxid, group_wxid
     * >>> InviteInGroup 邀请加入群聊 robot_wxid, group_wxid, to_wxid
     */
    public function request($param){
        if(is_string($param['msg']))
            $param['msg'] = $this->formatEmoji($param['msg']);//处理emoji
        //处理完事件返回要怎么做
        $headers = [
            'Content-Type:application/json;charset=utf-8',
        ];
        if($this->authorization)
            $headers[] = "Authorization:{$this->authorization}";
        $json = json_encode($param);
        echo $json;
        return json_decode($this->sendHttp($json,null,$headers),true);
    }

    /**
     * 收到机器人的信息，告诉它怎么做
     * @param array $request
     * @return string[]
     *
     * request
     * >>>  event 事件名称
     * >>>  robot_wxid 机器人id
     * >>>  robot_name 机器人昵称 一般空值
     * >>>  type 1/文本消息 3/图片消息 34/语音消息  42/名片消息  43/视频 47/动态表情 48/地理位置  49/分享链接  2000/转账 2001/红包  2002/小程序  2003/群邀请
     * >>>  from_wxid 来源群id
     * >>>  from_name 来源群名称
     * >>>  final_from_wxid 具体发消息的群成员id/私聊时用户id
     * >>>  final_from_name 具体发消息的群成员昵称/私聊时用户昵称
     * >>>  to_wxid 发给谁，往往是机器人自己(也可能别的成员收到消息)
     * >>>  money 金额，只有"EventReceivedTransfer"事件才有该参数
     * >>>  msg 消息体(str/json) 不同事件和不同type都不一样，自己去试验吧
     *
     * request.event
     * >>>  EventLogin'://新的账号登录成功/下线时
     * >>>  EventGroupMsg'://群消息事件（收到群消息时，运行这里）
     * >>>  EventFriendMsg'://私聊消息事件（收到私聊消息时，运行这里）
     * >>>  EventReceivedTransfer'://收到转账事件（收到好友转账时，运行这里）
     * >>>  EventScanCashMoney'://面对面收款（二维码收款时，运行这里）
     * >>>  EventFriendVerify'://好友请求事件（插件3.0版本及以上）
     * >>>  EventContactsChange'://朋友变动事件（插件4.0版本及以上，当前为测试版，还未启用，留以备用）
     * >>>  EventGroupMemberAdd'://群成员增加事件（新人进群）
     * >>>  EventGroupMemberDecrease'://
     */
    public function response($request){
        $response = ["event" => ""];//event空时，机器人不处理消息
        if(empty($request)) return $response;
        $functions = $this->events[$request['event']];
        if(empty($functions)){//若没处理方法，直接返回空数据告知机器人不处理即可！
            return $response;
        }
        foreach ($functions as $func => $is_on){
            if($is_on){
                if($request['event']=='EventGroupMsg'){//判断为群消息
                    // 判断是否为服务群对象
                    if(!robotServicedForObj($request['robot_wxid'],$request['from_wxid'])) return $response;
                }
                
                $response = call_user_func([$this,$func],$request);
                if($response !== false)
                    break;//只要一个成功就跳出循环
            }
        }
        //处理完事件返回要怎么做
        return $response;
    }
    
    //赠送小黄车指令
    public function duoduo_send($request){
        $msg = trim($request['msg']);
        if($request['from_wxid']=='wxid_yw50xr3odmmv22'){
            if(strstr($msg,'送')){
                $send_api='https://duodkandian.com/test/send_dd?uid='.str_replace('送','',$msg);
                $resp=file_get_contents($send_api);
                return SendTextMsg($request,$resp);
            }
        }
        return false;
    }
    
    //关键词回复
    public function keyword_reply($request){
        $msg = trim($request['msg']);
        $answer=readRobotKeyword($request['robot_wxid'],$msg);
        if($answer){//这里不建议从数据库读，因为群消息每一条都会先读缓存，再读mysql，性能会降低，所以尽量不要清空缓存就行。
            return SendTextMsg($request,$answer);
        }
        return false;
    }
    
    //复读机功能
    public function replay_text($request){
        $msg = trim($request['msg']);
        if($this->startWith($msg,'复读')){
            return SendTextMsg($request,$msg);
        }
        return false;
    }
    
    //发图功能
    public function replay_img($request){
        $msg = trim($request['msg']);
        if($this->startWith($msg,'图')){
            return SendImageMsg($request,'http://pic.5tu.cn/uploads/allimg/1901/pic_5tu_big_201901170106566711.jpg','pic_5tu_big_201901170106566711.jpg');
        }
        return false;
    }
    
    //发送视频功能
    public function replay_video($request){
        $msg = trim($request['msg']);
        if($this->startWith($msg,'视频')){
            return SendVideoMsg($request,'https://v9.douyinvod.com/8ae942678610b53075cefe549a2c314a/61cad328/video/tos/cn/tos-cn-ve-15-alinc2/bd191ead1b9f48e89f7570dd6c12f7a6/?a=1128&br=2433&bt=2433&cd=0%7C0%7C0&ch=96&cr=0&cs=0&cv=1&dr=0&ds=6&er=&ft=YbbdSWWFBLwqO543agD12UZ13_3w&l=202112281604160102121012333A00D68F&lr=all&mime_type=video_mp4&net=0&pl=0&qs=0&rc=MzxqbTo6ZmozOjMzNGkzM0ApM2llOWUzZWU3N2c3PDVoOmcpaHV2fWVuZDFwekAvXjEucjQwZi1gLS1kLTBzcy5eNWM2X2EtXzZgMjZiMC46Y29zYlxmK2BtYmJeYA%3D%3D&vl=&vr=','6.mp4');
        }
        return false;
    }

    //发送文件功能
    public function replay_file_msg($request){
        $msg = trim($request['msg']);
        if($this->startWith($msg,'文件')){
            return SendFileMsg($request,'http://pic.5tu.cn/uploads/allimg/1901/pic_5tu_big_201901170106566711.jpg','111.jpg');
        }
        return false;
    }
    
    //发送动态表情
    public function replay_emjoy_msg($request){
        $msg = trim($request['msg']);
        if($this->startWith($msg,'表情')){
            return SendEmojiMsg($request,'https://qq.yh31.com/tp/qw/202111191551197602.gif','202111191551197602.gif');
        }
        return false;
    }
    
    //发送分享消息功能
    public function replay_link_msg($request){
        $msg = trim($request['msg']);
        if($this->startWith($msg,'分享')){
            $title='测试标题';
            $text='测试描述';
            $target_url='http://www.baidu.com';
            $pic_url='https://www.baidu.com/img/flexible/logo/pc/result.png';
            $icon_url='https://img0.baidu.com/it/u=2294861089,3210317650&fm=253&fmt=auto&app=120&f=JPEG?w=200&h=200';
            return SendLinkMsg($request,$title,$text,$target_url,$pic_url,$icon_url);
        }
        return false;
    }
    
    //发送分享音乐功能
    public function replay_music_msg($request){
        $msg = trim($request['msg']);
        if($this->startWith($msg,'音乐')){
            $name='我要你';
            return SendMusicMsg($request,$name);
        }
        return false;
    }

    public function music($request){
        $key = ['点歌','我想听','来一首'];
        $msg = trim($request['msg']);
        foreach ($key as $v){
            if($this->startWith($msg,$v)){
                $name = trim(str_replace($v,'',$msg));//把 key的前缀词替换为空
                return [
                    "event" => "SendMusicMsg",
                    "robot_wxid" => $request['robot_wxid'],
                    "to_wxid" => $request['from_wxid'] ? $request['from_wxid'] : $request['final_from_wxid'],
                    "member_wxid" => '',
                    "member_name" => '',
                    "group_wxid" => '',
                    "msg" => ['name'=>$name,'type'=>0],
                ];
            }
        }
        return false;
    }

    public function douyin($request){
        $key = ['抖音','抖音视频','抖'];
        $msg = trim($request['msg']);
        foreach ($key as $v){
            if($this->startWith($msg,$v)){
                $dou['link'] = trim(str_replace($v,'',$msg));//把用户发的消息截取为url
                $dou_json = $this->sendHttp(http_build_query($dou),'http://qsy.988g.cn/ajax/analyze.php');
                $dou_arr = json_decode($dou_json,true);
                $link = [
                    'title' => $dou_arr['data']['voidename'],
                    'text' => $dou_arr['data']['voidename'],
                    'target_url' => $dou_arr['data']['downurl'],
                    'pic_url' => $dou_arr['data']['cover'],
                    'icon_url' => $dou_arr['data']['cover'],
                ];
                //发送分享链接
                return [
                    "event" => "SendLinkMsg",
                    "robot_wxid" => $request['robot_wxid'],
                    "to_wxid" => $request['from_wxid'] ? $request['from_wxid'] : $request['final_from_wxid'],
                    "member_wxid" => '',
                    "member_name" => '',
                    "group_wxid" => '',
                    "msg" => $link,
                ];
            }
        }
        return false;
    }

    /**
     * 聊天内容是否以关键词xx开头
     */
    public function startWith($str,$pattern) {
        return strpos($str,$pattern) === 0 ? true : false;
    }

    /**
     * 格式化带emoji的消息，格式化为可爱猫可展示的表情
     */
    public function formatEmoji($str){
        $strEncode = '';
        $length = mb_strlen($str,'utf-8');
        for ($i=0; $i < $length; $i++) {
            $_tmpStr = mb_substr($str,$i,1,'utf-8');
            if(strlen($_tmpStr) >= 4){
                $strEncode .= '[@emoji='.trim(json_encode($_tmpStr),'"').']';
            }else{
                $strEncode .= $_tmpStr;
            }
        }
        return $strEncode;
    }

    /**
     * 发送 HTTP 请求
     */
    public function sendHttp($params, $url = null, $headers = null, $method = 'post', $timeout = 3)
    {
        $url = $url ? $url : $this->host.':'.$this->port;

        $curl = curl_init();
        if ('get' == strtolower($method)) {//以GET方式发送请求
            curl_setopt($curl, CURLOPT_URL, "{$url}?{$params}");
        } else {//以POST方式发送请求
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_POST, 1);//post提交方式
            curl_setopt($curl, CURLOPT_POSTFIELDS, $params);//设置传送的参数
        }
        if(!empty($headers))
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_HEADER, false);//是否打印服务器返回的header
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);//要求结果为字符串且输出到屏幕上
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $timeout);//设置等待时间
        $res = curl_exec($curl);//运行curl
        $err = curl_error($curl);

        if (false === $res || !empty($err)) {
            $Errno = curl_errno($curl);
            $Info = curl_getinfo($curl);
            curl_close($curl);
            print_r($Info);
            return $err. ' result: ' . $res . 'error_msg: '.$Errno;
        }
        curl_close($curl);//关闭curl

        return $res;
    }
    
    private function getHeaders() {
        $headers = [];
        if (!function_exists('getallheaders')) {
            foreach ($_SERVER as $name => $value) {
                if (substr($name, 0, 5) == 'HTTP_') {
                    $headers[str_replace(' ', '-',
                        ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
                }
            }
        }else{
            $headers = getallheaders();
        }
        return $headers;
    }

    private function setAuthorization($authorization = ''){
        file_put_contents($this->authorization_file,$authorization);
        $this->authorization = $authorization;
        return $this->authorization;
    }

    private function getAuthorization(){
        $this->authorization = file_get_contents($this->authorization_file) ?:'';
        return $this->authorization;
    }

    private function checkAuthorization(){
        $headers = $this->getHeaders();
        // file_put_contents("resp.txt",json_encode($headers).PHP_EOL, FILE_APPEND);
        if(!empty($headers['Authorization']) && $headers['Authorization'] != $this->getAuthorization())
            return $this->setAuthorization($headers['Authorization'] ?: '');
    }
}

