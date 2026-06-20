<?php

namespace Tests\Feature\Presentations;

use App\Services\Presentations\PresentationGenerationService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit-level QA untuk normalisasi konfigurasi & outline (#225): memastikan
 * batas slide/bullet/title dan fallback ditegakkan di sisi Laravel sebelum
 * dikirim ke renderer.
 */
class PresentationGenerationServiceTest extends TestCase
{
    private function service(): PresentationGenerationService
    {
        return new PresentationGenerationService;
    }

    #[Test]
    public function it_falls_back_to_default_title_when_blank(): void
    {
        $config = $this->service()->normalizeConfiguration(['title' => '   ']);

        $this->assertSame('Presentasi ISTA AI', $config['title']);
    }

    #[Test]
    public function it_clamps_slide_count_within_bounds(): void
    {
        $service = $this->service();

        $this->assertSame(
            PresentationGenerationService::SLIDE_COUNT_MIN,
            $service->normalizeConfiguration(['title' => 'X', 'slide_count' => 1])['slide_count']
        );
        $this->assertSame(
            PresentationGenerationService::SLIDE_COUNT_MAX,
            $service->normalizeConfiguration(['title' => 'X', 'slide_count' => 999])['slide_count']
        );
        $this->assertSame(
            10,
            $service->normalizeConfiguration(['title' => 'X', 'slide_count' => 10])['slide_count']
        );
    }

    #[Test]
    public function it_falls_back_to_known_template_for_unknown_value(): void
    {
        $config = $this->service()->normalizeConfiguration(['title' => 'X', 'visual_template' => 'alien_theme']);

        $this->assertArrayHasKey($config['visual_template'], PresentationGenerationService::VISUAL_TEMPLATES);
    }

    #[Test]
    public function it_normalizes_template_key_spacing_and_case(): void
    {
        $config = $this->service()->normalizeConfiguration(['title' => 'X', 'visual_template' => 'Modern Minimal']);

        $this->assertSame('modern_minimal', $config['visual_template']);
    }

    #[Test]
    public function it_builds_skeleton_outline_when_no_instruction(): void
    {
        $config = $this->service()->normalizeConfiguration(['title' => 'X']);
        $outline = $this->service()->buildOutline($config);

        $this->assertNotEmpty($outline);
        $this->assertSame('Agenda', $outline[0]['title']);
        $this->assertSame('Kesimpulan & Tindak Lanjut', $outline[array_key_last($outline)]['title']);
    }

    #[Test]
    public function it_truncates_instruction_points_and_caps_count(): void
    {
        $longLine = str_repeat('kata ', 100); // ~500 chars
        $manyLines = collect(range(1, 30))->map(fn ($i) => "Poin nomor {$i}")->implode("\n");

        $config = $this->service()->normalizeConfiguration([
            'title' => 'X',
            'additional_instruction' => $longLine."\n".$manyLines,
        ]);
        $outline = $this->service()->buildOutline($config);

        foreach ($outline as $slide) {
            foreach ($slide['bullets'] as $bullet) {
                $this->assertLessThanOrEqual(220, mb_strlen($bullet));
            }
        }

        // Outline tetap diapit Agenda + Kesimpulan, jumlah slide wajar.
        $this->assertSame('Agenda', $outline[0]['title']);
        $this->assertLessThanOrEqual(8, count($outline));
    }

    #[Test]
    public function it_limits_additional_instruction_length(): void
    {
        $config = $this->service()->normalizeConfiguration([
            'title' => 'X',
            'additional_instruction' => str_repeat('a', 5000),
        ]);

        $this->assertLessThanOrEqual(2000, mb_strlen($config['additional_instruction']));
    }

    #[Test]
    public function it_defaults_asset_mode_to_local_assets_only(): void
    {
        $config = $this->service()->normalizeConfiguration(['title' => 'X']);

        $this->assertSame(
            PresentationGenerationService::ASSET_MODE_LOCAL,
            $config['asset_mode']
        );
        $this->assertSame('local_assets_only', $config['asset_mode']);
    }

    #[Test]
    public function it_keeps_licensed_web_assets_mode_when_explicitly_chosen(): void
    {
        $config = $this->service()->normalizeConfiguration([
            'title' => 'X',
            'asset_mode' => 'Licensed-Web-Assets',
        ]);

        $this->assertSame(
            PresentationGenerationService::ASSET_MODE_LICENSED,
            $config['asset_mode']
        );
    }

    #[Test]
    public function it_falls_back_to_local_asset_mode_for_unknown_value(): void
    {
        $service = $this->service();

        $this->assertSame(
            PresentationGenerationService::ASSET_MODE_LOCAL,
            $service->normalizeAssetMode('google_images')
        );
        $this->assertSame(
            PresentationGenerationService::ASSET_MODE_LOCAL,
            $service->normalizeAssetMode(null)
        );
        $this->assertSame(
            PresentationGenerationService::ASSET_MODE_LOCAL,
            $service->normalizeConfiguration(['title' => 'X', 'asset_mode' => 'random'])['asset_mode']
        );
    }
}
