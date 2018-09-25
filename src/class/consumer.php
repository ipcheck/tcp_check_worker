<?php
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class Consumer
{
	private $channel;
	private $connection;
	private $consumers = null;
	private $daemonname;
	private $config;
	public static $default = [
		"queue" => [
			"name" => "",
			"passive" => false,
			"durable" => true,
			"exclusive" => false,
			"auto_delete" => false,
			"nowait" => false,
			"arguments" => array(),
			"ticket" => null
		],
		"exchange" => [
			"name" => "",
			"enable" => false,
			"type" => 'fanout',
			"passive" => false,
			"durable" => false,
			"auto_delete" => true,
			"internal" => false,
			"nowait" => false,
		],
		"qos" => [
			"enable" => false,
			"prefetch" => 5
		],
		"reply" => [
			"enable" => false,
        ],
		"custom_callback" => null,
		"custom_callback_filter" => null
	];
	public $logger;

	public function __construct($queue_name, $exchange_name, $consumerTag, $consumerlist = [], $data = []){
		$config = $this->load_config($data);

		if (is_object($config["custom_callback_filter"]) && is_array($consumerlist)){
			if (count($consumerlist) == 1){
				$this->consumers = require array_shift($consumerlist);
			}
		}


		$connection = new AMQPStreamConnection(getenv("RABBITMQ_HOST"), getenv("RABBITMQ_PORT"), getenv("RABBITMQ_USER"), getenv("RABBITMQ_PASS"), getenv("RABBITMQ_VHOST"));
		$channel = $connection->channel();
		$config["queue"]["name"] = $queue_name;
		call_user_func_array([$channel, 'queue_declare'], array_values($config["queue"]));

		if ($config["exchange"]["enable"]){
			unset($config["exchange"]["enable"]);
			if (is_array($exchange_name)){
				foreach ($exchange_name as $_name){
					$config["exchange"]["name"] = $_name;
					call_user_func_array([$channel, 'exchange_declare'], array_values($config["exchange"]));
					$channel->queue_bind($queue_name, $_name);	
				}
			} else {
				$config["exchange"]["name"] = $exchange_name;
				call_user_func_array([$channel, 'exchange_declare'], array_values($config["exchange"]));
				$channel->queue_bind($queue_name, $exchange_name);
			}
		}

		if ($config["qos"]["enable"]){
			$channel->basic_qos(null, $config["qos"]["prefetch"], null);
		}

		$this->connection = $connection;
		$this->channel = $channel;
		$this->config = $config;

		if (!$this->consumers){
			foreach ($consumerlist as $consumer){
				$this->consumers[basename($consumer, ".php")] = require $consumer;
			}
		}

        if (is_object($config["custom_callback"])){
            $channel->basic_consume($queue_name, $consumerTag, false, false, false, false, $config["custom_callback"]);    
            return;
        } 
		$channel->basic_consume($queue_name, $consumerTag, false, false, false, false, [$this, 'callback']);
	}

	private function load_config($data){
		$_return = self::$default;
		foreach ($_return as $_key => $_value){
			if (is_array($_value) && isset($data[$_key])){
				$_return[$_key] = $this->load_subconfig($_return[$_key], $data[$_key]);
			} elseif (isset($data[$_key])){
                $_return[$_key] = $data[$_key];
            }
		}

		return $_return;
	}

	private function load_subconfig($source, $data){
		foreach ($source as $_key => $_value){
			if (is_array($_value) && isset($data[$_key])){
				$source[$_key] = $this->load_subconfig($source[$_key], $data[$_key]);
				continue;
			}
			if (isset($data[$_key])){
				$source[$_key] = $data[$_key];
			}
		}
		return $source;
	}

	public function callback($message){
		if (!$this->config["custom_callback_filter"]){
			$data = json_decode($message->body, true);
			if (!is_array($data)){
				$this->logger->notice("Invaild message from queue, cancel the message (json require)");
				$message->delivery_info['channel']->basic_nack($message->delivery_info['delivery_tag'], false, false);
				return false;
			}
				
			if (!isset($this->consumers[$data["action"]])){
				$this->logger->notice("Invaild message from queue, cancel the message (action require)");
				$message->delivery_info['channel']->basic_nack($message->delivery_info['delivery_tag'], false, false);
				return false;
			}
		} else {
			$result = $this->config["custom_callback_filter"]($message->body);
			if ($result['result'] == 'success'){
				$data = $result['data'];
			} else {
				$this->logger->notice($result['error']);
				$message->delivery_info['channel']->basic_nack($message->delivery_info['delivery_tag'], false, false);
				return false;
			}
		}

		if (!$message->has('message_id')){
			$this->logger->notice("Invaild message from queue, cancel the message (message_id require)");
			$message->delivery_info['channel']->basic_nack($message->delivery_info['delivery_tag'], false, false);
			return false;
		}

		$message_id = $message->get('message_id');
		if ($this->config["reply"]["enable"]){
			if (!$message->has('correlation_id') || !$message->has('reply_to')){
				$this->logger->notice("Invaild message from queue, cancel the message (correlation_id/reply_to require)");
				$message->delivery_info['channel']->basic_nack($message->delivery_info['delivery_tag'], false, false);
				return false;
			}
			$correlation_id = $message->get('correlation_id');
		}
		
		$this->logger->notice("Processing message from queue, id: " . $message_id);
		if (!$this->config["custom_callback_filter"]){
			$return = ($this->consumers[$data["action"]])($data, $message);
		} else {
			$return = ($this->consumers)($data, $message);
		}

		if ($return["result"] == "error"){
			$this->logger->notice("failed to process message from queue, return the message. Details: " . $return["error"]);
			if (isset($return["stop"])){
				if ($return["stop"]){
					$message->delivery_info['channel']->basic_nack($message->delivery_info['delivery_tag'], false, false);
					return;
				}
			}
			$message->delivery_info['channel']->basic_nack($message->delivery_info['delivery_tag'], true, true);
			return;
		}

		$this->logger->notice("Answer message to queue, id: " . $message_id);
		
		if ($this->config["reply"]["enable"]){
			$newMessage = new AMQPMessage(json_encode($return), [ "correlation_id" => $correlation_id ]);
			$this->logger->notice("Replying message to queue, id: " . $message_id);
			foreach (explode(',', $message->get('reply_to')) as $replyTo){
				$message->delivery_info['channel']->basic_publish( $newMessage, '', $replyTo );
			}
		}
		$message->delivery_info['channel']->basic_ack( $message->delivery_info['delivery_tag'] );
	}

	public function getChannel(){
		return $this->channel;
	}

	public function createExchange($name){
		$config = $this->config;
		$config["exchange"]["name"] = $name;
		call_user_func_array([$this->channel, 'exchange_declare'], array_values($config["exchange"]));
	}

	public function __destruct(){
		$this->channel->close();
		$this->connection->close();
	}

	public function run(){
		if (count($this->channel->callbacks)) {
			$this->channel->wait();
		}
	}
}