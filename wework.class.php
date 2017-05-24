<?php

/**
 * Created by PhpStorm.
 * User: scott
 * Date: 2017/5/18
 * Time: 10:14
 */
class WeWork {

    const API_URL_PREFIX = 'https://qyapi.weixin.qq.com/cgi-bin';

    //错误码
    const ERR_NO_TOKEN = 90001;
    const ERR_MSG_FORMAT = 90101;


    //每次调用相关
    private $corp_id;
    private $secret;
    private $access_token;

    private $is_debug_mode = FALSE;

    function __construct($options) {
        $this->corp_id = isset($options['corp_id']) ? $options['corp_id'] : '';
        $this->secret = isset($options['secret']) ? $options['secret'] : '';


        $this->is_debug_mode = isset($options['debug']) ? $options['debug'] : '';

        // 初始化Token
        $this->get_access_token();
    }

    /**
     * 获取access token
     * @return bool|string 当未能获取到access_token时，返回false
     */
    private function get_access_token() {
        $cache_dir = dirname(__FILE__) . '/cache/';
        if (!file_exists($cache_dir)) {
            mkdir($cache_dir, 0777, TRUE);
        }
        $cache_file = $cache_dir . $this->corp_id;
        if (file_exists($cache_file)) {
            $res = file_get_contents($cache_file);

            $res_arr = json_decode($res, TRUE);
            if (isset($res_arr['timestamp']) && $res_arr['timestamp'] < time()) {
                $this->access_token = $res_arr['access_token'];
                return $this->access_token;
            }
        }
        // 缓存文件不存在或Token过期
        $req_url = self::API_URL_PREFIX . '/gettoken?corpid=' . $this->corp_id . '&corpsecret=' . $this->secret;

        $now = time();
        $res = $this->http_get($req_url);
        $this->log(__FILE__ . ":" . __LINE__ . $res);

        $res_arr = json_decode($res, TRUE);

        if (isset($res_arr['errcode']) && $res_arr['errcode'] === 0) {
            $this->access_token = $res_arr['access_token'];
            // 缓存token数据
            file_put_contents($cache_file, json_encode([
                'access_token' => $res_arr['access_token'],
                'timestamp' => $now + $res_arr['expires_in']
            ]));
            return $this->access_token;
        }
        return FALSE;
    }

    /**
     * GET 请求
     * @param string $url
     * @return string content
     */
    private function http_get($url) {
        $oCurl = curl_init();
        if (stripos($url, "https://") !== FALSE) {
            curl_setopt($oCurl, CURLOPT_SSL_VERIFYPEER, FALSE);
            curl_setopt($oCurl, CURLOPT_SSL_VERIFYHOST, FALSE);
            curl_setopt($oCurl, CURLOPT_SSLVERSION, 1); //CURL_SSLVERSION_TLSv1
        }
        curl_setopt($oCurl, CURLOPT_URL, $url);
        curl_setopt($oCurl, CURLOPT_RETURNTRANSFER, 1);
        $sContent = curl_exec($oCurl);
        $aStatus = curl_getinfo($oCurl);
        curl_close($oCurl);
        if (intval($aStatus["http_code"]) == 200) {
            return $sContent;
        }
        else {
            return FALSE;
        }
    }

    /**
     * POST 请求
     * @param string $url
     * @param array $param
     * @param boolean $post_file 是否文件上传
     * @return string content
     */
    private function http_post($url, $param, $post_file = FALSE) {
        $oCurl = curl_init();
        if (stripos($url, "https://") !== FALSE) {
            curl_setopt($oCurl, CURLOPT_SSL_VERIFYPEER, FALSE);
            curl_setopt($oCurl, CURLOPT_SSL_VERIFYHOST, FALSE);
            curl_setopt($oCurl, CURLOPT_SSLVERSION, 1); //CURL_SSLVERSION_TLSv1
        }
        if (is_string($param) || $post_file) {
            $strPOST = $param;
        }
        else {
            $aPOST = array();
            foreach ($param as $key => $val) {
                $aPOST[] = $key . "=" . urlencode($val);
            }
            $strPOST = join("&", $aPOST);
        }
        curl_setopt($oCurl, CURLOPT_URL, $url);
        curl_setopt($oCurl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($oCurl, CURLOPT_POST, TRUE);

        curl_setopt($oCurl, CURLOPT_POSTFIELDS, $strPOST);
        $sContent = curl_exec($oCurl);
        $aStatus = curl_getinfo($oCurl);
        curl_close($oCurl);
        if (intval($aStatus["http_code"]) == 200) {
            return $sContent;
        }
        else {
            return FALSE;
        }
    }

    protected function log($msg) {
        if ($this->is_debug_mode !== FALSE) {
            echo date('Y-m-d h:i:s', time()) . "\t[DEBUG]\t" . $msg . "\n";
        }
    }

    /**
     * 通用返回结果数组
     * @param int $errcode 错误码
     * @param string $msg 错误描述
     * @param array $data 相关的数据
     * @return array 结果数组
     */
    private function result($errcode = 0, $msg = 'ok', $data = []) {
        return [
            'errcode' => $errcode,
            'msg' => $msg,
            'data' => $data,
        ];
    }

    /**
     * 发消息
     * @param array $msg_arr 数组格式的消息
     * @return array|bool|mixed
     */
    public function send_msg($msg_arr) {
        if (!isset($this->access_token) && !$this->get_access_token()) {
            return $this->result(self::ERR_NO_TOKEN, '没有获取到Token');
        }
        $req_url = self::API_URL_PREFIX . '/message/send?access_token=' . $this->access_token;

        if ($this->check_msg($msg_arr)) {
            $res = $this->http_post($req_url, json_encode($msg_arr));
            $this->log(__FILE__ . ":" . __LINE__ . $res);

            $res_arr = json_decode($res, TRUE);

            return $res_arr;
        }
        else {
            return $this->result(self::ERR_MSG_FORMAT, '消息格式有问题');
        }
    }

    /**
     * 验证消息数组是否符合要求，主要对必填字段和消息类型进行判断
     * @param array $msg_arr 消息体
     * @return bool 是否是标准的消息格式
     */
    private function check_msg($msg_arr) {
        // 检查必填项：touser, toparty, totag, msgtype, agentid
        if (!isset($msg_arr['msgtype']) || !isset($msg_arr[$msg_arr['msgtype']]) || !isset($msg_arr['agentid']) ||
            (!isset($msg_arr['agentid']) && !isset($msg_arr['toparty']) && isset($msg_arr['totag']))
        ) {
            return FALSE;
        }

        // 检查消息类型
        $msgtype = [
            'image',
            'text',
            'voice',
            'video',
            'file',
            'textcard',
            'news',
            'mpnews'
        ];
        if (!in_array($msg_arr['msgtype'], $msgtype)) {
            return FALSE;
        }
        return TRUE;
    }

    // 菜单相关
    public function create_menu($menu_arr) {
        return FALSE;
    }

    // 用户相关
    /**
     * 根据用户的ticket来获取用户详细信息
     * @param string $user_ticket 通过
     * @return array 能用结果，用户信息在data下
     */
    public function get_user_detail($user_ticket) {
        if (!isset($this->access_token) && !$this->get_access_token()) {
            return $this->result(self::ERR_NO_TOKEN, '没有获取到Token');
        }
        $req_url = self::API_URL_PREFIX . '/user/getuserdetail?access_token=' . $this->access_token;

        $res = $this->http_post($req_url, ['user_ticket' => $user_ticket]);
        $this->log(__FILE__ . ":" . __LINE__ . $res);

        $res_arr = json_decode($res, TRUE);

        return $this->result(0, 'ok', $res_arr);
    }

    // 认证相关
    /**
     * 生成企业微信中的应用oauth认证界面
     * @param string $redirect_url url_encode的URL
     * @param string $scope 应用授权作用域。snsapi_base：静默授权，可获取成员的基础信息；snsapi_userinfo：静默授权，可获取成员的详细信息，但不包含手机、邮箱；snsapi_privateinfo：手动授权，可获取成员的详细信息，包含手机、邮箱。
     * @return string 返回OAUTH的URL, 用于页面直接跳转
     */
    public function get_app_oauth_url($redirect_url, $scope) {
        $url = 'https://open.weixin.qq.com/connect/oauth2/authorize?appid='
            . $this->corp_id . '&redirect_uri=' . $redirect_url
            . '&response_type=code&scope=' . $scope . '&agentid=1000002&state=STATE#wechat_redirect';

        return $url;
    }

    /**
     * 根据URL中的code获取用户名
     * @param string $code
     * @return string|bool 用户名，失败为False
     */
    public function get_user_name($code = '') {
        if (!isset($this->access_token) && !$this->get_access_token()) {
            return $this->result(self::ERR_NO_TOKEN, '没有获取到Token');
        }

        if (empty($code) && isset($_GET['code'])) {
            $code = $_GET['code'];
        }
        $req_url = self::API_URL_PREFIX . '/user/getuserinfo?access_token=' . $this->access_token . '&code=' . $code;
        $res = http_get($req_url);
        $res_arr = json_decode($res, TRUE);

        if (isset($res_arr['errcode']) && $res_arr['errcode'] === 0) {
            $username = $res_arr['UserId'];
            return $username;
        }

        return FALSE;
    }
}

/**
 * Class ErrCodeWeWork 企业微信的全局错误信息
 *
 */
class ErrCodeWeWork {
    static $err_map = [
        '-1' => '系统繁忙',
        '0' => '请求成功',
        '40001' => '获取access_token时Secret错误，或者access_token无效',
        '40002' => '不合法的凭证类型',
        '40003' => '不合法的UserID',
        '40004' => '不合法的媒体文件类型',
        '40005' => '不合法的文件类型',
        '40006' => '不合法的文件大小',
        '40007' => '不合法的媒体文件id',
        '40008' => '不合法的消息类型',
        '40013' => '不合法的corpid',
        '40014' => '不合法的access_token',
        '40015' => '不合法的菜单类型',
        '40016' => '不合法的按钮个数',
        '40017' => '不合法的按钮类型',
        '40018' => '不合法的按钮名字长度',
        '40019' => '不合法的按钮KEY长度',
        '40020' => '不合法的按钮URL长度',
        '40021' => '不合法的菜单版本号',
        '40022' => '不合法的子菜单级数',
        '40023' => '不合法的子菜单按钮个数',
        '40024' => '不合法的子菜单按钮类型',
        '40025' => '不合法的子菜单按钮名字长度',
        '40026' => '不合法的子菜单按钮KEY长度',
        '40027' => '不合法的子菜单按钮URL长度',
        '40028' => '不合法的自定义菜单使用成员',
        '40029' => '不合法的oauth_code',
        '40031' => '不合法的UserID列表',
        '40032' => '不合法的UserID列表长度',
        '40033' => '不合法的请求字符，不能包含\uxxxx格式的字符',
        '40035' => '不合法的参数',
        '40038' => '不合法的请求格式',
        '40039' => '不合法的URL长度',
        '40040' => '不合法的插件token',
        '40041' => '不合法的插件id',
        '40042' => '不合法的插件会话',
        '40048' => 'url中包含不合法domain',
        '40054' => '不合法的子菜单url域名',
        '40055' => '不合法的按钮url域名',
        '40056' => '不合法的agentid',
        '40057' => '不合法的callbackurl或者callbackurl验证失败',
        '40058' => '不合法的红包参数',
        '40059' => '不合法的上报地理位置标志位',
        '40060' => '设置上报地理位置标志位时没有设置callbackurl',
        '40061' => '设置应用头像失败',
        '40062' => '不合法的应用模式',
        '40063' => '参数为空',
        '40064' => '管理组名字已存在',
        '40065' => '不合法的管理组名字长度',
        '40066' => '不合法的部门列表',
        '40067' => '标题长度不合法',
        '40068' => '不合法的标签ID',
        '40069' => '不合法的标签ID列表',
        '40070' => '列表中所有标签（成员）ID都不合法',
        '40071' => '不合法的标签名字，标签名字已经存在',
        '40072' => '不合法的标签名字长度',
        '40073' => '不合法的openid',
        '40074' => 'news消息不支持指定为高保密消息',
        '40077' => '不合法的预授权码',
        '40078' => '不合法的临时授权码',
        '40079' => '不合法的授权信息',
        '40080' => '不合法的suitesecret',
        '40082' => '不合法的suitetoken',
        '40083' => '不合法的suiteid',
        '40084' => '不合法的永久授权码',
        '40085' => '不合法的suiteticket',
        '40086' => '不合法的第三方应用appid',
        '40092' => '导入文件存在不合法的内容',
        '40093' => '不合法的跳转target',
        '40094' => '不合法的URL',
        '40095' => '修改失败，并发冲突',
        '40155' => '请勿添加其他公众号的主页链接',
        '41001' => '缺少access_token参数',
        '41002' => '缺少corpid参数',
        '41003' => '缺少refresh_token参数',
        '41004' => '缺少secret参数',
        '41005' => '缺少多媒体文件数据',
        '41006' => '缺少media_id参数',
        '41007' => '缺少子菜单数据',
        '41008' => '缺少oauth code',
        '41009' => '缺少UserID',
        '41010' => '缺少url',
        '41011' => '缺少agentid',
        '41012' => '缺少应用头像mediaid',
        '41013' => '缺少应用名字',
        '41014' => '缺少应用描述',
        '41015' => '缺少Content',
        '41016' => '缺少标题',
        '41017' => '缺少标签ID',
        '41018' => '缺少标签名字',
        '41021' => '缺少suiteid',
        '41022' => '缺少suitetoken',
        '41023' => '缺少suiteticket',
        '41024' => '缺少suitesecret',
        '41025' => '缺少永久授权码',
        '41034' => '缺少login_ticket',
        '41035' => '缺少跳转target',
        '42001' => 'access_token过期',
        '42002' => 'refresh_token过期',
        '42003' => 'oauth_code过期',
        '42004' => '插件token过期',
        '42007' => '预授权码失效',
        '42008' => '临时授权码失效',
        '42009' => 'suitetoken失效',
        '43001' => '需要GET请求',
        '43002' => '需要POST请求',
        '43003' => '需要HTTPS',
        '43004' => '需要成员已关注',
        '43005' => '需要好友关系',
        '43006' => '需要订阅',
        '43007' => '需要授权',
        '43008' => '需要支付授权',
        '43010' => '需要处于接收消息模式',
        '43011' => '需要企业授权',
        '43013' => '应用对成员不可见',
        '44001' => '多媒体文件为空',
        '44002' => 'POST的数据包为空',
        '44003' => '图文消息内容为空',
        '44004' => '文本消息内容为空',
        '45001' => '多媒体文件大小超过限制',
        '45002' => '消息内容大小超过限制',
        '45003' => '标题大小超过限制',
        '45004' => '描述大小超过限制',
        '45005' => '链接长度超过限制',
        '45006' => '图片链接长度超过限制',
        '45007' => '语音播放时间超过限制',
        '45008' => '图文消息的文章数量不能超过8条',
        '45009' => '接口调用超过限制',
        '45010' => '创建菜单个数超过限制',
        '45015' => '回复时间超过限制',
        '45016' => '系统分组，不允许修改',
        '45017' => '分组名字过长',
        '45018' => '分组数量超过上限',
        '45022' => '应用名字长度不合法，合法长度为2-16个字',
        '45024' => '帐号数量超过上限',
        '45025' => '同一个成员每周只能邀请一次',
        '45026' => '触发删除用户数的保护',
        '45027' => 'mpnews每天只能发送100次',
        '45028' => '素材数量超过上限',
        '45029' => 'media_id对该应用不可见',
        '45032' => '作者名字长度超过限制',
        '46001' => '不存在媒体数据',
        '46002' => '不存在的菜单版本',
        '46003' => '不存在的菜单数据',
        '46004' => '不存在的成员',
        '47001' => '解析JSON/XML内容错误',
        '48001' => 'Api未授权',
        '48002' => 'Api禁用(一般是管理组类型与Api不匹配，例如普通管理组调用会话服务的Api)',
        '48003' => 'suitetoken无效',
        '48004' => '授权关系无效',
        '48005' => 'Api已废弃',
        '50001' => 'redirect_uri未授权',
        '50002' => '成员不在权限范围',
        '50003' => '应用已停用',
        '50004' => '成员状态不正确，需要成员为企业验证中状态',
        '50005' => '企业已禁用',
        '60001' => '部门长度不符合限制',
        '60002' => '部门层级深度超过限制',
        '60003' => '部门不存在',
        '60004' => '父部门不存在',
        '60005' => '不允许删除有成员的部门',
        '60006' => '不允许删除有子部门的部门',
        '60007' => '不允许删除根部门',
        '60008' => '部门ID或者部门名称已存在',
        '60009' => '部门名称含有非法字符',
        '60010' => '部门存在循环关系',
        '60011' => '权限不足，user/department/agent无权限(只有通迅录同步助手才有通迅录写权限，同时要开启写权限)',
        '60012' => '不允许删除默认应用',
        '60013' => '不允许关闭应用',
        '60014' => '不允许开启应用',
        '60015' => '不允许修改默认应用可见范围',
        '60016' => '不允许删除存在成员的标签',
        '60017' => '不允许设置企业',
        '60019' => '不允许设置应用地理位置上报开关',
        '60020' => '访问ip不在白名单之中',
        '60023' => '已授权的应用不允许企业管理组调用接口修改菜单',
        '60025' => '主页型应用不支持的消息类型',
        '60027' => '不支持第三方修改主页型应用字段',
        '60028' => '应用已授权予第三方，不允许通过接口修改主页url',
        '60029' => '应用已授权予第三方，不允许通过接口修改可信域名',
        '60031' => '未设置管理组的登录授权域名',
        '60102' => 'UserID已存在',
        '60103' => '手机号码不合法',
        '60104' => '手机号码已存在',
        '60105' => '邮箱不合法',
        '60106' => '邮箱已存在',
        '60107' => '微信号不合法',
        '60108' => '微信号已存在',
        '60109' => 'QQ号已存在',
        '60110' => '用户同时归属部门超过20个',
        '60111' => 'UserID不存在',
        '60112' => '成员姓名不合法',
        '60113' => '身份认证信息（微信号/手机/邮箱）不能同时为空',
        '60114' => '性别不合法',
        '60115' => '已关注成员微信不能修改',
        '60116' => '扩展属性已存在',
        '60118' => '成员无有效邀请字段，详情参考(邀请成员关注)的接口说明',
        '60119' => '成员已关注',
        '60120' => '成员已禁用',
        '60121' => '找不到该成员',
        '60122' => '邮箱已被外部管理员使用',
        '60123' => '无效的部门id',
        '60124' => '无效的父部门id',
        '60125' => '非法部门名字，长度超过限制、重名等，重名包括与csv文件中同级部门重名或者与旧组织架构包含成员的同级部门重名',
        '60126' => '创建部门失败',
        '60127' => '缺少部门id',
        '60128' => '字段不合法，可能存在主键冲突或者格式错误',
        '60129' => '用户设置了拒绝邀请',
        '60131' => '不合法的职位长度',
        '80001' => '可信域名不匹配，或者可信域名没有IPC备案（后续将不能在该域名下正常使用jssdk）',
        '81003' => '邀请额度已用完',
        '81004' => '部门数量超过上限',
        '82001' => '发送消息或者邀请的参数全部为空或者全部不合法',
        '82002' => '不合法的PartyID列表长度',
        '82003' => '不合法的TagID列表长度',
        '82004' => '微信版本号过低',
        '85002' => '包含不合法的词语',
        '86001' => '不合法的会话ID',
        '86003' => '不存在的会话ID',
        '86004' => '不合法的会话名',
        '86005' => '不合法的会话管理员',
        '86006' => '不合法的成员列表大小',
        '86007' => '不存在的成员',
        '86101' => '需要会话管理员权限',
        '86201' => '缺少会话ID',
        '86202' => '缺少会话名',
        '86203' => '缺少会话管理员',
        '86204' => '缺少成员',
        '86205' => '非法的会话ID长度',
        '86206' => '非法的会话ID数值',
        '86207' => '会话管理员不在用户列表中',
        '86208' => '消息服务未开启',
        '86209' => '缺少操作者',
        '86210' => '缺少会话参数',
        '86211' => '缺少会话类型（单聊或者群聊）',
        '86213' => '缺少发件人',
        '86214' => '非法的会话类型',
        '86215' => '会话已存在',
        '86216' => '非法会话成员',
        '86217' => '会话操作者不在成员列表中',
        '86218' => '非法会话发件人',
        '86219' => '非法会话收件人',
        '86220' => '非法会话操作者',
        '86221' => '单聊模式下，发件人与收件人不能为同一人',
        '86222' => '不允许消息服务访问的API',
        '86304' => '不合法的消息类型',
        '86305' => '客服服务未启用',
        '86306' => '缺少发送人',
        '86307' => '缺少发送人类型',
        '86308' => '缺少发送人id',
        '86309' => '缺少接收人',
        '86310' => '缺少接收人类型',
        '86311' => '缺少接收人id',
        '86312' => '缺少消息类型',
        '86313' => '缺少客服，发送人或接收人类型，必须有一个为kf',
        '86314' => '客服不唯一，发送人或接收人类型，必须只有一个为kf',
        '86315' => '不合法的发送人类型',
        '86316' => '不合法的发送人id。Userid不存在、openid不存在、kf不存在',
        '86317' => '不合法的接收人类型',
        '86318' => '不合法的接收人id。Userid不存在、openid不存在、kf不存在',
        '86319' => '不合法的客服，kf不在客服列表中',
        '86320' => '不合法的客服类型',
        '88001' => '缺少seq参数',
        '88002' => '缺少offset参数',
        '88003' => '非法seq',
        '90001' => '未认证摇一摇周边',
        '90002' => '缺少摇一摇周边ticket参数',
        '90003' => '摇一摇周边ticket参数不合法',
        '90004' => '摇一摇周边ticket过期',
        '90005' => '未开启摇一摇周边服务',
        '91004' => '卡券已被核销',
        '91011' => '无效的code',
        '91014' => '缺少卡券详情',
        '91015' => '代金券缺少least_cost或者reduce_cost参数',
        '91016' => '折扣券缺少discount参数',
        '91017' => '礼品券缺少gift参数',
        '91019' => '缺少卡券sku参数',
        '91020' => '缺少卡券有效期',
        '91021' => '缺少卡券有效期类型',
        '91022' => '缺少卡券logo_url',
        '91023' => '缺少卡券code类型',
        '91025' => '缺少卡券title',
        '91026' => '缺少卡券color',
        '91027' => '缺少offset参数',
        '91028' => '缺少count参数',
        '91029' => '缺少card_id',
        '91030' => '缺少卡券code',
        '91031' => '缺少卡券notice',
        '91032' => '缺少卡券description',
        '91033' => '缺少ticket类型',
        '91036' => '不合法的有效期',
        '91038' => '变更库存值不合法',
        '91039' => '不合法的卡券id',
        '91040' => '不合法的ticket type',
        '91041' => '没有创建，上传卡券logo，以及核销卡券的权限',
        '91042' => '没有该卡券投放权限',
        '91043' => '没有修改或者删除该卡券的权限',
        '91044' => '不合法的卡券参数',
        '91045' => '缺少团购券groupon结构',
        '91046' => '缺少现金券cash结构',
        '91047' => '缺少折扣券discount 结构',
        '91048' => '缺少礼品券gift结构',
        '91049' => '缺少优惠券coupon结构',
        '91050' => '缺少卡券必填字段',
        '91051' => '商户名称超过12个汉字',
        '91052' => '卡券标题超过9个汉字',
        '91053' => '卡券提醒超过16个汉字',
        '91054' => '卡券描述超过1024个汉字',
        '91055' => '卡券副标题长度超过18个汉字',

        '301001' => '应用id已存在',
        '301002' => 'accesstoken不允许操作其它应用。',
        '301004' => '不允许删除超级管理员',
        '301005' => '消息型应用不允许做此操作',
        '301006' => '不允许禁用超级管理员',
        '301008' => '主页型应用不允许做此操作',
        '301009' => '应用发送消息没有接收主体',
        '301010' => '部门名已存在',
        '301013' => '座机不合法',
        '301014' => '英文名称不合法',
        '301021' => 'userid错误',
        '301022' => '获取打卡数据失败',
        '301023' => 'useridlist非法或超过限额',
        '301024' => '获取打卡记录时间间隔超限',

        '302001' => '批量同步成员存在userid为空的用户',
        '302002' => '管理员userid不存在',
        '302003' => '存在重复的userid',
        '302004' => '（1不是一棵树，2 多个一样的partyid，3 partyid空，4 partyid name 空，5 同一个父节点下有两个子节点 部门名字一样 可能是以上情况，请一一排查）'
    ];

    public static function get_err_desc($errcode) {
        return isset($err_map[$errcode]) ? $err_map[$errcode] : 'Not Found';
    }
}
