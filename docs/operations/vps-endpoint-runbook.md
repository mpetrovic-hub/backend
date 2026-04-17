# VPS Endpoint Runbook

This runbook documents the edge endpoint setup used to expose landing-page domains through a VPS-hosted reverse proxy.

## Purpose

Use this setup when campaign domains cannot be mapped directly to the exact backend runtime on shared hosting.

Current model:

- public host (for example `your.joy-play.com`)
- VPS edge proxy (Caddy)
- backend origin (`backend.kiwimobile.de`)

Routing flow:

`https://your.joy-play.com/...` -> `Caddy on VPS` -> `https://backend.kiwimobile.de/...`

## Current server paths

- Caddy config: `/etc/caddy/Caddyfile`
- Caddy system log: `/var/log/caddy/system.log`
- Host access log: `/var/log/caddy/your.joy-play.com.access.log`

## Standard operations

Validate config:

```bash
sudo caddy validate --config /etc/caddy/Caddyfile
```

Format config:

```bash
sudo caddy fmt --overwrite /etc/caddy/Caddyfile
```

Reload service:

```bash
sudo systemctl reload caddy
```

Service status:

```bash
sudo systemctl status caddy --no-pager -l
```

Recent service logs:

```bash
sudo journalctl -u caddy -n 100 --no-pager
```

## Add a new domain

Example target domain: `new.joy-play.com`

1. DNS:
   - point `new.joy-play.com` to the VPS (A/AAAA or CNAME to an edge hostname)
2. Update Caddy:
   - add a new site block for `new.joy-play.com`
   - reuse the same reverse-proxy pattern to `backend.kiwimobile.de`
3. Apply:
   - validate config
   - reload caddy
4. Verify:
   - `curl -I https://new.joy-play.com/_edge/health`
   - `curl -I https://new.joy-play.com/wp-json`
   - `curl -I https://new.joy-play.com/lp/fr/myjoyplay5`
5. Landing metadata:
   - add hostname to the target landing `integration.php` where needed

## Verification checklist

After each config or DNS change, verify:

1. `https://<domain>/_edge/health` returns HTTP 200.
2. `https://<domain>/wp-json` returns HTTP 200.
3. `https://<domain>/lp/...` returns landing content (not WordPress 404).
4. Caddy service is `active (running)`.
5. Error scans are clean:

```bash
grep -Ei '"level":"error"| 50[234] |timeout|permission denied|no such host|handshake' /var/log/caddy/system.log | tail -n 50
grep -Ei '"status":50[234]|"status":499' /var/log/caddy/your.joy-play.com.access.log | tail -n 50
```

## Rollback

If a new config causes failures:

1. Restore previous config copy:

```bash
sudo cp /etc/caddy/Caddyfile.bak /etc/caddy/Caddyfile
```

2. Validate + reload:

```bash
sudo caddy validate --config /etc/caddy/Caddyfile
sudo systemctl reload caddy
```

If reload is stuck or failed:

```bash
sudo systemctl reset-failed caddy
sudo systemctl restart caddy
sudo systemctl status caddy --no-pager -l
```

## Common troubleshooting

- `unrecognized directive: email`
  - global `{ ... }` block is malformed or duplicated; keep one global block at file top.
- `permission denied` for `/var/log/caddy/...`
  - fix ownership/permissions for caddy user on log files.
- `rest_no_route` on custom domain but not backend host
  - domain is still routed to the wrong WordPress runtime; check DNS and proxy host mapping.
- TLS/cert errors
  - ensure inbound 80/443 are open and DNS resolves to the VPS.

## Security notes

- Keep only required inbound ports open on VPS firewall: `22/tcp`, `80/tcp`, `443/tcp`.
- Restrict SSH access by source IP when possible.
- Prefer SSH keys over password-only root access.
- Keep OS and Caddy updated regularly.
