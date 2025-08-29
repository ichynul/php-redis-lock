# php-redis-lock Redis并发锁

### 使用：
  
```php

$rdLock = new RedisLockLogic;
$key1 = 'ip_127.0.0.1';
$key2 = 'mobile_13800000000';//同一个实例可以多个分别锁定多个key

if(!($rdLock->lock($key1) && $rdLock->lock($key2))){
     return '加锁失败';
}

try{
           //业务
}
catch(\Exception $e){

}
finally {
     //$rdLock->unlock($key1);
     //$rdLock->unlock($key2);
     $rdLock->unlockAll();//解锁全部
}
```

中间件示例：
```php
use app\common\logic\RedisLockLogic;

/**
 * 请求结束后释放未正常释放的锁
 * Class RedisLock
 * @package think\middleware
 */
class RedisLock implements MiddlewareInterface
{
    /**
     * 处理锁释放
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function process(Request $request, callable $next): Response
    {
        $response = $next($request);
        RedisLockLogic::checkUnexpectedKeys();
        return $response;
    }
}
```