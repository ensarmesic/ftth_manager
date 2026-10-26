<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class SourceEncodingTest extends TestCase
{
    /**
     * A UTF-8 BOM at the start of a Blade view is printed into the page and
     * pushes the whole layout down (it happened on 10 pages at once).
     */
    public function test_source_files_have_no_utf8_bom(): void
    {
        $root = dirname(__DIR__, 2);
        $withBom = [];
        foreach (['app', 'config', 'resources', 'routes', 'lang', 'public/js'] as $directory) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$directory", RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (preg_match('/\.(php|js|css|json)$/', $file->getFilename()) && file_get_contents($file->getPathname(), length: 3) === "\xEF\xBB\xBF") {
                    $withBom[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }

        $this->assertSame([], $withBom, 'Ukloni UTF-8 BOM iz: '.implode(', ', $withBom));
    }
}
