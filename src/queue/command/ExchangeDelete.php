<?php

namespace Linzc\ThinkQueueRabbitMQ\queue\command;

use Linzc\ThinkQueueRabbitMQ\queue\connector\RabbitMQ;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\Queue;

class ExchangeDelete extends Command
{
    protected $queue;

    public function __construct(Queue $queue)
    {
        parent::__construct();

        $this->queue = $queue;
    }

    protected function configure()
    {
        $this->setName('rabbitmq:exchange-delete')
            ->addArgument('name', Argument::REQUIRED, 'The name of the exchange to delete')
            ->addArgument('connection', Argument::OPTIONAL, 'The name of the queue connection to work')
            ->addOption('unused', null, Option::VALUE_OPTIONAL, 'Check if exchange is unused', 0)
            ->setDescription('Delete exchange');
    }

    protected function execute(Input $input, Output $output)
    {
        $connection = $input->getArgument('connection') ?: $this->app->config->get('queue.default');

        /** @var RabbitMQ $connector */
        $connector = $this->queue->connection($connection);

        if (! $connector->isExchangeExists($input->getArgument('name'))) {
            $output->warning('Exchange does not exist.');

            return;
        }

        $connector->deleteExchange(
            $input->getArgument('name'),
            (bool) $input->getOption('unused')
        );

        $output->info('Exchange deleted successfully.');
    }
}
