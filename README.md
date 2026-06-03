# Varient - News & Magazine Script to Wordpress Importer

This is a free WordPress plugin for importing posts from the **Varient News & Magazine Script** into WordPress.

It was built and tested with:
- **Soledad** theme
- **Polylang** plugin

The plugin is based on that stack. If you want to use it with other themes or other multilingual plugins, only small code changes may be needed depending on how post views, media fields, or translation relationships are stored.

## Features

- Import published posts from Varient into WordPress
- Import categories and subcategories
- Import featured images into the correct WordPress uploads year/month folders
- Register imported images in the WordPress Media Library
- Save image copyright/description as:
  - image alt text
  - image caption
- Import all time post views
- Continue migration in batches
- Reset imported data and start over
- Match translated post pairs using a conservative scoring system

## Important note about database connections

Most hosting providers block remote database connections from outside the server.

Because of that, the best setup is:
- both the source Varient site and the destination WordPress site on the same server, or
- both on the same hosting account, or
- preferably on the same local computer using localhost

This gives the most reliable import results.

## Tested scenario

This plugin was designed and tested for:
- Source: Varient News & Magazine Script
- Destination: WordPress
- Theme: Soledad
- Multilingual plugin: Polylang

## Installation

1. Upload the plugin folder to `wp-content/plugins/`
2. Activate the plugin from the WordPress Plugins screen
3. Open `Varient Importer > Settings`
4. Enter your source Varient database credentials and source root path
5. Save settings
6. Open `Varient Importer` and start the migration

## Settings

- **DB Host**: source Varient database host and port
- **DB Name**: source Varient database name
- **DB User**: source Varient database username
- **DB Password**: source Varient database password
- **Source Root**: absolute filesystem path to the Varient installation
- **Language 1 / Language 2**: map Varient source language IDs to WordPress / Polylang language slugs

## Usage

### Continue migration
Imports the next batch of posts that have not been imported yet.

### Match translations
Attempts to link translated posts between languages using:
- shared image fingerprint
- small publish date differences
- category and parent category similarity
- title and summary length similarity

### Reset migration
Deletes content created by this plugin and resets the migration state so you can start again from the beginning.

## Notes

- This plugin currently targets Varient source data structure specifically
- It is intended as a free project
- If your destination theme stores views or media metadata differently, you may need small adjustments
- If you use a multilingual plugin other than Polylang, translation linking logic will need small changes

## License

Free project. Use and modify as needed.
