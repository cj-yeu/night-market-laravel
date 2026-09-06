<?php

namespace Tests\Unit;

use App\Services\CatalogSourceReader;
use App\Services\GeminiCatalogSourceService;
use App\Services\NativeHostnameResolver;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class CatalogGeminiDiagnosticsTest extends TestCase
{
    private function summarize(string $message): string
    {
        $service = new GeminiCatalogSourceService(new CatalogSourceReader(new NativeHostnameResolver));

        return (new ReflectionMethod($service, 'safeResourceReason'))->invoke($service, $message);
    }

    public function test_retirement_reason_is_reported_without_raw_secrets_or_urls(): void
    {
        $result = $this->summarize('models/gemini-2.5-flash has been retired. Use models/gemini-3.6-flash. Private user PRIVATE_DATA, key TEST_SECRET, https://example.test/?token=SECRET.');
        $this->assertStringContainsString('retired or discontinued', $result);
        $this->assertStringContainsString('gemini-2.5-flash, gemini-3.6-flash', $result);
        foreach (['PRIVATE_DATA', 'TEST_SECRET', 'SECRET', 'https://'] as $excluded) {
            $this->assertStringNotContainsString($excluded, $result);
        }
    }

    public function test_version_and_method_diagnostic_is_distinct_from_retirement(): void
    {
        $result = $this->summarize('models/gemini-2.5-flash is not found for API version v1beta, or is not supported for generateContent.');
        $this->assertStringContainsString('not found', $result);
        $this->assertStringContainsString('generateContent is unsupported', $result);
        $this->assertStringContainsString('v1beta', $result);
        $this->assertStringNotContainsString('retired', $result);
    }

    public function test_unrecognized_provider_content_is_not_echoed(): void
    {
        $this->assertSame('', $this->summarize('PRIVATE_ACCOUNT_DETAILS TEST_SECRET'));
    }
}
