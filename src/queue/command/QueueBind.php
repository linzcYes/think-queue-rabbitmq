<?php

namespace Linzc\ThinkQueueRabbitMQ\queue\command;

use Linzc\ThinkQueueRabbitMQ\queue\connector\RabbitMQ;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\Queue;

class QueueBind extends Command
{
    protected $queue;

    public function __construct(Queue $queue)
    {
        parent::__construct();

        $this->queue = $queue;
    }

    protected function configure()
    {
        $this->setName('rabbitmq:queue-bind')
            ->addArgument('queue', Argument::REQUIRED, '')
            ->addArgument('exchange', Argument::REQUIRED, '')
            ->addArgument('connection', Argument::OPTIONAL, 'The name of the queue connection to work')
            ->addOption('routing-key', null, Option::VALUE_OPTIONAL, 'Bind queue to exchange via routing key', '')
            ->setDescription('Bind queue to exchange');
    }

    protected function execute(Input $input, Output $output)
    {
        $connection = $input->getArgument('connection') ?: $this->app->config->get('queue.default');

        /** @var RabbitMQ $connector */
        $connector = $this->queue->connection($connection);

        $connector->bindQueue(
            $input->getArgument('queue'),
            $input->getArgument('exchange'),
            $input->getOption('routing-key')
        );

        $output->info('Queue bound to exchange successfully.');
    }
}
