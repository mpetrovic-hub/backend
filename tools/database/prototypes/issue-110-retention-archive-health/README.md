# PROTOTYPE — Issue #110 retention archive health

This is disposable, local-only prototype code. It must never be deployed or
loaded by WordPress.

## Question

Can the proposed retention contract safely resume around SQLite receipt,
MariaDB delete, audit, lock, incident, quarantine, year-change, and annual
campaign boundaries, and how do the relevant SQLite checks and database phases
scale on this workstation up to an approximately 1.3 GiB synthetic archive?

The prototype uses only generated data. It never connects to Hostinger or opens
a Production archive.

## One-command run

From the repository root:

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\database\prototypes\issue-110-retention-archive-health\run-prototype.ps1
```

The launcher:

1. downloads the official MariaDB 11.8.8 Windows ZIP into a bounded Temp folder
   when it is not already present;
2. verifies SHA-256 before extraction;
3. initializes MariaDB without installing a Windows service;
4. starts it on `127.0.0.1:33110` without inherited output pipes;
5. enforces a short readiness timeout;
6. runs the Python logic and benchmark harness;
7. shuts MariaDB down in `finally`.

Small result files are written to `results/<run timestamp>/`. Scratch databases,
portable binaries, and generated SQLite files live below:

```text
%TEMP%\kiwi-retention-issue-110-prototype
```

Use `-SkipLarge` for a quick development pass. Use `-KeepScratch` only while
diagnosing a failed prototype run.

## Boundaries

- Local timings are not Hostinger timings.
- The Windows lock result still requires a Linux/Hostinger preflight.
- This harness validates the design and scaling trend; it is not production
  code and is not part of `tests/run-tests.php`.
