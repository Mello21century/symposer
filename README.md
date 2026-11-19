# symposer

pnpm-style package store for Composer. Every package version is stored **once** in `~/.symposer/store/`, and each project's `vendor/` links into it. A second Laravel project's `vendor/` uses about **2.6 MB of new disk instead of ~77 MB**.

## Installation

**Requirements:** PHP 7.2.5+ on your `PATH` (or set `SYMPOSER_PHP`). You do not need Composer installed: symposer downloads its own pinned, checksum-verified `composer.phar` on first run.

### Option 1: prebuilt binary
Download the binary for your platform from the [Releases page](https://github.com/Mello21century/symposer/releases).

**macOS / Linux**
```sh
# pick one: darwin-arm64, darwin-amd64, linux-amd64, linux-arm64
curl -fsSL -o symposer https://github.com/Mello21century/symposer/releases/latest/download/symposer-darwin-arm64
chmod +x symposer
sudo mv symposer /usr/local/bin/
symposer store status
```

**Windows (PowerShell)**
```powershell
$dir = "$env:LOCALAPPDATA\symposer"; New-Item -ItemType Directory -Force $dir | Out-Null
Invoke-WebRequest https://github.com/Mello21century/symposer/releases/latest/download/symposer-windows-amd64.exe -OutFile "$dir\symposer.exe"
$userPath = [Environment]::GetEnvironmentVariable("Path", "User")
[Environment]::SetEnvironmentVariable("Path", "$userPath;$dir", "User")   # then open a new terminal
symposer store status
```

### Option 2: with Go (1.23+)
```sh
go install github.com/Mello21century/symposer@latest
```
This puts `symposer` in `$(go env GOPATH)/bin`. Make sure that directory is on your `PATH`.

### Option 3: build from source
```sh
git clone https://github.com/Mello21century/symposer.git
cd symposer
go build -o bin/symposer .
```

### Verify
```sh
symposer store status   # prints version, store location, package count
symposer --version      # prints the Composer version symposer runs
```

## Usage

```sh
symposer install            # instead of: composer install
symposer update             # instead of: composer update
symposer require foo/bar    # any composer command works the same way
symposer store status       # store location, package count, size
```

### Choosing the PHP version
symposer is a native binary, so `php74 symposer install` does **not** work: PHP can't run it. Set `SYMPOSER_PHP` to the PHP you want, either a command on your `PATH` or a full path. Composer then runs on that PHP, so dependency resolution and platform checks match it. Any PHP from **7.2.5** up works.

Say your machine's default is PHP 8.3 and a project needs PHP 7.4:

**macOS / Linux (bash, zsh)**
```sh
SYMPOSER_PHP=php7.4 symposer install            # Debian/Ubuntu (ondrej PPA) naming
SYMPOSER_PHP=php74 symposer install             # Laravel Herd naming
SYMPOSER_PHP=/opt/homebrew/opt/php@7.4/bin/php symposer install   # Homebrew (shivammathur/php)

export SYMPOSER_PHP=php74                       # or: every symposer call in this shell
alias symposer74='SYMPOSER_PHP=php74 symposer'  # or: a shortcut in ~/.zshrc / ~/.bashrc
```

**Windows (PowerShell)**
```powershell
$env:SYMPOSER_PHP = "C:\php74\php.exe"; symposer install
```

**Windows (cmd)**
```bat
set SYMPOSER_PHP=C:\php74\php.exe && symposer install
```

To check which PHP is used: `SYMPOSER_PHP=php74 symposer --version` prints Composer's version and the PHP version running it.

Packages for different PHP versions share the store safely. Each one is keyed by its exact version and commit, so a PHP 7.4 project and a PHP 8.3 project link to whichever versions their own lock files ask for.

## How it works
symposer is a single Go binary. It runs Composer's real install and update pipeline (a pinned, sha256-verified `composer.phar`, downloaded once), with one change: the archive downloaders are wrapped (`shim/symposer.php`).

- **Store hit:** `vendor/<vendor>/<name>` becomes a link to `store/<vendor>/<name>/<version>-<ref>/` (a symlink, or a junction on Windows, which needs no admin rights).
- **Store miss:** Composer downloads it as usual (auth, cache, mirrors), extracts it into the store, then links it.
- **Packages with `bin` entries** get a *hardlinked* tree instead: a real dir whose files are hardlinks to the store. Bin scripts like Pest's find `vendor/autoload.php` through `__DIR__`, which PHP resolves through symlinks.

Composer still does everything else, so these work unchanged: resolution, `composer.lock`, private repos and `auth.json`, plugins and their events, `installer-paths`, scripts (`post-autoload-dump`, ...), `installed.php` and `vendor/bin`.

| package kind | result in vendor |
|---|---|
| normal dist package | symlink / junction → store |
| package with `bin` | hardlinked tree from store |
| `path` repository | Composer's own symlink to your local package (never stored) |
| `--prefer-source` / git source | normal clone (never stored) |
| excluded or patched (`extra.patches`) | normal copy |

Store files are made read-only (except on Windows), so a package that writes into itself fails loudly instead of changing every project that shares it.

## Opting packages out
```json
"extra": { "symposer": { "exclude": ["vendor/package"] } }
```
Packages patched by `cweagans/composer-patches` (`extra.patches`, `patches-file`, `patches.json`) are excluded automatically.

## Environment
| var | default |
|---|---|
| `SYMPOSER_HOME` | `~/.symposer` |
| `SYMPOSER_PHP` | `php` on PATH |
| `SYMPOSER_COMPOSER` | pinned phar (other versions at your own risk) |

## Develop
```sh
go build -o bin/symposer . && E2E_LARAVEL=1 e2e/run.sh bin/symposer
```
