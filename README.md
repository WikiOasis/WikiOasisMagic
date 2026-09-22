# WikiOasisMagic

Forked from [miraheze/MirahezeMagic](https://github.com/miraheze/MirahezeMagic)

## Upgrading wikis

`UpgradeWiki.php` runs an upgrade described by a JSON file against one or more
wikis. The wikis to upgrade are given as arguments, or with `--group` / `--file`.
`--wiki` only selects the wiki the script itself runs under, and does not have to
be one of the wikis being upgraded.

```sh
# One wiki.
php maintenance/run.php WikiOasisMagic:UpgradeWiki --wiki metawiki \
    --json /srv/upgrades/1.46.json examplewiki

# A staged rollout: upgrade group1 eight wikis at a time, and only point the wikis
# that upgraded cleanly at the new version once their schema changes are in.
php maintenance/run.php WikiOasisMagic:UpgradeWiki --wiki metawiki \
    --json /srv/upgrades/1.46.json --group group1 \
    --parallel 8 --change-version-after --continue-on-error
```

How a run works:

1. Every target's `updatelog` is checked for `upgrade-wiki-<mwversion>`, and wikis
   that already have it are skipped (unless `--force`).
2. With `--change-version`, every target is pointed at the new version first, in
   one pass.
3. Each remaining wiki is upgraded in its own `run.php --wiki=<target>` process,
   `--parallel` at a time, with output prefixed by the wiki name. A wiki's steps
   run in-process within that child, so it boots MediaWiki once per wiki rather
   than once per step. The first failure stops new wikis being started unless
   `--continue-on-error` is passed.
4. With `--change-version-after`, the wikis that upgraded (or already had) are
   pointed at the new version in one pass. Failed wikis stay where they were.

Either way the database lists are regenerated exactly once for the batch. That
rewrites this server's copy straight away and bumps CreateWiki's global timestamp,
so every other server regenerates its own copy once on its next request, rather
than the whole fleet regenerating once per wiki.

`--dry-run` lists what would be upgraded and what is already done.

### Rollout groups

`ManageUpgradeGroups.php` manages the `group1`, `group2`, ... database lists in
cw_cache that `UpgradeWiki --group` deploys to. A wiki is never in more than one
group, and every run reports duplicates. Group names are always `group<number>`,
so a group can never overwrite one of CreateWiki's lists or a wiki's cache file.

Wikis can be selected by state with `--state` (wikis in all of the given states)
and `--exclude-state`, using CreateWiki's own lists: `active`, `closed`,
`inactive`, `deleted`, `public`, `private`. Percentages are of the selected wikis.

```sh
# Show every group and how much of the fleet they cover.
php maintenance/run.php WikiOasisMagic:ManageUpgradeGroups --list

# Canary: every private wiki that isn't closed.
php maintenance/run.php WikiOasisMagic:ManageUpgradeGroups --add-all --state private --exclude-state closed

# Then grow the rollout. Each run creates the next group with only the wikis
# needed to reach the target, never repeating one already in a group.
php maintenance/run.php WikiOasisMagic:ManageUpgradeGroups --percent 25 --random
php maintenance/run.php WikiOasisMagic:ManageUpgradeGroups --percent 50 --random

# Half of the closed wikis, or just 20 more inactive ones.
php maintenance/run.php WikiOasisMagic:ManageUpgradeGroups --percent 50 --state closed
php maintenance/run.php WikiOasisMagic:ManageUpgradeGroups --count 20 --state inactive --random

# Add, move and remove individual wikis.
php maintenance/run.php WikiOasisMagic:ManageUpgradeGroups --group group1 --add examplewiki
php maintenance/run.php WikiOasisMagic:ManageUpgradeGroups --group group2 --add examplewiki --move
php maintenance/run.php WikiOasisMagic:ManageUpgradeGroups --remove examplewiki

# Audit the lists (optionally for one state), and drop any duplicates that crept in.
php maintenance/run.php WikiOasisMagic:ManageUpgradeGroups --check --state private
php maintenance/run.php WikiOasisMagic:ManageUpgradeGroups --check --fix
```

`--percent`, `--count` and `--add-all` go into the next free group unless
`--group` is given. `--random --seed <n>` makes a random pick reproducible.
`--dry-run` works on every action.
