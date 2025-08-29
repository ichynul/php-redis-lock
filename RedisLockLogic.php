<?php

namespace app\common\logic;

/**
 * redis 并发锁
 * 
 * 使用：
 * 
 * $rdLock = new RedisLockLogic;
 * $key1 = 'mykey1';
 * $key2 = 'mykey2';//同一个实例可以分别锁定多个key
 * 
 * if($rdLock->lock($key1) && $rdLock->lock($key2))
 * {
 *      try{
 *          //业务
 *      }
 *      catch(\Exception $e){
 *
 *      }
 *      finally {
 *           //$rdLock->unlock($key1);
 *           //$rdLock->unlock($key2);
 *           $rdLock->unlockAll();//解锁全部
 *      }
 * }
 * 
 */
class RedisLockLogic
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

    protected $locks = [];

    protected $separator = '__redis_lock__';
    protected $predis = false;

    protected static $instance = [];

    public function __construct($options = [])
    {
        if ($options) {
            $this->options = array_merge($this->options, $options);
        }
        $str = preg_replace('/\W/', '_', __FILE__) . ':';
        if (is_null($this->options['select'])) {
            $char = substr(md5($this->options['prefix']), 0, 1);
            $this->options['select'] = is_numeric($char) ? $char : (['a' => 11, 'b' => 12, 'c' => 13, 'd' => 14, 'e' => 15, 'f' => 16][$char]);
        }
        $this->options['prefix'] = $this->options['prefix'] ?: $str;

        if (extension_loaded('redis')) {
            $this->handler = new \Redis;

            if ($this->options['persistent']) {
                $this->handler->pconnect($this->options['host'], $this->options['port'], $this->options['timeout'], 'persistent_id_' . $this->options['select']);
            } else {
                $this->handler->connect($this->options['host'], $this->options['port'], $this->options['timeout']);
            }

            if ('' != $this->options['password']) {
                $this->handler->auth($this->options['password']);
            }

            if (0 != $this->options['select']) {
                $this->handler->select($this->options['select']);
            }
        } elseif (class_exists('\Predis\Client')) {
            //predis composer安装

            $params = [];
            foreach ($this->options as $key => $val) {
                if (in_array($key, ['aggregate', 'cluster', 'connections', 'exceptions', 'prefix', 'profile', 'replication', 'parameters'])) {
                    $params[$key] = $val;
                    unset($this->options[$key]);
                }
            }

            if ('' == $this->options['password']) {
                unset($this->options['password']);
            }

            $this->handler = new \Predis\Client($this->options, $params);

            $this->options['prefix'] = '';
            $this->predis = true;
        } else {
            throw new \Exception('未安装Redis扩展或Predis');
        }

        static::$instance[] = $this;
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
        if (!empty(static::$instance)) {
            foreach (static::$instance as $key => $ins) {
                $ins->unlockAll();
            }
        }
        static::$instance = [];
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
        if ($this->predis) { //predis eval 参数不同
            return (bool) $this->handler->eval($script, 1, $key, $val);
        }

        return (bool) $this->handler->eval($script, [$key, $val], 1);
    }
}
