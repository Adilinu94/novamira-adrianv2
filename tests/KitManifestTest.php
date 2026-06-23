<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Tests;

use Novamira\AdrianV2\Abilities\ElementorTemplates\Kit_Manifest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Novamira\AdrianV2\Abilities\ElementorTemplates\Kit_Manifest
 */
final class KitManifestTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    private function enhanced_manifest( array $overrides = [] ): string
    {
        $base = [
            'kit_name'    => 'Choco Deluxe',
            'kit_version' => '1.0',
            'templates'   => [
                [
                    'id'          => 'homepage',
                    'title'       => 'Homepage',
                    'post_type'   => 'page',
                    'type'        => 'page',
                    'content'     => [ [ 'elType' => 'container' ] ],
                    'conditions'  => [],
                    'page_settings' => [],
                    'seo'         => [
                        'yoast_title'       => 'Choco Deluxe',
                        'yoast_description' => 'Test',
                    ],
                ],
                [
                    'id'          => 'header',
                    'title'       => 'Header',
                    'post_type'   => 'elementor-hf',
                    'type'        => 'section-header',
                    'content'     => [],
                    'conditions'  => [ 'include/general' ],
                    'page_settings' => [],
                    'seo'         => null,
                ],
            ],
            'plugins' => [
                'required' => [
                    [ 'slug' => 'header-footer-elementor', 'source' => 'wordpress', 'min_version' => '2.0', 'name' => 'Ultimate Addons' ],
                ],
                'optional' => [],
                'premium'  => [
                    [ 'slug' => 'elementor-pro', 'name' => 'Elementor Pro', 'url' => 'https://elementor.com/pro' ],
                ],
            ],
            'settings' => [
                'site_name'           => 'Choco Deluxe',
                'site_tagline'        => 'Pralinen',
                'front_page'          => 'homepage',
                'permalink_structure' => '/%postname%/',
            ],
            'menus' => [
                [
                    'name'     => 'Hauptmenü',
                    'location' => 'menu-1',
                    'items'    => [
                        [ 'title' => 'Start', 'target' => 'page:homepage' ],
                    ],
                ],
            ],
            'design_system' => [
                'globals' => [
                    'system_colors' => [
                        [ '_id' => 'primary', 'title' => 'Primary', 'color' => '#6B3FA0' ],
                    ],
                ],
            ],
            'media' => [
                'source_base_url' => 'https://demo.com/wp-content/uploads/',
                'files' => [
                    'logo.png' => [
                        'original_url' => 'https://demo.com/wp-content/uploads/logo.png',
                        'local_path'   => '2025/01/logo.png',
                    ],
                ],
            ],
            'fonts' => [
                'google_fonts_to_host' => [ 'Inter' ],
                'strategy'             => 'local',
            ],
            'theme' => [
                'stylesheet' => 'hello-elementor',
                'mods'       => [ 'custom_logo' => 'logo.png' ],
            ],
            'cleanup' => [
                'delete_default_posts' => true,
            ],
        ];

        return json_encode( array_merge( $base, $overrides ) );
    }

    /** Real Elementor kit manifest (Sunexia format). */
    private function elementor_manifest(): string
    {
        return json_encode( [
            'manifest_version' => '1.0.23',
            'title'            => 'Sunexia',
            'kit_version'      => '1.0.0',
            'templates'        => [
                [
                    'name'     => 'Global Kit Styles',
                    'source'   => 'templates/global.json',
                    'type'     => 'section',
                    'category' => 'page',
                    'metadata' => [ 'template_type' => 'global-styles' ],
                    'elementor_pro_required' => false,
                ],
                [
                    'name'     => 'Home',
                    'source'   => 'templates/home.json',
                    'type'     => 'page',
                    'category' => 'page',
                    'metadata' => [ 'template_type' => 'single-page' ],
                    'elementor_pro_required' => false,
                ],
                [
                    'name'     => 'Header',
                    'source'   => 'templates/header.json',
                    'type'     => 'section',
                    'category' => 'section',
                    'metadata' => [ 'template_type' => 'section-header' ],
                    'elementor_pro_required' => false,
                ],
                [
                    'name'     => 'Footer',
                    'source'   => 'templates/footer.json',
                    'type'     => 'section',
                    'category' => 'section',
                    'metadata' => [ 'template_type' => 'section-footer' ],
                    'elementor_pro_required' => false,
                ],
                [
                    'name'     => 'Form Contact',
                    'source'   => 'templates/form-contact.json',
                    'type'     => 'section',
                    'category' => 'section',
                    'metadata' => [ 'template_type' => 'section-other' ],
                    'elementor_pro_required' => false,
                ],
            ],
            'required_plugins' => [
                [ 'name' => 'Elementor',         'version' => '3.25.9', 'file' => 'elementor/elementor.php',                    'author' => 'Elementor.com' ],
                [ 'name' => 'ElementsKit Lite',  'version' => '3.4.0',  'file' => 'elementskit-lite/elementskit-lite.php',       'author' => 'Wpmet' ],
                [ 'name' => 'Ultimate Addons',   'version' => '2.1.0',  'file' => 'header-footer-elementor/header-footer-elementor.php', 'author' => 'Brainstorm' ],
            ],
            'images' => [
                [
                    'filename'      => '01_hero_sunexia.jpg',
                    'thumbnail_url' => 'https://kitpro.site/sunexia/wp-content/uploads/sites/490/2025/12/01_hero_sunexia-800x533.jpg',
                    'filesize'      => 177810,
                ],
            ],
        ] );
    }

    // -------------------------------------------------------------------------
    // Format detection
    // -------------------------------------------------------------------------

    public function test_enhanced_format_detected(): void
    {
        $m = new Kit_Manifest( $this->enhanced_manifest() );
        $this->assertSame( Kit_Manifest::FORMAT_ENHANCED, $m->get_format() );
    }

    public function test_elementor_format_detected(): void
    {
        $m = new Kit_Manifest( $this->elementor_manifest() );
        $this->assertSame( Kit_Manifest::FORMAT_ELEMENTOR, $m->get_format() );
    }

    public function test_invalid_json_throws(): void
    {
        $this->expectException( \InvalidArgumentException::class );
        new Kit_Manifest( 'not json at all' );
    }

    // -------------------------------------------------------------------------
    // Identity
    // -------------------------------------------------------------------------

    public function test_kit_name_enhanced(): void
    {
        $m = new Kit_Manifest( $this->enhanced_manifest() );
        $this->assertSame( 'Choco Deluxe', $m->get_kit_name() );
    }

    public function test_kit_name_elementor(): void
    {
        $m = new Kit_Manifest( $this->elementor_manifest() );
        $this->assertSame( 'Sunexia', $m->get_kit_name() );
    }

    // -------------------------------------------------------------------------
    // Templates — enhanced format
    // -------------------------------------------------------------------------

    public function test_enhanced_templates_count(): void
    {
        $m   = new Kit_Manifest( $this->enhanced_manifest() );
        $tpl = $m->get_templates();
        $this->assertCount( 2, $tpl );
    }

    public function test_enhanced_page_template_fields(): void
    {
        $m   = new Kit_Manifest( $this->enhanced_manifest() );
        $tpl = $m->get_templates()[0];

        $this->assertSame( 'homepage', $tpl['id'] );
        $this->assertSame( 'Homepage', $tpl['title'] );
        $this->assertSame( 'page', $tpl['type'] );
        $this->assertSame( 'page', $tpl['post_type'] );
        $this->assertSame( [ [ 'elType' => 'container' ] ], $tpl['content'] );
        $this->assertSame( 'Choco Deluxe', $tpl['seo']['yoast_title'] );
    }

    public function test_enhanced_header_post_type(): void
    {
        $m   = new Kit_Manifest( $this->enhanced_manifest() );
        $tpl = $m->get_templates()[1];

        $this->assertSame( 'section-header', $tpl['type'] );
        $this->assertSame( 'elementor-hf', $tpl['post_type'] );
        $this->assertSame( [ 'include/general' ], $tpl['conditions'] );
    }

    // -------------------------------------------------------------------------
    // Templates — Elementor format
    // -------------------------------------------------------------------------

    public function test_elementor_templates_type_mapping(): void
    {
        $contents = [
            'templates/global.json'  => [ 'content' => [], 'page_settings' => [ 'system_colors' => [] ] ],
            'templates/home.json'    => [ 'content' => [ [ 'elType' => 'container' ] ] ],
            'templates/header.json'  => [ 'content' => [] ],
            'templates/footer.json'  => [ 'content' => [] ],
            'templates/form-contact.json' => [ 'content' => [] ],
        ];

        $m   = new Kit_Manifest( $this->elementor_manifest(), $contents );
        $tpl = $m->get_templates();

        $this->assertSame( 'global-styles',   $tpl[0]['type'] );
        $this->assertSame( 'elementor_library', $tpl[0]['post_type'] );

        $this->assertSame( 'page',   $tpl[1]['type'] );
        $this->assertSame( 'page',   $tpl[1]['post_type'] );

        $this->assertSame( 'section-header', $tpl[2]['type'] );
        $this->assertSame( 'elementor-hf',   $tpl[2]['post_type'] );

        $this->assertSame( 'section-footer', $tpl[3]['type'] );
        $this->assertSame( 'elementor-hf',   $tpl[3]['post_type'] );

        $this->assertSame( 'section',             $tpl[4]['type'] );
        $this->assertSame( 'elementor_library',   $tpl[4]['post_type'] );
    }

    public function test_elementor_template_content_loaded(): void
    {
        $content  = [ [ 'elType' => 'container', 'id' => 'abc' ] ];
        $contents = [ 'templates/home.json' => [ 'content' => $content ] ];

        $m   = new Kit_Manifest( $this->elementor_manifest(), $contents );
        $tpl = $m->get_templates()[1]; // Home

        $this->assertSame( $content, $tpl['content'] );
    }

    public function test_elementor_template_slug_from_name(): void
    {
        $m   = new Kit_Manifest( $this->elementor_manifest() );
        $tpl = $m->get_templates();

        $this->assertSame( 'global-kit-styles', $tpl[0]['id'] );
        $this->assertSame( 'home', $tpl[1]['id'] );
        $this->assertSame( 'form-contact', $tpl[4]['id'] );
    }

    public function test_elementor_global_styles_page_settings(): void
    {
        $page_settings = [ 'system_colors' => [ [ '_id' => 'primary', 'color' => '#0246D0' ] ] ];
        $contents = [
            'templates/global.json' => [ 'content' => [], 'page_settings' => $page_settings ],
        ];

        $m   = new Kit_Manifest( $this->elementor_manifest(), $contents );
        $tpl = $m->get_templates()[0];

        $this->assertSame( $page_settings, $tpl['page_settings'] );
    }

    // -------------------------------------------------------------------------
    // Plugins
    // -------------------------------------------------------------------------

    public function test_enhanced_required_plugins(): void
    {
        $m       = new Kit_Manifest( $this->enhanced_manifest() );
        $plugins = $m->get_required_plugins();

        $this->assertCount( 1, $plugins );
        $this->assertSame( 'header-footer-elementor', $plugins[0]['slug'] );
        $this->assertSame( '2.0', $plugins[0]['min_version'] );
        $this->assertSame( 'wordpress', $plugins[0]['source'] );
    }

    public function test_enhanced_premium_plugins(): void
    {
        $m       = new Kit_Manifest( $this->enhanced_manifest() );
        $premium = $m->get_premium_plugins();

        $this->assertCount( 1, $premium );
        $this->assertSame( 'elementor-pro', $premium[0]['slug'] );
    }

    public function test_elementor_plugins_slug_from_file(): void
    {
        $m       = new Kit_Manifest( $this->elementor_manifest() );
        $plugins = $m->get_required_plugins();

        // Elementor itself must be excluded
        $slugs = array_column( $plugins, 'slug' );
        $this->assertNotContains( 'elementor', $slugs );

        $this->assertContains( 'elementskit-lite', $slugs );
        $this->assertContains( 'header-footer-elementor', $slugs );
        $this->assertCount( 2, $plugins );
    }

    public function test_elementor_no_premium_plugins(): void
    {
        $m = new Kit_Manifest( $this->elementor_manifest() );
        $this->assertSame( [], $m->get_premium_plugins() );
    }

    // -------------------------------------------------------------------------
    // Design system
    // -------------------------------------------------------------------------

    public function test_enhanced_design_system(): void
    {
        $m  = new Kit_Manifest( $this->enhanced_manifest() );
        $ds = $m->get_design_system();

        $this->assertIsArray( $ds );
        $this->assertSame( '#6B3FA0', $ds['system_colors'][0]['color'] );
    }

    public function test_elementor_design_system_from_global_template(): void
    {
        $page_settings = [ 'system_colors' => [ [ '_id' => 'primary', 'color' => '#0246D0' ] ] ];
        $contents      = [ 'templates/global.json' => [ 'content' => [], 'page_settings' => $page_settings ] ];

        $m  = new Kit_Manifest( $this->elementor_manifest(), $contents );
        $ds = $m->get_design_system();

        $this->assertSame( $page_settings, $ds );
    }

    // -------------------------------------------------------------------------
    // Settings, menus, media, fonts, cleanup
    // -------------------------------------------------------------------------

    public function test_get_settings(): void
    {
        $m = new Kit_Manifest( $this->enhanced_manifest() );
        $s = $m->get_settings();

        $this->assertSame( 'Choco Deluxe', $s['site_name'] );
        $this->assertSame( 'homepage', $s['front_page'] );
    }

    public function test_get_menus(): void
    {
        $m     = new Kit_Manifest( $this->enhanced_manifest() );
        $menus = $m->get_menus();

        $this->assertCount( 1, $menus );
        $this->assertSame( 'Hauptmenü', $menus[0]['name'] );
    }

    public function test_enhanced_media(): void
    {
        $m     = new Kit_Manifest( $this->enhanced_manifest() );
        $media = $m->get_media();

        $this->assertCount( 1, $media );
        $this->assertSame( 'logo.png', $media[0]['ref'] );
        $this->assertSame( 'https://demo.com/wp-content/uploads/logo.png', $media[0]['url'] );
    }

    public function test_elementor_media_from_images(): void
    {
        $m     = new Kit_Manifest( $this->elementor_manifest() );
        $media = $m->get_media();

        $this->assertCount( 1, $media );
        $this->assertSame( '01_hero_sunexia.jpg', $media[0]['filename'] );
        $this->assertStringStartsWith( 'https://kitpro.site/', $media[0]['url'] );
    }

    public function test_get_fonts(): void
    {
        $m = new Kit_Manifest( $this->enhanced_manifest() );
        $f = $m->get_fonts();

        $this->assertSame( [ 'Inter' ], $f['google_fonts_to_host'] );
        $this->assertSame( 'local', $f['strategy'] );
    }

    public function test_get_cleanup_config(): void
    {
        $m = new Kit_Manifest( $this->enhanced_manifest() );
        $c = $m->get_cleanup_config();

        $this->assertTrue( $c['delete_default_posts'] );
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    public function test_valid_manifest_no_errors(): void
    {
        $m = new Kit_Manifest( $this->enhanced_manifest() );
        $this->assertSame( [], $m->validate() );
    }

    public function test_missing_templates_gives_error(): void
    {
        $json = json_encode( [ 'kit_name' => 'Test', 'templates' => [] ] );
        $m    = new Kit_Manifest( $json );
        $this->assertNotEmpty( $m->validate() );
    }

    public function test_template_missing_title_and_id(): void
    {
        $json = json_encode( [
            'kit_name'  => 'Test',
            'templates' => [ [ 'type' => 'page', 'content' => [] ] ],
        ] );
        $m      = new Kit_Manifest( $json );
        $errors = $m->validate();
        $this->assertNotEmpty( $errors );
        $this->assertStringContainsString( 'templates[0]', $errors[0] );
    }
}
