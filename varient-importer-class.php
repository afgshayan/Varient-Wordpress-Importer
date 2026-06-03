<?php

namespace VarientImporter;

use WP_Term;
use wpdb;

if (!defined('ABSPATH')) {
    exit;
}

class VarientImporterPlugin
{
    private const SETTINGS_OPTION = 'varient_importer_settings';
    private const STATE_OPTION = 'varient_importer_state';

    private ?wpdb $sourceDb = null;

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addAdminPages']);
        add_action('admin_init', [$this, 'registerSettings']);
    }

    public function addAdminPages(): void
    {
        add_menu_page('Varient Importer', 'Varient Importer', 'manage_options', 'varient-importer', [$this, 'renderImporterPage'], 'dashicons-migrate', 58);
        add_submenu_page('varient-importer', 'Settings', 'Settings', 'manage_options', 'varient-importer-settings', [$this, 'renderSettingsPage']);
    }

    public function registerSettings(): void
    {
        register_setting('varient_importer_settings_group', self::SETTINGS_OPTION, [$this, 'sanitizeSettings']);
    }

    public function sanitizeSettings(array $input): array
    {
        return [
            'db_host' => isset($input['db_host']) ? sanitize_text_field($input['db_host']) : '127.0.0.1:3306',
            'db_name' => isset($input['db_name']) ? sanitize_text_field($input['db_name']) : '',
            'db_user' => isset($input['db_user']) ? sanitize_text_field($input['db_user']) : '',
            'db_password' => isset($input['db_password']) ? (string) $input['db_password'] : '',
            'source_root' => isset($input['source_root']) ? rtrim(str_replace('\\', '/', sanitize_text_field($input['source_root'])), '/') . '/' : '',
            'language_map' => [
                1 => isset($input['language_map'][1]) ? sanitize_key($input['language_map'][1]) : 'en',
                2 => isset($input['language_map'][2]) ? sanitize_key($input['language_map'][2]) : 'fa',
            ],
        ];
    }

    public function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = $this->getSettings();
        echo '<div class="wrap">';
        echo '<h1>Varient Importer Settings</h1>';
        echo '<div class="notice notice-info"><p>Most hosting providers block remote database connections from outside the server. For best results, keep both the source Varient site and the destination WordPress site on the same server. The safest and easiest option is to run both sites on the same local computer using localhost.</p></div>';
        echo '<form method="post" action="options.php">';
        settings_fields('varient_importer_settings_group');
        echo '<table class="form-table">';
        echo '<tr><th scope="row">DB Host</th><td><input type="text" name="' . esc_attr(self::SETTINGS_OPTION) . '[db_host]" value="' . esc_attr($settings['db_host']) . '" class="regular-text"><p class="description">The hostname and optional port of the Varient source database. Example: 127.0.0.1:3306 or localhost.</p></td></tr>';
        echo '<tr><th scope="row">DB Name</th><td><input type="text" name="' . esc_attr(self::SETTINGS_OPTION) . '[db_name]" value="' . esc_attr($settings['db_name']) . '" class="regular-text"><p class="description">The name of the Varient database that contains the source posts.</p></td></tr>';
        echo '<tr><th scope="row">DB User</th><td><input type="text" name="' . esc_attr(self::SETTINGS_OPTION) . '[db_user]" value="' . esc_attr($settings['db_user']) . '" class="regular-text"><p class="description">The database username used to connect to the source Varient database.</p></td></tr>';
        echo '<tr><th scope="row">DB Password</th><td><input type="password" name="' . esc_attr(self::SETTINGS_OPTION) . '[db_password]" value="' . esc_attr($settings['db_password']) . '" class="regular-text"><p class="description">The database password for the source Varient database. Leave blank if the database does not use a password.</p></td></tr>';
        echo '<tr><th scope="row">Source Root</th><td><input type="text" name="' . esc_attr(self::SETTINGS_OPTION) . '[source_root]" value="' . esc_attr($settings['source_root']) . '" class="regular-text"><p class="description">The absolute filesystem path to the Varient installation root. Example: C:/wamp64/www/backup/</p></td></tr>';
        echo '<tr><th scope="row">Language 1</th><td><input type="text" name="' . esc_attr(self::SETTINGS_OPTION) . '[language_map][1]" value="' . esc_attr($settings['language_map'][1]) . '" class="small-text"><p class="description">The destination WordPress or Polylang language slug for source language ID 1. Example: en</p></td></tr>';
        echo '<tr><th scope="row">Language 2</th><td><input type="text" name="' . esc_attr(self::SETTINGS_OPTION) . '[language_map][2]" value="' . esc_attr($settings['language_map'][2]) . '" class="small-text"><p class="description">The destination WordPress or Polylang language slug for source language ID 2. Example: fa</p></td></tr>';
        echo '</table>';
        submit_button('Save Settings');
        echo '</form>';
        echo '</div>';
    }

    public function renderImporterPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $result = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['varient_importer_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['varient_importer_nonce'])), 'varient_importer_action')) {
            $limit = isset($_POST['limit']) ? max(1, min(100, (int) $_POST['limit'])) : 20;
            $action = isset($_POST['importer_action']) ? sanitize_key(wp_unslash($_POST['importer_action'])) : 'continue';
            if ($action === 'reset') {
                $result = $this->resetMigration();
            } elseif ($action === 'match_translations') {
                $result = $this->matchTranslations();
            } else {
                $result = $this->migrateNextBatch($limit);
            }
        }

        $state = $this->getState();
        echo '<div class="wrap">';
        echo '<h1>Varient Importer</h1>';
        echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=varient-importer-settings')) . '">Open Settings</a></p>';
        echo '<p><strong>Processed source posts:</strong> ' . esc_html((string) $state['processed']) . '</p>';
        echo '<p><strong>Last source post ID:</strong> ' . esc_html((string) $state['last_source_id']) . '</p>';
        echo '<p><strong>Matched translation pairs:</strong> ' . esc_html((string) $state['matched_pairs']) . '</p>';
        echo '<form method="post">';
        wp_nonce_field('varient_importer_action', 'varient_importer_nonce');
        echo '<table class="form-table">';
        echo '<tr><th scope="row">Batch size</th><td><input name="limit" type="number" min="1" max="100" value="20" class="small-text"></td></tr>';
        echo '</table>';
        echo '<p>';
        echo '<button type="submit" name="importer_action" value="continue" class="button button-primary">Continue migration</button> ';
        echo '<button type="submit" name="importer_action" value="match_translations" class="button">Match translations</button> ';
        echo '<button type="submit" name="importer_action" value="reset" class="button">Reset migration</button>';
        echo '</p>';
        echo '</form>';
        if (is_array($result)) {
            echo '<h2>Result</h2><pre>' . esc_html(wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre>';
        }
        echo '</div>';
    }

    private function getSettings(): array
    {
        $settings = get_option(self::SETTINGS_OPTION, []);
        return [
            'db_host' => $settings['db_host'] ?? '127.0.0.1:3306',
            'db_name' => $settings['db_name'] ?? '',
            'db_user' => $settings['db_user'] ?? '',
            'db_password' => $settings['db_password'] ?? '',
            'source_root' => $settings['source_root'] ?? '',
            'language_map' => [
                1 => $settings['language_map'][1] ?? 'en',
                2 => $settings['language_map'][2] ?? 'fa',
            ],
        ];
    }

    private function getSourceDb(): wpdb
    {
        if ($this->sourceDb instanceof wpdb) {
            return $this->sourceDb;
        }

        $settings = $this->getSettings();
        $this->sourceDb = new wpdb($settings['db_user'], $settings['db_password'], $settings['db_name'], $settings['db_host']);
        $this->sourceDb->set_charset($this->sourceDb->dbh, 'utf8');
        return $this->sourceDb;
    }

    private function getSourceRoot(): string
    {
        return $this->getSettings()['source_root'];
    }

    private function mapLanguageSlug(int $sourceLangId): ?string
    {
        $settings = $this->getSettings();
        return $settings['language_map'][$sourceLangId] ?? null;
    }

    private function migrateNextBatch(int $limit): array
    {
        $db = $this->getSourceDb();
        $state = $this->getState();
        $lastSourceId = (int) $state['last_source_id'];

        $posts = $db->get_results($db->prepare(
            "SELECT p.*, c.id AS source_category_id, c.name AS source_category_name, c.name_slug AS source_category_slug, c.parent_id AS source_category_parent_id,
            parent.id AS source_parent_category_id, parent.name AS source_parent_category_name, parent.name_slug AS source_parent_category_slug
            FROM posts p
            LEFT JOIN categories c ON c.id = p.category_id
            LEFT JOIN categories parent ON parent.id = c.parent_id
            WHERE p.visibility = 1 AND p.status = 1 AND p.is_scheduled = 0 AND p.id > %d
            ORDER BY p.id ASC LIMIT %d",
            $lastSourceId,
            $limit
        ), ARRAY_A);

        $results = [];
        $maxSourceId = $lastSourceId;
        foreach ($posts as $post) {
            $results[] = $this->migrateSinglePost($post);
            $maxSourceId = max($maxSourceId, (int) $post['id']);
        }

        $processedCount = count($posts);
        if ($processedCount > 0) {
            $this->saveState([
                'last_source_id' => $maxSourceId,
                'processed' => (int) $state['processed'] + $processedCount,
                'matched_pairs' => (int) $state['matched_pairs'],
            ]);
        }

        return [
            'count' => count($results),
            'items' => $results,
            'completed' => $processedCount < $limit,
            'state' => $this->getState(),
        ];
    }

    private function migrateSinglePost(array $sourcePost): array
    {
        $languageSlug = $this->mapLanguageSlug((int) $sourcePost['lang_id']);
        if ($languageSlug === null) {
            return ['source_post_id' => (int) $sourcePost['id'], 'status' => 'skipped', 'reason' => 'Unsupported language'];
        }

        $categoryIds = $this->resolveCategories($sourcePost, $languageSlug);
        $wpPostId = $this->upsertPost($sourcePost, $categoryIds);
        if (is_wp_error($wpPostId)) {
            return ['source_post_id' => (int) $sourcePost['id'], 'status' => 'error', 'reason' => $wpPostId->get_error_message()];
        }

        if (function_exists('pll_set_post_language')) {
            pll_set_post_language((int) $wpPostId, $languageSlug);
        }

        $attachmentId = $this->importFeaturedImage($sourcePost, (int) $wpPostId);
        if ($attachmentId) {
            set_post_thumbnail((int) $wpPostId, $attachmentId);
        }

        update_post_meta((int) $wpPostId, 'penci_post_views_count', (int) $sourcePost['pageviews']);
        update_post_meta((int) $wpPostId, '_varient_source_post_id', (int) $sourcePost['id']);
        update_post_meta((int) $wpPostId, '_varient_source_lang_id', (int) $sourcePost['lang_id']);

        return ['source_post_id' => (int) $sourcePost['id'], 'target_post_id' => (int) $wpPostId, 'language' => $languageSlug, 'featured_image_id' => $attachmentId, 'status' => 'ok'];
    }

    private function upsertPost(array $sourcePost, array $categoryIds)
    {
        $existingPostId = $this->findExistingPostId((int) $sourcePost['id']);
        $postDate = $this->normalizeDate($sourcePost['created_at']);
        $postData = [
            'ID' => $existingPostId,
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_title' => wp_strip_all_tags((string) $sourcePost['title']),
            'post_name' => sanitize_title((string) $sourcePost['title_slug']),
            'post_content' => (string) $sourcePost['content'],
            'post_excerpt' => (string) $sourcePost['summary'],
            'post_date' => $postDate,
            'post_date_gmt' => get_gmt_from_date($postDate),
            'post_category' => $categoryIds,
        ];

        return $existingPostId ? wp_update_post($postData, true) : wp_insert_post($postData, true);
    }

    private function importFeaturedImage(array $sourcePost, int $wpPostId): int
    {
        $relativePath = !empty($sourcePost['image_big']) ? (string) $sourcePost['image_big'] : (!empty($sourcePost['image_default']) ? (string) $sourcePost['image_default'] : '');
        if ($relativePath === '' || !$this->isLocalImage($relativePath)) {
            return 0;
        }

        $existingAttachmentId = $this->findExistingAttachmentId($relativePath);
        if ($existingAttachmentId) {
            if (!empty($sourcePost['image_description'])) {
                update_post_meta($existingAttachmentId, '_wp_attachment_image_alt', sanitize_text_field((string) $sourcePost['image_description']));
                wp_update_post(['ID' => $existingAttachmentId, 'post_excerpt' => (string) $sourcePost['image_description']]);
            }
            return $existingAttachmentId;
        }

        $sourcePath = $this->getSourceRoot() . ltrim(str_replace('\\', '/', $relativePath), '/');
        if (!file_exists($sourcePath)) {
            return 0;
        }

        $timestamp = strtotime((string) $sourcePost['created_at']) ?: current_time('timestamp');
        $uploadDir = wp_upload_dir($timestamp);
        $destination = trailingslashit($uploadDir['path']) . wp_unique_filename($uploadDir['path'], basename($sourcePath));
        if (!copy($sourcePath, $destination)) {
            return 0;
        }

        $filetype = wp_check_filetype($destination);
        $attachmentId = wp_insert_attachment([
            'post_mime_type' => $filetype['type'],
            'post_title' => preg_replace('/\.[^.]+$/', '', basename($destination)),
            'post_content' => '',
            'post_excerpt' => !empty($sourcePost['image_description']) ? (string) $sourcePost['image_description'] : '',
            'post_status' => 'inherit',
        ], $destination, $wpPostId);
        if (is_wp_error($attachmentId)) {
            return 0;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata($attachmentId, $destination);
        wp_update_attachment_metadata($attachmentId, $metadata);
        update_post_meta($attachmentId, '_varient_source_image_key', $relativePath);
        if (!empty($sourcePost['image_description'])) {
            update_post_meta($attachmentId, '_wp_attachment_image_alt', sanitize_text_field((string) $sourcePost['image_description']));
        }

        return (int) $attachmentId;
    }

    private function resolveCategories(array $sourcePost, string $languageSlug): array
    {
        $categoryIds = [];
        if (!empty($sourcePost['source_parent_category_name'])) {
            $parentId = $this->upsertCategory((string) $sourcePost['source_parent_category_name'], (string) $sourcePost['source_parent_category_slug'], $languageSlug, 0, !empty($sourcePost['source_parent_category_id']) ? (int) $sourcePost['source_parent_category_id'] : 0);
            if ($parentId && !empty($sourcePost['source_category_name'])) {
                $childId = $this->upsertCategory((string) $sourcePost['source_category_name'], (string) $sourcePost['source_category_slug'], $languageSlug, $parentId, !empty($sourcePost['source_category_id']) ? (int) $sourcePost['source_category_id'] : 0);
                if ($childId) {
                    $categoryIds[] = $parentId;
                    $categoryIds[] = $childId;
                }
            }
        } elseif (!empty($sourcePost['source_category_name'])) {
            $categoryId = $this->upsertCategory((string) $sourcePost['source_category_name'], (string) $sourcePost['source_category_slug'], $languageSlug, 0, !empty($sourcePost['source_category_id']) ? (int) $sourcePost['source_category_id'] : 0);
            if ($categoryId) {
                $categoryIds[] = $categoryId;
            }
        }
        return $categoryIds;
    }

    private function upsertCategory(string $name, string $slug, string $languageSlug, int $parentId, int $sourceCategoryId): int
    {
        $existing = get_terms(['taxonomy' => 'category', 'hide_empty' => false, 'slug' => sanitize_title($slug), 'lang' => $languageSlug]);
        if (!is_wp_error($existing) && !empty($existing)) {
            $termId = (int) $existing[0]->term_id;
            update_term_meta($termId, '_varient_source_category_id', $sourceCategoryId);
            return $termId;
 }

        $created = wp_insert_term($name, 'category', ['slug' => sanitize_title($slug), 'parent' => $parentId]);
        if (is_wp_error($created)) {
            $term = get_term_by('slug', sanitize_title($slug), 'category');
            return $term instanceof WP_Term ? (int) $term->term_id : 0;
        }

        $termId = (int) $created['term_id'];
        update_term_meta($termId, '_varient_source_category_id', $sourceCategoryId);
        if (function_exists('pll_set_term_language')) {
            pll_set_term_language($termId, $languageSlug);
        }
        return $termId;
    }

    private function matchTranslations(): array
    {
        $db = $this->getSourceDb();
        $sourcePosts = $db->get_results(
            "SELECT p.id, p.lang_id, p.category_id, p.created_at, p.image_big, p.image_default, p.title, p.summary, c.parent_id AS parent_category_id
             FROM posts p
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.visibility = 1 AND p.status = 1 AND p.is_scheduled = 0 AND p.lang_id IN (1,2)
             ORDER BY p.id ASC",
            ARRAY_A
        );

        $bySourceId = [];
        $byImageFingerprint = [];
        foreach ($sourcePosts as $sourcePost) {
            $sourceId = (int) $sourcePost['id'];
            $sourcePost['image_fingerprint'] = $this->buildImageFingerprint($sourcePost);
            $bySourceId[$sourceId] = $sourcePost;
            if ($sourcePost['image_fingerprint'] !== '') {
                $byImageFingerprint[$sourcePost['image_fingerprint']][] = $sourceId;
            }
        }

        $importedPosts = get_posts(['post_type' => 'post', 'post_status' => 'any', 'meta_key' => '_varient_source_post_id', 'numberposts' => -1, 'fields' => 'ids']);
        $pairs = [];
        $usedTargets = [];
        $debug = [];

        foreach ($importedPosts as $wpPostId) {
            $wpPostId = (int) $wpPostId;
            $sourceId = (int) get_post_meta($wpPostId, '_varient_source_post_id', true);
            if (empty($bySourceId[$sourceId])) {
                continue;
            }

            $currentTranslations = function_exists('pll_get_post_translations') ? pll_get_post_translations($wpPostId) : [];
            if (!empty($currentTranslations) && count($currentTranslations) > 1) {
                continue;
            }

            $sourcePost = $bySourceId[$sourceId];
            $sourceLangId = (int) $sourcePost['lang_id'];
            $targetLangId = $sourceLangId === 1 ? 2 : ($sourceLangId === 2 ? 1 : 0);
            if ($targetLangId === 0 || $sourcePost['image_fingerprint'] === '') {
                continue;
            }

            $candidateIds = $byImageFingerprint[$sourcePost['image_fingerprint']] ?? [];
            $bestCandidate = null;
            $bestScore = -1;
            $candidateDebug = [];

            foreach ($candidateIds as $candidateSourceId) {
                if ($candidateSourceId === $sourceId || isset($usedTargets[$candidateSourceId])) {
                    continue;
                }
                $candidate = $bySourceId[$candidateSourceId] ?? null;
                if (!$candidate || (int) $candidate['lang_id'] !== $targetLangId) {
                    continue;
                }

                $score = 60;
                $daysDiff = $this->getDayDifference((string) $sourcePost['created_at'], (string) $candidate['created_at']);
                if ($daysDiff <= 1) {
                    $score += 25;
                } elseif ($daysDiff <= 3) {
                    $score += 20;
                } elseif ($daysDiff <= 7) {
                    $score += 10;
                } elseif ($daysDiff <= 14) {
                    $score += 5;
                } else {
                    $score -= 20;
                }

                if ((int) $sourcePost['category_id'] === (int) $candidate['category_id']) {
                    $score += 20;
                }
                if (!empty($sourcePost['parent_category_id']) && (int) $sourcePost['parent_category_id'] === (int) $candidate['parent_category_id']) {
                    $score += 10;
                }

                $summaryLengthDiff = abs(mb_strlen((string) $sourcePost['summary']) - mb_strlen((string) $candidate['summary']));
                if ($summaryLengthDiff <= 120) {
                    $score += 5;
                }

                $titleLengthDiff = abs(mb_strlen((string) $sourcePost['title']) - mb_strlen((string) $candidate['title']));
                if ($titleLengthDiff <= 40) {
                    $score += 5;
                }

                $candidateDebug[] = [
                    'candidate_source_post_id' => $candidateSourceId,
                    'score' => $score,
                    'days_diff' => $daysDiff,
                    'category_match' => (int) $sourcePost['category_id'] === (int) $candidate['category_id'],
                    'parent_category_match' => !empty($sourcePost['parent_category_id']) && (int) $sourcePost['parent_category_id'] === (int) $candidate['parent_category_id'],
                ];

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestCandidate = $candidate;
                }
            }

            if ($bestCandidate && $bestScore >= 80) {
                $targetWpPostId = $this->findExistingPostId((int) $bestCandidate['id']);
                if ($targetWpPostId && $targetWpPostId !== $wpPostId) {
                    pll_save_post_translations([
                        $this->mapLanguageSlug((int) $sourcePost['lang_id']) => $wpPostId,
                        $this->mapLanguageSlug((int) $bestCandidate['lang_id']) => $targetWpPostId,
                    ]);
                    $pairs[] = [
                        'source_post_id' => $sourceId,
                        'target_source_post_id' => (int) $bestCandidate['id'],
                        'wp_post_id' => $wpPostId,
                        'target_wp_post_id' => $targetWpPostId,
                        'score' => $bestScore,
                    ];
                    $usedTargets[$sourceId] = true;
                    $usedTargets[(int) $bestCandidate['id']] = true;
                }
            } elseif (count($debug) < 20 && !empty($candidateDebug)) {
                $debug[] = [
                    'source_post_id' => $sourceId,
                    'image_fingerprint' => $sourcePost['image_fingerprint'],
                    'candidates' => $candidateDebug,
                ];
            }
        }

        $state = $this->getState();
        $state['matched_pairs'] = (int) $state['matched_pairs'] + count($pairs);
        $this->saveState($state);

        return ['status' => 'matched', 'pairs_count' => count($pairs), 'pairs' => $pairs, 'debug' => $debug, 'state' => $this->getState()];
    }

    private function findExistingPostId(int $sourcePostId): int
    {
        $posts = get_posts(['post_type' => 'post', 'post_status' => 'any', 'meta_key' => '_varient_source_post_id', 'meta_value' => $sourcePostId, 'numberposts' => 1, 'fields' => 'ids']);
        return empty($posts) ? 0 : (int) $posts[0];
    }

    private function findExistingAttachmentId(string $relativePath): int
    {
        $attachments = get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_varient_source_image_key', 'meta_value' => $relativePath, 'numberposts' => 1, 'fields' => 'ids']);
        return empty($attachments) ? 0 : (int) $attachments[0];
    }

    private function getState(): array
    {
        $state = get_option(self::STATE_OPTION, []);
        return ['last_source_id' => isset($state['last_source_id']) ? (int) $state['last_source_id'] : 0, 'processed' => isset($state['processed']) ? (int) $state['processed'] : 0, 'matched_pairs' => isset($state['matched_pairs']) ? (int) $state['matched_pairs'] : 0];
    }

    private function resetMigration(): array
    {
        $deletedPosts = 0;
        $deletedAttachments = 0;
        $deletedTerms = 0;

        $postIds = get_posts(['post_type' => 'post', 'post_status' => 'any', 'meta_key' => '_varient_source_post_id', 'numberposts' => -1, 'fields' => 'ids']);
        foreach ($postIds as $postId) {
            if (wp_delete_post((int) $postId, true)) {
                $deletedPosts++;
            }
        }

        $attachmentIds = get_posts(['post_type' => 'attachment', 'post_status' => 'any', 'meta_key' => '_varient_source_image_key', 'numberposts' => -1, 'fields' => 'ids']);
        foreach ($attachmentIds as $attachmentId) {
            if (wp_delete_attachment((int) $attachmentId, true)) {
                $deletedAttachments++;
            }
        }

        $terms = get_terms(['taxonomy' => 'category', 'hide_empty' => false, 'meta_query' => [['key' => '_varient_source_category_id', 'compare' => 'EXISTS']]]);
        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                if (wp_delete_term((int) $term->term_id, 'category')) {
                    $deletedTerms++;
                }
            }
        }

        $this->resetState();
        return ['status' => 'reset', 'deleted_posts' => $deletedPosts, 'deleted_attachments' => $deletedAttachments, 'deleted_terms' => $deletedTerms, 'state' => $this->getState()];
    }

    private function saveState(array $state): void
    {
        update_option(self::STATE_OPTION, ['last_source_id' => (int) $state['last_source_id'], 'processed' => (int) $state['processed'], 'matched_pairs' => isset($state['matched_pairs']) ? (int) $state['matched_pairs'] : 0], false);
    }

    private function resetState(): void
    {
        delete_option(self::STATE_OPTION);
    }

    private function buildImageFingerprint(array $sourcePost): string
    {
        $path = !empty($sourcePost['image_big']) ? (string) $sourcePost['image_big'] : (!empty($sourcePost['image_default']) ? (string) $sourcePost['image_default'] : '');
        if ($path === '') {
            return '';
        }

        $basename = basename($path);
        $parts = explode('_', $basename);
        if (count($parts) >= 3) {
            $last = end($parts);
            return (string) preg_replace('/\.[^.]+$/', '', (string) $last);
        }

        return (string) preg_replace('/\.[^.]+$/', '', $basename);
    }

    private function getDayDifference(string $leftDate, string $rightDate): int
    {
        $left = strtotime($leftDate);
        $right = strtotime($rightDate);
        if (!$left || !$right) {
            return 9999;
        }

        return (int) floor(abs($left - $right) / DAY_IN_SECONDS);
    }

    private function normalizeDate(string $date): string
    {
        $timestamp = strtotime($date) ?: current_time('timestamp');
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    private function isLocalImage(string $path): bool
    {
        return $path !== '' && !preg_match('/^https?:\/\//i', $path);
    }
}
