<?php

namespace Linzc\ThinkQueueRabbitMQ\queue\command;

use Linzc\ThinkQueueRabbitMQ\queue\connector\RabbitMQ;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\Queue;

class ExchangeDeclare extends Command
{
    protected $queue;

    public function __construct(Queue $queue)
    {
        parent::__construct();

        $this->queue = $queue;
    }

    protected function configure()
    {
        $this->setName('rabbitmq:exchange-declare')
            ->addArgument('name', Argument::REQUIRED, 'The name of the exchange to declare')
            ->addArgument('connection', Argument::OPTIONAL, 'The name of the queue connection to work')
            ->addOption('type', null, Option::VALUE_OPTIONAL, '', 'direct')
            ->addOption('durable', null, Option::VALUE_OPTIONAL, '', 1)
            ->addOption('auto-delete', null, Option::VALUE_OPTIONAL, '', 0)
            ->setDescription('Declare exchange');
    }

    protected function execute(Input $input, Output $output)
    {
        $connection = $input->getArgument('connection') ?: $this->app->config->get('queue.default');

        /** @var RabbitMQ $connector */
        $connector = $this->queue->connection($connection);

        if ($connector->isExchangeExists($input->getArgument('name'))) {
            $output->warning('Exchange already exists.');

            return;
        }

        $connector->declareExchange(
            $input->getArgument('name'),
            $input->getOption('type'),
            (bool) $input->getOption('durable'),
            (bool) $input->getOption('auto-delete')
        );

        $output->info('Exchange declared successfully.');
    }
}
