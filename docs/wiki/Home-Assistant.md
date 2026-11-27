# Home Assistant Integration

EverShelf integrates natively with [Home Assistant](https://www.home-assistant.io/) to bring your pantry data into your smart-home automations.

**Capabilities:**
- 📡 **REST sensors** — expose pantry counts as HA sensor entities (expiring, expired, shopping list, total items)
- 🔔 **Webhooks** — trigger HA automations on pantry events (expiry alerts, shopping additions, stock updates)
- 📣 **Push notifications** — send alerts to your phone via any HA `notify.*` service
- 🔊 **TTS on smart speakers** — read recipe steps aloud on any HA `media_player` entity
- 🍳 **Generate recipes from HA** — `evershelf.generate_recipe` (HACS integration ≥ 1.3.0) with fuel / veloce / scadenze / …; returns title + main ingredients
- ⚙️ **In-app config panel** — configure everything from Settings → 🏠 tab (no need to edit `.env` manually)

---

## Quick Setup

1. **Generate a Long-Lived Access Token** in Home Assistant:
   - Open HA → your **Profile** (bottom-left avatar) → **Security** → **Long-Lived Access Tokens** → **Create Token**
   - Copy the generated token — you won't see it again.

2. **Open EverShelf Settings** → tab **🏠 Home Assistant**.

3. Fill in **Home Assistant URL** (e.g. `http://homeassistant.local:8123`) and paste the token.

4. Click **Test connection** — you should see ✅.

5. Enable the features you want (TTS, Webhooks, REST Sensors) and click **Save HA settings**.

---

## REST Sensors

Add EverShelf pantry data as native HA sensor entities that update automatically.

### Endpoints

| URL | Returns | Sensor |
|-----|---------|--------|
| `/api/?action=ha_sensor` | Items expiring soon (≤`HA_EXPIRY_DAYS` days) | `sensor.evershelf_overview` |
| `/api/?action=ha_sensor&sensor=expired` | Expired items count | `sensor.evershelf_expired` |
| `/api/?action=ha_sensor&sensor=shopping` | Shopping list item count | `sensor.evershelf_shopping` |
| `/api/?action=ha_sensor&sensor=total` | Total pantry items | `sensor.evershelf_total` |
| `/api/?action=ha_sensor&sensor=product` | Full inventory — all items with complete details | `sensor.evershelf_products` |
| `/api/?action=ha_sensor&sensor=product&id=42` | Full details for inventory row `id=42` | — |
| `/api/?action=ha_sensor&sensor=product&name=milk` | Full details for items whose name contains "milk" | — |
| `/api/?action=ha_sensor&sensor=product&location=frigo` | All items in a specific location | — |

### Generate & Copy YAML

In Settings → 🏠 Home Assistant → **REST Sensors** card, click **Copy YAML** to get a ready-to-paste `configuration.yaml` block that already contains your EverShelf URL.

### API authentication after pairing or token migration

**Fresh EverShelf installations require an API token.** An existing open-LAN
installation stays open until you explicitly secure it. When EverShelf requires
authentication, Home Assistant's REST sensors must authenticate too; otherwise
they return HTTP 401 and become unavailable. The **Home Assistant long-lived
access token** (`HA_TOKEN`, used by EverShelf when calling Home Assistant) is
not the **EverShelf API token** (`API_TOKEN`, used by Home Assistant when
calling EverShelf).

On the Home Assistant server, add this to the private `secrets.yaml`:

```yaml
evershelf_api_authorization: "Bearer YOUR_EVERSHELF_API_TOKEN"
```

Replace the placeholder with your EverShelf `API_TOKEN` from your EverShelf
server configuration. **Do not paste it into Git, ordinary YAML, screenshots or
public support posts.** The button **Copy YAML** in EverShelf uses the
`!secret` reference on a paired client; it never copies the token itself.
Existing `ALLOW_OPEN_LAN=true` installations without an API token can omit
the `headers:` block.

### Manual YAML example

```yaml
# configuration.yaml
sensor:
  - platform: rest
    name: "EverShelf Overview"
    unique_id: evershelf_overview
    resource: "http://YOUR_EVERSHELF_URL/api/?action=ha_sensor"
    headers:
      Authorization: !secret evershelf_api_authorization
    scan_interval: 300        # seconds
    value_template: "{{ value_json.state }}"
    # The overview endpoint wraps additional data in its "attributes" object.
    json_attributes_path: "$.attributes"
    json_attributes:
      - expiring_soon
      - expiring_3d
      - expired_items
      - total_items
      - shopping_items
      - expiring_list      # full product details for expiring items
      - expired_list       # full product details for expired items
      - low_stock_list     # full product details for items with quantity ≤ 1
      - next_expiry_name
      - next_expiry_date
      - days_to_next_expiry
      - last_updated
    unit_of_measurement: "items"

  - platform: rest
    name: "EverShelf Shopping Count"
    unique_id: evershelf_shopping
    resource: "http://YOUR_EVERSHELF_URL/api/?action=ha_sensor&sensor=shopping"
    headers:
      Authorization: !secret evershelf_api_authorization
    scan_interval: 180
    value_template: "{{ value_json.state }}"
    unit_of_measurement: "items"

  # Full product inventory — each item includes all details (location, brand, category, …)
  - platform: rest
    name: "EverShelf Products"
    unique_id: evershelf_products
    resource: "http://YOUR_EVERSHELF_URL/api/?action=ha_sensor&sensor=product"
    headers:
      Authorization: !secret evershelf_api_authorization
    scan_interval: 600
    value_template: "{{ value_json.state }}"
    json_attributes:
      - items
      - last_updated
    unit_of_measurement: "items"
```

Restart Home Assistant after editing `configuration.yaml`.

Every product entry inside `expiring_list`, `expired_list`, `low_stock_list`, and `sensor=product` responses follows the same schema:

```json
{
  "product_id":       42,
  "inventory_id":     7,
  "name":             "Whole milk",
  "brand":            "Parmalat",
  "category":         "Lattiero-caseari",
  "quantity":         2.0,
  "unit":             "conf",
  "default_quantity": 1000.0,
  "package_unit":     "ml",
  "location":         "frigo",
  "expiry_date":      "2025-06-15",
  "days_remaining":   3,
  "opened_at":        "2025-06-10",
  "vacuum_sealed":    false
}
```

Field details:

| Field | Type | Description |
|-------|------|-------------|
| `product_id` | int | Products table ID |
| `inventory_id` | int | Inventory row ID |
| `name` | string | Product name |
| `brand` | string\|null | Brand (if set) |
| `category` | string\|null | Category (if set) |
| `quantity` | float | Current quantity in inventory |
| `unit` | string | Unit (`conf`, `g`, `ml`, `pz`, …) |
| `default_quantity` | float | Default package size (e.g. 1000 for 1-litre carton) |
| `package_unit` | string\|null | Unit of the default package (`g`, `ml`) |
| `location` | string\|null | Storage location (`frigo`, `freezer`, `dispensa`, …) |
| `expiry_date` | string\|null | ISO date `YYYY-MM-DD` |
| `days_remaining` | int\|null | Days until expiry (negative = already expired) |
| `opened_at` | string\|null | ISO date when the package was opened |
| `vacuum_sealed` | bool | Whether the item is vacuum-sealed |

---

## Generate a recipe from Home Assistant

With the [HACS integration](https://github.com/dadaloop82/ha-evershelf) **≥ 1.3.0** and EverShelf **≥ 1.7.68**:

```yaml
service: evershelf.generate_recipe
data:
  meal: pranzo          # or auto / colazione / cena / …
  persons: 2
  fuel: true            # “a ritmo mio” (requires Health)
  veloce: true
  scadenze: true
response_variable: recipe
```

**Service response / event `evershelf_recipe_generated`:** `title`, `main_ingredients`, `summary`, `meal`, `persons`, …

**API (without the custom component):**

`POST /api/index.php?action=ha_generate_recipe` with JSON body (same fields). Returns structured JSON including `title` and `main_ingredients`.

Legacy free-text: `GET ?action=ha_suggest_recipe`.

---

## Webhook Automations

EverShelf fires an HTTP POST to your HA webhook URL when pantry events occur.

### Create the HA Webhook Automation

1. HA → **Settings** → **Automations & Scenes** → **Create Automation**
2. Click **Add Trigger** → choose **Webhook**
3. HA generates a **Webhook ID** — copy it
4. Paste the ID into **Settings → 🏠 Home Assistant → Webhook ID**
5. Select which events should trigger the webhook

### Supported Events

| Event key | When it fires |
|-----------|--------------|
| `expiry` | Daily cron — items expiring within `HA_EXPIRY_DAYS` days |
| `shopping_add` | Item added to the shopping list |
| `stock_update` | Inventory quantity changed |
| `barcode_scan` | (reserved for future use) |

### Webhook Payload (POST body)

```json
{
  "event": "expiry_alert",
  "source": "evershelf",
  "ts": 1795248000,
  "type": "expiring_soon",
  "count": 3,
  "days": 3,
  "summary": "Milk, Yogurt, Butter",
  "items": [
    {
      "product_id": 42,
      "inventory_id": 7,
      "name": "Milk",
      "brand": "Parmalat",
      "category": "Dairy",
      "quantity": 2.0,
      "unit": "conf",
      "default_quantity": 1000.0,
      "package_unit": "ml",
      "location": "frigo",
      "expiry_date": "2026-11-23",
      "days_remaining": 2,
      "opened_at": "2026-11-20",
      "vacuum_sealed": false
    }
  ]
}
```

EverShelf sends the event metadata and attributes **directly at the JSON root**:
`event`, `source`, `ts` (Unix seconds), plus event-specific keys such as
`type`, `count`, and `items`. There is **no** nested `data` object.
The settings option `expiry` enables events with the actual payload name
`expiry_alert`. The cron may send two types: `expiring_soon` and `expired`.

### Example: Expiry Alert → Telegram

Only run the example for **upcoming** expiry events. The same webhook URL can
also receive `stock_update` and `shopping_add`, which have no `items` list.

```yaml
alias: EverShelf Expiry Alert
trigger:
  - platform: webhook
    webhook_id: "evershelf_webhook_abc123"   # your secret HA Webhook ID
    allowed_methods:
      - POST
    local_only: true
condition:
  - condition: template
    value_template: >
      {{ trigger.json.get('event') == 'expiry_alert'
         and trigger.json.get('type') == 'expiring_soon' }}
action:
  - service: notify.telegram_bot
    data:
      message: >-
        🥫 EverShelf: {{ trigger.json['count'] }} product(s) expiring soon
        {% for item in trigger.json['items'] %}
        — {{ item.name }}{% if item.brand %} ({{ item.brand }}){% endif %} ·
          {{ item.quantity }} {{ item.unit }} · 📍 {{ item.location }} ·
          expires {{ item.expiry_date }} ({{ item.days_remaining }} days)
        {% endfor %}
```

### Example: Automation on location

You can filter by location in the automation template to only alert for fridge items:

```yaml
condition:
  - condition: template
    value_template: >
      {{ trigger.json.get('items', []) | selectattr('location','eq','frigo') | list | length > 0 }}
```

---

## Push Notifications

If you prefer to receive push alerts without using webhooks, configure a **HA notify service** directly:

1. Find your notify service name in HA: **Developer Tools → Services** → search `notify`
2. Paste it into **Settings → 🏠 → Notify service** (e.g. `notify.mobile_app_my_phone`)
3. Save

EverShelf will call this service from the cron job whenever expiry alerts fire.

> **No Home Assistant?** The same events can be pushed to **ntfy** or to any generic
> webhook from **Settings → 🔔 Notifiche** — free, no account, and it can send a test
> notification. See [Configuration](Configuration) → *Push Notifications*.

---

## TTS on Smart Speakers

Read recipe steps aloud on an Amazon Echo, Google Home, Sonos, or any HA `media_player`.

### Configuration

1. Enter the **Entity ID** of your media player (e.g. `media_player.kitchen_display`)
   - Find it in HA: **Developer Tools → States**
2. Click **Apply HA preset to TTS tab** — this auto-fills the TTS tab with the correct HA endpoint and auth headers
3. Save settings

### How it Works

When recipe step TTS is triggered, EverShelf calls:

```
POST /api/services/tts/speak
Authorization: Bearer <HA_TOKEN>
{
  "entity_id": "media_player.kitchen_display",
  "message": "Add 200 g of flour and mix well."
}
```

The request is proxied through the EverShelf PHP backend (avoids CORS / mixed-content issues).

---

## Environment Variables Reference

Settings are configurable from `.env` or from the in-app Settings panel, except the administrator-only webhook CA bundle path and deferred-delivery switch.

| Variable | Default | Description |
|----------|---------|-------------|
| `HA_ENABLED` | `false` | Master switch for all HA features |
| `HA_URL` | _(empty)_ | Base URL of HA instance, no trailing slash |
| `HA_TOKEN` | _(empty)_ | Long-Lived Access Token |
| `HA_TTS_ENTITY` | _(empty)_ | `media_player` entity for TTS |
| `HA_WEBHOOK_ID` | _(empty)_ | Webhook trigger ID from HA automation |
| `HA_WEBHOOK_CA_BUNDLE` | _(empty)_ | Optional absolute path to a trusted PEM CA bundle for outbound HTTPS webhooks (`.env` only) |
| `HA_WEBHOOK_ASYNC` | `false` | Opt in to deferred outbound HA webhook attempts; requires the CLI worker (`.env` only) |
| `HA_WEBHOOK_EVENTS` | `expiry,shopping_add,stock_update` | Comma-separated list of events |
| `HA_NOTIFY_SERVICE` | _(empty)_ | HA notify service (e.g. `notify.mobile_app_phone`) |
| `HA_EXPIRY_DAYS` | `3` | Days before expiry to trigger the daily alert |

---

## HTTPS webhook trust

HTTPS webhooks verify the server certificate chain and hostname. Existing installations using untrusted self-signed certificates must install a trusted certificate or set `HA_WEBHOOK_CA_BUNDLE=/absolute/path/ca.pem` in `.env` to a trusted private CA bundle. The file must be readable by PHP; Docker users must mount it read-only into the container, outside the web root, and use the container path. Obtain the CA certificate from a trusted administrator, not from an unverified connection. Do not include private keys. A missing or invalid bundle fails delivery rather than disabling verification. This option applies only to outbound HA webhooks, not TTS or other integrations.

HTTP remains supported for existing local installations but does not provide TLS protection. Failed webhook delivery is logged without rolling back stock or shopping writes.

## Optional deferred webhook delivery (draft)

Enable `HA_WEBHOOK_ASYNC=true` in `.env` only after scheduling
`api/cron_ha_webhooks.php` as the same user as PHP. The Docker image includes
a once-per-minute cron entry; bare-metal administrators must install it first
(see [Installation](Installation#cron-jobs)). The default is unchanged
synchronous delivery to preserve existing installations and automation timing.

With deferred delivery, stock/shopping writes do not wait for the HA webhook
network request. A private separate SQLite outbox under
`data/ha_webhook_outbox/` stores event payloads, but not the destination URL,
webhook ID or CA path (only a destination fingerprint). Keep the runtime data
directory inaccessible via HTTP and out of backups/image build contexts.
Normally a new event is attempted at the next cron tick (up to about one
minute of scheduling delay, plus backlog and network time). A stopped worker
does not deliver events. The queue is bounded (500 events, 256 KiB per payload,
10-minute expiry); the worker handles at most 50 events per run, with a
45-second start budget. Excess, stale, disabled or changed-destination events
are dropped and logged. A failed local enqueue never rolls back a stock write.

This is best-effort, not a transactional outbox: an event may be lost between
the pantry write and enqueue, or if the worker stops after claiming it. Each
queue row is removed before one network attempt, so a timeout, HTTP 503 or
worker crash is **not retried automatically**. No exactly-once delivery is
promised. Event order follows queue insertion, not a global transaction order.
Other notification channels (ntfy, generic webhook, HA notify, Web Push) and
Bring! are unchanged and may still perform synchronous network calls.

Do not enable this experimental mode before reviewing backup/restore behavior
and validating the worker schedule on your installation. Before restoring a
pantry backup, stop the worker and discard pending test events if appropriate;
queue contents are not part of the pantry SQLite backup.

## Troubleshooting

**Test shows ❌ "Connection failed"**
- Verify the URL is reachable from the EverShelf server (not just your browser)
- If using HTTPS with a private CA or self-signed certificate, check the certificate trust and hostname. For outbound webhooks, configure the trusted PEM bundle as described above rather than disabling TLS verification. The webhook CA setting does not configure the connection test or TTS.
- Check that port 8123 (or your custom port) is open on the HA host

**Test shows ❌ "bad_token"**
- The Long-Lived Access Token may have expired or been revoked — generate a new one in HA Profile

**Webhook not firing**
- Confirm HA_ENABLED=true and the Webhook ID is exactly as shown in HA
- Check the EverShelf cron is running (`/api/cron_smart_shopping.php` every 5 minutes)
- For shopping/stock events: verify the event name is in `HA_WEBHOOK_EVENTS`

**TTS not speaking**
- Ensure the media player entity is online in HA (check its state in Developer Tools)
- Try the "Apply HA preset to TTS tab" button and send a test from the TTS tab
- Check HA logs for `tts.speak` errors (some platforms require `tts_options`)

**Sensors show unavailable in HA**
- The EverShelf URL must be reachable from the HA host
- If running EverShelf behind a reverse proxy, ensure `/api/` is accessible
- Use `scan_interval` ≥ 60 to avoid hammering the server
