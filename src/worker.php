<?php
return function($data, $message) {
    global $client, $applog, $machine_type, $type, $worker_ip_details;
    
    if (!$message->has('correlation_id') || !$message->has('reply_to')){
        $applog->notice("Invaild message from queue, cancel the message (correlation_id/reply_to require)");
        $message->delivery_info['channel']->basic_nack($message->delivery_info['delivery_tag'], false, false);
        return false;
    }

    $content = http_build_query([
        'ip'             => $data,
        'correlation_id' => $message->get('correlation_id'),
        'reply_to'       => $message->get('reply_to'),
        'machine_type'   => $machine_type,
        'machine_ip'     => $worker_ip_details['ip'],
        'type'           => $type,
        
    ]);

    $request = new \hollodotme\FastCGI\Requests\PostRequest('/app/src/public/worker.php', $content);

    $requestId = $client->sendAsyncRequest($request);

    return true;
};