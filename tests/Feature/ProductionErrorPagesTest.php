<?php

namespace Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Tests\TestCase;

class ProductionErrorPagesTest extends TestCase
{
    public function test_not_found_response_uses_the_branded_safe_page_when_debug_is_disabled(): void
    {
        config()->set('app.debug', false);

        $this->get('/this-page-does-not-exist')
            ->assertNotFound()
            ->assertSee('This page is unavailable')
            ->assertSee('Browse Markets')
            ->assertDontSee('Stack trace')
            ->assertDontSee(base_path(), false);
    }

    public function test_error_views_provide_safe_recovery_content_without_debug_details(): void
    {
        foreach ([403, 419, 429, 500, 503] as $status) {
            $html = view("errors.$status")->render();

            $this->assertStringContainsString('NightBite', $html);
            $this->assertStringNotContainsString('Stack trace', $html);
            $this->assertStringNotContainsString(base_path(), $html);
        }
    }

    public function test_exception_renderer_returns_safe_branded_responses_for_required_statuses(): void
    {
        config()->set('app.debug', false);
        $handler = app(ExceptionHandler::class);
        $request = Request::create('/safe-error-check');

        foreach ([
            403 => new HttpException(403),
            419 => new TokenMismatchException,
            429 => new TooManyRequestsHttpException,
            500 => new HttpException(500),
            503 => new HttpException(503),
        ] as $status => $exception) {
            $response = $handler->render($request, $exception);

            $this->assertSame($status, $response->getStatusCode());
            $this->assertStringContainsString('NightBite', $response->getContent());
            $this->assertStringNotContainsString('Stack trace', $response->getContent());
        }
    }

    public function test_shared_layout_has_skip_link_main_landmark_and_focusable_navigation_controls(): void
    {
        $html = view('layouts.app')->render();

        $this->assertStringContainsString('Skip to main content', $html);
        $this->assertStringContainsString('id="main-content"', $html);
        $this->assertStringContainsString('aria-label="Toggle navigation"', $html);
    }
}
