# Issue #42 — Phase A: host and instrumentation qualification (independent Mac)

Recorded 2026-10-20 (UTC) **before** any Phase B measurement. Repository commit `15a82d1` (= `main` after PR #41, checked
read-only against the GitHub API; no `git fetch`). Harness hash `699598752b7f5bf685d24632f91eebff02bbde1723da50e9b37172c80cec6f7a`
— identical to run-5's, i.e. the measured code path is unchanged. Nothing here is C2/C3 data.

## 1. Is this the contaminated laptop of runs 1–5 / the blocked Phase A? — No (evidence, not assumption)

| | Runs 1–5 host (from their `environment.json`) | This host |
|---|---|---|
| Host name | `Mohameds-MacBook-Pro-2.local` | `mohameds-MacBook-Pro.local` |
| Kernel | Darwin **27.0.0**, `xnu-13432.1.9` (Aug 2026) | Darwin **25.5.0**, `xnu-12377.121.10` (macOS Tahoe 26.5.2) |
| PHP | 8.5.9 | 8.4.4 |
| SoC | Apple M1 (`T8103`) | Apple M1 (`T8103`) |

- This machine's install history (`softwareupdate --history`) ends at macOS 26.5.2 (2026-07-11); it has never run Darwin 27.
- This machine was **powered off** from 2026-10-19 07:18 +03:00 to 2026-10-20 10:06 +03:00 (`last reboot`). Run-5 was
  recorded at 2026-10-19T23:55Z and the blocked Phase A of Issue #42 was reported between 05:54Z and 06:59Z on
  2026-10-20 — both while this machine was off.

Conclusion: a different physical Mac of the same model class (same owner account). Same SoC family, so the comparison
with runs 1–5 is between comparable but not identical hosts (§5).

## 2. Hardware and runtime

| Item | Value |
|---|---|
| Machine | MacBook Pro `MacBookPro17,1`, Apple M1, 8 cores (4 performance + 4 efficiency), 16 GB RAM, internal SSD (APFS) |
| OS | macOS 26.5.2 (25F84), arm64 |
| Power | **battery** (no adapter; Low Power Mode off; no thermal/performance warning recorded). Owner decision: measure both runs on battery; power source, battery level and Low Power Mode are logged every ~10 s in `host-processes.log`; any change of power conditions stops the work |
| Docker | Docker Desktop 4.95.0, engine 29.8.2, Apple Virtualization framework, VirtioFS, linuxkit `7.0.14` (same kernel as runs 3/5) |
| Docker VM resources | **8 vCPU**, 8092 MiB configured (8 215 117 824 bytes reported; runs 3/5: 8 319 770 624), swap 1 GiB, Kubernetes off, Resource Saver on (VM is active while the study container runs) |
| MySQL | official `mysql:8.0.46`, repo digest `sha256:7dcddc01f13bab2f15cde676d44d01f61fc9f99fe7785e86196dfc07d358ae2b` — **identical** to runs 3/5; `REPEATABLE-READ`, deadlock detection ON, `innodb_lock_wait_timeout` 50, `innodb_flush_log_at_trx_commit` 1 |
| PHP (workers) | 8.4.4 CLI (Homebrew), `pdo_mysql` (mysqlnd 8.4.4), `zlib`, opcache CLI off |

## 3. Competing workloads

- Docker: the study container `maatify-i32-mysql` is the **only running container**. All other containers on this
  machine are stopped (latest exit 2026-08-05); an `athar-ci-gates` image exists (built 2026-10-15) but no container of it
  runs. The "athar-ci" / `php-rate-limiter` workloads reported in the blocked Phase A are **not** present.
- No crontab; no CI runner, test suite, Composer, Node or other PHP CLI process outside the study.
- Idle background services (0.0 % CPU at inspection, monitored throughout): Homebrew `mysqld` (port 3306, not the study
  server), `redis`, `mongod`, `httpd`, `php-fpm`, `syncthing` (its folders do not include this repository); MEGA and
  Dropbox sync clients not running; Time Machine has no destination.
- Interactive apps open but idle; the executor's Claude desktop session itself is foreign to the study tree (~10–25 %).
- The machine had just booted: `boot-settle.log` shows foreign CPU 175–306 % in the first minutes (Spotlight,
  `assistant_service`, `mobileassetd`, `appstoreagent`), which settled to ~50–90 % before any qualification run. The
  remaining steady foreign load is `WindowServer` (~30–45 %, animated wallpaper; owner chose to leave it) and kernel/VM overhead.

## 4. Instrumentation checks (all on this host)

| Check | Result | File |
|---|---|---|
| Harness self-tests incl. real-MySQL preflight + observer negative control + host attribution with busy processes (`selftest.php --with-db=1 --with-host=1`) | **63/63 pass**; busy processes outside the study tree +274 % vs inside | `selftest.txt` |
| Real-container attribution (not covered by the faked `docker stats` self-tests) | 2 burners **inside** the study container: foreign 88 → 79 (own, not counted); 2 burners in a **foreign** container: 77 → 265 (+~190, counted); study container not listed → whole VM counted foreign (269, fail-closed) | `docker-attribution-control.txt` |
| End-to-end pipeline from an empty database (`run-all.sh … smoke`, 8 ops; pipeline proof only, `QUIET_GATE=off` as documented for smoke) | exit 0; preflight, guarantees, analyzer, load classification work | `pipeline-smoke/` |
| Sensor under real study load (1 round × 400 ops, 8 connections, **Immediate only** so no policy comparison is possible; collection only, not evidence) | foreign 75–141 % during load (limits: mean 200 %, peak 400 %); 0/1 noisy; foreign processes = `WindowServer`, VM overhead, `kernel_task`, `top` | `sensor-under-load/` |

## 5. Differences from runs 1–5 that must be kept in mind

PHP 8.4.4 instead of 8.5.9 (client-side worker timing); macOS 26.5.2 instead of Darwin 27; Docker VM memory 7.65 GiB
instead of 7.75 GiB; on battery (runs 1–5 power state not recorded). Same SoC class, same vCPU count, same MySQL image
digest, same linuxkit kernel, same harness hash. Absolute numbers are therefore not directly comparable with runs 1–5;
the paired Immediate/Hybrid comparison within each run is.

## 6. Verdict

**Phase A: PASS** — an independent host (not the contaminated laptop), no competing project workloads or containers,
8 vCPU Docker VM, MySQL 8.0.46 with the identical image digest, sensor attribution verified with real processes and real
containers, fail-closed sensor behaviour verified, observer positive/negative controls pass. Phase B may start under the
frozen plan in [`../../ISSUE-42-PLAN.md`](../../ISSUE-42-PLAN.md). The quiet gate and the per-run noisy classification
remain in force during Phase B; a gate pass alone is not treated as proof of isolation.
