<?php

namespace Telegram;
use Telegram\Elements; // TODO

class Sender {
	private $parent;
	public $bot;
	private $content = array();
	private $method = NULL;
	private $broadcast = NULL;
	private $language = "en";
	public  $convert_emoji = TRUE; // Default
	private $_keyboard;
	private $_inline;
	private $_payment;
	private $_sticker;

	function __construct($uid = NULL, $key = NULL, $name = NULL){
		$this->_keyboard = new \Telegram\Keyboards\Keyboard($this);
		$this->_inline = new \Telegram\Keyboards\InlineKeyboard($this);
		$this->_payment = new \Telegram\Payments\Stripe($this);
		$this->_sticker = new \Telegram\Sticker($this);

		if(!empty($uid)){
			if($uid instanceof Receiver){
				$this->parent = $uid;
				$this->bot = $this->parent->bot;
				$this->language = $this->parent->language;
			}elseif($uid instanceof Bot){
				$this->bot = $uid;
			}else{
				$this->set_access($uid, $key, $name);
			}
		}
	}

	function set_access($uid, $key = NULL, $name = NULL){
		$this->bot = new \Telegram\Bot($uid, $key, $name);
		return $this;
	}

	function chat($id = NULL){
		if(empty($id)){
			if(isset($this->content['chat_id'])){ return $this->content['chat_id']; }
			$id = TRUE; // HACK ?
		}
		if($id === TRUE && $this->parent instanceof \Telegram\Receiver){ $id = $this->parent->chat->id; }
		$this->content['chat_id'] = $id;
		return $this;
	}

	function chats($ids){
		if(empty($ids)){ return $this; } // HACK
		$this->broadcast = $ids;
		$this->content['chat_id'] = $ids[0]; // HACK
		return $this;
	}

	function user($id = NULL){
		if(empty($id)){ return $this->content['user_id']; }
		elseif($id === TRUE){ $id = $this->parent->user->id; }
		$this->content['user_id'] = $id;
		return $this;
	}

	function message($id = NULL){
		if(empty($id)){ return $this->content['message_id']; }
		if($id === TRUE && $this->parent instanceof \Telegram\Receiver){ $id = $this->parent->message; }
		elseif(is_array($id) and isset($id['message_id'])){ $id = $id['message_id']; } // JSON Response from another message.
		$this->content['message_id'] = $id;
		return $this;
	}

	function get_file($id){
		$this->method = "getFile";
		$this->content['file_id'] = $id;
		return $this->send();
	}

	function file($type, $file, $caption = NULL, $keep = FALSE){
		if(!in_array($type, ["photo", "chatphoto", "audio", "voice", "document", "sticker", "video", "video_note", "videonote"])){ return FALSE; }

		$url = FALSE;
		if(filter_var($file, FILTER_VALIDATE_URL) !== FALSE){
			// ES URL, descargar y enviar.
			$url = TRUE;
			$tmp = tempnam("/tmp", "telegram") .substr($file, -4); // .jpg
			file_put_contents($tmp, fopen($file, 'r'));
			$file = $tmp;
		}

		$this->method = "send" .ucfirst(strtolower($type));
		if(in_array($type, ["videonote", "video_note"])){
			$type = "video_note";
			$this->method = "sendVideoNote";
		}elseif($type == "chatphoto"){
			$type = "photo";
			$this->method = "sendChatPhoto";
		}
		if(file_exists(realpath($file))){
			$this->content[$type] = new \CURLFile(realpath($file));
		}else{
			$this->content[$type] = $file;
		}
		if($caption === NULL && isset($this->content['text'])){
			$caption = $this->content['text'];
			unset($this->content['text']);
		}
		if($caption !== NULL){
			$key = "caption";
			if($type == "audio"){ $key = "title"; }
			$this->content[$key] = $caption;
		}

		$output = $this->send("POSTKEEP");
		if($url === TRUE){ unlink($file); }
		if($keep === FALSE){ $this->_reset(); }
		$json = json_decode($output, TRUE);
		if($json){ return $json['result']; }
		return $output;
		// return $this;
	}

	function location($lat, $lon = NULL){
		if(is_array($lat) && $lon == NULL){ $lon = $lat[1]; $lat = $lat[0]; }
		elseif(is_string($lat) && strpos($lat, ",") !== FALSE){
			$lat = explode(",", $lat);
			$lon = trim($lat[1]);
			$lat = trim($lat[0]);
		}
		$this->content['latitude'] = $lat;
		$this->content['longitude'] = $lon;
		$this->method = "sendLocation";
		return $this;
	}

	function venue($title, $address, $foursquare = NULL){
		if(isset($this->content['latitude']) && isset($this->content['longitude'])){
			$this->content['title'] = $title;
			$this->content['address'] = $address;
			if(!empty($foursquare)){ $this->content['foursquare_id'] = $foursquare; }
			$this->method = "sendVenue";
		}
		return $this;
	}

	function dump($user){
		var_dump($this->method); var_dump($this->content);
		$bm = $this->method;
		$bc = $this->content;

		$this->_reset();
		$this
			->chat($user)
			->text(json_encode($bc))
		->send();
		$this->method = $bm;
		$this->content = $bc;
		return $this;
	}

	function contact($phone, $first_name, $last_name = NULL){
		$this->content['phone_number'] = $phone;
		$this->content['first_name'] = $first_name;
		if(!empty($last_name)){ $this->content['last_name'] = $last_name; }
		$this->method = "sendContact";
		return $this;
	}

	function language($set){
		$this->language = $set;
		return $this;
	}

	function text($text, $type = NULL){
		if(is_array($text)){
			if(isset($text[$this->language])){
				$text = $text[$this->language];
			}elseif(isset($text["en"])){
				$text = $text["en"];
			}else{
				$text = current($text); // First element.
			}
		}

		if($this->convert_emoji){ $text = $this->parent->emoji($text); }
		$this->content['text'] = $text;
		$this->method = "sendMessage";
		if($type === TRUE){ $this->content['parse_mode'] = 'Markdown'; }
		elseif(in_array($type, ['Markdown', 'HTML'])){ $this->content['parse_mode'] = $type; }
		elseif($text != strip_tags($text)){ $this->content['parse_mode'] = 'HTML'; } // Autodetect HTML.

		return $this;
	}

	function text_replace($text, $replace, $type = NULL){
		if(is_array($text)){
			if(isset($text[$this->language])){
				$text = $text[$this->language];
			}elseif(isset($text["en"])){
				$text = $text["en"];
			}else{
				$text = current($text); // First element.
			}
		}

		if(strpos($text, "%s") !== FALSE){
			if(!is_array($replace)){ $replace = [$replace]; }
			$pos = 0;
			foreach($replace as $r){
				$pos = strpos($text, "%s", $pos);
				if($pos === FALSE){ break; }
				$text = substr_replace($text, $r, $pos, 2); // 2 = strlen("%s")
			}
		}else{
			$text = str_replace(array_keys($replace), array_values($replace), $text);
		}

		return $this->text($text, $type);
	}

	function keyboard(){ return $this->_keyboard; }
	function inline_keyboard(){ return $this->_inline; }
	function payment($provider = "Stripe"){
		$this->_payment = new \Telegram\Payments\Stripe($this);
		return $this->_payment;
	}
	function sticker($id = NULL){
		if(!empty($id)){ return $this->file('sticker', $id); }
		return $this->_sticker;
	}

	function payment_precheckout($id, $ok = TRUE){
		$this->content['pre_checkout_query_id'] = $id;
		if($ok === TRUE){
			$this->content['ok'] = TRUE;
		}else{
			$this->content['ok'] = FALSE;
			$this->content['error_message'] = $ok;
		}

		$this->method = "answerPreCheckoutQuery";
		return $this->send();
	}

	function force_reply($selective = TRUE){
		$this->content['reply_markup'] = ['force_reply' => TRUE, 'selective' => $selective];
		return $this;
	}

	function caption($text){
		$this->content['caption'] = $text;
		return $this;
	}

	function disable_web_page_preview($value = FALSE){
		if($value === TRUE){ $this->content['disable_web_page_preview'] = TRUE; }
		return $this;
	}

	function notification($value = TRUE){
		if($value === FALSE){ $this->content['disable_notification'] = TRUE; }
		else{ if(isset($this->content['disable_notification'])){ unset($this->content['disable_notification']); } }
		return $this;
	}

	function reply_to($message_id = NULL){
		if(is_bool($message_id) && $this->parent instanceof Receiver){
			if($message_id === TRUE or ($message_id === FALSE && !$this->parent->has_reply)){ $message_id = $this->parent->message; }
			elseif($message_id === FALSE){
				if(!$this->parent->has_reply){ return; }
				$message_id = $this->parent->reply->message_id;
			}
		}
		$this->content['reply_to_message_id'] = $message_id;
		return $this;
	}

	function forward_to($chat_id_to){
		if(empty($this->chat()) or empty($this->content['message_id'])){ return $this; }
		$this->content['from_chat_id'] = $this->chat();
		$this->chat($chat_id_to);
		$this->method = "forwardMessage";

		return $this;
	}

	function chat_action($type){
		$actions = [
			'typing', 'upload_photo', 'record_video', 'upload_video', 'record_audio', 'upload_audio',
			'upload_document', 'find_location', 'record_video_note', 'upload_video_note'
		];
		if(!in_array($type, $actions)){ $type = $actions[0]; } // Default is typing
		$this->content['action'] = $type;
		$this->method = "sendChatAction";
		return $this;
	}

	function until_date($until){
		if(!is_numeric($until) and strtotime($until) !== FALSE){ $until = strtotime($until); }
		$this->content['until_date'] = $until;
		return $this;
	}

	function kick($user = NULL, $chat = NULL, $keep = FALSE){
				$this->ban($user, $chat, $keep);
		return  $this->unban($user, $chat, $keep);
	}

	function restrict($option = NULL, $user = NULL, $chat = NULL){
		if(!empty($option) and strpos($option, "can_") === FALSE){ $option = "can_" . strtolower($option); }
		$this->method = "restrictChatMember";

		/* send_messages, send_media_messages,
		send_other_messages, add_web_page_previews */

		if($option == "can_none"){ // restrict none
			$this->content['can_send_other_messages'] = TRUE;
			$this->content['can_add_web_page_previews'] = TRUE;
		}elseif($option == "can_all"){ // restrict all = ban
			return $this->ban($user, $chat);
		}elseif(!empty($option)){
			$this->content[$option] = TRUE;
		}

		return $this->send();
	}

	function restrict_until($until, $option = NULL, $user = NULL, $chat = NULL){
		return $this
			->until_date($until)
			->restrict($option, $user, $chat);
	}

	function ban_until($until, $user = NULL, $chat = NULL, $keep = FALSE){
		return $this
			->until_date($until)
			->ban($user, $chat, $keep);
	}

	function ban($user = NULL, $chat = NULL, $keep = FALSE){ return $this->_parse_generic_chatFunctions("kickChatMember", $keep, $chat, $user); }
	function unban($user = NULL, $chat = NULL, $keep = FALSE){ return $this->_parse_generic_chatFunctions("unbanChatMember", $keep, $chat, $user); }
	function leave_chat($chat = NULL, $keep = FALSE){ return $this->_parse_generic_chatFunctions("leaveChat", $keep, $chat); }
	function get_chat($chat = NULL, $keep = FALSE){ return $this->_parse_generic_chatFunctions("getChat", $keep, $chat); }
	function get_admins($chat = NULL, $keep = FALSE){ return $this->_parse_generic_chatFunctions("getChatAdministrators", $keep, $chat); }
	function get_member_info($user = NULL, $chat = NULL, $keep = FALSE){ return $this->_parse_generic_chatFunctions("getChatMember", $keep, $chat, $user); }
	function get_members_count($chat = NULL, $keep = FALSE){ return $this->_parse_generic_chatFunctions("getChatMembersCount", $keep, $chat); }
	function get_chat_link($chat = NULL, $keep = FALSE){ return $this->_parse_generic_chatFunctions("exportChatInviteLink", $keep, $chat); }
	function get_user_avatar($user = NULL, $offset = NULL, $limit = 100){
		if(!empty($user)){ $this->user($user); }
		$this->content['offset'] = $offset;
		$this->content['limit'] = $limit;
		$this->method = "getUserProfilePhotos";

		$res = $this->send($keep);
		if(!isset($res['photos']) or empty($res['photos'])){ return FALSE; }
		return $res['photos'];
	}

	function set_title($text){
		$this->method = "setChatTitle";
		if($this->convert_emoji){ $text = $this->parent->emoji($text); }
		$this->content['title'] = $text;
		return $this->send();
	}

	function set_description($text = ""){
		$this->method = "setChatDescription";
		if($this->convert_emoji){ $text = $this->parent->emoji($text); }
		$this->content['description'] = $text;
		return $this->send();
	}

	function set_photo($path = FALSE){
		if($path === NULL or $path === FALSE){
			$this->method = "deleteChatPhoto";
			return $this->send();
		}
		return $this->file("chatphoto", $path);
	}

	function promote($vars, $user = NULL, $defval = TRUE){
		if(!empty($user)){ $this->user($user); }
		if(!is_array($vars)){ $vars = [$vars]; }

		/* post_messages, edit_messages, delete_messages, pin_messages,
		change_info, invite_users, restrict_members, promote_members */

		$this->method = "promoteChatMember";
		foreach($vars as $k => $v){
			$key = (is_numeric($k) ? $v : $k);
			$value = (!is_numeric($k) and is_bool($v) ? $v : $defval);

			if(strpos($key, "can_") === FALSE){ $key = "can_" . $key; }
			$key = strtolower($key);

			$this->content[$key] = (bool) $value;
		}

		return $this;
	}

	// Alias for promote but negative
	function demote($vars, $user = NULL){ return $this->promote($vars, $user, FALSE); }

	function pin_message($message = NULL){
		$this->method = "pinChatMessage";

		if($message === FALSE){
			$this->method = "un" . $this->method; // unpin
			return $this->send();
		}

		if(!empty($message)){ $this->message($message); }
		return $this->send();
	}

	// DEBUG
	/* function get_message($message, $chat = NULL){
		$this->method = 'getMessage';
		if(empty($chat) && !isset($this->content['chat_id'])){
			$this->content['chat_id'] = $this->parent->chat->id;
		}

		return $this->send();
	} */

	function answer_callback($alert = FALSE, $text = NULL, $id = NULL){
		// Function overload :>
		// $this->text can be empty. (Answer callback with empty response to finish request.)
		if($text == NULL && $id == NULL){
			$text = $this->content['text'];
			if($this->parent instanceof Receiver && $this->parent->key == "callback_query"){
				$id = $this->parent->id;
			}
			if(empty($id)){ return $this; } // HACK
			$this->content['callback_query_id'] = $id;
			if($this->convert_emoji){ $text = $this->parent->emoji($text); }
			$this->content['text'] = $text;
			$this->content['show_alert'] = $alert;
			$this->method = "answerCallbackQuery";
		}

		return $this->send();
	}

	function edit($type){
		if(!in_array($type, ['text', 'message', 'caption', 'keyboard', 'inline', 'markup'])){ return FALSE; }
		if(isset($this->content['text']) && in_array($type, ['text', 'message'])){
			$this->method = "editMessageText";
		}elseif(isset($this->content['caption']) && $type == "caption"){
			$this->method = "editMessageCaption";
		}elseif(isset($this->content['inline_keyboard']) && in_array($type, ['keyboard', 'inline', 'markup'])){
			$this->method = "editMessageReplyMarkup";
		}else{
			return FALSE;
		}

		return $this->send();
	}

	function delete($message = NULL, $chat = NULL){
		if($message === TRUE or (empty($message) && !isset($this->content['message_id']))){
			$this->message(TRUE);
		}elseif(is_array($message) and isset($message["message_id"])){
			$this->message($message["message_id"]);
		}elseif(!empty($message)){
			$this->message($message);
		}

		if($message === TRUE or (empty($chat) && !isset($this->content['chat_id']))){
			$this->chat(TRUE);
		}elseif(!empty($chat)){
			$this->chat($chat);
		}

		$this->method = "deleteMessage";
		return $this->send();
	}

	function game($name, $notification = FALSE){
		$this->content['game_short_name'] = $name;
		$this->content['disable_notification'] = (bool) $notification;

		$this->method = "sendGame";
		return $this;
	}

	function game_score($user, $score = NULL, $force = FALSE, $edit_message = TRUE){
		$this->user($user);

		if($score == NULL){
			$this->method = "getGameHighScores";
			return $this;
		}

		$this->content['score'] = (int) $score;
		if($force){ $this->content['force'] = (bool) $force; }
		if(!$edit_message){ $this->content['disable_edit_message'] = FALSE; }

		$this->method = "setGameScore";
		return $this;
	}

	function _push($key, $val){
		$this->content[$key] = $val;
		return $this;
	}

	function _push_method($name){
		$this->method = $name;
		return $this;
	}

	function _reset(){
		$this->method = NULL;
		$this->content = array();
	}

	private function _url($with_method = FALSE, $host = "api.telegram.org"){
		$url = ("https://$host/bot" .$this->bot->id .':' .$this->bot->key .'/');
		if($with_method){ $url .= $this->method; }
		return $url;
	}

	function send($keep = FALSE, $_broadcast = FALSE){
		if(!empty($this->broadcast) and !$_broadcast){
			$result = array();
			if(in_array(strtoupper($keep), ["POST", "POSTKEEP"])){ $keep = "POSTKEEP"; }
			else{ $keep = TRUE; }
			foreach($this->broadcast as $chat){
				$this->chat($chat);
				// Send and keep data
				$result[] = $this->send($keep, TRUE);
			}
			return $result;
		}

		if(empty($this->method)){ return FALSE; }
		if(empty($this->chat()) && $this->parent instanceof Receiver){ $this->chat($this->parent->chat->id); }

		$post = FALSE;

		if(is_string($keep)){
			$keep = strtoupper($keep);
			if($keep == "POST"){ $keep = FALSE; $post = TRUE; }
			elseif($keep = "POSTKEEP"){ $keep = TRUE; $post = TRUE; }
		}

		$result = $this->Request($this->method, $this->content, $post);
		if($keep === FALSE){ $this->_reset(); }
		return $result;
	}

	function _parse_generic_chatFunctions($action, $keep, $chat, $user = FALSE){
		$this->method = $action;
		if($user === FALSE){ // No hay user.
			if(empty($chat) && empty($this->chat())){ return FALSE; }
		}else{
			if(empty($user) && empty($chat) && (empty($this->chat()) or empty($this->user()))){ return FALSE; }
		}
		if(!empty($chat)){ $this->chat($chat); }
		if(!empty($user)){ $this->user($user); }
		return $this->send($keep);
		// return $this;
	}

	function RequestWebhook($method, $parameters) {
		if (!is_string($method)) {
			error_log("Method name must be a string\n");
			return false;
		}

		if (!$parameters) {
			$parameters = array();
		} else if (!is_array($parameters)) {
			error_log("Parameters must be an array\n");
			return false;
		}

		$parameters["method"] = $method;

		header("Content-Type: application/json");
		echo json_encode($parameters);
		return true;
	}

	function exec_curl_request($handle) {
		$response = curl_exec($handle);

		if ($response === false) {
			$errno = curl_errno($handle);
			$error = curl_error($handle);
			error_log("Curl returned error $errno: $error\n");
			curl_close($handle);
			return false;
		}

		$http_code = intval(curl_getinfo($handle, CURLINFO_HTTP_CODE));
		curl_close($handle);

		if ($http_code >= 500) {
		// do not wat to DDOS server if something goes wrong
			sleep(10);
			return false;
		} else if ($http_code != 200) {
			$response = json_decode($response, true);
			error_log("Request has failed with error {$response['error_code']}: {$response['description']}\n");
			if ($http_code == 401) {
				throw new \Exception('Invalid access token provided');
			}
			return false;
		} else {
			$response = json_decode($response, true);
			if (isset($response['description'])) {
				error_log("Request was successfull: {$response['description']}\n");
			}
			$response = $response['result'];
		}

		return $response;
	}

	private function Request($method, $parameters, $post = FALSE) {
		if (!is_string($method)) {
			error_log("Method name must be a string\n");
			return false;
		}

		if (!$parameters) {
			$parameters = array();
		} else if (!is_array($parameters)) {
			error_log("Parameters must be an array\n");
			return false;
		}

		foreach ($parameters as $key => &$val) {
		// encoding to JSON array parameters, for example reply_markup
			if (!is_numeric($val) && !is_string($val) && !($val instanceof \CURLFile) ) {
				$val = json_encode($val);
			}
		}

		$url = $this->_url(TRUE);
		if(!$post){ $url .= '?'.http_build_query($parameters); }

		$handle = curl_init($url);
		curl_setopt($handle, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($handle, CURLOPT_TIMEOUT, 60);

		if($post){
			curl_setopt($handle, CURLOPT_HTTPHEADER, ["Content-Type:multipart/form-data"]);
			curl_setopt($handle, CURLOPT_POSTFIELDS, $parameters);
		}

		return $this->exec_curl_request($handle);
	}
}

?>
