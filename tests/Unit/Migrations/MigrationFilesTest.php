<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Migrations;

use Beacon\Migrations\MigrationFiles;
use Beacon\Migrations\MigrationSourceKind;
use Beacon\Migrations\RegisteredDirectory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MigrationFilesTest extends TestCase
{
    #[Test]
    public function filenames_are_listed_without_executing_php(): void
    {
        $base = $this->directory('beacon-migrations-base');
        $migrations = $base . '/database/migrations';
        mkdir($migrations, 0o777, true);
        $marker = $migrations . '/ran.txt';
        file_put_contents($migrations . '/2026_01_01_000000_beacon_side_effect.php', <<<'PHP'
            <?php

            file_put_contents(__DIR__ . '/ran.txt', 'migration-php-was-read');
            PHP);
        $outside = $this->directory('beacon-migrations-outside');
        file_put_contents($outside . '/2026_01_02_000000_secret_outside.php', '<?php');

        try {
            $result = (new MigrationFiles())->discover($base, [
                new RegisteredDirectory($migrations, MigrationSourceKind::Application),
                new RegisteredDirectory($base . '/database/empty', MigrationSourceKind::Registered),
                new RegisteredDirectory($outside, MigrationSourceKind::Registered),
            ]);

            $this->assertFileDoesNotExist($marker);
            $this->assertFalse(class_exists('BeaconMigrationSideEffectProbe', false));
            $this->assertCount(1, $result->files);
            $this->assertSame('2026_01_01_000000_beacon_side_effect', $result->files[0]->name);
            $this->assertSame('database/migrations/2026_01_01_000000_beacon_side_effect.php', $result->files[0]->relativePath);
            $this->assertNotNull($result->files[0]->filenameAt);
            $this->assertTrue($result->unreadable);
            $this->assertTrue($result->outside);
            $this->assertNotContains('secret_outside', array_map(static fn ($file) => $file->name, $result->files));
            $this->assertStringNotContainsString('migration-php-was-read', (string) json_encode($result));
        } finally {
            $this->remove($base);
            $this->remove($outside);
        }
    }

    private function directory(string $name): string
    {
        $path = sys_get_temp_dir() . '/' . $name . '-' . bin2hex(random_bytes(4));
        mkdir($path, 0o777, true);

        return $path;
    }

    private function remove(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);
        $items = $items === false ? [] : $items;

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path . '/' . $item;

            if (is_dir($child)) {
                $this->remove($child);
            } else {
                unlink($child);
            }
        }

        rmdir($path);
    }
}
