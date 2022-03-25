<?php

namespace Linzc\ThinkQueueRabbitMQ\queue\connector;

use ErrorException;
use Exception;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPLazyConnection;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use think\helper\Arr;
use think\helper\Str;
use think\queue\Connector;
use think\queue\InteractsWithTime;
use Linzc\ThinkQueueRabbitMQ\queue\job\RabbitMQ as RabbitMQJob;
use Throwable;

class RabbitMQ extends Connector
{

    use InteractsWithTime;

    /**
     * The RabbitMQ connection instance.
     *
     * @var AbstractConnection
     */
    protected $rabbitmq;

    /**
     * The RabbitMQ channel instance.
     *
     * @var AMQPChannel
     */
    protected $channel;

    /**
     * The name of the default queue.
     *
     * @var string
     */
    protected $default;

    /**
     * List of already declared exchanges.
     *
     * @var array
     */
    protected $exchanges = [];

    /**
     * List of already declared queues.
     *
     * @var array
     */
    protected $queues = [];

    /**
     * List of already bound queues to exchanges.
     *
     * @var array
     */
    protected $boundQueues = [];

    /**
     * Current job being processed.
     *
     * @var RabbitMQJob
     */
    protected $currentJob;

    /**
     * @var array
     */
    protected $options;

    /**
     * RabbitMQ constructor.
     *
     * @param AbstractConnection $rabbitmq
     * @param string $default
     * @param array $options
     */
    public function __construct(
        AbstractConnection $rabbitmq,
        string $default,
        array $options = []
    ) {
        $this->rabbitmq = $rabbitmq;
        $this->channel  = $rabbitmq->channel();
        $this->default  = $default;
        $this->options  = $options;
    }

    public static function __make($config)
    {
        $connection = self::createConnection(Arr::except($config, 'options.queue'));

        return new self($connection, $config['queue'], Arr::get($config, 'options.queue', []));
    }

    /**
     * Establish a queue connection.
     *
     * @param array $config
     * @return AbstractConnection
     * @throws \Exception
     */
    protected static function createConnection(array $config): AbstractConnection
    {
        /** @var AbstractConnection $connection */
        $connection = Arr::get($config, 'connection', AMQPLazyConnection::class);

        // disable heartbeat when not configured, so long-running tasks will not fail
        $config = Arr::add($config, 'options.heartbeat', 0);

        return $connection::create_connection(
            Arr::shuffle(Arr::get($config, 'hosts', [])),
            self::filter(Arr::get($config, 'options', []))
        );
    }

    /**
     * {@inheritdoc}
     *
     * @throws AMQPProtocolChannelException
     */
    public function size($queue = null)
    {
        $queue = $this->getQueue($queue);

        if (! $this->isQueueExists($queue)) {
            return 0;
        }

        // create a temporary channel, so the main channel will not be closed on exception
        $channel = $this->rabbitmq->channel();
        [, $size] = $channel->queue_declare($queue, true);
        $channel->close();

        return $size;
    }

    /**
     * {@inheritdoc}
     *
     * @throws AMQPProtocolChannelException
     */
    public function push($job, $data = '', $queue = null)
    {
        return $this->pushRaw($this->createPayload($job, $data), $queue, []);
    }

    /**
     * {@inheritdoc}
     *
     * @throws AMQPProtocolChannelException
     */
    public function pushRaw($payload, $queue = null, array $options = [])
    {
        [$destination, $exchange, $exchangeType, $attempts] = $this->publishProperties($queue, $options);

        $this->declareDestination($destination, $exchange, $exchangeType);

        [$message, $correlationId] = $this->createMessage($payload, $attempts);

        $this->channel->basic_publish($message, $exchange, $destination, true, false);

        return $correlationId;
    }

    /**
     * {@inheritdoc}
     *
     * @throws AMQPProtocolChannelException
     */
    public function later($delay, $job, $data = '', $queue = null)
    {
        return $this->laterRaw(
            $delay,
            $this->createPayload($job, $data),
            $queue
        );
    }

    /**
     * {@inheritdoc}
     *
     * @throws AMQPProtocolChannelException
     */
    public function laterRaw($delay, $payload, $queue = null, $attempts = 0)
    {
        $ttl = $this->secondsUntil($delay) * 1000;

        // When no ttl just publish a new message to the exchange or queue
        if ($ttl <= 0) {
            return $this->pushRaw($payload, $queue, ['delay' => $delay, 'attempts' => $attempts]);
        }

        $destination = $this->getQueue($queue) . '.delay.' . $ttl;

        $this->declareQueue($destination, true, false, $this->getDelayQueueArguments($this->getQueue($queue), $ttl));

        [$message, $correlationId] = $this->createMessage($payload, $attempts);

        // Publish directly on the delayQueue, no need to publish trough an exchange.
        $this->channel->basic_publish($message, '', $destination, true, false);

        return $correlationId;
    }

    /**
     * {@inheritdoc}
     *
     * @throws AMQPProtocolChannelException
     * @throws Throwable
     */
    public function pop($queue = null)
    {
        try {
            $queue = $this->getQueue($queue);

            $job = $this->getJobClass();

            /** @var AMQPMessage|null $message */
            if ($message = $this->channel->basic_get($queue)) {
                return $this->currentJob = new $job(
                    $this->app,
                    $this,
                    $message,
                    $this->connection,
                    $queue
                );
            }
        } catch (AMQPProtocolChannelException $exception) {
            // If there is not exchange or queue AMQP will throw exception with code 404
            // We need to catch it and return null
            if ($exception->amqp_reply_code === 404) {
                // Because of the channel exception the channel was closed and removed.
                // We have to open a new channel. Because else the worker(s) are stuck in a loop, without processing.
                $this->channel = $this->rabbitmq->channel();

                return null;
            }

            throw $exception;
        }

        return null;
    }

    /**
     * Job class to use.
     *
     * @return string
     * @throws Throwable
     */
    public function getJobClass(): string
    {
        $job = Arr::get($this->options, 'job', RabbitMQJob::class);

        throw_if(
            ! is_a($job, RabbitMQJob::class, true),
            Exception::class,
            sprintf('Class %s must extend: %s', $job, RabbitMQJob::class)
        );

        return $job;
    }

    /**
     * Purge the queue of messages.
     *
     * @param string|null $queue
     * @return void
     */
    public function purge(string $queue = null): void
    {
        // create a temporary channel, so the main channel will not be closed on exception
        $channel = $this->rabbitmq->channel();
        $channel->queue_purge($this->getQueue($queue));
        $channel->close();
    }

    /**
     * Acknowledge the message.
     *
     * @param RabbitMQJob $job
     * @return void
     */
    public function ack(RabbitMQJob $job): void
    {
        $this->channel->basic_ack($job->getRabbitMQMessage()->getDeliveryTag());
    }

    /**
     * Reject the message.
     *
     * @param RabbitMQJob $job
     * @param bool $requeue
     *
     * @return void
     */
    public function reject(RabbitMQJob $job, bool $requeue = false): void
    {
        $this->channel->basic_reject($job->getRabbitMQMessage()->getDeliveryTag(), $requeue);
    }

    /**
     * Create a AMQP message.
     *
     * @param $payload
     * @param int $attempts
     * @return array
     */
    protected function createMessage($payload, int $attempts = 0): array
    {
        $properties = [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ];

        $currentPayload = json_decode($payload, true, 512);
        if ($correlationId = $currentPayload['id'] ?? null) {
            $properties['correlation_id'] = $correlationId;
        }

        if ($this->isPrioritizeDelayed()) {
            $properties['priority'] = $attempts;
        }

        if (isset($currentPayload['data']['command'])) {
            $commandData = unserialize($currentPayload['data']['command']);
            if (property_exists($commandData, 'priority')) {
                $properties['priority'] = $commandData->priority;
            }
        }

        $message = new AMQPMessage($payload, $properties);

        $message->set('application_headers', new AMQPTable([
            'think' => [
                'attempts' => $attempts,
            ],
        ]));

        return [
            $message,
            $correlationId,
        ];
    }

    /**
     * Create a payload array from the given job and data.
     *
     * @param $job
     * @param string $data
     * @return array
     */
    protected function createPayloadArray($job, $data = '')
    {
        return array_merge(parent::createPayloadArray($job, $data), [
            'id' => $this->getRandomId(),
        ]);
    }

    /**
     * Get a random ID string.
     *
     * @return string
     */
    protected function getRandomId(): string
    {
        return Str::random(32);
    }

    /**
     * Declare the destination when necessary.
     *
     * @param string $destination
     * @param string|null $exchange
     * @param string|null $exchangeType
     * @return void
     * @throws AMQPProtocolChannelException
     */
    protected function declareDestination(
        string $destination,
        ?string $exchange = null,
        string $exchangeType = AMQPExchangeType::DIRECT
    ): void {
        // When a exchange is provided and no exchange is present in RabbitMQ, create an exchange.
        if ($exchange && ! $this->isExchangeExists($exchange)) {
            $this->declareExchange($exchange, $exchangeType);
        }

        // When a exchange is provided, just return.
        if ($exchange) {
            return;
        }

        // When the queue already exists, just return.
        if ($this->isQueueExists($destination)) {
            return;
        }

        // Create a queue for amq.direct publishing.
        $this->declareQueue($destination, true, false, $this->getQueueArguments($destination));
    }

    /**
     * Get the Queue arguments.
     *
     * @param string $destination
     * @return array
     */
    protected function getQueueArguments(string $destination): array
    {
        $arguments = [];

        // Messages without a priority property are treated as if their priority were 0.
        // Messages with a priority which is higher than the queue's maximum, are treated as if they were
        // published with the maximum priority.
        // Quorum queues does not support priority.
        if ($this->isPrioritizeDelayed() && ! $this->isQuorum()) {
            $arguments['x-max-priority'] = $this->getQueueMaxPriority();
        }

        if ($this->isRerouteFailed()) {
            $arguments['x-dead-letter-exchange'] = $this->getFailedExchange() ?? '';
            $arguments['x-dead-letter-routing-key'] = $this->getFailedRoutingKey($destination);
        }

        if ($this->isQuorum()) {
            $arguments['x-queue-type'] = 'quorum';
        }

        return $arguments;
    }

    /**
     * Get the Delay queue arguments.
     *
     * @param string $destination
     * @param int $ttl
     * @return array
     */
    protected function getDelayQueueArguments(string $destination, int $ttl): array
    {
        return [
            'x-dead-letter-exchange' => $this->getExchange() ?? '',
            'x-dead-letter-routing-key' => $this->getRoutingKey($destination),
            'x-message-ttl' => $ttl,
            'x-expires' => $ttl * 2,
        ];
    }

    /**
     * Determine all publish properties.
     *
     * @param $queue
     * @param array $options
     * @return array
     */
    protected function publishProperties($queue, array $options = []): array
    {
        $queue = $this->getQueue($queue);
        $attempts = Arr::get($options, 'attempts') ?: 0;

        $destination = $this->getRoutingKey($queue);
        $exchange = $this->getExchange(Arr::get($options, 'exchange'));
        $exchangeType = $this->getExchangeType(Arr::get($options, 'exchange_type'));

        return [$destination, $exchange, $exchangeType, $attempts];
    }

    /**
     * Checks if the given exchange already present/defined in RabbitMQ.
     * Returns false when when the exchange is missing.
     *
     * @param string $exchange
     * @return bool
     * @throws AMQPProtocolChannelException
     */
    public function isExchangeExists(string $exchange): bool
    {
        if ($this->isExchangeDeclared($exchange)) {
            return true;
        }

        try {
            // create a temporary channel, so the main channel will not be closed on exception
            $channel = $this->rabbitmq->channel();
            $channel->exchange_declare($exchange, '', true);
            $channel->close();

            $this->exchanges[] = $exchange;

            return true;
        } catch (AMQPProtocolChannelException $exception) {
            if ($exception->amqp_reply_code === 404) {
                return false;
            }

            throw $exception;
        }
    }

    /**
     * Declare a exchange in rabbitMQ, when not already declared.
     *
     * @param string $name
     * @param string $type
     * @param bool $durable
     * @param bool $autoDelete
     * @param array $arguments
     * @return void
     */
    public function declareExchange(
        string $name,
        string $type = AMQPExchangeType::DIRECT,
        bool $durable = true,
        bool $autoDelete = false,
        array $arguments = []
    ): void {
        if ($this->isExchangeDeclared($name)) {
            return;
        }

        $this->channel->exchange_declare(
            $name,
            $type,
            false,
            $durable,
            $autoDelete,
            false,
            true,
            new AMQPTable($arguments)
        );
    }

    /**
     * Delete a exchange from rabbitMQ, only when present in RabbitMQ.
     *
     * @param string $name
     * @param bool $unused
     * @return void
     * @throws AMQPProtocolChannelException
     */
    public function deleteExchange(string $name, bool $unused = false): void
    {
        if (! $this->isExchangeExists($name)) {
            return;
        }

        $idx = array_search($name, $this->exchanges);
        unset($this->exchanges[$idx]);

        $this->channel->exchange_delete(
            $name,
            $unused
        );
    }

    /**
     * Checks if the given queue already present/defined in RabbitMQ.
     * Returns false when when the queue is missing.
     *
     * @param string|null $name
     * @return bool
     * @throws AMQPProtocolChannelException
     */
    public function isQueueExists(string $name = null): bool
    {
        try {
            // create a temporary channel, so the main channel will not be closed on exception
            $channel = $this->rabbitmq->channel();
            $channel->queue_declare($this->getQueue($name), true);
            $channel->close();

            return true;
        } catch (AMQPProtocolChannelException $exception) {
            if ($exception->amqp_reply_code === 404) {
                return false;
            }

            throw $exception;
        }
    }

    /**
     * Declare a queue in rabbitMQ, when not already declared.
     *
     * @param string $name
     * @param bool $durable
     * @param bool $autoDelete
     * @param array $arguments
     * @return void
     */
    public function declareQueue(
        string $name,
        bool $durable = true,
        bool $autoDelete = false,
        array $arguments = []
    ): void {
        if ($this->isQueueDeclared($name)) {
            return;
        }

        $this->channel->queue_declare(
            $name,
            false,
            $durable,
            false,
            $autoDelete,
            false,
            new AMQPTable($arguments)
        );
    }

    /**
     * Delete a queue from rabbitMQ, only when present in RabbitMQ.
     *
     * @param string $name
     * @param bool $ifUnused
     * @param bool $ifEmpty
     * @throws AMQPProtocolChannelException
     */
    public function deleteQueue(string $name, bool $ifUnused = false, bool $ifEmpty = false): void
    {
        if (! $this->isQueueExists($name)) {
            return;
        }

        $this->channel->queue_delete($name, $ifUnused, $ifEmpty);
    }

    /**
     * Bind a queue to an exchange.
     *
     * @param string $queue
     * @param string $exchange
     * @param string $routingKey
     * @return void
     */
    public function bindQueue(string $queue, string $exchange, string $routingKey = ''): void
    {
        if (in_array(
            implode('', compact('queue', 'exchange', 'routingKey')),
            $this->boundQueues,
            true
        )) {
            return;
        }

        $this->channel->queue_bind($queue, $exchange, $routingKey);
    }

    /**
     * Checks if the exchange was already declared.
     *
     * @param string $name
     * @return bool
     */
    protected function isExchangeDeclared(string $name): bool
    {
        return in_array($name, $this->exchanges, true);
    }

    /**
     * Checks if the queue was already declared.
     *
     * @param string $name
     * @return bool
     */
    protected function isQueueDeclared(string $name): bool
    {
        return in_array($name, $this->queues, true);
    }

    /**
     * Gets a queue/destination, by default the queue option set on the connection.
     *
     * @param null $queue
     * @return string
     */
    public function getQueue($queue = null): string
    {
        return $queue ?: $this->default;
    }

    /**
     * Returns &true;, if delayed messages should be prioritized.
     *
     * @return bool
     */
    protected function isPrioritizeDelayed(): bool
    {
        return (bool) (Arr::get($this->options, 'prioritize_delayed') ?: false);
    }

    /**
     * Returns a integer with a default of '2' for when using prioritization on delayed messages.
     * If priority queues are desired, we recommend using between 1 and 10.
     * Using more priority layers, will consume more CPU resources and would affect runtimes.
     *
     * @see https://www.rabbitmq.com/priority.html
     * @return int
     */
    protected function getQueueMaxPriority(): int
    {
        return (int) (Arr::get($this->options, 'queue_max_priority') ?: 2);
    }

    /**
     * Get the exchange name, or &null; as default value.
     *
     * @param string|null $exchange
     * @return string|null
     */
    protected function getExchange(string $exchange = null): ?string
    {
        return $exchange ?: Arr::get($this->options, 'exchange') ?: null;
    }

    /**
     * Get the routing-key for when you use exchanges
     * The default routing-key is the given destination.
     *
     * @param string $destination
     * @return string
     */
    protected function getRoutingKey(string $destination): string
    {
        return ltrim(sprintf(Arr::get($this->options, 'exchange_routing_key') ?: '%s', $destination), '.');
    }

    /**
     * Get the exchange for failed messages.
     *
     * @param string|null $exchange
     * @return string|null
     */
    protected function getFailedExchange(string $exchange = null): ?string
    {
        return $exchange ?: Arr::get($this->options, 'failed_exchange') ?: null;
    }

    /**
     * Get the routing-key for failed messages
     * The default routing-key is the given destination substituted by '.failed'.
     *
     * @param string $destination
     * @return string
     */
    protected function getFailedRoutingKey(string $destination): string
    {
        return ltrim(sprintf(Arr::get($this->options, 'failed_routing_key') ?: '%s.failed', $destination), '.');
    }

    /**
     * Get the exchangeType, or AMQPExchangeType::DIRECT as default.
     *
     * @param string|null $type
     * @return string
     */
    protected function getExchangeType(?string $type = null): string
    {
        return @constant(AMQPExchangeType::class.'::'.Str::upper($type ?: Arr::get(
                $this->options,
                'exchange_type'
            ) ?: 'direct')) ?: AMQPExchangeType::DIRECT;
    }

    /**
     * Returns &true;, if failed messages should be rerouted.
     *
     * @return bool
     */
    protected function isRerouteFailed(): bool
    {
        return (bool) (Arr::get($this->options, 'reroute_failed') ?: false);
    }

    /**
     * Returns &true;, if declared queue must be quorum queue.
     *
     * @return bool
     */
    protected function isQuorum(): bool
    {
        return (bool) (Arr::get($this->options, 'quorum') ?: false);
    }

    /**
     * @return AMQPChannel
     */
    public function getChannel(): AMQPChannel
    {
        return $this->channel;
    }

    /**
     * Close the connection to RabbitMQ.
     *
     * @return void
     * @throws Exception
     */
    public function close(): void
    {
        if ($this->currentJob && ! $this->currentJob->isAckedOrRejected()) {
            $this->reject($this->currentJob, true);
        }

        try {
            $this->rabbitmq->close();
        } catch (ErrorException $exception) {
            // Ignore the exception
        }
    }

    /**
     * Recursively filter only null values.
     *
     * @param array $array
     * @return array
     */
    private static function filter(array $array): array
    {
        foreach ($array as $index => &$value) {
            if (is_array($value)) {
                $value = self::filter($value);
                continue;
            }

            // If the value is null then remove it.
            if ($value === null) {
                unset($array[$index]);
                continue;
            }
        }
        return $array;
    }
}
