=== Tainacan Immich ===
Author: steve
Contributors: steve
Tags: museums, archives, collections, tainacan
Requires at least: 6.5
Tested up to: 6.7
Requires PHP: 7.1
Stable tag: 0.2.2
Requires Plugins: tainacan
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Links Tainacan items to assets in an Immich photo server.

== Description ==

[Tainacan](https://tainacan.org/) is an open-source, flexible and powerful tool for creating digital repositories on WordPress. It allows you to create and manage collections with a variety of metadata types, rich search and filtering of items and much more.

== Installation ==

Upload the files to the plugins directory and activate it. You can also install and activate directly from the admin panel.

This plugin will only work with [Tainacan plugin](https://wordpress.org/plugins/tainacan/) active at version 1.0.0 or newer;

== Find out more ==

* Visit our official website: [https://tainacan.org/](https://tainacan.org/)
* Check our documentation Wiki: [https://wiki.tainacan.org/](https://wiki.tainacan.org/)
* Source code on GitHub: [https://github.com/tainacan/tainacan](https://github.com/tainacan/tainacan)

== Changelog ==

= 0.2.2 =
* Fix: the admin scripts now load with the plugin version in their URL, so a browser or CDN cache cannot keep an old copy after an update.

= 0.2.1 =
* Release tooling: GitHub Actions builds and publishes the zip when a version tag is pushed.

= 0.2.0 =
* Update notices in the WordPress dashboard from GitHub releases. "View details" shows the release notes.
* New setting "Check for plugin updates" (on by default). Sites can also turn the check off with the filter tainacan_immich_enable_update_check or the constant TAINACAN_IMMICH_UPDATE_REPO set to ''.
* "Update URI" header, so wordpress.org does not offer a different plugin with the same name.

= 0.1.0 =
* Settings page for the Immich URL and API key.
* "Immich link" metadata type for assets, albums and people. Read-only: the plugin never changes Immich.
* EXIF place, date and OpenStreetMap link shown in the item edit form.
* Options form and item field use the Tainacan 1.4.1 (Vue 3) component contract.

= 0.0.1 =
* First scaffold.

== Copyright ==

Tainacan Immich, Copyright 2025 Tainacan.org
Tainacan Immich plugin is distributed under the terms of the GNU GPLv3
License details: https://github.com/tainacan/blocksy-tainacan/blob/master/LICENSE
