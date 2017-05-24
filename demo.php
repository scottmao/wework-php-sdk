<?php
/**
 * Created by PhpStorm.
 * User: scott mao
 * Date: 2017/5/18
 * Time: 10:46
 */
require_once('wework.class.php');

$options = [
    'corp_id' => 'ww75444785060a80bd',
    'secret' => 'fLyMGnSX1ScIMdWeZX-dut2bXA7YRdNqu6DgqlP2QpU',
    'debug' => TRUE,
];

$ww = new WeWork($options);

$msg = [
    'touser' => '@all',
    'msgtype' => 'text',
    'agentid' => 1000002,
    'text' => [
        'content' => 'test message. <a href="http://mim.cuifang.com">这是一个链接</a>',
    ],
];

var_dump($ww->send_msg($msg));

