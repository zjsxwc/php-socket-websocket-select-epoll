<?php
set_time_limit(0);
ini_set('memory_limit','500M');
error_reporting(0);
//require_once (__DIR__.'/ip_location/IP.php');
class EpollSocketServer{
	private static $socket;
	private static $connections;
	private static $buffers;
	private static $users=array();

	function EpollSocketServer ($port){
		global $errno, $errstr;
		if (!extension_loaded('libevent')) {
			die("Please install libevent extension firstly/n");
		}
		$socket_server = stream_socket_server("tcp://0.0.0.0:{$port}", $errno, $errstr);
		if (!$socket_server) die("$errstr ($errno)");

		stream_set_blocking($socket_server, 0); // 非阻塞
		$base = event_base_new();
		$event = event_new();
		event_set($event, $socket_server, EV_READ | EV_PERSIST, array(__CLASS__, 'ev_accept'), $base);
		event_base_set($event, $base);
		event_add($event);
		event_base_loop($base);

		self::$connections = array();
		self::$buffers = array();
	}
	function ev_accept($socket, $flag, $base){
		static $id = 0;
		$connection = stream_socket_accept($socket);
		stream_set_blocking($connection, 0);
		$id++; // increase on each accept
		
		$buffer = event_buffer_new($connection, array(__CLASS__, 'ev_read'), array(__CLASS__, 'ev_write'), array(__CLASS__, 'ev_error'), $id);
		event_buffer_base_set($buffer, $base);
		event_buffer_timeout_set($buffer, 300, 300);
		event_buffer_watermark_set($buffer, EV_READ, 0, 0xffffff);
		event_buffer_priority_set($buffer, 10);
		event_buffer_enable($buffer, EV_READ | EV_PERSIST);
		
		self::$connections[$id] = $connection;// we need to save both buffer and connection outside
		self::$buffers[$id] = $buffer;
	}
	function ev_error($buffer, $error, $id){
		$this->send_to_all(json_encode(array('type'=>'closed', 'id'=>$id)));
		event_buffer_disable(self::$buffers[$id], EV_READ | EV_WRITE);
		event_buffer_free(self::$buffers[$id]);
		fclose(self::$connections[$id]);
		unset(self::$buffers[$id], self::$connections[$id],self::$users[$id]);
	}	
	function get_online_count(){
		return count(self::$connections);
	}	
	function ev_read($buffer, $id){ 		
 		if(!self::$users[$id]['handshaked']){//首次连接进行握手
 			$read = event_buffer_read($buffer, 1024*5);//握手最多只读5k
 		    $tmp = str_replace("\r", '', $read);
            if (strpos($tmp, "\n\n") === false ) {
            	return; 
            }
 			$this->doHandshake($id, $read);
 			return;
 		}
 		$read = event_buffer_read($buffer, 500);
 		if (($message = $this->deframe($read, self::$users[$id],$id)) == FALSE) {
 			return;
 		}
 		if(self::$users[$id]['hasSentClose']) {
 			$this->ev_error(self::$buffers[$id],"Client disconnected. Sent close: " . $id, $id);
 		}
 		$message_data=trim($message);
		$message_data = json_decode($message_data, true);
		if(!$message_data){
			return ;
		}
		switch($message_data['type']){
			case 'login':
				self::send($id,'{"type":"welcome","id":"'.$id.'"}');
				break;
			case 'update':// 转播给所有用户
				self::send_to_all(json_encode(array(
					'type'     => 'update',
					'id'         => $id,
					'angle'   => $message_data["angle"]+0,
					'momentum' => $message_data["momentum"]+0,
					'x'                   => $message_data["x"]+0,
					'y'                   => $message_data["y"]+0,
					'life'                => 1,
					'name'           => isset($message_data['name']) ? $message_data['name'] : 'xkd-'.$id,
					'authorized'  => false))
				);
				break;
			case 'message':// 向大家说
				$new_message = array(
					'type'=>'message',
					'id'=>$id,
					'message'=>$message_data['message'],
				);
				return self::send_to_all(json_encode($new_message));
			default:
				break;
		}
	}
	protected function send($id,$message) {
		$message = $this->frame($message,self::$users[$id]);
		event_buffer_write(self::$buffers[$id],$message);
	}
	
	//广播
	function send_to_all($message){
		foreach (self::$buffers as $id => $buffer){
			$this->send($id,$message);
		}
	}	
	function ev_write($buffer, $id){}
	
	function doHandshake($id, $read) {
		$magicGUID = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11";
		$headers = array();
		$lines = explode("\n",$read);
		foreach ($lines as $line) {
			if (strpos($line,":") !== false) {
				$header = explode(":",$line,2);
				$headers[strtolower(trim($header[0]))] = trim($header[1]);
			}
			elseif (stripos($line,"get ") !== false) {
				preg_match("/GET (.*) HTTP/i", $read, $reqResource);
				$headers['get'] = trim($reqResource[1]);
			}
		}
		if (isset($headers['get'])) {
			self::$users[$id]['requestedResource']=$headers['get'];
		}
		else {
			// todo: fail the connection
			$handshakeResponse = "HTTP/1.1 405 Method Not Allowed\r\n\r\n";
		}
		if (!isset($headers['host']) || !$this->checkHost($headers['host'])) {
			$handshakeResponse = "HTTP/1.1 400 Bad Request";
		}
		if (!isset($headers['upgrade']) || strtolower($headers['upgrade']) != 'websocket') {
			$handshakeResponse = "HTTP/1.1 400 Bad Request";
		}
		if (!isset($headers['connection']) || strpos(strtolower($headers['connection']), 'upgrade') === FALSE) {
			$handshakeResponse = "HTTP/1.1 400 Bad Request";
		}
		if (!isset($headers['sec-websocket-key'])) {
			$handshakeResponse = "HTTP/1.1 400 Bad Request";
		}
		if (!isset($headers['sec-websocket-version']) || strtolower($headers['sec-websocket-version']) != 13) {
			$handshakeResponse = "HTTP/1.1 426 Upgrade Required\r\nSec-WebSocketVersion: 13";
		}
		if (($this->headerOriginRequired && !isset($headers['origin']) ) || ($this->headerOriginRequired && !$this->checkOrigin($headers['origin']))) {
			$handshakeResponse = "HTTP/1.1 403 Forbidden";
		}
		if (($this->headerSecWebSocketProtocolRequired && !isset($headers['sec-websocket-protocol'])) || ($this->headerSecWebSocketProtocolRequired && !$this->checkWebsocProtocol($headers['sec-websocket-protocol']))) {
			$handshakeResponse = "HTTP/1.1 400 Bad Request";
		}
		if (($this->headerSecWebSocketExtensionsRequired && !isset($headers['sec-websocket-extensions'])) || ($this->headerSecWebSocketExtensionsRequired && !$this->checkWebsocExtensions($headers['sec-websocket-extensions']))) {
			$handshakeResponse = "HTTP/1.1 400 Bad Request";
		}
	
		// Done verifying the _required_ headers and optionally required headers.
		if (isset($handshakeResponse)) {
			event_buffer_write(self::$buffers[$id],$handshakeResponse);
			$this->ev_error(self::$buffers[$id], '握手失败', $id);
			return;
		}
		self::$users[$id]['headers']=$headers;
		self::$users[$id]['handshake']=$read;
		$webSocketKeyHash = sha1($headers['sec-websocket-key'] . $magicGUID);
		
		$rawToken = "";
		for ($i = 0; $i < 20; $i++) {
			$rawToken .= chr(hexdec(substr($webSocketKeyHash,$i*2, 2)));
		}
		$handshakeToken = base64_encode($rawToken) . "\r\n";
	
		$subProtocol = (isset($headers['sec-websocket-protocol'])) ? $this->processProtocol($headers['sec-websocket-protocol']) : "";
		$extensions = (isset($headers['sec-websocket-extensions'])) ? $this->processExtensions($headers['sec-websocket-extensions']) : "";
	
		$handshakeResponse = "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: $handshakeToken$subProtocol$extensions\r\n";
		event_buffer_write(self::$buffers[$id],$handshakeResponse);
		
		self::$users[$id]['handshaked']=true;
	}
	protected function checkHost($hostName) {
		return true; 
		// Override and return false if the host is not one that you would expect.
		// Ex: You only want to accept hosts from the my-domain.com domain,
		// but you receive a host from malicious-site.com instead.
	}
	protected function processProtocol($protocol) {
		return ""; 
		// return either "Sec-WebSocket-Protocol: SelectedProtocolFromClientList\r\n" or return an empty string.
		// The carriage return/newline combo must appear at the end of a non-empty string, and must not
		// appear at the beginning of the string nor in an otherwise empty string, or it will be considered part of
		// the response body, which will trigger an error in the client as it will not be formatted correctly.
	}
	protected function processExtensions($extensions) {
		return ""; 
		// return either "Sec-WebSocket-Extensions: SelectedExtensions\r\n" or return an empty string.
	}
	protected function extractHeaders($message) {
		$header = array('fin'     => $message[0] & chr(128),
				'rsv1'    => $message[0] & chr(64),
				'rsv2'    => $message[0] & chr(32),
				'rsv3'    => $message[0] & chr(16),
				'opcode'  => ord($message[0]) & 15,
				'hasmask' => $message[1] & chr(128),
				'length'  => 0,
				'mask'    => "");
		$header['length'] = (ord($message[1]) >= 128) ? ord($message[1]) - 128 : ord($message[1]);
	
		if ($header['length'] == 126) {
			if ($header['hasmask']) {
				$header['mask'] = $message[4] . $message[5] . $message[6] . $message[7];
			}
			$header['length'] = ord($message[2]) * 256
			+ ord($message[3]);
		}
		elseif ($header['length'] == 127) {
			if ($header['hasmask']) {
				$header['mask'] = $message[10] . $message[11] . $message[12] . $message[13];
			}
			$header['length'] = ord($message[2]) * 65536 * 65536 * 65536 * 256
			+ ord($message[3]) * 65536 * 65536 * 65536
			+ ord($message[4]) * 65536 * 65536 * 256
			+ ord($message[5]) * 65536 * 65536
			+ ord($message[6]) * 65536 * 256
			+ ord($message[7]) * 65536
			+ ord($message[8]) * 256
			+ ord($message[9]);
		}
		elseif ($header['hasmask']) {
			$header['mask'] = $message[2] . $message[3] . $message[4] . $message[5];
		}
		return $header;
	}
	protected function frame($message, $user, $messageType='text', $messageContinues=false) {
		switch ($messageType) {
			case 'continuous':
				$b1 = 0;
				break;
			case 'text':
				$b1 = ($user['sendingContinuous']) ? 0 : 1;
				break;
			case 'binary':
				$b1 = ($user['sendingContinuous']) ? 0 : 2;
				break;
			case 'close':
				$b1 = 8;
				break;
			case 'ping':
				$b1 = 9;
				break;
			case 'pong':
				$b1 = 10;
				break;
		}
		if ($messageContinues) {
			$user['sendingContinuous'] = true;
		} else {
			$b1 += 128;
			$user['sendingContinuous'] = false;
		}
	
		$length = strlen($message);
		$lengthField = "";
		if ($length < 126) {
			$b2 = $length;
		} elseif ($length <= 65536) {
			$b2 = 126;
			$hexLength = dechex($length);
			if (strlen($hexLength)%2 == 1) {
				$hexLength = '0' . $hexLength;
			}
			$n = strlen($hexLength) - 2;
	
			for ($i = $n; $i >= 0; $i=$i-2) {
				$lengthField = chr(hexdec(substr($hexLength, $i, 2))) . $lengthField;
			}
			while (strlen($lengthField) < 2) {
				$lengthField = chr(0) . $lengthField;
			}
		} else {
			$b2 = 127;
			$hexLength = dechex($length);
			if (strlen($hexLength)%2 == 1) {
				$hexLength = '0' . $hexLength;
			}
			$n = strlen($hexLength) - 2;
	
			for ($i = $n; $i >= 0; $i=$i-2) {
				$lengthField = chr(hexdec(substr($hexLength, $i, 2))) . $lengthField;
			}
			while (strlen($lengthField) < 8) {
				$lengthField = chr(0) . $lengthField;
			}
		}
		return chr($b1) . chr($b2) . $lengthField . $message;
	}
	
	protected function deframe($message, &$user,$id=null) {
		//echo $this->strtohex($message);
		$headers = $this->extractHeaders($message);
		$pongReply = false;
		$willClose = false;
		switch($headers['opcode']) {
			case 0:
			case 1:
			case 2:
				break;
			case 8:
				// todo: close the connection
				$user['hasSentClose'] = true;
				return "";
			case 9:
				$pongReply = true;
			case 10:
				break;
			default:
				$willClose = true;
				break;
		}
	
		if ($user['handlingPartialPacket']) {
			$message = $user['partialBuffer'] . $message;
			$user['handlingPartialPacket'] = false;
			return $this->deframe($message, $user,$id);
		}
		if ($this->checkRSVBits($headers,$user)) {
			return false;
		}
		if ($willClose) {
			return false;
		}
		$payload = $user['partialMessage'] . $this->extractPayload($message,$headers);
	
		if ($pongReply) {
			$reply = $this->frame($payload,$user,'pong');
			event_buffer_write(self::$buffers[$id],$reply);
			return false;
		}
		if (extension_loaded('mbstring')) {
			if ($headers['length'] > mb_strlen($this->applyMask($headers,$payload))) {
				$user['handlingPartialPacket'] = true;
				$user['partialBuffer'] = $message;
				return false;
			}
		}
		else {
			if ($headers['length'] > strlen($this->applyMask($headers,$payload))) {
				$user['handlingPartialPacket'] = true;
				$user['partialBuffer'] = $message;
				return false;
			}
		}
	
		$payload = $this->applyMask($headers,$payload);
	
		if ($headers['fin']) {
			$user['partialMessage'] = "";
			return $payload;
		}
		$user['partialMessage'] = $payload;
		return false;
	}

	protected function extractPayload($message,$headers) {
		$offset = 2;
		if ($headers['hasmask']) {
			$offset += 4;
		}
		if ($headers['length'] > 65535) {
			$offset += 8;
		}
		elseif ($headers['length'] > 125) {
			$offset += 2;
		}
		return substr($message,$offset);
	}
	protected function checkRSVBits($headers,$user) { // override this method if you are using an extension where the RSV bits are used.
		if (ord($headers['rsv1']) + ord($headers['rsv2']) + ord($headers['rsv3']) > 0) {
			//$this->disconnect($user); // todo: fail connection
			return true;
		}
		return false;
	}
	protected function applyMask($headers,$payload) {
		$effectiveMask = "";
		if ($headers['hasmask']) {
			$mask = $headers['mask'];
		}
		else {
			return $payload;
		}
	
		while (strlen($effectiveMask) < strlen($payload)) {
			$effectiveMask .= $mask;
		}
		while (strlen($effectiveMask) > strlen($payload)) {
			$effectiveMask = substr($effectiveMask,0,-1);
		}
		return $effectiveMask ^ $payload;
	}
}
new EpollSocketServer(8866);
exit;
