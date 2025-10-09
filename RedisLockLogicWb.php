<?php

namespace app\common\logic;

use Webman\Context;
use Workerman\Coroutine\Pool;

/**
 * redis 并发锁 for webman(协程)
 * 
 */
class RedisLockLogicWb
{
    protected $handler;

    protected $options = [
        'host' => '127.0.0.1',
        'port' => 6379,
        'password' => '',
        'select' => null, //0~15 或null自动选择
        'timeout' => 0,
        'persistent' => false,
        'prefix' => '', //填写或留空自动选择
    ];

    protected $poolConfig = [
        'max_connections' => 100, // 最大连接数
        'min_connections' => 1, // 最小连接数
        'wait_timeout' => 3,    // 从连接池获取连接等待超时时间
        'idle_timeout' => 60,   // 连接最大空闲时间，超过该时间会被回收
        'heartbeat_interval' => 50, // 心跳检测间隔，需要小于60秒
    ];

    protected $locks = [];

    protected $separator = '__redis_lock__';

    /**
     * @var Pool
     */
    protected static $pool = null;

    public function __construct($options = [])
    {
        if ($options) {
            $this->options = array_merge($this->options, $options);
        }

        $this->handler = $this->createDriver();

        // 使用Context存储实例，避免静态变量在协程间共享导致的问题
        $instances = Context::get(static::class) ?: [];
        $instances[] = $this;
        Context::set(static::class, $instances);
    }

    /**
     * 加锁
     *
     * @param string $key
     * @param string $owner 用于区别不同操作人，如：用户id，用户ip
     * @param int $expire 业务超时(秒)，根据业务复杂程度调节。尽量大些，避免请求量大时服务器卡顿导致锁自动释放。锁的释放应该在业务/请求结束时手动释放，而不是依赖超时自动。
     * @return bool 是否加锁成功
     */
    public function lock($key, $owner = '', $expire = 120)
    {
        if (!empty($this->options['prefix'])) {
            $key = $this->options['prefix'] . $key;
        }

        if (isset($this->locks[$key])) {
            throw new \Exception('已存在key不为空,请确保每个key只加锁一次:' . $key); //
        }

        if ($expire < 5) {
            $expire = 5;
        }

        //操作人为空，使用随机数
        if (empty($owner)) {
            $owner = mt_rand();
        }

        $isLock = $this->add($key, $owner, $expire) || $this->checkExpire($key, $owner, $expire);

        return $isLock;
    }

    /**
     * 解锁
     * 
     * 正常业务逻辑后释放锁
     * 只有加锁的人可以释放
     * @param string $key
     * @return void
     */
    public function unlock($key)
    {
        if (!empty($this->options['prefix'])) {
            $key = $this->options['prefix'] . $key;
        }

        if (!isset($this->locks[$key])) {
            throw new \Exception('加锁成功过才能解锁,key:' . $key); //
        }

        $this->del($key, $this->locks[$key]);

        unset($this->locks[$key]);
    }

    /**
     * 解锁全部
     * @return void
     */
    public function unlockAll()
    {
        foreach ($this->locks as $key => $val) {
            $this->del($key, $val);
            unset($this->locks[$key]);
        }

        $this->handler = null;
    }

    /**
     * 检测超时并重试加锁
     *
     * @param string $key
     * @param string $owner
     * @param int $expire
     * @return bool
     */
    protected function checkExpire($key, $owner, $expire)
    {
        $isLock = false;

        $saveVal = $this->handler->get($key); // 取出保存值: 1675238193__redis_lock__user10001_1234

        if ($saveVal) {
            $lockTime = explode($this->separator, $saveVal)[0]; //1675238193

            // 锁已过期
            if (time() > $lockTime && $this->del($key, $saveVal)) {
                $isLock = $this->add($key, $owner, $expire);
            }
        }

        return $isLock;
    }

    /**
     * 尝试加锁
     *
     * @param string $key
     * @param string $owner
     * @param int $expire
     * @return bool
     */
    protected function add($key, $owner, $expire)
    {
        $mtime = explode('.', microtime(true));

        $val = ($mtime[0] + $expire) . //值
            $this->separator . //分割符
            $owner . '_' . ($mtime[1] ?? '0000'); //操作人

        /**
         * microtime    : 1675238190.1234
         * owner        : user10001
         * expire       : 3
         * 
         * val          : 1675238193__redis_lock__user10001_1234
         */

        $isLock = $this->handler->setnx($key, $val);

        if ($isLock) {
            //成功，保存当前信息，以供解锁
            $this->locks[$key] = $val;

            //防止key写入成功后未正常释放，后续再未被访问，key就会一直存在。
            //让redis主动清理掉
            $this->handler->expire($key, $expire + 600);
        }
        return $isLock;
    }

    /**
     * 检测并释放未正常释放的key。一般用在中间件中请求结束时调用
     */
    public static function checkUnexpectedKeys()
    {
        // 从Context中获取当前请求的实例
        $instances = Context::get(static::class);
        if (!empty($instances)) {
            foreach ($instances as $ins) {
                $ins->unlockAll();
            }
        }
        // 清理Context中的实例
        Context::set(static::class, null);
    }

    /**
     * Undocumented function
     *
     * @param string $key
     * @param string $val
     * @return bool
     */
    protected function del($key, $val)
    {
        $script = <<<'LUA'
if redis.call("get",KEYS[1]) == ARGV[1] then
    return redis.call("del",KEYS[1])
else
    return 0
end
LUA;
        if (class_exists(\Predis\Client::class) && $this->handler instanceof \Predis\Client) {
            return (bool) $this->handler->eval($script, 1, $key, $val);
        }
        return (bool) $this->handler->eval($script, [$key, $val], 1);
    }

    /**
     * 创建驱动
     *
     * @return mixed
     *
     * @throws \Throwable
     */
    protected function createDriver(): mixed
    {
        $key = "__redis_lock__";

        // 尝试从当前请求的Context中获取连接
        $connection = Context::get($key);

        // 如果当前请求的Context中没有连接，则从连接池获取一个
        if (!$connection) {
            // 初始化连接池（如果尚未初始化）
            if (!static::$pool) {
                static::$pool = new Pool($this->poolConfig['max_connections'] ?? 10, $this->poolConfig);
                static::$pool->setConnectionCreator(function () {
                    return $this->make();
                });
                static::$pool->setConnectionCloser(function ($connection) {
                    if (method_exists($connection, 'close')) {
                        $connection->close();
                    } else {
                        $connection->quit();
                    }
                });
                static::$pool->setHeartbeatChecker(function ($connection) {
                    $connection->get('PING');
                });
            }
            try {
                $connection = static::$pool->get();
                Context::set($key, $connection);
            } finally {
                Context::onDestroy(function () use ($connection) {
                    try {
                        $connection && static::$pool->put($connection);
                    } catch (\Throwable $e) {
                        // ignore
                    }
                });
            }
        }

        return $connection;
    }

    protected function make()
    {
        //根据项目的路径自动选择，前缀和数据库
        $str = preg_replace('/\W/', '_', __FILE__) . ':';
        if (is_null($this->options['select'])) {
            $char = substr(md5($this->options['prefix']), 0, 1);
            $this->options['select'] = is_numeric($char) ? $char : (['a' => 11, 'b' => 12, 'c' => 13, 'd' => 14, 'e' => 15, 'f' => 16][$char]);
        }
        $this->options['prefix'] = $this->options['prefix'] ?: $str;

        $handler = null;

        if (extension_loaded('redis')) {
            $handler = new \Redis;

            if ($this->options['persistent']) {
                $handler->pconnect($this->options['host'], $this->options['port'], $this->options['timeout'], 'persistent_id_' . $this->options['select']);
            } else {
                $handler->connect($this->options['host'], $this->options['port'], $this->options['timeout']);
            }

            if ('' != $this->options['password']) {
                $handler->auth($this->options['password']);
            }

            if (0 != $this->options['select']) {
                $handler->select($this->options['select']);
            }
        } elseif (class_exists('\Predis\Client')) {
            //predis composer安装
            $params = [];
            $options = $this->options;
            foreach ($options as $key => $val) {
                if (in_array($key, ['aggregate', 'cluster', 'connections', 'exceptions', 'prefix', 'profile', 'replication', 'parameters'])) {
                    $params[$key] = $val;
                    unset($options[$key]);
                }
            }

            if ('' == $options['password']) {
                unset($options['password']);
            }

            $handler = new \Predis\Client($options, $params);

            $this->options['prefix'] = '';
        } else {
            throw new \Exception('未安装Redis扩展或Predis');
        }

        return $handler;
    }
}
