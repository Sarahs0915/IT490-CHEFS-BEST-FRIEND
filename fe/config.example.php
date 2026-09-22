<?php
return [
    'host'     => getenv('RMQ_HOST')  ?: '192.168.x.x',
    'port'     => (int)(getenv('RMQ_PORT') ?: 5672),
    'user'     => getenv('RMQ_USER')  ?: 'fe_user',
    'password' => getenv('RMQ_PASS')  ?: 'change_me',
    'vhost'    => getenv('RMQ_VHOST') ?: 'chef_vhost',
    'exchange' => getenv('RMQ_EXCHANGE') ?: 'chef_exchange',
    'routing_key' => getenv('RMQ_ROUTING_KEY') ?: 'befe.rpc',
];