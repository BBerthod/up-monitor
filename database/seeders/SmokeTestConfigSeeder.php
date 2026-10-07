<?php

namespace Database\Seeders;

use App\Models\SmokeTestConfig;
use Illuminate\Database\Seeder;

class SmokeTestConfigSeeder extends Seeder
{
    public function run(): void
    {
        $configs = [
            [
                'application_id' => 'fr-example-shop',
                'application_name' => 'example-shop FR',
                'tests' => [
                    ['url' => 'https://fr.example-shop.com/', 'expected_status' => 200, 'expected_keyword' => 'example-shop', 'timeout_ms' => 5000],
                    ['url' => 'https://fr.example-shop.com/api/health', 'expected_status' => 200, 'expected_json_path' => '$.status', 'expected_value' => 'healthy', 'timeout_ms' => 3000],
                    ['url' => 'https://fr.example-shop.com/sitemap.xml', 'expected_status' => 200, 'timeout_ms' => 5000],
                ],
                'is_active' => true,
            ],
            [
                'application_id' => 'us-example-shop',
                'application_name' => 'example-shop US',
                'tests' => [
                    ['url' => 'https://us.example-shop.com/', 'expected_status' => 200, 'expected_keyword' => 'example-shop', 'timeout_ms' => 5000],
                    ['url' => 'https://us.example-shop.com/api/health', 'expected_status' => 200, 'timeout_ms' => 3000],
                ],
                'is_active' => true,
            ],
            [
                'application_id' => 'fr-example',
                'application_name' => 'Example FR',
                'tests' => [
                    ['url' => 'https://example.com/', 'expected_status' => 200, 'expected_keyword' => 'Example', 'timeout_ms' => 5000],
                    ['url' => 'https://example.com/sitemap.xml', 'expected_status' => 200, 'timeout_ms' => 5000],
                ],
                'is_active' => true,
            ],
            [
                'application_id' => 'example-camping',
                'application_name' => 'Jura Camping',
                'tests' => [
                    ['url' => 'https://example-camping.fr/', 'expected_status' => 200, 'expected_keyword' => 'camping', 'timeout_ms' => 5000],
                    ['url' => 'https://example-camping.fr/sitemap.xml', 'expected_status' => 200, 'timeout_ms' => 5000],
                ],
                'is_active' => true,
            ],
            [
                'application_id' => 'example-humor',
                'application_name' => 'Blague Humour',
                'tests' => [
                    ['url' => 'https://example-humor.com/', 'expected_status' => 200, 'timeout_ms' => 5000],
                    ['url' => 'https://example-humor.com/sitemap.xml', 'expected_status' => 200, 'timeout_ms' => 5000],
                ],
                'is_active' => true,
            ],
        ];

        foreach ($configs as $config) {
            SmokeTestConfig::updateOrCreate(
                ['application_id' => $config['application_id']],
                $config
            );
        }
    }
}
