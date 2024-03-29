<?php


namespace interfaces\event;

/**自定义事件
 * Interface CustomEvent
 * @package interfaces
 */
interface CustomEvent
{
    const ON_BEFORE_START = 'onBeForeStart';
    const ON_FRAMEWORK_INITED = 'onFrameworkInited';//当服务初始化完成，开启server之前
    const ON_START = 'onStart';
    const ON_WORKER_START = 'onWorkerStart';
    const ON_MANAGER_START = 'onManagerStart';
}