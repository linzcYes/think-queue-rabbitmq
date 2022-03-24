<?php

namespace Linzc\ThinkQueueRabbitMQ\queue;

use Exception;
use Linzc\ThinkQueueRabbitMQ\queue\connector\RabbitMQ as RabbitMQConnector;
use Linzc\ThinkQueueRabbitMQ\queue\job\RabbitMQ as RabbitMQJob;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Message\AMQPMessage;
use think\App;
use think\queue\Worker;
use Throwable;

class Consumer extends Worker
{

    /** @var App */
    protected $app;

    /** @var string */
    protected $consumerTag;

    /** @var int */
    protected $prefetchSize;

    /** @var int */
    protected $maxPriority;

    /** @var int */
    protected $prefetchCount;

    /** @var AMQPChannel */
    protected $channel;

    /**
     * Current job being processed.
     *
     * @var RabbitMQJob
     */
    protected $currentJob;


    public function setApp(App $app): void
    {
        $this->app = $app;
    }

    public function setConsumerTag(string $value): void
    {
        $this->consumerTag = $value;
    }

    public function setMaxPriority(int $value): void
    {
        $this->maxPriority = $value;
    }

    public function setPrefetchSize(int $value): void
    {
        $this->prefetchSize = $value;
    }

    public function setPrefetchCount(int $value): void
    {
        $this->prefetchCount = $value;
    }

    public function daemon($connection, $queue, $delay = 0, $sleep = 3, $maxTries = 0, $memory = 128, $timeout = 60)
    {
        if ($this->supportsAsyncSignals()) {
            $this->listenForSignals();
        }

        $lastRestart = $this->getTimestampOfLastQueueRestart();

        [$startTime, $jobsProcessed] = [hrtime(true) / 1e9, 0];

        /** @var RabbitMQConnector $connector */
        $connector = $this->queue->connection($connection);

        $this->channel = $connector->getChannel();

        $this->channel->basic_qos(
            $this->prefetchSize,
            $this->prefetchCount,
            null
        );

        $jobClass = $connector->getJobClass();
        $arguments = [];
        if ($this->maxPriority) {
            $arguments['priority'] = ['I', $this->maxPriority];
        }

        $this->channel->basic_consume(
            $queue,
            $this->consumerTag,
            false,
            false,
            false,
            false,
            function (AMQPMessage $message) use ($connector, $connection, $queue, $delay, $maxTries, $timeout, $jobClass, &$jobsProcessed): void {
                $job = new $jobClass(
                    $this->app,
                    $connector,
                    $message,
                    $connection,
                    $queue
                );

                $this->currentJob = $job;

                if ($this->supportsAsyncSignals()) {
                    $this->registerTimeoutHandler($job, $timeout);
                }

                $jobsProcessed++;

                $this->runJob($job, $connection, $maxTries, $delay);
            },
            null,
            $arguments
        );

        while ($this->channel->is_consuming()) {
            // If the daemon should run (not in maintenance mode, etc.), then we can wait for a job.
            try {
                $this->channel->wait(null, true, (int) $timeout);
            } catch (AMQPRuntimeException $exception) {
                $this->handle->report($exception);

                $this->kill(1);
            } catch (Exception | Throwable $exception) {
                $this->handle->report($exception);
            }

            // If no job is got off the queue, we will need to sleep the worker.
            if ($this->currentJob === null) {
                $this->sleep($sleep);
            }

            $this->currentJob = null;

            // Finally, we will check to see if we have exceeded our memory limits or if
            // the queue should restart based on other indications. If so, we'll stop
            // this worker and let whatever is "monitoring" it restart the process.
            $this->stopIfNecessary($this->currentJob, $lastRestart, $memory);
        }
    }

    public function stop($status = 0)
    {
        // Tell the server you are going to stop consuming.
        // It will finish up the last message and not send you any more.
        $this->channel->basic_cancel($this->consumerTag, false, true);

        parent::stop($status);
    }

}
