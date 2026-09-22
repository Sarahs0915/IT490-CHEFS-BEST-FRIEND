<?php
require_once __DIR__ . '/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Exception\AMQPTimeoutException;

class RabbitRPCClient
{
    private ?AMQPStreamConnection $connection = null;
    private $channel = null;
    private string $callback_queue = '';
    private ?string $response = null;
    private string $corr_id = '';
    private array $cfg;

    public string $lastCorrId = '';

    public function __construct()
    {
        $file = is_file(__DIR__ . '/config.php') ? 'config.php' : 'config.example.php';
        $this->cfg = require __DIR__ . '/' . $file;

        $this->connection = new AMQPStreamConnection(
            $this->cfg['host'],
            $this->cfg['port'],
            $this->cfg['user'],
            $this->cfg['password'],
            $this->cfg['vhost'],
            false, 'AMQPLAIN', null, 'en_US',
            3.0,  // connection timeout
            3.0   // read/write timeout
        );
        $this->channel = $this->connection->channel();

        // Exclusive, auto-delete, server-named reply queue
        [$this->callback_queue] = $this->channel->queue_declare('', false, false, true, true);

        $this->channel->basic_consume(
            $this->callback_queue, '', false, true, false, false,
            [$this, 'onResponse']
        );
    }

    public function onResponse(AMQPMessage $rep): void
    {
        if ($rep->get('correlation_id') === $this->corr_id) {
            $this->response = $rep->body;
        }
    }

    public function call(string $action, array $payload = [], int $timeout = 5): array
    {
        $this->response = null;
        $this->corr_id = $this->lastCorrId = uniqid('', true);

        $payload['action'] = $action;
        $msg = new AMQPMessage(json_encode($payload), [
            'correlation_id' => $this->corr_id,
            'reply_to'       => $this->callback_queue,
            'content_type'   => 'application/json',
        ]);

        $this->channel->basic_publish($msg, $this->cfg['exchange'], $this->cfg['routing_key']);

        try {
            while ($this->response === null) {
                $this->channel->wait(null, false, $timeout);
            }
        } catch (AMQPTimeoutException $e) {
            return ['status' => 'error', 'message' => 'Backend timed out'];
        }

        $decoded = json_decode($this->response, true);
        if (!is_array($decoded)) {
            return ['status' => 'error', 'message' => 'Malformed reply from backend'];
        }
        $decoded['correlation_id'] = $this->corr_id;
        return $decoded;
    }

    public function __destruct()
    {
        try {
            if ($this->channel)    $this->channel->close();
            if ($this->connection) $this->connection->close();
        } catch (\Throwable $e) {
            // ignore shutdown errors
        }
    }
}