<?php
require_once ('./IP.php');
function testIpLocation() {
	$this->assertEquals(['中国', '河南', '郑州', ''], Ip::find('1.192.94.203'));
	$this->assertEquals(['中国', '浙江', '杭州', ''], Ip::find('110.75.115.70'));
}