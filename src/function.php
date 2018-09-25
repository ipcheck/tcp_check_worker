<?php
function msleep(int $millisecond){
    usleep(1000 * $millisecond);
}

function excute($command, $timeout = 10){
    $start_time = microtime(true);
    $proc = proc_open(
        $command,
        [ 
            //STDIN
            ['pipe', 'r'], 
            //STDOUT
            ['pipe', 'w'],
            //STDERROR    
            ['pipe', 'r'],
        ],
        $pipes
    );

    fclose($pipes[0]);
    stream_set_timeout($pipes[1], 0);
    stream_set_timeout($pipes[2], 0);

    $output = "";
    
    while (proc_get_status($proc)['running'])
    {
        if ( microtime(true) - $start_time > $timeout){
            proc_terminate($proc);
            return 'timeout';
        }
        msleep(100);
    }

    $output .= stream_get_contents($pipes[1]);
    $output .= stream_get_contents($pipes[2]);
    $return = proc_close($proc);

    return $output;
}