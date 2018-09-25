<?php declare(strict_types=1);
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;
use hollodotme\FastCGI\Client;
use hollodotme\FastCGI\SocketConnections\UnixDomainSocket;

require __DIR__ . '/init.php';
require __DIR__ . '/class/consumer.php';

$formatter = new LineFormatter(null, null, false, true);
$handle = new StreamHandler('php://stdout');
$handle->setFormatter($formatter);

if (!isset($appname)){
    $appname = 'app';
}

$applog = new Logger($appname);
$applog->pushHandler($handle);
$applog->notice('Get worker ip address...');


$client  = new Client( new UnixDomainSocket( '/run/php-fpm.sock' ) );


$worker_ip_details = json_decode(file_get_contents('http://api.ipstack.com/check?access_key=' . getenv('IPSTACK_ACCESS_KEY') . '&format=1'), true);

while(true){
    if (!empty($worker_ip_details)) break;
    if (isset($worker_ip_details['success']) && $worker_ip_details['success'] == 'false'){
        echo '======================= WARNING: REQUIRE MANUAL RESTART =======================\n';
        $applog->error('Get worker ip address failed, reason: ' . $worker_ip_details['error']['info']);
        echo '======================= WARNING: REQUIRE MANUAL RESTART =======================\n';
        sleep(10);
        continue;
    }
    break;
}

foreach ($worker_ip_details as $k => $v){
    if (is_string($v)){
        $worker_ip_details[$k] = str_replace(' ', '_', strtolower($v));
    }
}

if ($worker_ip_details['country_name'] == 'china'){
    $machine_type = 'inside-gfw';
} else {
    $machine_type = 'outside-gfw';
}

$quene_name = 'ipcheck.' . $type . '.' . $machine_type;

$init = function() use ($applog, $worker_ip_details, $hostname, $quene_name, $type){
    $a = new Consumer(
        $quene_name,
        [ 'ipcheck.gfw-check.' . $type ],
        'worker-' . gethostname(),
        [ __DIR__ . '/worker.php' ],
        [
            'queue' => [
                'auto_delete' => true,
                'durable' => false,
            ],  
            'exchange' => [
                'enable' => true,
                'auto_delete' => true,
                "durable" => false,
            ], 
            'qos' => [
                'enable' => true, 
                'prefetch' => 3
            ],
            'reply' => [
                'enable' => false
            ],
            'custom_callback_filter' => function($data){
                if (filter_var($data, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)){
                    return [ 'result' => 'success', 'data' => $data ];
                }
                return [ 'result' => 'failed', 'error' => 'Invaild ip address, drop the message' ];
            }
        ]
    );
    $a->createExchange('ipcheck.gfw-check');
    $a->getChannel()->exchange_bind('ipcheck.gfw-check.' . $type, 'ipcheck.gfw-check');
    $applog->NOTICE("Successful to connect RabbitMQ Server");
    $a->logger = $applog;
    return $a;
};


while(true){
    $a = $init();
    try{
        while(true){
            $a->run();
        }
    } catch (\Exception $e) {
        $applog->notice("rabbitmq connection error: " . $e->getMessage());
        $a->__destruct();
        unset($a);
    }
}