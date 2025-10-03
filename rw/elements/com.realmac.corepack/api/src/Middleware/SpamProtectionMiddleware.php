<?php

namespace App\Middleware;

use App\Services\SpamProtectionService;
use App\ValueObjects\SpamProtectionResult;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Spam Protection Middleware
 * Automatically applies spam protection to routes
 */
class SpamProtectionMiddleware implements MiddlewareInterface
{
    private SpamProtectionService $spamProtectionService;
    private array $formConfig;

    public function __construct(SpamProtectionService $spamProtectionService, array $formConfig = [])
    {
        $this->spamProtectionService = $spamProtectionService;
        $this->formConfig = $formConfig;
    }

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        // Only apply spam protection to POST requests (form submissions)
        if ($request->getMethod() !== 'POST') {
            return $handler->handle($request);
        }

        // Get form configuration from request or use provided config
        $formConfig = $this->getFormConfigFromRequest($request) ?? $this->formConfig;

        // Get spam protection config to determine provider
        $spamConfig = $this->getSpamProtectionConfig($formConfig);
        $providerName = $spamConfig['provider'] ?? 'recaptcha';

        // If using honeypot provider, handle it entirely in middleware
        if ($providerName === 'honeypot') {
            // Check if honeypot spam protection is enabled
            if (!($spamConfig['enabled'] ?? false)) {
                // Honeypot disabled, continue without spam protection
                $request = $request->withAttribute(
                    'spam_protection_result',
                    \App\ValueObjects\SpamProtectionResult::success(['skipped' => true, 'reason' => 'disabled'])
                );
                return $handler->handle($request);
            }

            // Check honeypot fields
            $honeypotResult = $this->checkHoneypot($request);
            if ($honeypotResult !== null) {
                return $this->createErrorResponse($honeypotResult);
            }

            // Honeypot checks passed
            $request = $request->withAttribute(
                'spam_protection_result',
                \App\ValueObjects\SpamProtectionResult::success(['provider' => 'honeypot'])
            );
            return $handler->handle($request);
        }

        // For other providers (recaptcha, hcaptcha, turnstile), use the service
        $result = $this->spamProtectionService->verify($request, $formConfig);

        // If verification failed, return error response
        if ($result->isFailure()) {
            return $this->createErrorResponse($result);
        }

        // Add spam protection result to request attributes for controllers to access
        $request = $request->withAttribute('spam_protection_result', $result);

        return $handler->handle($request);
    }

    /**
     * Try to extract form configuration from request
     */
    private function getFormConfigFromRequest(Request $request): ?array
    {
        $parsedBody = $request->getParsedBody() ?? [];
        $configPath = $parsedBody['config_path'] ?? null;

        if (!$configPath) {
            return null;
        }

        try {
            // Try to load form configuration
            if (file_exists($configPath) && is_readable($configPath)) {
                return include $configPath;
            }
        } catch (\Exception $e) {
            // If we can't load config, fall back to provided config
        }

        return null;
    }

    /**
     * Get spam protection configuration for a form
     */
    private function getSpamProtectionConfig(array $formConfig): array
    {
        // Form-specific spam protection config takes precedence
        $formSpamConfig = $formConfig['spam_protection'] ?? [];

        // Merge with any global defaults (if we had them)
        return $formSpamConfig;
    }

    /**
     * Check honeypot fields for spam detection
     */
    private function checkHoneypot(Request $request): ?SpamProtectionResult
    {
        $parsedBody = $request->getParsedBody() ?? [];

        // Honeypot fields that should remain empty
        $honeypotFields = [
            'website_url_field',
            'business_name_info',
            'contact_phone_backup',
            'secondary_email_addr',
            'user_comments_extra',
            'newsletter_signup_ref',
            'promo_code_field',
            'referral_source_info'
        ];

        // Check if any honeypot field is filled
        foreach ($honeypotFields as $field) {
            if (isset($parsedBody[$field]) && !empty(trim($parsedBody[$field]))) {
                return SpamProtectionResult::failure(
                    'Spam detected via honeypot field',
                    'honeypot_triggered',
                    [
                        'provider' => 'honeypot',
                        'triggered_field' => $field,
                        'field_value' => substr($parsedBody[$field], 0, 50) // Log first 50 chars for debugging
                    ]
                );
            }
        }

        // Check submission timing (too fast = bot)
        if (isset($parsedBody['form_loaded_at']) && !empty($parsedBody['form_loaded_at'])) {
            $loadTime = (int) $parsedBody['form_loaded_at'];
            $currentTime = time() * 1000; // Convert to milliseconds
            $timeDiff = $currentTime - $loadTime;

            // If submitted in less than 2 seconds, likely a bot
            if ($timeDiff < 2000) {
                return SpamProtectionResult::failure(
                    'Form submitted too quickly',
                    'honeypot_timing',
                    [
                        'provider' => 'honeypot',
                        'submission_time_ms' => $timeDiff,
                        'minimum_time_ms' => 2000
                    ]
                );
            }
        }

        // Check JavaScript validation field
        if (isset($parsedBody['js_check']) && $parsedBody['js_check'] === 'no-js') {
            return SpamProtectionResult::failure(
                'JavaScript validation failed',
                'honeypot_js_check',
                [
                    'provider' => 'honeypot',
                    'js_check_value' => $parsedBody['js_check']
                ]
            );
        }

        // All honeypot checks passed
        return null;
    }

    /**
     * Create error response for spam protection failure
     */
    private function createErrorResponse($result): Response
    {
        $response = new \Slim\Psr7\Response();

        $errorData = [
            'success' => false,
            'error' => $result->getError(),
            'error_code' => $result->getErrorCode(),
            'timestamp' => date('c')
        ];

        // Add debug info in development
        if (isset($_ENV['APP_ENV']) && $_ENV['APP_ENV'] === 'development') {
            $errorData['debug'] = $result->getMetadata();
        }

        $response->getBody()->write(json_encode($errorData));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(400);
    }
}
