# php-redis-lock Redis并发锁

### 使用：
  
```php

  $rdLock = new RedisLockLogic;
  $key1 = 'mykey1';
  $key2 = 'mykey2';//同一个实例可以多个分别锁定多个key
  
  if($rdLock->lock($key1) && $rdLock->lock($key2))
  {
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
  }
```
