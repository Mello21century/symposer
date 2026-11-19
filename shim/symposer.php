<?php
// symposer shim: runs Composer's own Application with archive downloaders wrapped
// so package contents live once in a global store and vendor/ entries are links.
// Usage: php symposer.php <composer.phar> <composer args...>   (SYMPOSER_HOME = store root)

namespace Symposer;

use Composer\Composer;
use Composer\Console\Application;
use Composer\Downloader\DownloaderInterface;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;
use Composer\Util\Platform;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;

const MIN_COMPOSER = '2.11.0'; // ponytail: oldest version the decorator was verified against
const ARCHIVE_TYPES = ['zip', 'tar', 'gzip', 'xz', 'rar', 'phar', 'file'];

$phar = $argv[1] ?? '';
if ($phar === '' || !is_file($phar)) {
    fwrite(STDERR, "symposer: usage: php symposer.php <composer.phar> [args]\n");
    exit(1);
}
$home = getenv('SYMPOSER_HOME') ?: (getenv('HOME') ?: getenv('USERPROFILE')) . '/.symposer';

setlocale(LC_ALL, 'C');
require 'phar://' . $phar . '/src/bootstrap.php';

if (version_compare(Composer::getVersion(), MIN_COMPOSER, '<')) {
    fwrite(STDERR, 'symposer: Composer ' . MIN_COMPOSER . '+ required, got ' . Composer::getVersion() . "\n");
    exit(1);
}

error_reporting(-1);
// Must run while argv still starts with this shim: the Xdebug-free restart re-executes argv as-is.
(new \Composer\XdebugHandler\XdebugHandler('Composer'))->check();

// Now make Composer see the usual argv (argv[0] = phar).
array_splice($_SERVER['argv'], 0, 2, [$phar]);
$_SERVER['argc'] = count($_SERVER['argv']);
$argv = $_SERVER['argv'];
if (PHP_SAPI === 'cli') {
    @ini_set('display_errors', 'stderr');
}
if ($limit = getenv('COMPOSER_MEMORY_LIMIT')) {
    @ini_set('memory_limit', $limit);
} elseif (ini_get('memory_limit') !== '-1') {
    @ini_set('memory_limit', '1536M');
}
Platform::putEnv('COMPOSER_BINARY', realpath($phar));
\Composer\Util\ErrorHandler::register();

final class Stats
{
    public static int $linked = 0;
    public static int $stored = 0;
}

final class Store
{
    private Filesystem $fs;

    public function __construct(public readonly string $root)
    {
        $this->fs = new Filesystem();
    }

    /** null = not storable (no reference to key on) */
    public function dirFor(PackageInterface $p): ?string
    {
        $ref = $p->getDistReference();
        if ($ref === null || $ref === '') {
            // ponytail: stable releases without a reference (e.g. wpackagist) key on their immutable dist URL
            if ($p->isDev() || !$p->getDistUrl()) {
                return null;
            }
            $ref = sha1($p->getDistUrl());
        }
        $key = preg_replace('{[^A-Za-z0-9._+-]}', '_', $p->getPrettyVersion()) . '-' . substr(preg_replace('{[^A-Za-z0-9]}', '', $ref), 0, 12);

        return $this->root . '/store/' . $p->getName() . '/' . $key;
    }

    public function tmpDir(): string
    {
        $dir = $this->root . '/store/.tmp/' . bin2hex(random_bytes(8));
        $this->fs->ensureDirectoryExists(dirname($dir));
        @chmod($this->root, 0700);

        return $dir;
    }

    /** Move a freshly extracted tmp dir into the store; loses races gracefully. */
    public function commit(string $tmp, string $dir): void
    {
        $this->fs->ensureDirectoryExists(dirname($dir));
        if (!is_dir($dir) && @rename($tmp, $dir)) {
            self::readOnly($dir);
            Stats::$stored++;

            return;
        }
        $this->fs->removeDirectory($tmp); // another process stored it first
    }

    /**
     * ponytail: files only (dirs stay writable so prune/rm -r just work); catches self-modifying packages.
     * Skipped on Windows, where read-only files block deleting hardlinked vendor trees.
     */
    private static function readOnly(string $dir): void
    {
        if (Platform::isWindows()) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && !$file->isLink()) {
                @chmod($file->getPathname(), $file->isExecutable() ? 0555 : 0444);
            }
        }
    }

    /**
     * $hardlink: build a real dir whose files are hardlinks into the store. Used for packages with
     * bin entries, whose scripts locate vendor/autoload.php via __DIR__ (which PHP resolves through symlinks).
     */
    public function link(string $dir, string $path, bool $hardlink): void
    {
        $this->unlink($path);
        if (is_dir($path)) {
            $this->fs->removeDirectory($path); // real dir left by plain composer or a previous hardlink tree
        }
        $this->fs->ensureDirectoryExists(dirname($path));
        if ($hardlink) {
            $this->hardlinkTree($dir, $path);
        } elseif (Platform::isWindows()) {
            $this->fs->junction($dir, $path); // no admin rights needed
        } elseif (!@symlink($dir, $path)) {
            throw new \RuntimeException("symposer: could not symlink $path -> $dir");
        }
        Stats::$linked++;
    }

    private function hardlinkTree(string $from, string $to): void
    {
        $this->fs->ensureDirectoryExists($to);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $file) {
            $src = $file->getPathname();
            $dst = $to . '/' . $it->getSubPathname();
            if ($file->isLink()) {
                @symlink((string) readlink($src), $dst); // keep the package's own relative symlinks
            } elseif ($file->isDir()) {
                @mkdir($dst, 0777, true);
            } elseif (!@link($src, $dst)) { // different volume than the store: plain copy
                if (!copy($src, $dst)) {
                    throw new \RuntimeException("symposer: could not link or copy $src to $dst");
                }
                @chmod($dst, fileperms($src) & 0777);
            }
        }
    }

    public function isLinkPath(string $path): bool
    {
        return is_link($path) || (Platform::isWindows() && $this->fs->isJunction($path));
    }

    /** @return bool whether $path was a link (and is now gone) */
    public function unlink(string $path): bool
    {
        if (Platform::isWindows() && $this->fs->isJunction($path)) {
            return $this->fs->removeJunction($path);
        }
        if (is_link($path)) {
            return $this->fs->unlink($path);
        }

        return false;
    }
}

/** Decorates an archive downloader: store hit = link, miss = inner install into store then link. */
final class StoreDownloader implements DownloaderInterface
{
    /** @var array<int, string> spl_object_id(package) => tmp dir for store misses */
    private array $tmp = [];

    /** @param array<string, true> $exclude */
    public function __construct(private DownloaderInterface $inner, private Store $store, private array $exclude, private \Composer\IO\IOInterface $io)
    {
    }

    public function getInstallationSource(): string
    {
        return $this->inner->getInstallationSource();
    }

    private function storeDir(PackageInterface $p): ?string
    {
        return isset($this->exclude[$p->getName()]) ? null : $this->store->dirFor($p);
    }

    /** Path the inner downloader should work on: store tmp dir for misses, real path otherwise. */
    private function innerPath(PackageInterface $p, string $path): string
    {
        $dir = $this->storeDir($p);
        if ($dir === null) {
            return $path;
        }

        return $this->tmp[spl_object_id($p)] ??= $this->store->tmpDir();
    }

    public function download(PackageInterface $package, string $path, ?PackageInterface $prevPackage = null): PromiseInterface
    {
        $dir = $this->storeDir($package);
        if ($dir !== null && is_dir($dir)) {
            return resolve(null);
        }

        return $this->inner->download($package, $this->innerPath($package, $path), $prevPackage);
    }

    public function prepare(string $type, PackageInterface $package, string $path, ?PackageInterface $prevPackage = null): PromiseInterface
    {
        if ($type === 'uninstall') {
            return $this->store->isLinkPath($path) ? resolve(null) : $this->inner->prepare($type, $package, $path, $prevPackage);
        }
        $dir = $this->storeDir($package);
        if ($dir !== null && is_dir($dir) && !isset($this->tmp[spl_object_id($package)])) {
            return resolve(null);
        }

        return $this->inner->prepare($type, $package, $this->innerPath($package, $path), $prevPackage);
    }

    public function install(PackageInterface $package, string $path): PromiseInterface
    {
        $dir = $this->storeDir($package);
        if ($dir === null) {
            $this->store->unlink($path); // was stored before, now excluded: get a real copy
            return $this->inner->install($package, $path);
        }
        $id = spl_object_id($package);
        if (!isset($this->tmp[$id])) {
            $hard = $package->getBinaries() !== [];
            $this->io->writeError('  - Linking <info>' . $package->getPrettyName() . '</info> (<comment>' . $package->getFullPrettyVersion() . '</comment>) from store' . ($hard ? ' (hardlinked, has bin)' : ''));
            $this->store->link($dir, $path, $hard); // store hit
            return resolve(null);
        }

        return $this->inner->install($package, $this->tmp[$id])->then(function () use ($id, $dir, $path, $package) {
            $this->store->commit($this->tmp[$id], $dir);
            $this->store->link($dir, $path, $package->getBinaries() !== []);
        });
    }

    public function update(PackageInterface $initial, PackageInterface $target, string $path): PromiseInterface
    {
        if ($this->storeDir($target) === null && !$this->store->isLinkPath($path)) {
            return $this->inner->update($initial, $target, $path); // plain vendor copy both sides
        }

        return $this->remove($initial, $path)->then(fn () => $this->install($target, $path));
    }

    public function remove(PackageInterface $package, string $path): PromiseInterface
    {
        if ($this->store->unlink($path)) {
            return resolve(null); // never touch the store
        }

        return $this->inner->remove($package, $path);
    }

    public function cleanup(string $type, PackageInterface $package, string $path, ?PackageInterface $prevPackage = null): PromiseInterface
    {
        $id = spl_object_id($package);
        if (!isset($this->tmp[$id])) {
            return $this->storeDir($package) === null || $type === 'uninstall'
                ? $this->inner->cleanup($type, $package, $path, $prevPackage)
                : resolve(null);
        }
        $tmp = $this->tmp[$id];
        unset($this->tmp[$id]);

        return $this->inner->cleanup($type, $package, $tmp, $prevPackage)->then(function () use ($tmp) {
            if (is_dir($tmp)) {
                (new Filesystem())->removeDirectory($tmp); // failed install leftovers
            }
        });
    }
}

/** Root packages that must stay real vendor copies: extra.symposer.exclude + composer-patches targets. */
function excludes(Composer $composer): array
{
    $extra = $composer->getPackage()->getExtra();
    $names = $extra['symposer']['exclude'] ?? [];
    $names = array_merge($names, array_keys($extra['patches'] ?? []));
    $files = array_filter([$extra['patches-file'] ?? null, $extra['composer-patches']['patches-file'] ?? null, 'patches.json']);
    foreach ($files as $file) {
        if (is_file($file)) {
            $json = json_decode((string) file_get_contents($file), true);
            $names = array_merge($names, array_keys($json['patches'] ?? []));
        }
    }

    return array_fill_keys(array_map('strtolower', $names), true);
}

final class SymposerApplication extends Application
{
    private \WeakMap $wrapped;

    public function __construct(private Store $store)
    {
        parent::__construct();
        $this->wrapped = new \WeakMap();
    }

    public function getComposer(bool $required = true, ?bool $disablePlugins = null, ?bool $disableScripts = null): ?Composer
    {
        $composer = parent::getComposer($required, $disablePlugins, $disableScripts);
        if ($composer instanceof Composer && !isset($this->wrapped[$composer])) {
            $this->wrapped[$composer] = true;
            $dm = $composer->getDownloadManager();
            $exclude = excludes($composer);
            foreach (ARCHIVE_TYPES as $type) {
                $dm->setDownloader($type, new StoreDownloader($dm->getDownloader($type), $this->store, $exclude, $this->getIO()));
            }
        }

        return $composer;
    }
}

register_shutdown_function(static function (): void {
    if (Stats::$linked > 0) {
        fwrite(STDERR, sprintf("symposer: %d package(s) linked from store, %d newly stored\n", Stats::$linked, Stats::$stored));
    }
});

$store = new Store(rtrim(str_replace('\\', '/', $home), '/'));
(new SymposerApplication($store))->run();
