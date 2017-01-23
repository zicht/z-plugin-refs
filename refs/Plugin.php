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
     * Tries to resole a command and runs it with given arguments
     *
     * @param Container $container
     * @param array|string $id
     * @param mixed ...$args
     * @return mixed
     */
    protected function call(Container $container, $id, ...$args)
    {
        $ret = $container->resolve($id);

        if (is_array($ret) && $ret[0] instanceof \Closure && is_bool($ret[1])) {
            return ($ret[1]) ? $ret[0]($container, ...$args) : $ret[0](...$args);
        }

        return null;
    }

    /**
     * @{inheritDoc}
     */
    public function setContainer(Container $container)
    {
        $container->method(
            ['refs', 'path'],
            function (Container $c, $env) {
                $prefix = $c->resolve('refs.prefix');
                return ($prefix[strlen($prefix)-1] === DIRECTORY_SEPARATOR ? sprintf("%s%s", $prefix, $env) : sprintf("%s/%s", $prefix, $env));
            }
        );

        $container->method(
            ['refs', 'remote', 'exists'],
            function (Container $c, $env, $remote, $push = false) {
               try {
                    $c->helperExec(
                        sprintf(
                            'git ls-remote --exit-code $(%s) %s',
                            $this->call($c, 'refs.fmt.git_remote_url', $remote, $push),
                            $this->call($c, 'refs.path', $env)
                        )
                    );
                    return true;
                } catch (\Exception $e) {
                    return false;
                }
            }
        );

        $container->method(
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

        $container->method(
            ['refs', 'fmt', "git_update_ref"],
            function (Container $c, $message, $version, $env) {
                $path = $this->call($c, 'refs.path', $env);
			  	$cmd = [
				  	"git update-ref",
				  	"    -m \"$message\"",
				  	"    --no-deref --create-reflog",
					"    $path",
				  	"    $(git commit-tree",
				    "        $(git rev-parse ${version}^{tree})",
				];
				$exists = $this->call($c, 'refs.local.exists', $env);
			  	if ($exists) {
					$cmd[] = "        -p \"$(git show-ref --hash $path)\"";
				} else {
				  	$cmd[] = "        -m \"$(git log -1 --pretty=format:\"[z] Commit from: '%H %s'\" ${version})\"";
				  	$cmd[] = "    )";
				}
			  	if ($exists) {
				  	$cmd[] = "    $(git show-ref --hash $path)";
				}
				return implode(" \\\n", $cmd);
            }
        );

        $container->decl(
            ['refs', 'fmt', 'git_remote_default'],
            function () {
                return "git remote | head -1";
            }
        );

        $container->method(
            ['refs', 'fmt', 'git_remote_url'],
            function (Container $c, $remote, $push = false) {
                if (empty($remote)) {
                    $remote = sprintf("$(%s)", $c->resolve('refs.fmt.git_remote_default'));
                }
                return sprintf("git remote get-url %s%s", $remote, ($push) ? " --push" : null);
            }
        );

        $container->method(
            ['refs', 'fmt', 'update_remote'],
            function (Container $c, $env, $remote, $method = "push") {
                if (empty($remote)) {
                    $remote = sprintf("$(%s)", $c->resolve('refs.fmt.git_remote_default'));
                }
                $path = $this->call($c, 'refs.path', $env);
                return sprintf("git %s %s +%s:%s", $method, $remote, $path, $path);
            }
        );

        $container->method(
            ['refs', 'fmt', 'git_remote_push'],
            function (Container $c, $env, $remote) {
                return $this->call($c, 'refs.fmt.update_remote', $env, $remote);
            }
        );

        $container->method(
            ['refs', 'fmt', 'git_remote_fetch'],
            function (Container $c, $env, $remote) {
                return $this->call($c, 'refs.fmt.update_remote', $env, $remote, "fetch");
            }
        );

    }
}
