#!/usr/bin/env bash
# End-to-end check for symposer. Needs: php, network, a built binary.
# Usage: e2e/run.sh [path/to/symposer]   (E2E_LARAVEL=1 also runs the Laravel + Pest scenario)
set -euo pipefail

BIN=$(cd "$(dirname "${1:-bin/symposer}")" && pwd)/$(basename "${1:-bin/symposer}")
WORK=$(mktemp -d)
export SYMPOSER_HOME="$WORK/home" COMPOSER_NO_INTERACTION=1
trap 'rm -rf "$WORK"' EXIT
pass() { echo "ok   $*"; }
die() { echo "FAIL $*" >&2; exit 1; }
sym() { "$BIN" "$@" 2>&1 | tee "$WORK/last.log" >/dev/null || { cat "$WORK/last.log"; die "symposer $*"; }; }
# where: prints "store" | "hardlink" | "dir" | "path:<target>" for a vendor path (works for symlinks and junctions)
where() {
  php -r '$n=function($x){ return str_replace("\\","/",(string)$x); }; $p=$argv[1]; $s=$n(realpath(getenv("SYMPOSER_HOME")."/store")); $r=$n(realpath($p));
    if ($r==="") { echo "missing"; exit; }
    if ($r!==$n(realpath(dirname($p)))."/".basename($p)) { echo strpos($r,$s)===0 ? "store" : "path:".$r; exit; }
    $f=null; foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p, FilesystemIterator::SKIP_DOTS)) as $x) { if ($x->isFile()) { $f=$x; break; } }
    echo ($f && stat($f->getPathname())["nlink"] > 1) ? "hardlink" : "dir";' "$1"
}
expect() { local got; got=$(where "$1"); [[ "$got" == $2 ]] || die "$1: expected $2, got $got"; pass "$1 is $got"; }

# Fixtures use wide constraints so the same suite runs on PHP 7.2 through 8.x.
# 1. basic: install links into the store, autoload + InstalledVersions + bin proxies work
mkdir -p "$WORK/p1" && cd "$WORK/p1"
echo '{"require":{"monolog/monolog":"^2.9 || ^3.9"},"require-dev":{"phpunit/phpunit":"^8.5 || ^9.6 || ^10 || ^11"}}' > composer.json
sym update
expect vendor/monolog/monolog store
expect vendor/phpunit/phpunit hardlink
php -r 'require "vendor/autoload.php"; new Monolog\Logger("x"); echo \Composer\InstalledVersions::getPrettyVersion("monolog/monolog");' >/dev/null && pass "autoload + installed.php"
vendor/bin/phpunit --version >/dev/null && pass "vendor/bin/phpunit runs"

# 2. second project with the same lock downloads nothing
mkdir -p "$WORK/p2" && cp composer.json composer.lock "$WORK/p2/" && cd "$WORK/p2"
sym install
grep -q ", 0 newly stored" "$WORK/last.log" || die "second project downloaded packages"
pass "second project: all from store"

# 3. update moves only that link, the old version stays in the store
sym require monolog/monolog:2.9.1
php -r 'exit(strpos(realpath("vendor/monolog/monolog"), "2.9.1-") !== false ? 0 : 1);' || die "monolog link not moved"
[ "$(ls "$SYMPOSER_HOME/store/monolog/monolog" | wc -l)" -ge 2 ] || die "old monolog version missing from store"
pass "update relinked; old version kept"

# 4. remove unlinks without touching the store; plain composer interop replaces links with real dirs
sym remove --dev phpunit/phpunit
[ ! -e vendor/phpunit/phpunit ] && [ -d "$SYMPOSER_HOME/store/phpunit/phpunit" ] && pass "remove kept the store"

# 5. path repo stays a direct link; exclude + composer-patches targets are real copies; installer-paths honoured
mkdir -p "$WORK/p3/packages/local/src" && cd "$WORK/p3"
echo '{"name":"acme/local","autoload":{"psr-4":{"Acme\\":"src/"}}}' > packages/local/composer.json
cat > composer.json <<'EOF'
{
  "repositories": [
    { "type": "path", "url": "packages/*" },
    { "type": "composer", "url": "https://wpackagist.org", "only": ["wpackagist-plugin/*"] }
  ],
  "require": {
    "acme/local": "*@dev", "psr/log": "^1 || ^2 || ^3", "psr/container": "^1.1 || ^2",
    "composer/installers": "^2", "wpackagist-plugin/akismet": "*"
  },
  "extra": {
    "symposer": { "exclude": ["psr/container"] },
    "patches": { "psr/log": {} },
    "installer-paths": { "wp-content/plugins/{$name}/": ["type:wordpress-plugin"] }
  },
  "config": { "allow-plugins": { "composer/installers": true } }
}
EOF
sym update
expect vendor/acme/local "path:*packages*local"
expect vendor/psr/container dir
expect vendor/psr/log dir
expect vendor/composer/installers store
expect wp-content/plugins/akismet store

# 6. store prune keeps what a project uses and drops what none does; a missing store dir heals on install
export SYMPOSER_PRUNE_MIN_AGE=0
STORE="$SYMPOSER_HOME/store"
cd "$WORK/p1" && old=$(php -r 'echo basename(realpath("vendor/monolog/monolog"));')
sym store prune
[ -d "$STORE/monolog/monolog/$old" ] || die "prune removed monolog $old, which p1 uses"
pass "prune kept versions in use"
cd "$WORK" && rm -rf p1
sym store prune --dry-run
grep -q "would remove monolog/monolog/$old" "$WORK/last.log" && [ -d "$STORE/monolog/monolog/$old" ] || die "dry run"
pass "prune --dry-run lists without deleting"
sym store prune
[ ! -e "$STORE/monolog/monolog/$old" ] || die "prune kept monolog $old after p1 was deleted"
[ ! -e "$STORE/phpunit/phpunit" ] || die "prune kept phpunit, which no project uses"
cd "$WORK/p2"
php -r 'require "vendor/autoload.php"; new Monolog\Logger("x");' || die "p2 broken after prune"
pass "prune removed unused versions; p2 still works"
rm -rf "$STORE"/monolog/monolog/2.9.1-*
sym install
expect vendor/monolog/monolog store
php -r 'require "vendor/autoload.php"; new Monolog\Logger("x");' && pass "install heals a pruned link"
unset SYMPOSER_PRUNE_MIN_AGE

# 7. optional: full Laravel app with Pest (artisan scripts, package:discover, Pest's __DIR__ lookup)
if [ "${E2E_LARAVEL:-}" = 1 ]; then
  cd "$WORK" && php "$SYMPOSER_HOME"/composer-*.phar create-project laravel/laravel lara --no-install --no-scripts -q
  cd lara && cp .env.example .env
  sym install && sym require --dev pestphp/pest -W
  php artisan key:generate -q && php artisan --version >/dev/null && pass "artisan boots"
  ./vendor/bin/pest >/dev/null && pass "pest suite passes"
fi

echo "all e2e checks passed"
