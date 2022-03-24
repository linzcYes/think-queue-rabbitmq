<?php

namespace Linzc\ThinkQueueRabbitMQ\queue\command;

use Linzc\ThinkQueueRabbitMQ\queue\connector\RabbitMQ;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\Queue;

class QueueDelete extends Command
{
    protected $queue;

    public function __construct(Queue $queue)
    {
        parent::__construct();

        $this->queue = $queue;
    }

    protected function configure()
    {
        $this->setName('rabbitmq:queue-delete')
            ->addArgument('name', Argument::REQUIRED, 'The name of the queue to delete')
            ->addArgument('connection', Argument::OPTIONAL, 'The name of the queue connection to work')
            ->addOption('unused', null, Option::VALUE_OPTIONAL, 'Check if exchange is unused', 0)
            ->addOption('empty', null, Option::VALUE_OPTIONAL, 'Check if queue is empty', 0)
            ->setDescription('Delete queue');
    }

    protected function execute(Input $input, Output $output)
    {
        $connection = $input->getArgument('connection') ?: $this->app->config->get('queue.default');

        /** @var RabbitMQ $connector */
        $connector = $this->queue->connection($connection);

        if (! $connector->isQueueExists($input->getArgument('name'))) {
            $output->warning('Queue does not exist.');

            return;
        }

        $connector->deleteQueue(
            $input->getArgument('name'),
            (bool) $input->getOption('unused'),
            (bool) $input->getOption('empty')
        );

        $output->info('Queue deleted successfully.');
    }
}
