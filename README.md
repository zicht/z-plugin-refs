## z-plugin-refs

This plugin will create commit from the tree that is deployed (and if the ref already exists with the old ref as parent) 
and saves/update the commit hash in a ref that is located in refs/envs/{{target_env}}. So for example you deploy to 
production the ref will be stored in refs/envs/production (or on working dir ./git/refs/production).

So what it basically does is creating an new timeline/history from the commits deployed to a environment and because it 
is basically a branch with the refs/envs/{{target_env}} as head, you can do evertything what you normaly do with branch.

### installation

add refs to the plugins of a project

```
plugins: [... 'refs']

```

It hook into the post deploy andd add some extra commands.

```
  env:refs:remote:fetch    Fetch the refs from the remote
  env:refs:remote:push     Push the reft to remote
  env:refs:show            Prints matching refs (tags, branches etc.) that match the same tree as the ref.
```

### examples

print logs:

```
~# git reflog refs/envs/production

commit aa33bceb41dee4e97b92645809455dca49c0b6ba
Author: philip <philip@zicht.nl>
Date:   Wed Oct 26 11:51:32 2016 +0200

    [z] Commit from: '6876a77 capitalize first char'
```

or:

```
~# git reflog refs/envs/production
aa33bce refs/envs/production@{0}: [z] new ref for 6876a77b43256710f882c744eb117decde9ce222
```

do rollbacks:

```
z deploy production envs/production~1
```

check diffs of deploys:

```
git diff envs/production~1 envs/production
```

### dependencies

lib | description 
--- | --- 
zicht/z-plugin-git|because it has no use if you are not using git
php > 5.6.0|The internals are using some features tha are only available from > 5.6 (variadic functions and argument unpacking) 
