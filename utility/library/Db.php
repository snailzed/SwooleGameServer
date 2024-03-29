<?php


namespace gs;


use gs\pool\AbstractChannelPool;
use gs\swoole\CoroutineContext;
use Medoo\Medoo;
use Swoole\Coroutine;
use traits\Singleton;

/**
 * Class Db
 * @package gs
 */
class Db extends AbstractChannelPool
{
    use Singleton;

    private $connections = [];

    /**
     * Db constructor.
     */
    public function __construct()
    {
        $config = Config::getInstance()->pull('database');
        $max = $config['max_size'] ?? 100;
        $min = $config['min_size'] ?? 2;
        $idel_time = $config['max_idel_time'] ?? 60;
        $interval_check_time = $config['interval_check_time'] ?? 60000;
        unset($config['max_size'], $config['min_size'], $config['max_idel_time'], $config['interval_check_time']);
        parent::__construct(Medoo::class, $min, $max, $idel_time, $interval_check_time, $config);
    }

    /**
     * @param $name
     * @param $arguments
     * @return mixed
     * @throws \Throwable
     */
    public function __call($name, $arguments)
    {
        /** @var Medoo $object */
        return $this->callDbMethod($name, $arguments);
    }

    /**
     * @param $name
     * @param $arguments
     * @return mixed
     * @throws \Throwable
     */
    private function callDbMethod($name, $arguments)
    {
        //1、先判断是否已经有了
        $cid = Coroutine::getCid();
        if (CoroutineContext::getInstance()->exists($cid)) {
            return $this->_callDbApi($cid, $name, $arguments);
        }
        //以下是非http和ws的协程
        $object = $this->connections[$cid] ?? false;
        if (!empty($object) && $object instanceof Medoo) {
            return call_user_func_array([$object, $name], $arguments);
        }
        $object = $this->pop();
        try {
            $ret = call_user_func_array([$object, $name], $arguments);
            $this->recycle($object);
            return $ret;
        } catch (\Throwable $throwable) {
            //如果断开连接了，则
            if ($this->isBreak($throwable)) {
                unset($object);
                $object = $this->newInstance();
                $ret = call_user_func_array([$object, $name], $arguments);
                $this->recycle($object);
                Log::error($throwable);
                return $ret;
            }
            $this->recycle($object);
            throw $throwable;
        }
    }

    /**
     * @param $cid
     * @param $name
     * @param $arguments
     * @return mixed
     * @throws \Throwable
     */
    private function _callDbApi($cid, $name, $arguments)
    {
        $object = $this->connections[$cid] ?? false;
        if (!empty($object) && $object instanceof Medoo) {
            return call_user_func_array([$object, $name], $arguments);
        } else {
            $object = $this->pop();
            $this->connections[$cid] = $object;
        }
        try {
            $ret = call_user_func_array([$object, $name], $arguments);
            return $ret;
        } catch (\Throwable $throwable) {
            if ($this->isBreak($throwable)) {
                unset($object);
                $this->connections[$cid] = $this->newInstance();
                Log::error($throwable);
                return $this->_callDbApi($cid, $name, $arguments);
            }
            throw $throwable;
        }
    }


    /**
     *回收连接
     */
    public function recycleConnection($cid)
    {
        if (isset($this->connections[$cid])) {
            $object = $this->connections[$cid];
            parent::recycle($object);
            unset($this->connections[$cid]);
        }
    }


    /**判断数据库是否断线
     * @param \Throwable $e
     * @return bool
     */
    protected function isBreak($e)
    {
        $info = [
            'server has gone away',
            'no connection to the server',
            'Lost connection',
            'is dead or not enabled',
            'Error while sending',
            'decryption failed or bad record mac',
            'server closed the connection unexpectedly',
            'SSL connection has been closed unexpectedly',
            'Error writing data to the connection',
            'Resource deadlock avoided',
            'failed with errno',
        ];
        $error = $e->getMessage();
        foreach ($info as $msg) {
            if (false !== stripos($error, $msg)) {
                return true;
            }
        }
        return false;
    }

    /**判断实例是否有效
     * @return bool
     */
    public function isValid($object): bool
    {
        // TODO: Implement isValid() method.
        return true;
    }

    /**
     * @param callable $callable
     * @return mixed
     * @throws \Throwable
     */
    public function transaction(callable $callable)
    {
        $cid = Coroutine::getCid();
        if (!isset($this->connections[$cid])) {
            $object = $this->pop();
            $this->connections[$cid] = $object;
        } else {
            $object = $this->connections[$cid];
        }
        try {
            $object->pdo->beginTransaction();
            $ret = $callable();
            $object->pdo->commit();
            $this->recycle($object);
            unset($this->connections[$cid]);
            return $ret;
        } catch (AppException $appException) {
            $object->pdo->rollBack();
            $this->recycle($object);
            unset($this->connections[$cid]);
            throw $appException;
        } catch (\Throwable $throwable) {
            if ($this->isBreak($throwable)) {
                unset($object);
                unset($this->connections[$cid]);
                $this->push($this->create());
                return $this->transaction($callable);
            }
            $object->pdo->rollBack();
            $this->recycle($object);
            unset($this->connections[$cid]);
            throw $throwable;
        }
    }

    /**
     * 获取新的实例,不归连接池管理
     * @return mixed
     */
    public function newInstance()
    {
        return new $this->class(...($this->args));
    }
}