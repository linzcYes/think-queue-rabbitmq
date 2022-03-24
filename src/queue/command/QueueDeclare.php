<?php

namespace Linzc\ThinkQueueRabbitMQ\queue\command;

use Linzc\ThinkQueueRabbitMQ\queue\connector\RabbitMQ;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\Queue;

class QueueDeclare extends Command
{
    protected $queue;

    public function __construct(Queue $queue)
    {
        parent::__construct();

        $this->queue = $queue;
    }

    protected function configure()
    {
        $this->setName('rabbitmq:queue-declare')
            ->addArgument('name', Argument::REQUIRED, 'The name of the queue to declare')
            ->addArgument('connection', Argument::OPTIONAL, 'The name of the queue connection to work')
            ->addOption('max-priority', null, Option::VALUE_OPTIONAL, '', 0)
            ->addOption('durable', null, Option::VALUE_OPTIONAL, '', 1)
            ->addOption('auto-delete', null, Option::VALUE_OPTIONAL, '', 0)
            ->addOption('quorum', null, Option::VALUE_OPTIONAL, '', 0)
            ->setDescription('Declare queue');
    }

    protected function execute(Input $input, Output $output)
    {
        $connection = $input->getArgument('connection') ?: $this->app->config->get('queue.default');

        /** @var RabbitMQ $connector */
        $connector = $this->queue->connection($connection);

        if ($connector->isQueueExists($input->getArgument('name'))) {
            $output->warning('Queue already exists.');

            return;
        }

        $arguments = [];
        if ($maxPriority = (int) $input->getOption('max-priority')) {
            $arguments['x-max-priority'] = $maxPriority;
        }
        if ($input->getOption('quorum')) {
            $arguments['x-queue-type'] = 'quorum';
        }

        $connector->declareQueue(
            $input->getArgument('name'),
            (bool) $input->getOption('durable'),
            (bool) $input->getOption('auto-delete'),
            $arguments
        );

        $output->info('Queue declared successfully.');
    }
}
