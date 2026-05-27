<?php

declare(strict_types=1);

namespace WpMcp\Tools;

use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use WpMcp\Helpers\ResponseFormatter;

class GravityFormsTool extends AbstractTool
{
    private function requireGravityForms(): void
    {
        if (! class_exists('GFAPI')) {
            throw new \RuntimeException('Gravity Forms is required but not active.');
        }
    }

    /**
     * List all Gravity Forms.
     */
    #[McpTool(name: 'wp_list_forms', description: 'List all Gravity Forms with ID, title, active status, date created, and entry count.')]
    public function listForms(): string
    {
        $this->requireGravityForms();

        $forms = \GFAPI::get_forms();
        $data = [];

        foreach ($forms as $form) {
            $data[] = [
                'id'           => $form['id'],
                'title'        => $form['title'],
                'is_active'    => (bool) $form['is_active'],
                'date_created' => $form['date_created'],
                'entry_count'  => \GFAPI::count_entries($form['id']),
            ];
        }

        return ResponseFormatter::toJson([
            'total' => count($data),
            'forms' => $data,
        ]);
    }

    /**
     * Get a single Gravity Form structure with fields, confirmations, and notifications.
     */
    #[McpTool(name: 'wp_get_form', description: 'Get Gravity Form structure including fields, confirmations, and notifications. Pass full=true to receive the raw form array with every field/choice/input property (adminLabel, inputName, product pricing, cssClass, etc.).')]
    public function getForm(
        #[Schema(description: 'Gravity Form ID')]
        int $form_id,
        #[Schema(description: 'Return the full raw form array (all field, choice, notification, confirmation properties) instead of the summary view.')]
        bool $full = false,
    ): string {
        $this->requireGravityForms();

        $form = \GFAPI::get_form($form_id);
        if (! $form) {
            throw new \RuntimeException("Form not found: {$form_id}");
        }

        if ($full) {
            // Normalize GF_Field objects to plain arrays so every property round-trips.
            $rawFields = [];
            foreach ($form['fields'] as $field) {
                if (is_object($field) && method_exists($field, 'to_array')) {
                    $rawFields[] = $field->to_array();
                } elseif (is_object($field)) {
                    $rawFields[] = get_object_vars($field);
                } else {
                    $rawFields[] = $field;
                }
            }
            $form['fields'] = $rawFields;
            return ResponseFormatter::toJson($form);
        }

        $fields = [];
        foreach ($form['fields'] as $field) {
            $fieldData = [
                'id'         => $field->id,
                'label'      => $field->label,
                'type'       => $field->type,
                'isRequired' => (bool) $field->isRequired,
            ];

            if (! empty($field->choices)) {
                $fieldData['choices'] = array_map(function ($choice) {
                    return [
                        'text'  => $choice['text'],
                        'value' => $choice['value'],
                    ];
                }, $field->choices);
            }

            $fields[] = $fieldData;
        }

        $confirmations = [];
        if (! empty($form['confirmations'])) {
            foreach ($form['confirmations'] as $confirmation) {
                $confirmations[] = [
                    'id'      => $confirmation['id'],
                    'name'    => $confirmation['name'],
                    'type'    => $confirmation['type'],
                    'message' => $confirmation['message'] ?? '',
                ];
            }
        }

        $notificationNames = [];
        if (! empty($form['notifications'])) {
            foreach ($form['notifications'] as $notification) {
                $notificationNames[] = $notification['name'];
            }
        }

        return ResponseFormatter::toJson([
            'id'            => $form['id'],
            'title'         => $form['title'],
            'description'   => $form['description'] ?? '',
            'is_active'     => (bool) $form['is_active'],
            'fields'        => $fields,
            'confirmations' => $confirmations,
            'notifications' => [
                'count' => count($notificationNames),
                'names' => $notificationNames,
            ],
        ]);
    }

    /**
     * Normalize field definitions before passing to GFAPI.
     * - Preserves custom choice values
     * - Auto-generates inputs array for checkbox fields
     */
    private function processFields(array $fields): array
    {
        foreach ($fields as &$field) {
            if (! empty($field['choices'])) {
                foreach ($field['choices'] as &$choice) {
                    if (! isset($choice['value']) || $choice['value'] === '') {
                        $choice['value'] = $choice['text'];
                    }
                }
                unset($choice);
            }

            if (($field['type'] ?? '') === 'checkbox' && ! empty($field['choices']) && empty($field['inputs'])) {
                $fieldId = $field['id'] ?? 0;
                $inputs = [];
                foreach ($field['choices'] as $index => $choice) {
                    $inputs[] = [
                        'id'    => $fieldId . '.' . ($index + 1),
                        'label' => $choice['text'],
                        'name'  => '',
                    ];
                }
                $field['inputs'] = $inputs;
            }
        }
        unset($field);

        return $fields;
    }

    /**
     * Normalize notifications/confirmations arrays into the keyed structure GF stores on disk.
     * GF persists these as assoc arrays keyed by the item id. Accept either a list or a map
     * from callers and ensure each item has an id.
     */
    private function processKeyedItems(array $items): array
    {
        $out = [];
        foreach ($items as $key => $item) {
            if (! is_array($item)) {
                continue;
            }
            if (empty($item['id'])) {
                $item['id'] = is_string($key) && $key !== '' ? $key : uniqid('', true);
            }
            $out[$item['id']] = $item;
        }
        return $out;
    }

    /**
     * Create a new Gravity Form.
     */
    #[McpTool(name: 'wp_create_form', description: 'Create a new Gravity Form with title, description, fields, notifications, and confirmations (all as JSON). Notifications and confirmations accept the same array shape GF stores natively (event, to, subject, message, type, etc.).')]
    public function createForm(
        #[Schema(description: 'Form title')]
        string $title,
        #[Schema(description: 'Form description')]
        string $description = '',
        #[Schema(description: 'JSON array of field definitions')]
        string $fields = '[]',
        #[Schema(description: 'JSON array of notification definitions (each: name, event, to, subject, message, fromName, replyTo, etc.). Leave empty to use GF default admin notification.')]
        string $notifications = '',
        #[Schema(description: 'JSON array of confirmation definitions (each: name, type=message|page|redirect, message|pageId|url, isDefault, conditionalLogic). Leave empty to use GF default confirmation.')]
        string $confirmations = '',
    ): string {
        $this->requireGravityForms();

        $title = $this->sanitizeText($title);
        $description = $this->sanitizeText($description);

        $decodedFields = json_decode($fields, true);
        if (! is_array($decodedFields)) {
            throw new \RuntimeException('Invalid JSON for fields parameter.');
        }

        $formArray = [
            'title'       => $title,
            'description' => $description,
            'fields'      => $this->processFields($decodedFields),
        ];

        if ($notifications !== '') {
            $decoded = json_decode($notifications, true);
            if (! is_array($decoded)) {
                throw new \RuntimeException('Invalid JSON for notifications parameter.');
            }
            $formArray['notifications'] = $this->processKeyedItems($decoded);
        }

        if ($confirmations !== '') {
            $decoded = json_decode($confirmations, true);
            if (! is_array($decoded)) {
                throw new \RuntimeException('Invalid JSON for confirmations parameter.');
            }
            $formArray['confirmations'] = $this->processKeyedItems($decoded);
        }

        $newId = \GFAPI::add_form($formArray);
        if (is_wp_error($newId)) {
            throw new \RuntimeException('Failed to create form: ' . $newId->get_error_message());
        }

        return ResponseFormatter::toJson([
            'form_id' => $newId,
            'title'   => $title,
            'message' => 'Form created successfully.',
        ]);
    }

    /**
     * Update an existing Gravity Form.
     */
    #[McpTool(name: 'wp_update_form', description: 'Update a Gravity Form title, description, active status, fields, notifications, or confirmations. Notifications/confirmations replace the existing set when provided.')]
    public function updateForm(
        #[Schema(description: 'Gravity Form ID')]
        int $form_id,
        #[Schema(description: 'New form title (leave empty to keep current)')]
        string $title = '',
        #[Schema(description: 'New form description (leave empty to keep current)')]
        string $description = '',
        #[Schema(description: 'Whether the form is active')]
        ?bool $is_active = null,
        #[Schema(description: 'JSON array of field definitions (leave empty to keep current)')]
        string $fields = '',
        #[Schema(description: 'JSON array of notification definitions, replaces existing notifications (leave empty to keep current)')]
        string $notifications = '',
        #[Schema(description: 'JSON array of confirmation definitions, replaces existing confirmations (leave empty to keep current)')]
        string $confirmations = '',
    ): string {
        $this->requireGravityForms();

        $form = \GFAPI::get_form($form_id);
        if (! $form) {
            throw new \RuntimeException("Form not found: {$form_id}");
        }

        $updated = [];

        if ($title !== '') {
            $form['title'] = $this->sanitizeText($title);
            $updated[] = 'title';
        }

        if ($description !== '') {
            $form['description'] = $this->sanitizeText($description);
            $updated[] = 'description';
        }

        if ($fields !== '') {
            $decodedFields = json_decode($fields, true);
            if (! is_array($decodedFields)) {
                throw new \RuntimeException('Invalid JSON for fields parameter.');
            }
            $form['fields'] = $this->processFields($decodedFields);
            $updated[] = 'fields';
        }

        if ($notifications !== '') {
            $decoded = json_decode($notifications, true);
            if (! is_array($decoded)) {
                throw new \RuntimeException('Invalid JSON for notifications parameter.');
            }
            $form['notifications'] = $this->processKeyedItems($decoded);
            $updated[] = 'notifications';
        }

        if ($confirmations !== '') {
            $decoded = json_decode($confirmations, true);
            if (! is_array($decoded)) {
                throw new \RuntimeException('Invalid JSON for confirmations parameter.');
            }
            $form['confirmations'] = $this->processKeyedItems($decoded);
            $updated[] = 'confirmations';
        }

        $result = \GFAPI::update_form($form);
        if (is_wp_error($result)) {
            throw new \RuntimeException('Failed to update form: ' . $result->get_error_message());
        }

        if ($is_active !== null) {
            \GFAPI::update_form_property($form_id, 'is_active', $is_active ? 1 : 0);
            $updated[] = 'is_active';
        }

        return ResponseFormatter::toJson([
            'form_id' => $form_id,
            'updated' => $updated,
            'message' => 'Form updated successfully.',
        ]);
    }

    /**
     * List entries for a Gravity Form with pagination.
     */
    #[McpTool(name: 'wp_list_form_entries', description: 'List entries for a Gravity Form with pagination and status filter.')]
    public function listFormEntries(
        #[Schema(description: 'Gravity Form ID')]
        int $form_id,
        #[Schema(description: 'Entries per page', minimum: 1, maximum: 100)]
        int $per_page = 20,
        #[Schema(description: 'Page number', minimum: 1)]
        int $page = 1,
        #[Schema(description: 'Entry status: active, spam, or trash')]
        string $status = 'active',
    ): string {
        $this->requireGravityForms();

        $status = $this->sanitizeText($status);
        $offset = ($page - 1) * $per_page;

        $searchCriteria = ['status' => $status];
        $entries = \GFAPI::get_entries($form_id, $searchCriteria, null, ['offset' => $offset, 'page_size' => $per_page]);

        if (is_wp_error($entries)) {
            throw new \RuntimeException('Failed to retrieve entries: ' . $entries->get_error_message());
        }

        $total = \GFAPI::count_entries($form_id, $searchCriteria);

        $formatted = [];
        foreach ($entries as $entry) {
            $entryData = [
                'id'           => $entry['id'],
                'date_created' => $entry['date_created'],
                'source_url'   => $entry['source_url'],
                'status'       => $entry['status'],
                'created_by'   => $entry['created_by'],
            ];

            // Mask IP address: show only first octet
            if (! empty($entry['ip'])) {
                $firstOctet = explode('.', $entry['ip'])[0];
                $entryData['ip'] = $firstOctet . '.*.*.*';
            }

            // Include field values, skip internal fields starting with underscore
            foreach ($entry as $key => $value) {
                if (is_numeric($key) || (is_string($key) && strpos($key, '.') !== false && is_numeric(explode('.', $key)[0]))) {
                    $entryData['field_' . $key] = $value;
                }
            }

            $formatted[] = $entryData;
        }

        return ResponseFormatter::toJson([
            'entries'    => $formatted,
            'pagination' => [
                'page'        => $page,
                'per_page'    => $per_page,
                'total'       => (int) $total,
                'total_pages' => (int) ceil($total / $per_page),
            ],
        ]);
    }

    /**
     * List Gravity Forms add-on feeds (Stripe, Mollie, Mailchimp, etc.).
     */
    #[McpTool(name: 'wp_list_form_feeds', description: 'List Gravity Forms add-on feeds (Stripe, Mollie, Mailchimp, etc.). Optionally filter by form_id, addon_slug (e.g. gravityformsmollie, gravityformsstripe), or active status.')]
    public function listFormFeeds(
        #[Schema(description: 'Form ID to filter by (0 for all forms)')]
        int $form_id = 0,
        #[Schema(description: 'Add-on slug to filter by, e.g. gravityformsmollie, gravityformsstripe (empty for all)')]
        string $addon_slug = '',
        #[Schema(description: 'Filter by active state: 1 = active only, 0 = inactive only, -1 = all')]
        int $is_active = -1,
    ): string {
        $this->requireGravityForms();

        $feedIds = null;
        $formIds = $form_id > 0 ? $form_id : null;
        $slug = $addon_slug !== '' ? $this->sanitizeText($addon_slug) : null;
        $active = $is_active === -1 ? null : ($is_active === 1);

        $feeds = \GFAPI::get_feeds($feedIds, $formIds, $slug, $active);

        if (is_wp_error($feeds)) {
            // get_feeds returns WP_Error('not_found') when no feeds match — treat as empty list
            return ResponseFormatter::toJson(['total' => 0, 'feeds' => []]);
        }

        $formatted = [];
        foreach ($feeds as $feed) {
            $meta = $feed['meta'] ?? '';
            if (is_string($meta)) {
                $decoded = json_decode($meta, true);
                $meta = is_array($decoded) ? $decoded : [];
            }
            $formatted[] = [
                'id'         => (int) $feed['id'],
                'form_id'    => (int) $feed['form_id'],
                'addon_slug' => $feed['addon_slug'],
                'is_active'  => (bool) $feed['is_active'],
                'feed_order' => isset($feed['feed_order']) ? (int) $feed['feed_order'] : 0,
                'meta'       => $meta,
            ];
        }

        return ResponseFormatter::toJson([
            'total' => count($formatted),
            'feeds' => $formatted,
        ]);
    }

    /**
     * Get a single Gravity Forms feed by ID.
     */
    #[McpTool(name: 'wp_get_form_feed', description: 'Get a single Gravity Forms add-on feed by ID, including its full meta JSON.')]
    public function getFormFeed(
        #[Schema(description: 'Feed ID')]
        int $feed_id,
    ): string {
        $this->requireGravityForms();

        $feed = \GFAPI::get_feed($feed_id);
        if (is_wp_error($feed)) {
            throw new \RuntimeException('Feed not found: ' . $feed->get_error_message());
        }

        $meta = $feed['meta'] ?? '';
        if (is_string($meta)) {
            $decoded = json_decode($meta, true);
            $meta = is_array($decoded) ? $decoded : [];
        }

        return ResponseFormatter::toJson([
            'id'         => (int) $feed['id'],
            'form_id'    => (int) $feed['form_id'],
            'addon_slug' => $feed['addon_slug'],
            'is_active'  => (bool) $feed['is_active'],
            'feed_order' => isset($feed['feed_order']) ? (int) $feed['feed_order'] : 0,
            'meta'       => $meta,
        ]);
    }

    /**
     * Create a Gravity Forms add-on feed.
     */
    #[McpTool(name: 'wp_create_form_feed', description: 'Create a Gravity Forms add-on feed (Stripe, Mollie, Mailchimp, etc.). Meta JSON content depends on the add-on; inspect an existing feed with wp_get_form_feed to learn the expected shape.')]
    public function createFormFeed(
        #[Schema(description: 'Form ID to attach the feed to')]
        int $form_id,
        #[Schema(description: 'Add-on slug, e.g. gravityformsmollie, gravityformsstripe, gravityformsmailchimp')]
        string $addon_slug,
        #[Schema(description: 'Feed meta as a JSON object; structure depends on the add-on (feedName, mappedFields, paymentAmount, etc.)')]
        string $meta = '{}',
    ): string {
        $this->requireGravityForms();

        $slug = $this->sanitizeText($addon_slug);
        if ($slug === '') {
            throw new \RuntimeException('addon_slug is required.');
        }

        $form = \GFAPI::get_form($form_id);
        if (! $form) {
            throw new \RuntimeException("Form not found: {$form_id}");
        }

        $decodedMeta = json_decode($meta, true);
        if (! is_array($decodedMeta)) {
            throw new \RuntimeException('Invalid JSON for meta parameter.');
        }

        $feedId = \GFAPI::add_feed($form_id, $decodedMeta, $slug);
        if (is_wp_error($feedId)) {
            throw new \RuntimeException('Failed to create feed: ' . $feedId->get_error_message());
        }

        return ResponseFormatter::toJson([
            'feed_id'    => (int) $feedId,
            'form_id'    => $form_id,
            'addon_slug' => $slug,
            'message'    => 'Feed created successfully.',
        ]);
    }

    /**
     * Update a Gravity Forms add-on feed.
     */
    #[McpTool(name: 'wp_update_form_feed', description: 'Update a Gravity Forms add-on feed. Replaces the full meta JSON; use wp_get_form_feed first to merge changes.')]
    public function updateFormFeed(
        #[Schema(description: 'Feed ID')]
        int $feed_id,
        #[Schema(description: 'New feed meta as a JSON object (full replacement)')]
        string $meta = '',
        #[Schema(description: 'Whether the feed is active (null leaves unchanged)')]
        ?bool $is_active = null,
    ): string {
        $this->requireGravityForms();

        $feed = \GFAPI::get_feed($feed_id);
        if (is_wp_error($feed)) {
            throw new \RuntimeException('Feed not found: ' . $feed->get_error_message());
        }

        $updated = [];

        if ($meta !== '') {
            $decodedMeta = json_decode($meta, true);
            if (! is_array($decodedMeta)) {
                throw new \RuntimeException('Invalid JSON for meta parameter.');
            }
            $result = \GFAPI::update_feed($feed_id, $decodedMeta, (int) $feed['form_id']);
            if (is_wp_error($result)) {
                throw new \RuntimeException('Failed to update feed meta: ' . $result->get_error_message());
            }
            $updated[] = 'meta';
        }

        if ($is_active !== null && class_exists('GFFeedAddOn')) {
            \GFAPI::update_feed_property($feed_id, 'is_active', $is_active ? 1 : 0);
            $updated[] = 'is_active';
        } elseif ($is_active !== null) {
            global $wpdb;
            $wpdb->update($wpdb->prefix . 'gf_addon_feed', ['is_active' => $is_active ? 1 : 0], ['id' => $feed_id]);
            $updated[] = 'is_active';
        }

        return ResponseFormatter::toJson([
            'feed_id' => $feed_id,
            'updated' => $updated,
            'message' => 'Feed updated successfully.',
        ]);
    }

    /**
     * Delete a Gravity Forms add-on feed.
     */
    #[McpTool(name: 'wp_delete_form_feed', description: 'Delete a Gravity Forms add-on feed by ID.')]
    public function deleteFormFeed(
        #[Schema(description: 'Feed ID')]
        int $feed_id,
    ): string {
        $this->requireGravityForms();

        $result = \GFAPI::delete_feed($feed_id);
        if (is_wp_error($result)) {
            throw new \RuntimeException('Failed to delete feed: ' . $result->get_error_message());
        }

        return ResponseFormatter::toJson([
            'feed_id' => $feed_id,
            'message' => 'Feed deleted successfully.',
        ]);
    }
}
