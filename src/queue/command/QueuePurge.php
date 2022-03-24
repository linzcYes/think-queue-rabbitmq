<?php

namespace Linzc\ThinkQueueRabbitMQ\queue\command;

use Linzc\ThinkQueueRabbitMQ\queue\connector\RabbitMQ;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;
use think\Queue;

class QueuePurge extends Command
{
    protected $queue;

    public function __construct(Queue $queue)
    {
        parent::__construct();

        $this->queue = $queue;
    }

    protected function configure()
    {
        $this->setName('rabbitmq:queue-purge')
            ->addArgument('queue', Argument::REQUIRED, '')
            ->addArgument('connection', Argument::OPTIONAL, 'The name of the queue connection to work')
            ->setDescription('Purge all messages in queue');
    }

    protected function execute(Input $input, Output $output)
    {
        if (! $output->confirm($input, 'Are you sure to pruge all messages in queue?', false)) {
            return;
        }

        $connection = $input->getArgument('connection') ?: $this->app->config->get('queue.default');

        /** @var RabbitMQ $connector */
        $connector = $this->queue->connection($connection);

        $connector->purge($input->getArgument('queue'));

        $output->info('Queue purged successfully.');
    }
}
