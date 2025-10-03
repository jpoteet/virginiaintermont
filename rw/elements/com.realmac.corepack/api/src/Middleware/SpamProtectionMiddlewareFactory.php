<?php

namespace App\Middleware;

use App\Services\SpamProtectionService;

/**
 * Factory class for creating spam protection middleware with different configurations
 */
class SpamProtectionMiddlewareFactory
{
    private SpamProtectionService $spamProtectionService;

    public function __construct(SpamProtectionService $spamProtectionService)
    {
        $this->spamProtectionService = $spamProtectionService;
    }

    /**
     * Create middleware with specific form configuration
     */
    public function create(array $formConfig = []): SpamProtectionMiddleware
    {
        return new SpamProtectionMiddleware($this->spamProtectionService, $formConfig);
    }

    /**
     * Create middleware for email forms
     */
    public function forEmailForms(array $additionalConfig = []): SpamProtectionMiddleware
    {
        $defaultConfig = [
            'spam_protection' => [
                'enabled' => true,
                'provider' => 'recaptcha'
            ]
        ];

        $config = array_merge_recursive($defaultConfig, $additionalConfig);
        return $this->create($config);
    }

    /**
     * Create middleware for webhook forms
     */
    public function forWebhookForms(array $additionalConfig = []): SpamProtectionMiddleware
    {
        $defaultConfig = [
            'spam_protection' => [
                'enabled' => true,
                'provider' => 'recaptcha'
            ]
        ];

        $config = array_merge_recursive($defaultConfig, $additionalConfig);
        return $this->create($config);
    }

    /**
     * Create middleware with specific provider
     */
    public function withProvider(string $provider, array $additionalConfig = []): SpamProtectionMiddleware
    {
        $config = array_merge_recursive([
            'spam_protection' => [
                'enabled' => true,
                'provider' => $provider
            ]
        ], $additionalConfig);

        return $this->create($config);
    }
}
