<?php
/*
Plugin Name: Varient - News & Magazine Script to Wordpress Importer
Description: Import posts, media, categories and translations from Varient News & Magazine Script into WordPress.
Version: 0.1.0
Author: M.Ali Khodadadi
*/

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/varient-importer-class.php';

add_action('plugins_loaded', static function () {
    (new VarientImporter\VarientImporterPlugin())->register();
});
