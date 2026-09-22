# WikiOasisMagic

Forked from [miraheze/MirahezeMagic](https://github.com/miraheze/MirahezeMagic)

## Upgrading wikis

`UpgradeWiki.php` runs an upgrade described by a JSON file against one or more
wikis. The wikis to upgrade are given as arguments, or with `--group` / `--file`.
`--wiki` only selects the wiki the script itself runs under, and does not have to
be one of the wikis being upgraded:

```sh
# One wiki, running under whichever wiki you like.
php maintenance/run.php WikiOasisMagic:UpgradeWiki --wiki metawiki \
    --json /srv/upgrades/1.45.json --change-version examplewiki

# Several wikis at once.
php maintenance/run.php WikiOasisMagic:UpgradeWiki --wiki metawiki \
    --json /srv/upgrades/1.45.json examplewiki otherwiki

# A whole rollout group from cw_cache.
php maintenance/run.php WikiOasisMagic:UpgradeWiki --wiki metawiki \
    --json /srv/upgrades/1.45.json --group group1 --continue-on-error
```

Each wiki records its own `upgrade-wiki-<mwversion>` row in its `updatelog`, so a
wiki that has already been upgraded is skipped unless `--force` is passed. Steps
for a wiki other than the one the script is running under are executed as separate
`maintenance/run.php --wiki=<target>` processes, since an in-process maintenance
class always talks to the database of the wiki the process was booted for.

### Rollout groups

`ManageUpgradeGroups.php` manages the `group1`, `group2`, ... database lists in
cw_cache that `UpgradeWiki --group` deploys to. A wiki is never in more than one
group, and every run reports duplicates:

```sh
# Show every group and how much of the fleet they cover.
php maintenance/run.php WikiOasisMagic:ManageUpgradeGroups --list

# Create the next group so the groups together cover 25% of all wikis.
php maintenance/run.php WikiOasisMagic:ManageUpgradeGroups --percent 25 --random

# Then extend the rollout to half the fleet, without repeating any wiki.
php maintenance/run.php WikiOasisMagic:ManageUpgradeGroups --percent 50 --random

# Add, move and remove individual wikis.
php maintenance/run.php WikiOasisMagic:ManageUpgradeGroups --group group1 --add examplewiki
php maintenance/run.php WikiOasisMagic:ManageUpgradeGroups --group group2 --add examplewiki --move
php maintenance/run.php WikiOasisMagic:ManageUpgradeGroups --remove examplewiki

# Audit the lists, and drop any duplicates that crept in.
php maintenance/run.php WikiOasisMagic:ManageUpgradeGroups --check --fix
```

`--dry-run` works on every action.
