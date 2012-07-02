<?php

namespace Xi\Bundle\BreadcrumbsBundle\Service;

use \InvalidArgumentException;
use \Symfony\Component\DependencyInjection\ContainerInterface;
use \Symfony\Component\HttpKernel\Event\FilterControllerEvent;
use \Symfony\Component\Routing\Route;
use \Symfony\Component\Routing\RouteCollection;
use \Symfony\Component\Routing\RouterInterface;
use \Xi\Bundle\BreadcrumbsBundle\Model\Breadcrumb;

/**
 * A service class to build breadcrumbs from Symfony routes.
 *
 * @author Peter Hillerström <peter.hillerstrom@soprano.fi>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
class BreadcrumbService
{
    const TWIG_TAG = "# ?\{([^/}]+)\} ?#";

    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * @var RouterInterface
     */
    protected $router;

    /**
     * Used as a dict to save/cache Breadcrumbs for route and parameter combinations
     * @var array
     */
    private $cache = array();

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->router = $container->get('router');
    }

    public function getRouter()
    {
        return $this->router;
    }

    public function setRouter(RouterInterface $router)
    {
        return $this->router = $router;
    }

    /**
     * @param string $name
     * @return array
     */
    public function getBreadcrumbs($name, array $params = array(), $caching = false)
    {
        $hash = $this->getHash($name, $this->matchParams($name, $params));
        if (!$caching && array_key_exists($hash, $this->cache)) {
            return $this->cache[$hash];
        }

        $route = $this->getRoute($name);
        $parents = $this->getParents($name);
        $breadcrumbs = array();

        if ($route && $parents) {
            foreach (
                array_merge($parents, array($name)) as $current
            ) {
                $breadcrumbs[$current] = new Breadcrumb(
                    $this->getLabel($current, $params),
                    $this->getUrl($current, $params)
                );
            }
        }

        $this->cache[$hash] = $breadcrumbs;
        return $breadcrumbs;
    }

    public function addBreadcrumbs($route, array $params = array())
    {
        $matched = $this->matchParams($route, $params);
        if ($bc = $this->getBreadcrumbs($route, $matched, true)) {
            $this->cache[$this->getHash($route, $matched)] = $bc;
        }
    }

    /**
     * @param string $route
     * @return array
     */
    public function getParents($route)
    {
        $parents = array();
        $parent = $this->getParent($route);

        // Prevents circular loops by checking that the key doesn't exist already
        while ($parent && $parent !== $route && !array_key_exists($parent, $parents)) {
            $parents[$parent] = count($parents);
            $parent = $this->getParent($parent);
        }

        return array_reverse(array_flip($parents));
    }

    /**
     * @param string $name
     * @return Route|null
     */
    private function getParent($name) {
        $route = $this->getRoute($name);
        if ($route && $route->hasDefault('parent')) {
            $parent = $route->getDefault('parent');
            return ($this->getRoute($parent) ? $parent : null);
        } else {
            return null;
        }
    }

    /**
     * @param string name
     * @return string
     */
    public function getLabel($name, array $params = array())
    {
        if ($route = $this->getRoute($name)) {
            if ($route->hasDefault('label')) {
                return $this->applyParams(
                    $route->getDefault('label'),
                    $this->matchParams($name, $params, true)
                );
            } else {
                return $name;
            }
        } else {
            return '';
        }
    }

    /**
     * @param string name
     * @return string
     */
    public function getUrl($name, array $params = array()) {
        return $this->router->generate($name, $this->matchParams($name, $params));
    }

    /**
     * @param string str
     * @return string
     */
    protected function applyParams($str, array $params)
    {
        $patterns = array_map(
            function ($tag) {
                return "/\{${tag}\}/";
            },
            array_keys($params)
        );
        $res = preg_replace($patterns, array_values($params), $str);
        return preg_replace(self::TWIG_TAG, '', $res); // cleanup missing params
    }

    /**
     * Returns only parameters applicable for the named route/label
     *
     * @param string name
     * @return array
     */
    private function matchParams($name, array $params, $fromLabel = false)
    {
        if ($route = $this->getRoute($name)) {

            // Check for routes without placeholder parameters
            if (!preg_match(self::TWIG_TAG, $route->getPattern())) {
                return array();
            }

            // Ensure we have requirements
            if (!$reqs = $route->getRequirements()) {
                $template = $fromLabel
                    ? $route->getDefault('label')
                    : $route->getPattern();
                preg_match_all(self::TWIG_TAG, $template, $matches);
                $reqs = array_flip($matches[1]);
            }

            // Get default values for missing parameters
            foreach ($route->getDefaults() as $def => $value) {
                if (!array_key_exists($def, $params) && array_key_exists($def, $reqs)) {
                    $params[$def] = $value;
                }
            }

            // Return matched params
            if (!empty($params) && $reqs) {
                return array_intersect_key($params, $reqs);
            }

        }

        return array();
    }

    /**
     * @param string name
     * @return Route|null
     */
    private function getRoute($name)
    {
        if (!is_string($name)) {
            throw new InvalidArgumentException(__FUNCTION__ . '() only accepts route name as a string.');
        }
        return $this->getRouter()->getRouteCollection()->get($name);
    }

    private function getHash($route, $params) {
        return hash('sha1', json_encode(array_merge($params, array('route' => $route))));
    }
}
