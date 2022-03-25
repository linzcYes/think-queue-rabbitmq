<?php

namespace Linzc\ThinkQueueRabbitMQ\queue\job;

use Linzc\ThinkQueueRabbitMQ\queue\connector\RabbitMQ as RabbitMQQueue;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use think\App;
use think\helper\Arr;
use think\queue\Job;

class RabbitMQ extends Job
{

    /**
     * The rabbitmq queue instance.
     *
     * @var RabbitMQQueue
     */
    protected $rabbitmq;

    /**
     * The RabbitMQ message instance.
     *
     * @var AMQPMessage
     */
    protected $message;

    /**
     * The JSON decoded version of "$job".
     *
     * @var array
     */
    protected $decoded;

    /** @var bool */
    protected $acked = false;

    /** @var bool */
    protected $rejected = false;

    public function __construct(App $app, RabbitMQQueue $rabbitmq, $job, $connection, $queue)
    {
        $this->app        = $app;
        $this->message    = $job;
        $this->queue      = $queue;
        $this->rabbitmq   = $rabbitmq;
        $this->connection = $connection;

        $this->decoded = $this->payload();
    }

    /**
     * @inheritDoc
     */
    public function getJobId()
    {
        return $this->decoded['id'] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function attempts()
    {
        if (! $data = $this->getRabbitMQMessageHeaders()) {
            return 1;
        }

        $attempts = (int) Arr::get($data, 'think.attempts', 0);

        return $attempts + 1;
    }

    /**
     * @inheritDoc
     */
    public function getRawBody()
    {
        return $this->message->getBody();
    }

    /**
     * {@inheritdoc}
     */
    public function markAsFailed()
    {
        parent::markAsFailed();

        // We must tel rabbitMQ this Job is failed
        // The message must be rejected when the Job marked as failed, in case rabbitMQ wants to do some extra magic.
        // like: Death lettering the message to an other exchange/routing-key.
        if (! $this->isAckedOrRejected()) {
            $this->reject($this);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function delete()
    {
        parent::delete();

        // When delete is called and the Job was not failed, the message must be acknowledged.
        // This is because this is a controlled call by a developer. So the message was handled correct.
        if (! $this->isAckedOrRejected()) {
            $this->ack();
        }
    }

    /**
     * Release the job back into the queue.
     *
     * @param int $delay
     * @throws AMQPProtocolChannelException
     */
    public function release($delay = 0)
    {
        parent::release();

        // Always create a new message when this Job is released
        $this->rabbitmq->laterRaw($delay, $this->message->getBody(), $this->queue, $this->attempts());

        // Releasing a Job means the message was failed to process.
        // Because this Job message is always recreated and pushed as new message, this Job message is correctly handled.
        // We must tell rabbitMQ this job message can be removed by acknowledging the message.
        if (! $this->isAckedOrRejected()) {
            $this->ack();
        }
    }

    /**
     * acked the messge
     */
    public function ack()
    {
        $this->rabbitmq->ack($this);

        $this->acked = true;
    }

    /**
     * Determine if the job has been acked
     *
     * @return bool
     */
    public function isAcked()
    {
        return $this->acked;
    }

    /**
     * reject the message
     */
    public function reject()
    {
        $this->rabbitmq->reject($this);

        $this->rejected = true;
    }

    /**
     * Determine if the job has been rejected
     *
     * @return bool
     */
    public function isRejected()
    {
        return $this->rejected;
    }

    /**
     * Determine if the job has been acked or rejected.
     *
     * @return bool
     */
    public function isAckedOrRejected()
    {
        return $this->isAcked() || $this->isRejected();
    }

    /**
     * Get the underlying RabbitMQ connection.
     *
     * @return RabbitMQQueue
     */
    public function getRabbitMQ(): RabbitMQQueue
    {
        return $this->rabbitmq;
    }

    /**
     * Get the underlying RabbitMQ message.
     *
     * @return AMQPMessage
     */
    public function getRabbitMQMessage(): AMQPMessage
    {
        return $this->message;
    }

    /**
     * Get the headers from the rabbitMQ message.
     *
     * @return array|null
     */
    protected function getRabbitMQMessageHeaders(): ?array
    {
        /** @var AMQPTable|null $headers */
        if (! $headers = Arr::get($this->message->get_properties(), 'application_headers')) {
            return null;
        }

        return $headers->getNativeData();
    }
}
