<?php

declare(strict_types=1);

namespace BIomie\SystemHealth\Checks;

use BIomie\Bootstrap;
use BIomie\SystemHealth\CheckInterface;
use BIomie\SystemHealth\SystemHealthCheck;

/**
 * Verifica se o pacote Mobile Phase 1+2 está completamente instalado.
 *
 * Status:
 *  critical — CSS ou JS mobile ausente, ou viewport-fit=cover não configurado
 *  warning  — itens não críticos ausentes (partials, smoke check, features opcionais)
 *  ok       — todos os itens presentes
 */
final class MobileExperienceHealthCheck implements CheckInterface
{
    public function id(): string    { return 'mobile_experience'; }
    public function label(): string { return 'Experiência Mobile Chrome'; }

    public function run(): SystemHealthCheck
    {
        $start   = microtime(true);
        $root    = Bootstrap::rootPath('') . DIRECTORY_SEPARATOR;

        $critical = [];
        $warnings = [];
        $metrics  = [];

        // ── Arquivos críticos ─────────────────────────────────────────────
        $criticalFiles = [
            'assets/css/mag-mobile.css' => 'CSS mobile',
            'assets/js/mobile-shell.js' => 'JS mobile shell',
        ];
        foreach ($criticalFiles as $rel => $desc) {
            $exists = is_file($root . $rel);
            $metrics[$rel] = $exists ? 'present' : 'missing';
            if (!$exists) {
                $critical[] = $desc . ' ausente (' . $rel . ')';
            }
        }

        // ── Arquivos warning ──────────────────────────────────────────────
        $warnFiles = [
            'views/partials/mobile_topbar.php'    => 'Partial mobile topbar',
            'views/partials/mobile_bottom_nav.php' => 'Partial bottom nav',
            'tools/dev/mobile_smoke_check.php'    => 'Smoke check CLI',
        ];
        foreach ($warnFiles as $rel => $desc) {
            $exists = is_file($root . $rel);
            $metrics[$rel] = $exists ? 'present' : 'missing';
            if (!$exists) {
                $warnings[] = $desc . ' ausente (' . $rel . ')';
            }
        }

        // ── PageAssetRegistry: mag-mobile.css em BASE_CSS ─────────────────
        $registryFile = $root . 'app/PageAssetRegistry.php';
        $registryCode = is_file($registryFile) ? (string) file_get_contents($registryFile) : '';
        $hasMobileCss = str_contains($registryCode, "'assets/css/mag-mobile.css'");
        $hasMobileJs  = str_contains($registryCode, "'assets/js/mobile-shell.js'");
        $metrics['registry_mobile_css'] = $hasMobileCss ? 'registered' : 'missing';
        $metrics['registry_mobile_js']  = $hasMobileJs  ? 'registered' : 'missing';
        if (!$hasMobileCss) {
            $critical[] = 'mag-mobile.css não registrado em PageAssetRegistry BASE_CSS';
        }
        if (!$hasMobileJs) {
            $critical[] = 'mobile-shell.js não registrado em PageAssetRegistry BASE_JS';
        }

        // ── Layout inclui partials mobile ────────────────────────────────
        $layoutFile = $root . 'views/layouts/app.php';
        $layoutCode = is_file($layoutFile) ? (string) file_get_contents($layoutFile) : '';
        $hasTopbar    = str_contains($layoutCode, 'mobile_topbar.php');
        $hasBottomNav = str_contains($layoutCode, 'mobile_bottom_nav.php');
        $metrics['layout_has_topbar']     = $hasTopbar    ? 'yes' : 'no';
        $metrics['layout_has_bottom_nav'] = $hasBottomNav ? 'yes' : 'no';
        if (!$hasTopbar || !$hasBottomNav) {
            $warnings[] = 'layout app.php não inclui todos os partials mobile';
        }

        // ── viewport-fit=cover no PageRenderer ───────────────────────────
        $rendererFile = $root . 'app/PageRenderer.php';
        $rendererCode = is_file($rendererFile) ? (string) file_get_contents($rendererFile) : '';
        $hasViewport  = str_contains($rendererCode, 'viewport-fit=cover');
        $metrics['renderer_viewport_fit'] = $hasViewport ? 'ok' : 'missing';
        if (!$hasViewport) {
            $critical[] = 'PageRenderer não injeta viewport-fit=cover';
        }

        // ── CSS: safe-area-inset ──────────────────────────────────────────
        $cssFile    = $root . 'assets/css/mag-mobile.css';
        $cssContent = is_file($cssFile) ? (string) file_get_contents($cssFile) : '';
        $hasSafeArea    = str_contains($cssContent, 'safe-area-inset');
        $hasBottomSheet = str_contains($cssContent, 'translateY(100%)');
        $hasMediaMobile = str_contains($cssContent, 'max-width: 767px');
        $metrics['css_safe_area']    = $hasSafeArea    ? 'yes' : 'no';
        $metrics['css_bottom_sheet'] = $hasBottomSheet ? 'yes' : 'no';
        $metrics['css_media_mobile'] = $hasMediaMobile ? 'yes' : 'no';
        if (!$hasSafeArea || !$hasBottomSheet) {
            $warnings[] = 'mag-mobile.css incompleto (safe-area ou bottom-sheet ausente)';
        }

        // ── JS: namespace guard ───────────────────────────────────────────
        $jsFile    = $root . 'assets/js/mobile-shell.js';
        $jsContent = is_file($jsFile) ? (string) file_get_contents($jsFile) : '';
        $hasNamespace    = str_contains($jsContent, 'BIomieMobileShell');
        $hasMutObserver  = str_contains($jsContent, 'MutationObserver');
        $hasDataPage     = str_contains($jsContent, 'data-page') || str_contains($jsContent, 'dataset.page');
        $metrics['js_namespace']       = $hasNamespace   ? 'yes' : 'no';
        $metrics['js_mutation_observer'] = $hasMutObserver ? 'yes' : 'no';
        $metrics['js_data_page']       = $hasDataPage    ? 'yes' : 'no';
        if (!$hasNamespace) {
            $warnings[] = 'mobile-shell.js não expõe namespace BIomieMobileShell';
        }

        $ms = (int) round((microtime(true) - $start) * 1000);

        if ($critical !== []) {
            return SystemHealthCheck::critical(
                $this->id(),
                $this->label(),
                'Componentes críticos mobile ausentes: ' . implode('; ', $critical),
                $metrics,
                array_merge(
                    array_map(fn (string $c): string => 'CRITICAL: ' . $c, $critical),
                    array_map(fn (string $w): string => 'WARNING: '  . $w, $warnings),
                ),
                $ms,
            );
        }

        if ($warnings !== []) {
            return SystemHealthCheck::warning(
                $this->id(),
                $this->label(),
                'Experiência mobile parcialmente instalada. ' . count($warnings) . ' item(s) ausente(s).',
                $metrics,
                $warnings,
                $ms,
            );
        }

        return SystemHealthCheck::ok(
            $this->id(),
            $this->label(),
            'Pacote mobile completo: CSS, JS, partials, viewport e registry OK.',
            $metrics,
            $ms,
        );
    }
}
