# EverShelf on Unraid

EverShelf is a self-hosted pantry manager: PHP 8 + SQLite, one container, no external
database and no account. This page is the Unraid-specific part of the installation.

The Community Applications template lives in this repository too
([`unraid/evershelf.xml`](../../unraid/evershelf.xml)), so it moves with the releases. The
original CA template was contributed by **@DevlinDelFuego** — thank you.

## Install

1. *Apps* → search **EverShelf** (or *Docker* → *Add Container* → paste the template URL
   `https://raw.githubusercontent.com/dadaloop82/EverShelf/develop/unraid/evershelf.xml`).
2. Leave the two paths on the array:
   | Path | Default | What it is |
   |---|---|---|
   | **App data** (`/var/www/html/data`) | `/mnt/user/appdata/evershelf/data` | SQLite database, caches, logs — **the pantry** |
   | **Configuration** (`/config`) | `/mnt/user/appdata/evershelf` | the folder holding `.env` |
3. Set `TZ` (default `Europe/Rome`) and, if the port 8080 is taken, change the **Web UI** host port.
4. Apply. Open `http://<tower-ip>:8080`.

## The configuration file (`.env`)

The app reads **and writes** its configuration from a single file. Inside the container it
defaults to `/var/www/html/.env`; the template points it to `/config/.env` with the
environment variable **`EVERSHELF_ENV_FILE`**, so it ends up on the array (in the
*Configuration* folder) and survives container updates.

- The file is created from `.env.example` on the first start if it does not exist.
- Saving from **Settings** writes into it, so the *Configuration* folder must be writable by
  the container user (`www-data`, UID 33):

  ```bash
  chown -R 33:33 /mnt/user/appdata/evershelf
  ```

- Only the keys you change are rewritten; the rest of the file is kept.

## First run: pairing

The API token is never handed to an anonymous request. On the first start the UI asks for a
**pairing code**; find it in the container log:

```bash
docker logs evershelf 2>&1 | grep -i "pairing code" | tail -1
```

On a device that is already paired the live code is under **Settings → System → Security**.
On a fully trusted LAN you can opt out with `API_BOOTSTRAP_OPEN=true` (see
[`SECURITY.md`](../../SECURITY.md)).

## What to configure first

| Setting | Why |
|---|---|
| `GEMINI_API_KEY` (or OpenAI / Ollama) | barcode identification, expiry reading, recipes, receipt OCR |
| `SHOPPING_MODE` (`internal` or `bring`) | the shopping list: built-in or mirroring a Bring! account |
| `API_TOKEN` | required as soon as the app is reachable from outside your LAN |
| cron every 5 min on `api/cron_smart_shopping.php` | smart-shopping refresh (see *Settings → System* for the command) |

Every optional feature is a normal `.env` key (tags, quick stocktake, product families,
receipt OCR…) and can be switched off in **Settings → Product rules**.

## Backup

Everything you need is in the two paths:

```bash
# stop the container first, then copy the folder (database + .env together)
docker stop evershelf
cp -a /mnt/user/appdata/evershelf /mnt/user/backup/evershelf-$(date +%F)
docker start evershelf
```

The in-app backup (Settings → System → Backup) covers the database; keeping the whole
appdata folder also keeps the configuration.

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| The pantry is empty after an update | the **App data** path is missing or on a tmpfs — it must point at `/mnt/user/appdata/...` |
| Settings do not stick | `/config` is not writable by UID 33 (`chown -R 33:33 …`), or `EVERSHELF_ENV_FILE` does not match the mounted folder |
| `.env` is a directory | an older template mounted the file itself; create the file first or use the folder mount above |
| "Cannot reach EverShelf" in the kiosk | check the container is up and the tablet URL includes the host port |
