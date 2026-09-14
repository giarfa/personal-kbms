---
paths:
  - 'app/Launcher/**'
---

# Launcher

## The queue worker's PATH reaches the spawned terminal window
The launcher script runs inside the queue worker, which is a macOS LaunchAgent. launchd gives it a bare PATH, and `open -na <Terminal> --args -e <script>` propagates the caller's environment straight through to the terminal app — so a bare command name in the spawned window resolves against the *worker's* PATH, not the operator's login shell.

A user-installed binary (e.g. `claude` in `~/.local/bin`) is therefore not found, the window's shell exits instantly, and the window closes before the error can be read. Running the same script by hand always works, which makes this look like an app bug when it is not.

Rules: resolve the binary to an absolute path in the outer script and bake that into the window's script; never rely on PATH inside the spawned window. Keep an explicit `EnvironmentVariables:PATH` in `~/Library/LaunchAgents/com.personal-kbms.queue-worker.plist`. Never let the window vanish on a non-zero exit — hold it open, or the next failure is invisible again.

Also: the operator's question is arbitrary input. If the launcher generates a script, shell-quote it (single quotes, `'` escaped as `'\''`) — an unquoted heredoc re-introduces the injection that `LaunchCommand`'s argument array exists to prevent.
