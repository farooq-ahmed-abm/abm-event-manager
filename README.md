# ABM Event Manager

A private WordPress plugin for **Abu Bakr Masjid & Islamic Center**, Reading UK.

---

## Features

- **WP Admin event manager** — create, edit and delete events with image upload and AI Assist
- **REST API** — full CRUD at `/wp-json/abm/v1/events`
- **Front-end shortcodes** — `[abm_events]` for listings, event detail pages
- **MCP connector support** — manage events directly from Claude
- **Mobile responsive** — portrait/landscape image support, branded in ABM colours
- **Auto-updates** — checks this GitHub repo for new releases

---

## Shortcodes

```
[abm_events]                          — All upcoming events
[abm_events limit="3"]                — Limit to 3 events
[abm_events category="Youth"]         — Filter by category
[abm_events upcoming="no"]            — Include past events
```

---

## REST API

Base URL: `https://abmreading.org/wp-json/abm/v1/`

All endpoints require WordPress authentication (Application Password).

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/events` | List all events |
| GET | `/events/{id}` | Get single event |
| POST | `/events` | Create event |
| POST | `/events/{id}` | Update event |
| DELETE | `/events/{id}` | Delete event |

**Query params for GET /events:**
- `upcoming=yes` — only future events
- `category=Education` — filter by category

---

## MCP Tools

Available via the FMZ MCP connector:

| Tool | Description |
|------|-------------|
| `get_abm_events` | List all events |
| `get_abm_event` | Get single event by ID |
| `create_abm_event` | Create a new event |
| `update_abm_event` | Update any event field |
| `delete_abm_event` | Delete an event |

---

## Installation

1. Download the latest zip from [Releases](https://github.com/farooq-ahmed-abm/abm-event-manager/releases)
2. Go to **WP Admin → Plugins → Add New → Upload Plugin**
3. Upload the zip and activate
4. Go to **Settings → Permalinks → Save Changes** to register REST routes

---

## Updating

Once installed, WordPress will check this repo for new releases automatically.
When an update is available, a notice will appear in **WP Admin → Plugins** — just click **Update**.

---

## Changelog

### v1.1.0
- GitHub auto-updater
- ABM brand colours applied (gold `#d1ad3c`, navy `#2a486c`)
- Fixed `wp_post_id` bug for REST API created events
- Portrait/landscape image display on detail pages
- Mobile-friendly event listing cards
- Description truncation on listing view

### v1.0.0
- Initial release
- Event CRUD via WP Admin
- REST API endpoints
- Front-end shortcode and event detail pages
- MCP connector tools

---

## Brand Colours

| Role | Colour | Hex |
|------|--------|-----|
| Primary | Gold | `#d1ad3c` |
| Secondary | Navy | `#2a486c` |

---

*Private plugin — Abu Bakr Masjid & Islamic Center, Reading UK*
