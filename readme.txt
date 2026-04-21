=== ABM Event Manager ===
Contributors: abmreading
Tags: events, masjid, mosque, islamic
Requires at least: 5.0
Tested up to: 6.7
Stable tag: 1.1.0
License: Private

Internal event management tool for Abu Bakr Masjid & Islamic Center.

== Description ==

A private plugin for Abu Bakr Masjid administrators. Provides:

* Full event management UI in WP Admin (create, edit, delete)
* REST API at /wp-json/abm/v1/events
* Front-end shortcode [abm_events] for event listings
* Event detail pages with full image display
* Mobile-responsive layouts in ABM brand colours
* AI Assist for event creation
* Image upload and media library integration
* MCP connector support for managing events from Claude

== Changelog ==

= 1.1.0 =
* Added GitHub auto-updater
* Applied ABM brand colours (gold #d1ad3c, navy #2a486c)
* Fixed wp_post_id bug for REST API created events
* Improved portrait/landscape image display
* Mobile-friendly event listing cards
* Description truncation on listing view
* Fixed REST API registration

= 1.0.0 =
* Initial release
* Event CRUD via WP Admin
* REST API endpoints
* Front-end shortcode
* Event detail pages
* MCP connector tools
