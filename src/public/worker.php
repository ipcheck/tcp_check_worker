<?php
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

require __DIR__ . "/../init.php";
while(true){
    try{
        $connection = new AMQPStreamConnection(getenv('RABBITMQ_HOST'), getenv('RABBITMQ_PORT'), getenv('RABBITMQ_USER'), getenv('RABBITMQ_PASS'), getenv('RABBITMQ_VHOST'));
        $channel = $connection->channel();
        
        $timeout_count = 0;
        $result = '';
        while(true){
            $_result = excute("sudo hping3 -c 10 -i u1000 -A " . $_POST['ip'] . " 2>&1", 10);
            if ($_result == "timeout"){
                if ($timeout_count >= 3){
                    $result = [ 'result' => 'failed' ];
                    break;
                }
                $timeout_count++;
                continue;
            }
            
            $result = [
                'result' => 'success',
                'data' => [
                    'ip'            => $_POST['ip'],
                    'type'          => $_POST['type'],
                    'machine_ip'    => $_POST['machine_ip'],
                    'machine_type'  => $_POST['machine_type'],
                    'alive'         => !(bool)strpos($_result, "100% packet loss"),
                ]
            ];
            break;
        }
        
        $newMessage = new AMQPMessage(json_encode($result), [ "correlation_id" => $_POST['correlation_id'] ]);
        $channel->basic_publish( $newMessage, '', $_POST['reply_to'] );
        
        $channel->close();
        $connection->close();
        exit();
    } catch (\Exception $e) {
        
    }
}

