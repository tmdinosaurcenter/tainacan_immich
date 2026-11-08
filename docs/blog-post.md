# Connect your Tainacan collection to your Immich photos

Many museums, archives and community groups keep their photos in [Immich](https://immich.app/). It is a fast, private photo server that you host yourself. Many of the same groups use [Tainacan](https://tainacan.org/) to publish their collections on WordPress. Until now, the two did not talk to each other.

**Tainacan Immich** is a free WordPress plugin that connects them. You keep your photos in Immich. Your Tainacan items point to them.

## What it does

**Add a new kind of field.** The plugin adds an "Immich link" field type to Tainacan. Add it to any collection, like any other field. Then choose what the field links to:

- **A photo.** The item shows a thumbnail. You can link more than one photo to an item.
- **An album.** The item shows a link to the album in Immich.
- **A person.** The item shows the person's face and a link.

**Paste a link, and you are done.** To link a photo, open it in Immich and copy the address. Paste it into the item. The plugin finds the right photo, album or person for you.

**See where and when a photo was taken.** When you link a photo, the editing screen shows the place (city, state and country) and the date. It also shows a link to the place on OpenStreetMap. This helps you fill in your catalog records faster and with fewer mistakes.

**Keep exact locations private.** The place details are for editors only. Visitors to your public pages do not see GPS coordinates.

## Safe by design

Your Immich API key is a secret, and the plugin treats it that way:

- You enter the key once on the plugin's settings page. After you save it, the plugin never shows it again.
- The key never goes to your visitors' browsers. Thumbnails load through your own WordPress site, with a signed link.
- The plugin only reads from Immich. It cannot change, move or delete your photos. We suggest you create an API key with read permissions only.

## Easy to set up

1. Download the plugin and upload it in WordPress under **Plugins > Add New > Upload Plugin**.
2. Open the plugin's settings page. Enter your Immich address and your API key.
3. In Tainacan, add an "Immich link" field to a collection and choose what it links to.
4. In an item, paste a link from Immich.

You need WordPress 6.5 or newer, Tainacan 1.4.1 or newer, and an Immich server.

## Stays up to date

The plugin tells you when a new version is ready. You will see the notice on the WordPress Plugins page, as with any other plugin. You can turn the check off in the settings. The check does not send any information about your site.

## Free and open

Tainacan Immich is free software under the GPL v3 license. You can find the code and the downloads on GitHub: <https://github.com/tmdinosaurcenter/tainacan_immich>

If you try it, we would like to hear how it works for your collection.
