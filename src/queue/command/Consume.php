<?php

namespace Linzc\ThinkQueueRabbitMQ\queue\command;

use Linzc\ThinkQueueRabbitMQ\queue\Consumer;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\helper\Str;
use think\queue\command\Work;

class Consume extends Work
{

    public function __construct(Consumer $worker)
    {
        parent::__construct($worker);
    }

    protected function configure()
    {
        $this->setName('rabbitmq:consume')
            ->addArgument('connection', Argument::OPTIONAL, 'The name of the queue connection to work')
            ->addOption('queue', null, Option::VALUE_OPTIONAL, 'The queue to listen on')
            ->addOption('once', null, Option::VALUE_NONE, 'Only process the next job on the queue')
            ->addOption('delay', null, Option::VALUE_OPTIONAL, 'Amount of time to delay failed jobs', 0)
            ->addOption('force', null, Option::VALUE_NONE, 'Force the worker to run even in maintenance mode')
            ->addOption('memory', null, Option::VALUE_OPTIONAL, 'The memory limit in megabytes', 128)
            ->addOption('timeout', null, Option::VALUE_OPTIONAL, 'The number of seconds a child process can run', 60)
            ->addOption('sleep', null, Option::VALUE_OPTIONAL, 'Number of seconds to sleep when no job is available', 3)
            ->addOption('tries', null, Option::VALUE_OPTIONAL, 'Number of times to attempt a job before logging it failed', 0)
            ->addOption('max-priority', null, Option::VALUE_OPTIONAL, '', '')
            ->addOption('consumer-tag', null, Option::VALUE_OPTIONAL, '', '')
            ->addOption('prefetch-size', null, Option::VALUE_OPTIONAL, '', 0)
            ->addOption('prefetch-count', null, Option::VALUE_OPTIONAL, '', 1000)
            ->setDescription('Consume messages');
    }

    public function execute(Input $input, Output $output)
    {
        /** @var Consumer $consumer */
        $consumer = $this->worker;

        $consumer->setApp($this->app);
        $consumer->setConsumerTag($this->consumerTag());
        $consumer->setMaxPriority((int) $input->getOption('max-priority'));
        $consumer->setPrefetchSize((int) $input->getOption('prefetch-size'));
        $consumer->setPrefetchCount((int) $input->getOption('prefetch-count'));

        parent::execute($input, $output);
    }

    protected function consumerTag(): string
    {
        if ($consumerTag = $this->input->getOption('consumer-tag')) {
            return $consumerTag;
        }

        $consumerTag = implode('_', [
            'think',
            $this->input->getOption('queue'),
            md5(serialize($this->input->getOptions()) . Str::random(16) . getmypid()),
        ]);

        return Str::substr($consumerTag, 0, 255);
    }
}
