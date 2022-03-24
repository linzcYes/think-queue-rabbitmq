<?php

namespace Linzc\ThinkQueueRabbitMQ\queue;

use Linzc\ThinkQueueRabbitMQ\queue\command\Consume;
use Linzc\ThinkQueueRabbitMQ\queue\command\ExchangeDeclare;
use Linzc\ThinkQueueRabbitMQ\queue\command\ExchangeDelete;
use Linzc\ThinkQueueRabbitMQ\queue\command\QueueBind;
use Linzc\ThinkQueueRabbitMQ\queue\command\QueueDeclare;
use Linzc\ThinkQueueRabbitMQ\queue\command\QueueDelete;
use Linzc\ThinkQueueRabbitMQ\queue\command\QueuePurge;

class Service extends \think\Service
{
    public function register()
    {
        $config = require __DIR__.'/config.php';

        $queueConfig = $this->app->config->get('queue', []);
        $queueConfig['connections'] = array_merge(
            $config, $queueConfig['connections'] ?? []
        );

        $this->app->config->set($queueConfig, 'queue');
    }

    public function boot()
    {
        $this->commands([
            Consume::class,
            ExchangeDeclare::class,
            ExchangeDelete::class,
            QueueBind::class,
            QueueDeclare::class,
            QueueDelete::class,
            QueuePurge::class,
        ]);
    }
}
