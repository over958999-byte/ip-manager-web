<?php
/**
 * 依赖注入容器
 * 轻量级 DI Container，支持单例、工厂、自动装配
 */

class Container {
    private static ?Container $instance = null;
    
    // 绑定定义
    private array $bindings = [];
    
    // 单例实例缓存
    private array $instances = [];
    
    // 别名映射
    private array $aliases = [];
    
    private function __construct() {}
    
    /**
     * 获取容器实例（单例）
     */
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 绑定服务
     * @param string $abstract 抽象名/接口名
     * @param mixed $concrete 具体实现（类名、闭包、实例）
     * @param bool $shared 是否单例
     */
    public function bind(string $abstract, $concrete = null, bool $shared = false): self {
        if ($concrete === null) {
            $concrete = $abstract;
        }
        
        $this->bindings[$abstract] = [
            'concrete' => $concrete,
            'shared' => $shared
        ];
        
        return $this;
    }
    
    /**
     * 绑定单例
     */
    public function singleton(string $abstract, $concrete = null): self {
        return $this->bind($abstract, $concrete, true);
    }
    
    /**
     * 绑定已有实例
     */
    public function instance(string $abstract, $instance): self {
        $this->instances[$abstract] = $instance;
        return $this;
    }
    
    /**
     * 设置别名
     */
    public function alias(string $abstract, string $alias): self {
        $this->aliases[$alias] = $abstract;
        return $this;
    }
    
    /**
     * 解析服务
     */
    public function make(string $abstract, array $parameters = []) {
        // 解析别名
        $abstract = $this->getAlias($abstract);
        
        // 如果已有实例，直接返回
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }
        
        // 获取具体实现
        $concrete = $this->getConcrete($abstract);
        
        // 构建实例
        $object = $this->build($concrete, $parameters);
        
        // 如果是单例，缓存实例
        if ($this->isShared($abstract)) {
            $this->instances[$abstract] = $object;
        }
        
        return $object;
    }
    
    /**
     * 快捷方法：获取服务
     */
    public function get(string $abstract) {
        return $this->make($abstract);
    }
    
    /**
     * 检查是否已绑定
     */
    public function has(string $abstract): bool {
        $abstract = $this->getAlias($abstract);
        return isset($this->bindings[$abstract]) || isset($this->instances[$abstract]);
    }
    
    /**
     * 构建实例
     */
    protected function build($concrete, array $parameters = []) {
        // 如果是闭包，直接调用
        if ($concrete instanceof Closure) {
            return $concrete($this, $parameters);
        }
        
        // 如果不是类名字符串，直接返回
        if (!is_string($concrete) || !class_exists($concrete)) {
            return $concrete;
        }
        
        // 反射构建
        $reflector = new ReflectionClass($concrete);
        
        // 检查是否可实例化
        if (!$reflector->isInstantiable()) {
            throw new Exception("Class [{$concrete}] is not instantiable.");
        }
        
        $constructor = $reflector->getConstructor();
        
        // 无构造函数，直接实例化
        if ($constructor === null) {
            return new $concrete;
        }
        
        // 解析构造函数参数
        $dependencies = $this->resolveDependencies($constructor->getParameters(), $parameters);
        
        return $reflector->newInstanceArgs($dependencies);
    }
    
    /**
     * 解析依赖
     */
    protected function resolveDependencies(array $parameters, array $primitives = []): array {
        $dependencies = [];
        
        foreach ($parameters as $parameter) {
            $name = $parameter->getName();
            
            // 优先使用传入的参数
            if (array_key_exists($name, $primitives)) {
                $dependencies[] = $primitives[$name];
                continue;
            }
            
            // 获取类型提示
            $type = $parameter->getType();
            
            if ($type === null || $type->isBuiltin()) {
                // 无类型或内置类型，使用默认值
                if ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                } else {
                    throw new Exception("Unresolvable dependency [{$name}] in class");
                }
            } else {
                // 类类型，递归解析
                $className = $type->getName();
                try {
                    $dependencies[] = $this->make($className);
                } catch (Exception $e) {
                    if ($parameter->isDefaultValueAvailable()) {
                        $dependencies[] = $parameter->getDefaultValue();
                    } else {
                        throw $e;
                    }
                }
            }
        }
        
        return $dependencies;
    }
    
    /**
     * 获取具体实现
     */
    protected function getConcrete(string $abstract) {
        if (isset($this->bindings[$abstract])) {
            return $this->bindings[$abstract]['concrete'];
        }
        return $abstract;
    }
    
    /**
     * 检查是否单例
     */
    protected function isShared(string $abstract): bool {
        return isset($this->instances[$abstract]) 
            || (isset($this->bindings[$abstract]['shared']) && $this->bindings[$abstract]['shared']);
    }
    
    /**
     * 获取真实抽象名（解析别名）
     */
    protected function getAlias(string $abstract): string {
        return $this->aliases[$abstract] ?? $abstract;
    }
    
    /**
     * 调用方法并自动注入依赖
     */
    public function call($callback, array $parameters = []) {
        if (is_array($callback)) {
            [$class, $method] = $callback;
            if (is_string($class)) {
                $class = $this->make($class);
            }
            $reflector = new ReflectionMethod($class, $method);
            $dependencies = $this->resolveDependencies($reflector->getParameters(), $parameters);
            return $reflector->invokeArgs($class, $dependencies);
        }
        
        if (is_string($callback) && strpos($callback, '@') !== false) {
            [$class, $method] = explode('@', $callback);
            return $this->call([$this->make($class), $method], $parameters);
        }
        
        if ($callback instanceof Closure) {
            $reflector = new ReflectionFunction($callback);
            $dependencies = $this->resolveDependencies($reflector->getParameters(), $parameters);
            return $callback(...$dependencies);
        }
        
        throw new Exception("Invalid callback provided.");
    }
    
    /**
     * 重置容器（主要用于测试）
     */
    public function flush(): void {
        $this->bindings = [];
        $this->instances = [];
        $this->aliases = [];
    }
}

// ==================== 全局辅助函数 ====================

/**
 * 获取容器实例
 */
function app(?string $abstract = null, array $parameters = []) {
    $container = Container::getInstance();
    if ($abstract === null) {
        return $container;
    }
    return $container->make($abstract, $parameters);
}

/**
 * 解析服务
 */
function resolve(string $abstract, array $parameters = []) {
    return Container::getInstance()->make($abstract, $parameters);
}
