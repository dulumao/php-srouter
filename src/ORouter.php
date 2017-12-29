<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017/7/14
 * Time: 下午8:03
 */

namespace Inhere\Route;

use Inhere\Route\Dispatcher\Dispatcher;
use Inhere\Route\Dispatcher\DispatcherInterface;

/**
 * Class ORouter - this is object version
 * @package Inhere\Route
 */
class ORouter extends AbstractRouter
{
    /** @var int */
    protected $routeCounter = 0;

    /** @var array global Options */
    private $globalOptions = [
        // 'domains' => [ 'localhost' ], // allowed domains
        // 'schemas' => [ 'http' ], // allowed schemas
        // 'time' => ['12'],
    ];

    /*******************************************************************************
     * route collection
     ******************************************************************************/

    /**
     * @param string|array $methods The match request method(s).
     * e.g
     *  string: 'get'
     *  array: ['get','post']
     * @param string $route The route path string. is allow empty string. eg: '/user/login'
     * @param callable|string $handler
     * @param array $opts some option data
     * [
     *     'params' => [ 'id' => '[0-9]+', ],
     *     'defaults' => [ 'id' => 10, ],
     *     'domains'  => [ 'a-domain.com', '*.b-domain.com'],
     *     'schemas' => ['https'],
     * ]
     * @return static
     * @throws \LogicException
     * @throws \InvalidArgumentException
     */
    public function map($methods, $route, $handler, array $opts = [])
    {
        if (!$this->initialized) {
            $this->initialized = true;
        }

        $methods = $this->validateArguments($methods, $handler);

        $this->formatRoutePattern($route);

        $id = $this->routeCounter;
        $data = [
            'handler' => $handler,
        ];

        if ($opts = array_merge($this->currentGroupOption, $opts)) {
            $data['option'] = $opts;
        }

        // it is static route
        if (self::isStaticRoute($route)) {
            $this->routesData[$id] = $data;

            foreach ($methods as $method) {
                if ($method === 'ANY') {
                    continue;
                }

                $this->routeCounter++;
                $this->staticRoutes[$route][$method] = $id;
            }

            return $this;
        }

        $data['original'] = $route;
        $this->routesData[$id] = $data;
        // $conf = ['dataId' => $id];

        $params = $this->getAvailableParams($opts['params'] ?? []);
        list($first, $conf) = $this->parseParamRoute($route, $params);

        // route string have regular
        if ($first) {
            $conf['methods'] = implode(',', $methods) . ',';
            $this->routeCounter++;
            $this->regularRoutes[$first][$id] = $conf;
        } else {
            foreach ($methods as $method) {
                if ($method === 'ANY') {
                    continue;
                }

                $this->routeCounter++;
                $this->vagueRoutes[$method][$id] = $conf;
            }
        }

        return $this;
    }

    /*******************************************************************************
     * route match
     ******************************************************************************/

    /**
     * find the matched route info for the given request uri path
     * @param string $method
     * @param string $path
     * @return array
     */
    public function match($path, $method = 'GET')
    {
        // if enable 'matchAll'
        if ($matchAll = $this->matchAll) {
            if (\is_string($matchAll) && $matchAll{0} === '/') {
                $path = $matchAll;
            } elseif (\is_callable($matchAll)) {
                return [
                    self::FOUND,
                    $path,
                    [
                        'handler' => $matchAll
                    ]
                ];
            }
        }

        $path = $this->formatUriPath($path, $this->ignoreLastSlash);
        $method = strtoupper($method);

        // is a static route path
        if ($routeInfo = $this->findInStaticRoutes($path, $method)) {
            return [self::FOUND, $path, $routeInfo];
        }

        $first = null;
        $allowedMethods = [];

        // eg '/article/12'
        if ($pos = strpos($path, '/', 1)) {
            $first = substr($path, 1, $pos - 1);
        }

        // is a regular dynamic route(the first node is 1th level index key).
        if ($first && isset($this->regularRoutes[$first])) {
            $result = $this->findInRegularRoutes($this->regularRoutes[$first], $path, $method);

            if ($result[0] === self::FOUND) {
                return $result;
            }

            $allowedMethods = $result[1];
        }

        // is a irregular dynamic route
        if (isset($this->vagueRoutes[$method])) {
            $result = $this->findInVagueRoutes($this->vagueRoutes[$method], $path, $method);

            if ($result[0] === self::FOUND) {
                return $result;
            }
        }

        // handle Auto Route
        if ($this->autoRoute && ($handler = $this->matchAutoRoute($path))) {
            return [
                self::FOUND,
                $path,
                [
                    'handler' => $handler,
                ]
            ];
        }

        // For HEAD requests, attempt fallback to GET
        if ($method === self::HEAD) {
            if ($routeInfo = $this->findInStaticRoutes($path, 'GET')) {
                return [self::FOUND, $path, $routeInfo];
            }

            if ($first && isset($this->regularRoutes[$first])) {
                $result = $this->findInRegularRoutes($this->regularRoutes[$first], $path, 'GET');

                if ($result[0] === self::FOUND) {
                    return $result;
                }
            }

            if (isset($this->vagueRoutes['GET'])) {
                $result = $this->findInVagueRoutes($this->vagueRoutes['GET'], $path, 'GET');

                if ($result[0] === self::FOUND) {
                    return $result;
                }
            }
        }

        // If nothing else matches, try fallback routes. $router->any('*', 'handler');
        if ($routeInfo = $this->findInStaticRoutes('/*', $method)) {
            return [self::FOUND, $path, $routeInfo];
        }

        if ($this->notAllowedAsNotFound) {
            return [self::NOT_FOUND, $path, null];
        }

        // collect allowed methods from: staticRoutes, vagueRoutes OR return not found.
        return $this->findAllowedMethods($path, $method, $allowedMethods);
    }

    /*******************************************************************************
     * helper methods
     ******************************************************************************/

    /**
     * @param string $path
     * @param string $method
     * @param array $allowedMethods
     * @return array
     */
    protected function findAllowedMethods($path, $method, array $allowedMethods)
    {
        if (isset($this->staticRoutes[$path])) {
            $allowedMethods = array_merge($allowedMethods, array_keys($this->staticRoutes[$path]));
        }

        foreach ($this->vagueRoutes as $m => $routes) {
            if ($method === $m) {
                continue;
            }

            $result = $this->findInVagueRoutes($this->vagueRoutes['GET'], $path, $m);

            if ($result[0] === self::FOUND) {
                $allowedMethods[] = $method;
            }
        }

        if ($allowedMethods && ($list = array_unique($allowedMethods))) {
            return [self::METHOD_NOT_ALLOWED, $path, $list];
        }

        // oo ... not found
        return [self::NOT_FOUND, $path, null];
    }

    /**
     * @param string $path
     * @param string $method
     * @return array|false
     */
    protected function findInStaticRoutes($path, $method)
    {
        if (isset($this->staticRoutes[$path][$method])) {
            $index = $this->staticRoutes[$path][$method];

            return $this->routesData[$index];
        }

        return false;
    }

    /**
     * @param array $routesInfo
     * @param string $path
     * @param string $method
     * @return array
     */
    protected function findInRegularRoutes(array $routesInfo, $path, $method)
    {
        $allowedMethods = '';

        foreach ($routesInfo as $id => $conf) {
            if (0 === strpos($path, $conf['start']) && preg_match($conf['regex'], $path, $matches)) {
                $allowedMethods .= $conf['methods'];

                if (false !== strpos($conf['methods'], $method . ',')) {
                    $data = $this->routesData[$id];
                    $this->filterMatches($matches, $data);

                    return [self::FOUND, $path, $data];
                }
            }
        }

        return [self::NOT_FOUND, explode(',', trim($allowedMethods, ','))];
    }

    /**
     * @param array $routesInfo
     * @param string $path
     * @param string $method
     * @return array
     */
    protected function findInVagueRoutes(array $routesInfo, $path, $method)
    {
        foreach ($routesInfo as $id => $conf) {
            if ($conf['include'] && false === strpos($path, $conf['include'])) {
                continue;
            }

            if (preg_match($conf['regex'], $path, $matches)) {
                $data = $this->routesData[$id];
                $this->filterMatches($matches, $data);

                return [self::FOUND, $path, $data];
            }
        }

        return [self::NOT_FOUND];
    }

    /*******************************************************************************
     * route callback handler dispatch
     ******************************************************************************/

    /**
     * Runs the callback for the given request
     * @param DispatcherInterface|array $dispatcher
     * @param null|string $path
     * @param null|string $method
     * @return mixed
     * @throws \Throwable
     */
    public function dispatch($dispatcher = null, $path = null, $method = null)
    {
        if (!$dispatcher) {
            $dispatcher = new Dispatcher;
        } elseif (\is_array($dispatcher)) {
            $dispatcher = new Dispatcher($dispatcher);
        }

        if (!$dispatcher instanceof DispatcherInterface) {
            throw new \InvalidArgumentException(
                'The first argument is must an array OR an object instanceof the DispatcherInterface'
            );
        }

        if (!$dispatcher->getRouter()) {
            $dispatcher->setRouter($this);
        }

        return $dispatcher->dispatchUri($path, $method);
    }

    /**
     * @return int
     */
    public function count()
    {
        return $this->routeCounter;
    }

    /**
     * @return array
     */
    public function getGlobalOptions()
    {
        return $this->globalOptions;
    }

    /**
     * @param array $globalOptions
     * @return $this
     */
    public function setGlobalOptions(array $globalOptions)
    {
        $this->globalOptions = $globalOptions;

        return $this;
    }
}
