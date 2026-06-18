<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    /**
     * Verifica se todos os headers OWASP críticos estão presentes.
     */
    public function test_security_headers_are_present(): void
    {
        $response = $this->get('/admin');

        $requiredHeaders = [
            'Content-Security-Policy',
            'Strict-Transport-Security',
            'X-Frame-Options',
            'X-Content-Type-Options',
            'Referrer-Policy',
            'Permissions-Policy',
        ];

        foreach ($requiredHeaders as $header) {
            $response->assertHeader($header);
        }
    }

    /**
     * Verifica valores específicos dos headers.
     */
    public function test_security_header_values(): void
    {
        $response = $this->get('/admin');

        // X-Frame-Options deve ser DENY
        $response->assertHeader('X-Frame-Options', 'DENY');

        // X-Content-Type-Options deve ser nosniff
        $response->assertHeader('X-Content-Type-Options', 'nosniff');

        // Referrer-Policy deve ser strict-origin-when-cross-origin
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        // HSTS deve conter max-age e includeSubDomains
        $hsts = $response->headers->get('Strict-Transport-Security');
        $this->assertStringContainsString('max-age=31536000', $hsts);
        $this->assertStringContainsString('includeSubDomains', $hsts);

        // CSP deve bloquear frame-ancestors
        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
    }

    /**
     * Verifica que CSP permite recursos self.
     */
    public function test_csp_allows_self_resources(): void
    {
        $response = $this->get('/admin');

        $csp = $response->headers->get('Content-Security-Policy');

        // Deve permitir scripts, styles e imagens da própria origem
        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringContainsString("style-src 'self'", $csp);
        $this->assertStringContainsString("img-src 'self'", $csp);
    }

    /**
     * Verifica que Permissions-Policy bloqueia APIs perigosas.
     */
    public function test_permissions_policy_blocks_dangerous_apis(): void
    {
        $response = $this->get('/admin');

        $policy = $response->headers->get('Permissions-Policy');

        // Deve bloquear câmara, microfone e geolocalização
        $this->assertStringContainsString('camera=()', $policy);
        $this->assertStringContainsString('microphone=()', $policy);
        $this->assertStringContainsString('geolocation=()', $policy);
    }
}
