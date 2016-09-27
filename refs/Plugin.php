<?php
/**
 * @author    Philip Bergman <philip@zicht.nl>
 * @copyright Zicht Online <http://www.zicht.nl>
 */

namespace Zicht\Tool\Plugin\Refs;

use Zicht\Tool\Container\Container;
use Zicht\Tool\Plugin as BasePlugin;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

class Plugin extends BasePlugin
{
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
            ->end()
        ;
    }

    /**
     * @{inheritDoc}
     */
    public function setContainer(Container $container)
    {
        $container->method(
            array('refs', 'path'),
            function (Container $container, $env) {
                $prefix = $container->resolve('refs.prefix');
                return ($prefix[strlen($prefix)-1] === "/" ? sprintf("%s%s", $prefix, $env) : sprintf("%s%s", $prefix, $env));
            }
        );
        $container->method(
            array('refs', 'local', 'exists'),
            function(Container $container, $env) {
                $path = call_user_func_array($container->resolve('refs.path')[0], [$container, $env]);
                try {
                    $container->helperExec(sprintf('git show-ref --verify --quiet %s', $path));
                    return true;
                } catch (\Exception $e) {
                    return false;
                }
            }
        );
        $container->method(
            array('refs', 'remote', 'exists'),
            function(Container $container, $env) {
                $path = call_user_func_array($container->resolve('refs.path')[0], [$container, $env]);
                try {
                    $container->helperExec(sprintf('git ls-remote --exit-code . %s', $path));
                    return true;
                } catch (\Exception $e) {
                    return false;
                }
            }
        );
        $container->method(
            array('refs', 'resolve'),
            function(Container $container, $env, $verbose = false) {
                $path = call_user_func_array($container->resolve('refs.path')[0], [$container, $env]);
                $commit = $container->helperExec(sprintf('git show-ref %s%s', $path, (!$verbose) ? " --hash" : null));
                return trim($commit);
            }
        );
        $container->method(
            array('refs', 'resolve_tree'),
            function(Container $container, $version) {
                $tree = $container->helperExec(sprintf('git rev-parse %s^{tree}', $version));
                return trim($tree);
            }
        );
        $container->method(
            array('refs', 'log_message'),
            function(Container $container, $version) {
                $message = $container->helperExec(sprintf("git log -1 --pretty=format:\"[z] Commit from: '%%h %%s'\" %s", $version));
                return trim($message);
            }
        );
        $container->method(
            array('refs', 'create_command'),
            function(Container $container, $message, $version, $env) {
                $tree = call_user_func_array($container->resolve('refs.resolve_tree')[0], [$container, $version]);
                $path = call_user_func_array($container->resolve('refs.path')[0], [$container, $env]);
                $exists = call_user_func_array($container->resolve('refs.local.exists')[0], [$container, $env]);
                $hash = ($exists) ? call_user_func_array($container->resolve('refs.resolve')[0], [$container, $env, false]) : null;
                $refMessage = call_user_func_array($container->resolve('refs.log_message')[0], [$container, $version]);
                return sprintf(
                    'git update-ref -m "%s" --no-deref --create-reflog %s $(git commit-tree %s%s -m "%s")%s',
                    $message,
                    $path,
                    $tree,
                    ($exists) ? " -p ${hash}": null,
                    $refMessage,
                    ($exists) ? " ${hash}": null
                );
            }
        );
    }
}