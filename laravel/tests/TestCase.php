<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function validMemoDocxBytes(): string
    {
        $content = file_get_contents(base_path('tests/Fixtures/edited-memo.docx'));

        if ($content === false) {
            throw new \RuntimeException('Fixture DOCX memo tidak dapat dibaca.');
        }

        return $content;
    }

}
