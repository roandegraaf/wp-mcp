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
    #[McpTool(name: 'wp_get_form', description: 'Get Gravity Form structure including fields, confirmations, and notification names.')]
    public function getForm(
        #[Schema(description: 'Gravity Form ID')]
        int $form_id,
    ): string {
        $this->requireGravityForms();

        $form = \GFAPI::get_form($form_id);
        if (! $form) {
            throw new \RuntimeException("Form not found: {$form_id}");
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
     * Create a new Gravity Form.
     */
    #[McpTool(name: 'wp_create_form', description: 'Create a new Gravity Form with a title, optional description, and field definitions as JSON.')]
    public function createForm(
        #[Schema(description: 'Form title')]
        string $title,
        #[Schema(description: 'Form description')]
        string $description = '',
        #[Schema(description: 'JSON array of field definitions')]
        string $fields = '[]',
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
            'fields'      => $decodedFields,
        ];

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
    #[McpTool(name: 'wp_update_form', description: 'Update a Gravity Form title, description, active status, or fields.')]
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
            $form['fields'] = $decodedFields;
            $updated[] = 'fields';
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
}
