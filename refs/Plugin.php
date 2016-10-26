<?php
/**
 * @author    Philip Bergman <philip@zicht.nl>
 * @copyright Zicht Online <http://www.zicht.nl>
 */

namespace Zicht\Tool\Plugin\Refs;

use Zicht\Tool\Container\Container;
use Zicht\Tool\Plugin as BasePlugin;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

/**
 * Class Plugin
 *
 * @package Zicht\Tool\Plugin\Refs
 */
class Plugin extends BasePlugin
{
    /** @var array  */
    protected $cache;

    /**
     * Appends the refs configuration
     *
     * @param \Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition $rootNode
     * @return void
     */
    public function appendConfiguration(ArrayNodeDefinition $rootNode)
    {
        $rootNode
            ->children()
                ->arrayNode('refs')
                    ->children()
                        ->scalarNode('prefix')->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * will squash all arguments and generate a sh1 from string
     *
     * @param mixed ...$data
     *
     * @return string
     */
    protected function hash(...$data)
    {
        return sha1(implode('', $data));
    }

    /**
     * Will create a hash for saving result from command
     * based on given parameters and method name.
     *
     * @param array|string $id
     * @param mixed ...$args
     *
     * @return string
     */
    protected function createHash($id, ...$args)
    {
        if (is_string($id)) {
            $id = explode(".", $id);
        }

        return sprintf(
            "%s.%s",
            $this->hash(...$id),
            $this->hash(...$args)
        );
    }

    /**
     * Helper that caches result for command based on args.
     *
     * @param Container $container
     * @param array $id
     * @param callable $func
     */
    protected function cachedResultMethod(Container $container, $id, callable $func)
    {
        $container->method(
            $id,
            function (Container $c, ...$args) use ($func, $id) {
                $hash = $this->createHash($id, ...$args);
                if (isset($this->cache[$hash])) {
                    return $this->cache[$hash];
                } else {
                    $ret = $func($c, ...$args);
                    $this->cache[$hash] = $ret;
                    return $ret;
                }
            }
        );
    }

    /**
     * @param Container $c
     * @param string $env
     *
     * @return null|string
     */
    protected function getParentArg(Container $c, $env)
    {
        if (null !== ($hash = $this->getLocalTreeHash($c, $env))) {
            return sprintf(" -p %s", $hash);
        }

        return null;
    }

    /**
     * @param Container $c
     * @param string $env
     * @return mixed
     */
    protected function getLocalTreeHash(Container $c, $env)
    {
        if ($this->call($c, 'refs.local.exists', $env)) {
            return $this->call($c, 'refs.resolve', $env, false);
        }
        return null;
    }

    /**
     * Tries to resole a command and runs it with given arguments
     *
     * @param Container $container
     * @param array|string $id
     * @param mixed ...$args
     * @return mixed
     */
    protected function call(Container $container, $id, ...$args)
    {
        if (($callable = $container->resolve($id)[0]) instanceof \Closure) {
            return $callable($container, ...$args);
        }
        return null;
    }

    /**
     * @{inheritDoc}
     */
    public function setContainer(Container $container)
    {
        $this->cachedResultMethod(
            $container,
            ['refs', 'path'],
            function (Container $c, $env) {
                $prefix = $c->resolve('refs.prefix');
                return ($prefix[strlen($prefix)-1] === DIRECTORY_SEPARATOR ? sprintf("%s%s", $prefix, $env) : sprintf("%s%s", $prefix, $env));
            }
        );

        $this->cachedResultMethod(
            $container,
            ['refs', 'local', 'exists'],
            function (Container $c, $env) {
                try {
                    $c->helperExec(
                        sprintf(
                            'git show-ref --verify --quiet %s',
                            $this->call($c, 'refs.path', $env)
                        )
                    );
                    return true;
                } catch (\Exception $e) {
                    return false;
                }
            }
        );

        $this->cachedResultMethod(
            $container,
            ['refs', 'remote', 'exists'],
            function (Container $c, $env) {
                try {
                    $c->helperExec(
                        sprintf(
                            'git ls-remote --exit-code . %s',
                            $this->call($c, 'refs.path', $env)
                        )
                    );
                    return true;
                } catch (\Exception $e) {
                    return false;
                }
            }
        );

        $this->cachedResultMethod(
            $container,
            ['refs', 'resolve'],
            function (Container $c, $env, $verbose = false) {
                $commit = $c->helperExec(
                    sprintf(
                        'git show-ref %s%s',
                        $this->call($c, 'refs.path', $env),
                        (!$verbose) ? " --hash" : null
                    )
                );
                return trim($commit);
            }
        );

        $this->cachedResultMethod(
            $container,
            ['refs', 'resolve_tree'],
            function (Container $c, $version) {
                return trim($c->helperExec(sprintf('git rev-parse %s^{tree}', $version)));
            }
        );

        $this->cachedResultMethod(
            $container,
            ['refs', 'log_message'],
            function (Container $c, $version) {
                return trim($c->helperExec(sprintf("git log -1 --pretty=format:\"[z] Commit from: '%%h %%s'\" %s", $version)));
            }
        );

        $container->method(
            ['refs', 'cache', 'flush'],
            function (Container $c, $name, ...$args) {
                $hash = $this->createHash($name, ...$args);
                if (isset($this->cache[$hash])) {
                    unset($this->cache[$hash]);
                    return "Refs: Cache cleard for " . $hash;
                } else {
                    return "Refs: No cache found for " . $hash;
                }
            }
        );

        $this->cachedResultMethod(
            $container,
            ['refs', 'create_command'],
            function (Container $c, $message, $version, $env) {
                return sprintf(
                    'git update-ref -m "%s" --no-deref --create-reflog %s $(git commit-tree %s %s -m "%s") %s',
                    $message,
                    $this->call($c, 'refs.path', $env),
                    $this->call($c, 'refs.resolve_tree', $version),
                    $this->getParentArg($c, $env),
                    $this->call($c, 'refs.log_message', $version),
                    $this->getLocalTreeHash($c, $env)
                );
            }
        );
    }
}
