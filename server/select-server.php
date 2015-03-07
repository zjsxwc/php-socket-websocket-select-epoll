<?php
set_time_limit(0);
ini_set('memory_limit','500M');
error_reporting(0);
require_once (__DIR__.'/websockets.php');
require_once (__DIR__.'/ip_location/IP.php');

class TTTServer extends WebSocketServer {
	protected $interactive = false;
	protected function process($user, $message) {
		$message_data = json_decode ( trim ( $message ), true );
		if(!$message_data){
			return;
		}
		switch ($message_data ['type']) {
			case 'login' :
				$this->send ( $user, '{"type":"welcome","id":"' . $user->id . '"}' );
				break;
			case 'update' : // 转播给所有用户
				$this->broadcast ( json_encode ( array (
						'type' => 'update',
						'id' => $user->id,
						'angle' => $message_data ["angle"] + 0,
						'momentum' => $message_data ["momentum"] + 0,
						'x' => $message_data ["x"] + 0,
						'y' => $message_data ["y"] + 0,
						'life' => 1,
						'name' => isset ( $message_data ['name'] ) ? $message_data ['name'] : $user->ip,
						'authorized' => false 
				) ) );
				break;
			case 'message' : // 向大家说
				$new_message = array (
						'type' => 'message',
						'id' => $user->id,
						'message' => $message_data ['message'] 
				);
				$this->broadcast ( json_encode ( $new_message ) );
				break;
			default :
				//$this->send ( $user, $message );
				break;
		}
	}
	// Do nothing: This is just an echo server, there's no need to track the user.
	// However, if we did care about the users, we would probably have a cookie to
	// parse at this step, would be looking them up in permanent storage, etc.
	protected function connected($user) {
		socket_getpeername ($user->socket,$user->ip);//save the client ip
		$ip=Ip::find($user->ip);
		$user->ip=$ip[1].$ip[2].'-'.date('H:i:s');
		//file_put_contents(__DIR__.'/log.log', serialize($ip));
		//exit;
	}
	
	protected function closed($user) {		
       	$this->broadcast(json_encode(array('type'=>'closed', 'id'=>$user->id)));
	}
	protected function broadcast($message){
		foreach($this->users as $id => $user){
			$this->send ($user, $message );
		}
	}
}

$echo = new TTTServer ( "0.0.0.0", "8866" );
try {
	$echo->run ();
} catch ( Exception $e ) {
	$echo->stdout ( $e->getMessage () );
}
