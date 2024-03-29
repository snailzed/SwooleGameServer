<?php


namespace gs;


use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\AnnotationRegistry;
use gs\annotation\Command;
use gs\annotation\Listener;
use gs\annotation\Middleware;
use gs\annotation\Process;
use gs\annotation\Route;
use gs\annotation\Task;
use gs\helper\ComposerHelper;
use interfaces\event\CustomEvent;
use interfaces\event\SwooleEvent;
use interfaces\InterfaceMiddleware;
use interfaces\InterfaceProcess;
use interfaces\InterfaceTask;
use traits\Singleton;

/**
 * Class Annotation
 * @package gs
 */
class Annotation
{
    use Singleton;
    /**
     * @var array
     */
    private $definitions = [];
    /**
     * @var array
     */
    protected $scanNamespaces = [
        'app\\websocket',
        'app\\event',
        'app\\task',
        'app\\process',
        'app\\http',
    ];
    /**
     * @var array
     */
    protected $ignoredNames = [
        'Usage',
        'Options',
        'Arguments',
        'Example',
        'package'
    ];

    /**
     * Annotation constructor.
     */
    public function __construct()
    {
        //这里进行命令扫描和解析
        AnnotationRegistry::registerLoader(function ($class) {
            if (class_exists($class) || interface_exists($class)) {
                return true;
            }
            return false;
        });
        $this->scanNamespaces = array_unique(array_merge($this->scanNamespaces, Config::getInstance()->get('scan_namespace')));
    }

    /**
     * @throws \Doctrine\Common\Annotations\AnnotationException
     * @throws \ReflectionException
     */
    public function collectDefinitions()
    {
        foreach ($this->scanNamespaces as $namespace) {
            $dir = realpath(str_replace('\\', '/', ComposerHelper::getDirByNamespace($namespace)));
            if (false === $dir) {
                continue;
            }
            $classes = $this->scanPhpFile($dir, $namespace);
            $this->parseAnnotations($classes);
        }
    }

    /**
     * @param array $classes
     * @throws \Doctrine\Common\Annotations\AnnotationException
     * @throws \ReflectionException
     */
    protected function parseAnnotations(array $classes)
    {
        foreach ($classes as $class) {
            if (!class_exists($class) && !interface_exists($class)) {
                continue;
            }
            // 注解解析器
            $reader = new AnnotationReader();
            $reader = $this->addIgnoredNames($reader);
            $reflectionClass = new \ReflectionClass($class);

            //解析类名
            $class_annos = $reader->getClassAnnotations($reflectionClass);
            if (!empty($class_annos)) {
                foreach ($class_annos as $anno) {
                    //事件监听
                    if ($anno instanceof Listener) {
                        if ($reflectionClass->implementsInterface(SwooleEvent::class)) {
                            $this->definitions['swoole_event'][$anno->getEvent()] = $reflectionClass->getName();
                        } else if ($reflectionClass->implementsInterface(CustomEvent::class)) {
                            $this->definitions['custom_event'][$anno->getEvent()][] = $reflectionClass->getName();
                        }
                    } else if ($anno instanceof Process && $reflectionClass->implementsInterface(InterfaceProcess::class)) {
                        //自定义进程
                        $this->definitions['process'][] = [
                            'class' => $reflectionClass->getName(),
                            'name'  => $anno->getName(),
                            'co'    => $anno->getCo(),
                        ];
                    } else if ($anno instanceof Task && $reflectionClass->hasMethod('handle')) {
                        //自定义任务
                        $this->definitions['task'][$anno->getName()] = $reflectionClass->getName();
                    } else if ($anno instanceof Middleware && $reflectionClass->implementsInterface(InterfaceMiddleware::class)) {
                        //如果是中间件类注解且实现了中间件接口
                        $middlewareName = $anno->getName();
                        $this->definitions['middleware'][$middlewareName] = [
                            'obj'    => $reflectionClass->newInstance(),
                            'weight' => $anno->getWeight(),
                        ];
                    }
                }
            }
            // 解析方法
            $publicMethods = $reflectionClass->getMethods(\ReflectionMethod::IS_PUBLIC);
            foreach ($publicMethods as $method) {
                if ($method->isStatic()) {
                    continue;
                }
                // 解析方法注解
                $sub_commands = $reader->getMethodAnnotations($method);
                foreach ($sub_commands as $sub_command) {
                    if (empty($sub_command)) {
                        continue;
                    }
                    if ($sub_command instanceof Command) {
                        $this->definitions['command'][$sub_command->getCode()] = [
                            'class'  => $method->getDeclaringClass()->getName(),
                            'method' => $method->getName(),
                        ];
                    } else if ($sub_command instanceof Route) {
                        $this->definitions['router'][] = [
                            $sub_command->getMethod(),
                            $sub_command->getUri(),
                            $method->getDeclaringClass()->getName() . '@' . $method->getName(),
                        ];
                    }
                }
            }
        }
    }

    /**获取注解定义
     * @return mixed
     */
    public function getDefinitions($name = null)
    {
        if (!is_null($name)) {
            if (!strpos($name, '.')) {
                return $this->definitions[$name] ?? [];
            }
            $value = explode('.', $name);
            $definitions = $this->definitions;
            while ($key = array_shift($value)) {
                if (isset($definitions[$key])) {
                    $definitions = $definitions[$key];
                } else {
                    return [];
                }
            }
            return $definitions;
        }
        return $this->definitions;
    }

    /**
     * @param $code
     * @return mixed
     */
    public function getCommand($code)
    {
        return $this->definitions['command'][$code] ?? false;
    }

    /**
     * @param $name
     * @return bool
     */
    public function getTask($name)
    {
        return $this->definitions['task'][$name] ?? false;
    }

    /**
     * 扫描目录下PHP文件
     *
     * @param string $dir
     * @param string $namespace
     *
     * @return array
     */
    protected function scanPhpFile(string $dir, string $namespace)
    {
        if (!is_dir($dir)) {
            return [];
        }
        $iterator = new \RecursiveDirectoryIterator($dir);
        $files = new \RecursiveIteratorIterator($iterator);

        $phpFiles = [];
        foreach ($files as $file) {
            $fileType = pathinfo($file, PATHINFO_EXTENSION);
            if ($fileType != 'php') {
                continue;
            }
            $replaces = ['', '\\', '', ''];
            $searches = [$dir, '/', '.php', '.PHP'];

            $file = str_replace($searches, $replaces, $file);
            $phpFiles[] = $namespace . $file;
        }
        return $phpFiles;
    }

    /**
     * add ignored names
     *
     * @param AnnotationReader $reader
     *
     * @return AnnotationReader
     */
    protected function addIgnoredNames(AnnotationReader $reader)
    {
        foreach ($this->ignoredNames as $name) {
            $reader->addGlobalIgnoredName($name);
        }
        return $reader;
    }


}