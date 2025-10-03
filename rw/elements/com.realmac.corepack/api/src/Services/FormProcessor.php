<?php

namespace App\Services;

use App\Config\DynamicConfigLoader;
use App\Validation\Validator;
use App\Validation\ValidationResult;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * FormProcessor Service
 * Shared logic for processing form data across different form handlers
 */
class FormProcessor
{
    private DynamicConfigLoader $configLoader;

    /**
     * Fields that should be filtered out from email and webhook data
     * These are metadata/system fields that shouldn't be included in form submissions
     */
    private array $metadataFields = [
        // System fields
        'config_path',
        'form_id',

        // Spam protection tokens
        'captcha_token',
        'g-recaptcha-response',
        'cf-turnstile-response',
        'h-captcha-response',

        // Honeypot fields (used for spam detection)
        'website_url_field',
        'business_name_info',
        'contact_phone_backup',
        'secondary_email_addr',
        'user_comments_extra',
        'newsletter_signup_ref',
        'promo_code_field',
        'referral_source_info',
        'form_loaded_at',
        'js_check',

        // Internal metadata
        'absolute_path',
        'backend_path',
        'component_id',

        // Security fields
        'token',
        'csrf_token',
        '_token',
        'key',
        'secret',
        'auth',
        'password',
        'confirm_password',
        'confirm_secret',

        // Other technical fields that shouldn't appear in emails/webhooks
        '_files',
        '_metadata'
    ];

    public function __construct(DynamicConfigLoader $configLoader)
    {
        $this->configLoader = $configLoader;
    }

    /**
     * Extract form data from PSR-7 request
     */
    public function extractFormData(Request $request): array
    {
        $formData = [];

        // Get parsed body (form data)
        $parsedBody = $request->getParsedBody();
        if (is_array($parsedBody)) {
            $formData = array_merge($formData, $parsedBody);
        }

        // Get uploaded files
        $uploadedFiles = $request->getUploadedFiles();
        if (!empty($uploadedFiles)) {
            $formData['_files'] = $uploadedFiles;
        }

        return $formData;
    }

    /**
     * Extract metadata from request
     */
    public function extractMetadata(Request $request): array
    {
        return [
            'ip' => $this->getClientIp($request),
            'user_agent' => $this->getUserAgent($request),
            'timestamp' => date('c')
        ];
    }

    /**
     * Prepare form data for processing (cleaning, sanitization)
     * This method filters out metadata fields that shouldn't appear in emails or webhooks
     */
    public function prepareFormData(array $formData, array $metadata, array $formConfig): array
    {
        $cleanFormData = $formData;

        // Filter out metadata/system fields that shouldn't be in emails or webhooks
        $cleanFormData = $this->filterMetadataFields($cleanFormData);

        // Add metadata (this will be used internally but filtered out from emails/webhooks)
        $cleanFormData['_metadata'] = $metadata;

        // Handle file uploads - preserve original file handling logic
        if (isset($formData['_files'])) {
            $cleanFormData['_files'] = $this->flattenUploadedFiles($formData['_files']);
        }

        // Sanitize text fields
        foreach ($cleanFormData as $key => $value) {
            if (is_string($value) && !str_starts_with($key, '_')) {
                $cleanFormData[$key] = $this->sanitizeString($value);
            }
        }

        return $cleanFormData;
    }

    /**
     * Prepare data for email or webhook (removes metadata fields)
     * This ensures clean data for both email and webhook submissions
     */
    public function prepareSubmissionData(array $cleanFormData): array
    {
        // Remove all metadata fields including _metadata and _files
        return $this->filterMetadataFields($cleanFormData);
    }

    /**
     * Filter out metadata fields that shouldn't appear in emails or webhooks
     */
    private function filterMetadataFields(array $data): array
    {
        return array_diff_key($data, array_flip($this->metadataFields));
    }

    /**
     * Validate form data against configuration
     */
    public function validateFormData(array $formData, array $formConfig): ValidationResult
    {
        $validator = $this->createValidator($formConfig);
        return $validator->validate($formData);
    }

    /**
     * Get form configuration from config path
     */
    public function getFormConfig(string $configPath): array
    {
        try {
            return $this->configLoader->loadConfigFromPath($configPath);
        } catch (\Exception $e) {
            throw new \Exception("Failed to load form configuration: " . $e->getMessage());
        }
    }

    /**
     * Prepare data specifically for webhook sending
     */
    public function prepareWebhookData(array $cleanFormData, array $formConfig): array
    {
        // Use the new prepareSubmissionData method to ensure clean data
        $webhookData = $this->prepareSubmissionData($cleanFormData);
        $webhookData['timestamp'] = date('c');

        return $webhookData;
    }

    /**
     * Flatten uploaded files array - preserves exact original logic
     */
    private function flattenUploadedFiles(array $uploadedFiles): array
    {
        $flattened = [];

        foreach ($uploadedFiles as $key => $file) {
            if (is_array($file)) {
                $flattened[$key] = $this->flattenUploadedFiles($file);
            } else {
                $flattened[$key] = [
                    'name' => $file->getClientFilename(),
                    'size' => $file->getSize(),
                    'type' => $file->getClientMediaType(),
                    'tmp_name' => $file->getStream()->getMetadata('uri')
                ];
            }
        }

        return $flattened;
    }

    /**
     * Create validator from form configuration
     */
    private function createValidator(array $formConfig): Validator
    {
        $rules = $formConfig['validation'] ?? [];
        return new Validator($rules);
    }

    /**
     * Get client IP from request
     */
    private function getClientIp(Request $request): string
    {
        $forwarded = $request->getHeaderLine('x-forwarded-for');
        if (!empty($forwarded)) {
            $ips = explode(',', $forwarded);
            return trim($ips[0]);
        }

        $realIp = $request->getHeaderLine('x-real-ip');
        if (!empty($realIp)) {
            return $realIp;
        }

        $serverParams = $request->getServerParams();
        return $serverParams['REMOTE_ADDR'] ?? 'unknown';
    }

    /**
     * Get user agent from request
     */
    private function getUserAgent(Request $request): string
    {
        return $request->getHeaderLine('user-agent') ?: 'unknown';
    }

    /**
     * Sanitize string input
     */
    private function sanitizeString(?string $input): string
    {
        if ($input === null) {
            return '';
        }

        // Remove null bytes
        $sanitized = str_replace("\0", '', $input);

        // Trim whitespace
        $sanitized = trim($sanitized);

        return $sanitized;
    }
}
