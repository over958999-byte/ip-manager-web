<?php
/**
 * 轻量级路由器
 * 支持 RESTful 路由、中间件、路由分组
 */

require_once __DIR__ . '/container.php';
require_once __DIR__ . '/utils.php';

class Router {
    private static ?Router $instance = null;
    
    // 路由表
    private array $routes = [];
    
    // 全局中间件
    private array $globalMiddleware = [];
    
    // 当前路由分组属性
    private array $groupStack = [];
    
    // 命名路由
    private array $namedRoutes = [];
    
    private function __construct() {}
    
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 注册 GET 路由
     */
    public function get(string $uri, $action): Route {
        return $this->addRoute(['GET'], $uri, $action);
    }
    
    /**
     * 注册 POST 路由
     */
    public function post(string $uri, $action): Route {
        return $this->addRoute(['POST'], $uri, $action);
    }
    
    /**
     * 注册 PUT 路由
     */
    public function put(string $uri, $action): Route {
        return $this->addRoute(['PUT'], $uri, $action);
    }
    
    /**
     * 注册 DELETE 路由
     */
    public function delete(string $uri, $action): Route {
        return $this->addRoute(['DELETE'], $uri, $action);
    }
    
    /**
     * 注册支持多个方法的路由
     */
    public function match(array $methods, string $uri, $action): Route {
        return $this->addRoute($methods, $uri, $action);
    }
    
    /**
     * 注册支持所有方法的路由
     */
    public function any(string $uri, $action): Route {
        return $this->addRoute(['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'], $uri, $action);
    }
    
    /**
     * 路由分组
     */
    public function group(array $attributes, callable $callback): void {
        $this->groupStack[] = $attributes;
        $callback($this);
        array_pop($this->groupStack);
    }
    
    /**
     * 添加全局中间件
     */
    public function middleware($middleware): self {
        if (is_array($middleware)) {
            $this->globalMiddleware = array_merge($this->globalMiddleware, $middleware);
        } else {
            $this->globalMiddleware[] = $middleware;
        }
        return $this;
    }
    
    /**
     * 添加路由
     */
    protected function addRoute(array $methods, string $uri, $action): Route {
        $uri = $this->prefixUri($uri);
        $middleware = $this->getGroupMiddleware();
        
        $route = new Route($methods, $uri, $action, $middleware);
        
        foreach ($methods as $method) {
            $this->routes[$method][$uri] = $route;
        }
        
        return $route;
    }
    
    /**
     * 获取分组前缀
     */
    protected function prefixUri(string $uri): string {
        $prefix = '';
        foreach ($this->groupStack as $group) {
            if (isset($group['prefix'])) {
                $prefix .= '/' . trim($group['prefix'], '/');
            }
        }
        return $prefix . '/' . trim($uri, '/');
    }
    
    /**
     * 获取分组中间件
     */
    protected function getGroupMiddleware(): array {
        $middleware = [];
        foreach ($this->groupStack as $group) {
            if (isset($group['middleware'])) {
                $m = is_array($group['middleware']) ? $group['middleware'] : [$group['middleware']];
                $middleware = array_merge($middleware, $m);
            }
        }
        return $middleware;
    }
    
    /**
     * 分发请求
     */
    public function dispatch(): void {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = $this->getRequestUri();
        
        // 处理OPTIONS预检请求
        if ($method === 'OPTIONS') {
            http_response_code(200);
            return;
        }
        
        // 查找路由
        $route = $this->findRoute($method, $uri);
        
        if ($route === null) {
            Utils::error('路由未找到: ' . $uri, 404);
            return;
        }
        
        // 执行中间件和路由
        $this->runRoute($route);
    }
    
    /**
     * 获取请求URI
     */
    protected function getRequestUri(): string {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $uri = parse_url($uri, PHP_URL_PATH);
        $uri = '/' . trim($uri, '/');
        
        // 移除.php后缀和api.php前缀
        $uri = preg_replace('/\/api\.php/', '', $uri);
        
        return $uri ?: '/';
    }
    
    /**
     * 查找匹配的路由
     */
    protected function findRoute(string $method, string $uri): ?Route {
        // 精确匹配
        if (isset($this->routes[$method][$uri])) {
            return $this->routes[$method][$uri];
        }
        
        // 参数路由匹配
        if (isset($this->routes[$method])) {
            foreach ($this->routes[$method] as $routeUri => $route) {
                if ($this->matchUri($routeUri, $uri, $params)) {
                    $route->setParameters($params);
                    return $route;
                }
            }
        }
        
        return null;
    }
    
    /**
     * URI 模式匹配
     */
    protected function matchUri(string $routeUri, string $requestUri, ?array &$params = null): bool {
        // 转换路由参数为正则表达式
        $pattern = preg_replace('/\{([a-zA-Z_]+)\}/', '(?P<$1>[^/]+)', $routeUri);
        $pattern = '#^' . $pattern . '$#';
        
        if (preg_match($pattern, $requestUri, $matches)) {
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            return true;
        }
        
        return false;
    }
    
    /**
     * 执行路由
     */
    protected function runRoute(Route $route): void {
        try {
            // 合并全局中间件和路由中间件
            $middleware = array_merge($this->globalMiddleware, $route->getMiddleware());
            
            // 构建中间件管道
            $pipeline = $this->buildPipeline($middleware, function() use ($route) {
                return $route->run();
            });
            
            // 执行
            $response = $pipeline();
            
            // 输出响应
            if ($response !== null && !is_bool($response)) {
                if (is_array($response)) {
                    Utils::jsonResponse($response);
                } else {
                    echo $response;
                }
            }
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
    
    /**
     * 构建中间件管道
     */
    protected function buildPipeline(array $middleware, callable $destination): callable {
        return array_reduce(
            array_reverse($middleware),
            function($next, $middleware) {
                return function() use ($next, $middleware) {
                    // 中间件是对象实例
                    if (is_object($middleware) && method_exists($middleware, 'handle')) {
                        return $middleware->handle($next);
                    }
                    // 中间件是类名字符串
                    if (is_string($middleware) && class_exists($middleware)) {
                        $instance = Container::getInstance()->make($middleware);
                        return $instance->handle($next);
                    }
                    // 中间件是可调用函数
                    if (is_callable($middleware)) {
                        return $middleware($next);
                    }
                    return $next();
                };
            },
            $destination
        );
    }
    
    /**
     * 异常处理
     */
    protected function handleException(Exception $e): void {
        $code = (int)($e->getCode() ?: 500);
        Utils::error($e->getMessage(), $code);
    }
    
    /**
     * 获取所有路由（用于调试）
     */
    public function getRoutes(): array {
        return $this->routes;
    }
}

/**
 * 路由对象
 */
class Route {
    private array $methods;
    private string $uri;
    private $action;
    private array $middleware = [];
    private array $parameters = [];
    private ?string $name = null;
    
    public function __construct(array $methods, string $uri, $action, array $middleware = []) {
        $this->methods = $methods;
        $this->uri = $uri;
        $this->action = $action;
        $this->middleware = $middleware;
    }
    
    /**
     * 设置路由名称
     */
    public function name(string $name): self {
        $this->name = $name;
        return $this;
    }
    
    /**
     * 添加中间件
     */
    public function middleware($middleware): self {
        if (is_array($middleware)) {
            $this->middleware = array_merge($this->middleware, $middleware);
        } else {
            $this->middleware[] = $middleware;
        }
        return $this;
    }
    
    /**
     * 设置路由参数
     */
    public function setParameters(array $params): void {
        $this->parameters = $params;
    }
    
    /**
     * 获取中间件
     */
    public function getMiddleware(): array {
        return $this->middleware;
    }
    
    /**
     * 执行路由
     */
    public function run() {
        $container = Container::getInstance();
        
        // 闭包处理
        if ($this->action instanceof Closure) {
            return $container->call($this->action, $this->parameters);
        }
        
        // 字符串 Controller@method 格式
        if (is_string($this->action) && strpos($this->action, '@') !== false) {
            [$controller, $method] = explode('@', $this->action);
            $instance = $container->make($controller);
            return $container->call([$instance, $method], $this->parameters);
        }
        
        // 数组 [Controller::class, 'method'] 格式
        if (is_array($this->action)) {
            [$controller, $method] = $this->action;
            $instance = is_string($controller) ? $container->make($controller) : $controller;
            return $container->call([$instance, $method], $this->parameters);
        }
        
        // 可调用对象
        if (is_callable($this->action)) {
            return call_user_func_array($this->action, $this->parameters);
        }
        
        throw new Exception('Invalid route action');
    }
}

// ==================== 全局辅助函数 ====================

function router(): Router {
    return Router::getInstance();
}
