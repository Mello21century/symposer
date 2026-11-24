// symposer: pnpm-style global package store for Composer.
// Every command except `store` is handed to Composer, running inside the embedded shim
// that links vendor/ packages from ~/.symposer/store instead of extracting copies.
package main

import (
	"crypto/sha256"
	_ "embed"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"net/http"
	"os"
	"os/exec"
	"os/signal"
	"path/filepath"
	"strings"
	"time"
)

//go:embed shim/symposer.php
var shim []byte

const (
	version = "0.2.0"
	// ponytail: pinned because the shim decorates Composer internals; bump both together after re-running e2e
	composerVersion = "2.11.2"
	composerSHA256  = "dd158c0afd41d15fac89e1b4e47b5d42911fe316c3687cd5edac98319b25d028"
)

func main() {
	home, err := symposerHome()
	if err != nil {
		fail(err)
	}
	args := os.Args[1:]
	if len(args) > 0 && args[0] == "store" {
		storeCmd(home, args[1:])
		return
	}
	os.Exit(runComposer(home, args))
}

func symposerHome() (string, error) {
	if h := os.Getenv("SYMPOSER_HOME"); h != "" {
		return filepath.Abs(h)
	}
	u, err := os.UserHomeDir()
	return filepath.Join(u, ".symposer"), err
}

func runComposer(home string, args []string) int {
	php := os.Getenv("SYMPOSER_PHP")
	if php == "" {
		var err error
		if php, err = exec.LookPath("php"); err != nil {
			fail(errors.New("php not found on PATH (set SYMPOSER_PHP)"))
		}
	}
	phar, err := ensurePhar(home)
	if err != nil {
		fail(err)
	}
	shimPath, err := ensureShim(home)
	if err != nil {
		fail(err)
	}

	cmd := exec.Command(php, append([]string{shimPath, phar}, args...)...)
	cmd.Stdin, cmd.Stdout, cmd.Stderr = os.Stdin, os.Stdout, os.Stderr
	cmd.Env = append(os.Environ(), "SYMPOSER_HOME="+home)
	signal.Ignore(os.Interrupt) // Ctrl-C reaches php too; let Composer clean up, we just relay its exit code
	if err := cmd.Run(); err != nil {
		var exit *exec.ExitError
		if errors.As(err, &exit) {
			return exit.ExitCode()
		}
		fail(err)
	}
	return 0
}

// ensurePhar returns SYMPOSER_COMPOSER or the pinned, checksum-verified composer.phar (downloaded once).
func ensurePhar(home string) (string, error) {
	if p := os.Getenv("SYMPOSER_COMPOSER"); p != "" {
		return p, nil
	}
	path := filepath.Join(home, "composer-"+composerVersion+".phar")
	if _, err := os.Stat(path); err == nil {
		return path, nil
	}
	fmt.Fprintf(os.Stderr, "symposer: downloading Composer %s\n", composerVersion)
	resp, err := http.Get("https://getcomposer.org/download/" + composerVersion + "/composer.phar")
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return "", fmt.Errorf("downloading composer.phar: %s", resp.Status)
	}
	data, err := io.ReadAll(resp.Body)
	if err != nil {
		return "", err
	}
	if sum := sha256.Sum256(data); hex.EncodeToString(sum[:]) != composerSHA256 {
		return "", errors.New("composer.phar checksum mismatch, refusing to run it")
	}
	return path, writeAtomic(path, data)
}

// ensureShim writes the embedded shim under a content-addressed name, so upgrades never see a stale copy.
func ensureShim(home string) (string, error) {
	sum := sha256.Sum256(shim)
	path := filepath.Join(home, "runtime", "shim-"+hex.EncodeToString(sum[:6])+".php")
	if _, err := os.Stat(path); err == nil {
		return path, nil
	}
	return path, writeAtomic(path, shim)
}

func writeAtomic(path string, data []byte) error {
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return err
	}
	tmp, err := os.CreateTemp(filepath.Dir(path), ".tmp-*")
	if err != nil {
		return err
	}
	defer os.Remove(tmp.Name())
	if _, err := tmp.Write(data); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Close(); err != nil {
		return err
	}
	return os.Rename(tmp.Name(), path)
}

func storeCmd(home string, args []string) {
	store := filepath.Join(home, "store")
	sub := ""
	if len(args) > 0 {
		sub = args[0]
	}
	switch sub {
	case "path":
		fmt.Println(store)
	case "status":
		pkgs, versions, size, err := storeStats(store)
		if err != nil && !errors.Is(err, fs.ErrNotExist) {
			fail(err)
		}
		fmt.Printf("symposer %s (Composer %s)\nstore:    %s\npackages: %d (%d versions)\nsize:     %.1f MB\n",
			version, composerVersion, store, pkgs, versions, float64(size)/1e6)
	case "prune":
		dry := len(args) > 1 && args[1] == "--dry-run"
		if err := pruneStore(home, dry); err != nil {
			fail(err)
		}
	default:
		fmt.Fprintln(os.Stderr, "usage: symposer store path|status|prune [--dry-run]\n       symposer <composer command> [args]   e.g. symposer install")
		os.Exit(2)
	}
}

// storeStats counts store/<vendor>/<name>/<version> dirs and their total file size.
func storeStats(store string) (pkgs, versions int, size int64, err error) {
	err = filepath.WalkDir(store, func(p string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		rel, _ := filepath.Rel(store, p)
		if strings.HasPrefix(rel, ".tmp") {
			return filepath.SkipDir
		}
		depth := len(strings.Split(filepath.ToSlash(rel), "/"))
		switch {
		case d.IsDir() && rel != "." && depth == 2:
			pkgs++
		case d.IsDir() && depth == 3:
			versions++
		case d.Type().IsRegular():
			if info, err := d.Info(); err == nil {
				size += info.Size()
			}
		}
		return nil
	})
	return
}

// pruneStore deletes store versions that no recorded project (projects/*.json, written by the shim) uses.
func pruneStore(home string, dryRun bool) error {
	// skip fresh dirs: an install may be running that has not recorded its project yet
	minAge := time.Hour
	if v := os.Getenv("SYMPOSER_PRUNE_MIN_AGE"); v != "" { // for tests
		d, err := time.ParseDuration(v)
		if err != nil {
			return fmt.Errorf("SYMPOSER_PRUNE_MIN_AGE: %w", err)
		}
		minAge = d
	}

	keep, projects := map[string]bool{}, 0
	records, _ := filepath.Glob(filepath.Join(home, "projects", "*.json"))
	for _, rec := range records {
		data, err := os.ReadFile(rec)
		if err != nil {
			return err
		}
		var p struct {
			Vendor string   `json:"vendor"`
			Uses   []string `json:"uses"`
		}
		if err := json.Unmarshal(data, &p); err != nil {
			return fmt.Errorf("%s: %w (fix or delete it; nothing was pruned)", rec, err)
		}
		if _, err := os.Stat(p.Vendor); errors.Is(err, fs.ErrNotExist) {
			if !dryRun {
				os.Remove(rec) // project is gone
			}
			continue
		}
		projects++
		for _, u := range p.Uses {
			keep[u] = true
		}
	}

	store := filepath.Join(home, "store")
	old := func(d fs.DirEntry) bool {
		info, err := d.Info()
		return err == nil && time.Since(info.ModTime()) >= minAge
	}
	verb := "removed"
	if dryRun {
		verb = "would remove"
	}
	removed, kept, freed := 0, 0, int64(0)
	remove := func(dir, label string) error {
		_, _, size, _ := storeStats(dir)
		if dryRun {
			fmt.Println(verb, label)
		} else if err := os.RemoveAll(dir); err != nil {
			return err
		}
		removed++
		freed += size
		return nil
	}

	vendors, _ := os.ReadDir(store)
	for _, v := range vendors {
		vdir := filepath.Join(store, v.Name())
		if !v.IsDir() {
			continue
		}
		if v.Name() == ".tmp" { // leftovers of interrupted installs
			tmps, _ := os.ReadDir(vdir)
			for _, t := range tmps {
				if old(t) {
					if err := remove(filepath.Join(vdir, t.Name()), ".tmp/"+t.Name()); err != nil {
						return err
					}
				}
			}
			continue
		}
		names, _ := os.ReadDir(vdir)
		for _, n := range names {
			ndir := filepath.Join(vdir, n.Name())
			versions, _ := os.ReadDir(ndir)
			for _, ver := range versions {
				key := v.Name() + "/" + n.Name() + "/" + ver.Name()
				if keep[key] || !old(ver) {
					kept++
					continue
				}
				if err := remove(filepath.Join(ndir, ver.Name()), key); err != nil {
					return err
				}
			}
			if !dryRun {
				os.Remove(ndir) // only succeeds when empty
			}
		}
		if !dryRun {
			os.Remove(vdir)
		}
	}

	fmt.Printf("%s %d version(s), %.1f MB; kept %d, used by %d project(s)\n", verb, removed, float64(freed)/1e6, kept, projects)
	fmt.Println("note: projects last installed with symposer < 0.2.0 are not tracked; run `symposer install` in them first")
	return nil
}

func fail(err error) {
	fmt.Fprintln(os.Stderr, "symposer:", err)
	os.Exit(1)
}
