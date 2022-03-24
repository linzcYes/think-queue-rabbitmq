
# RabbitMQ driver for ThinkPHP6 Queue.

## 安装

> composer require linzc/think-queue-rabbitmq

## 配置

公共配置
```php
[
    'default' => 'rabbitmq' // 驱动类型，可选择 sync(默认):同步执行，database:数据库驱动，redis:Redis驱动，rabbitmq:RabbitMQ驱动 //或其他自定义的完整的类名
]
```

添加连接到config/queue.php：
```php
'connections' => [
    // ...

    'rabbitmq' => [
        'type'       => Linzc\ThinkQueueRabbitMQ\queue\connector\RabbitMQ::class,
        'connection' => PhpAmqpLib\Connection\AMQPLazyConnection::class,
        'queue'      => 'default',
        'hosts' => [
            [
                'host'     => '127.0.0.1',
                'port'     => 5672,
                'user'     => 'guest',
                'password' => 'guest',
                'vhost'    => '/',
            ],
        ],
        'options' => [
            'ssl_options' => [
                'cafile'      => null,
                'local_cert'  => null,
                'local_key'   => null,
                'verify_peer' => null,
                'passphrase'  => null,
            ],
            'queue' => [
                'job' => Linzc\ThinkQueueRabbitMQ\queue\job\RabbitMQ::class,
            ],
        ],
    ],

    // ...    
],
```

###可选配置
可以选择将队列选项添加到连接的配置中。为此连接创建的每个队列，都获取属性。

如果您想在消息延迟时对其进行优先级排序，则可以通过添加额外选项来实现。

- 省略 max-priority 时，使用时最大优先级设置为 2。

```php
'connections' => [
    // ...

    'rabbitmq' => [
        // ...

        'options' => [
            'queue' => [
                // ...

                'prioritize_delayed' => false,
                'queue_max_priority' => 10,
            ],
        ],
    ],

    // ...    
],
```

当您想针对带有路由密钥的交换发布消息时，可以通过添加额外的选项来实现。

- 省略 exchange 时，RabbitMQ将使用默认交换机
- 省略 routing-key 时，routing-key 是 queue 的名称
- 在路由键中使用%s时，将替换为 queue 队列名称

> 注意：当使用 exchange 和 routing-key 时，你需要创建带有绑定的队列。

```php
'connections' => [
    // ...

    'rabbitmq' => [
        // ...

        'options' => [
            'queue' => [
                // ...

                'exchange' => 'exchange-name',
                'exchange_type' => 'topic',
                'exchange_routing_key' => '',
            ],
        ],
    ],

    // ...    
],
```

在 ThinkPHP 中，失败的作业被存储到数据库中。但也许您想指示其他进程也对消息进行处理。当您想指示 RabbitMQ 将失败的消息重新路由到交换器或特定队列时，可以通过添加额外的选项来实现。

- 省略 exchange 时，RabbitMQ将使用默认交换机
- 省略 routing-key 时，routing-key 为 queue 队列名称 + '.failed'
- 在路由键中使用%s时，将替换为 queue 队列名称

> 注意：当使用 exchange 和 routing-key 时，你需要创建带有绑定的交换机/队列。

```php
'connections' => [
    // ...

    'rabbitmq' => [
        // ...

        'options' => [
            'queue' => [
                // ...

                'reroute_failed' => true,
                'failed_exchange' => 'failed-exchange',
                'failed_routing_key' => 'failed_routing_key.%s',
            ],
        ],
    ],

    // ...    
],
```

## 创建任务类
> 单模块项目推荐使用 `app\job` 作为任务类的命名空间
> 多模块项目可用使用 `app\module\job` 作为任务类的命名空间
> 也可以放在任意可以自动加载到的地方

任务类不需继承任何类，如果这个类只有一个任务，那么就只需要提供一个`fire`方法就可以了，如果有多个小任务，就写多个方法，下面发布任务的时候会有区别  
每个方法会传入两个参数 `think\queue\Job $job`（当前的任务对象） 和 `$data`（发布任务时自定义的数据）

还有个可选的任务失败执行的方法 `failed` 传入的参数为`$data`（发布任务时自定义的数据）

### 下面写两个例子

```
namespace app\job;

use think\queue\Job;

class Job1{
    
    public function fire(Job $job, $data){
    
            //....这里执行具体的任务 
            
             if ($job->attempts() > 3) {
                  //通过这个方法可以检查这个任务已经重试了几次了
             }
            
            
            //如果任务执行成功后 记得删除任务，不然这个任务会重复执行，直到达到最大重试次数后失败后，执行failed方法
            $job->delete();
            
            // 也可以重新发布这个任务
            $job->release($delay); //$delay为延迟时间
          
    }
    
    public function failed($data){
    
        // ...任务达到最大重试次数后，失败了
    }

}

```

```

namespace app\lib\job;

use think\queue\Job;

class Job2{
    
    public function task1(Job $job, $data){
    
          
    }
    
    public function task2(Job $job, $data){
    
          
    }
    
    public function failed($data){
    
          
    }

}

```


## 发布任务
> `think\facade\Queue::push($job, $data = '', $queue = null)` 和 `think\facade\Queue::later($delay, $job, $data = '', $queue = null)` 两个方法，前者是立即执行，后者是在`$delay`秒后执行

`$job` 是任务名  
单模块的，且命名空间是`app\job`的，比如上面的例子一,写`Job1`类名即可  
多模块的，且命名空间是`app\module\job`的，写`model/Job1`即可  
其他的需要些完整的类名，比如上面的例子二，需要写完整的类名`app\lib\job\Job2`  
如果一个任务类里有多个小任务的话，如上面的例子二，需要用@+方法名`app\lib\job\Job2@task1`、`app\lib\job\Job2@task2`

`$data` 是你要传到任务里的参数

`$queue` 队列名，指定这个任务是在哪个队列上执行，同下面监控队列的时候指定的队列名,可不填


### 消费消息
有两种消费消息的方式。

- queue:work 命令是 ThinkPHP 的内置命令。该命令利用basic_get。
- rabbitmq:consume 此软件包提供的命令。此命令使用basic_consume，比basic_get性能更高。

具体的可选参数可以输入命令加 --help 查看

>可配合supervisor使用，保证进程常驻

### 其他命令

- rabbitmq:exchange-declare 声明交换机
- rabbitmq:exchange-delete 删除交换机
- rabbitmq:queue-bind 队列绑定交换机
- rabbitmq:queue-declare 声明队列
- rabbitmq:queue-delete 删除队列
- rabbitmq:queue-purge 清除队列所有消息
