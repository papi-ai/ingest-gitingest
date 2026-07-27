<?php

/*
 * This file is part of PapiAI,
 * A simple but powerful PHP library for building AI agents.
 *
 * (c) Marcello Duarte <marcello.duarte@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

use PapiAI\Ingest\Gitingest\DigestParser;
use PapiAI\Ingest\RepositoryDigest;

describe('DigestParser', function () {
    beforeEach(function () {
        $this->parser = new DigestParser();
        $this->recorded = file_get_contents(__DIR__ . '/../fixtures/gitingest-digest.txt');
    });

    it('pulls the tree out from under the header', function () {
        $tree = $this->parser->parse($this->recorded)['tree'];

        expect($tree)->toContain('└── example/');
        expect($tree)->toContain('├── Order.php');
        expect($tree)->not->toContain(RepositoryDigest::SEPARATOR);
        expect($tree)->not->toContain('FILE:');
    });

    it('pulls every file block out, keyed by path', function () {
        $files = $this->parser->parse($this->recorded)['files'];

        expect(array_keys($files))->toBe(['README.md', 'src/Order.php', 'src/Money.php']);
        expect($files['README.md'])->toBe("# Example\n\nA fixture repository.");
        expect($files['src/Money.php'])->toContain('final class Money');
    });

    it('keeps file contents intact, separators and all', function () {
        $files = $this->parser->parse($this->recorded)['files'];

        expect($files['src/Order.php'])->toStartWith('<?php');
        expect($files['src/Order.php'])->toContain('public function total(Money $money): Money');
    });

    it('handles Windows line endings', function () {
        $files = $this->parser->parse(str_replace("\n", "\r\n", $this->recorded))['files'];

        expect(array_keys($files))->toBe(['README.md', 'src/Order.php', 'src/Money.php']);
    });

    it('returns nothing rather than guessing when the text is not a digest', function () {
        $parsed = $this->parser->parse('this is not a gitingest digest at all');

        expect($parsed['files'])->toBe([]);
        expect($parsed['tree'])->toBe('');
    });

    it('handles a digest with a tree and no files', function () {
        $parsed = $this->parser->parse("Directory structure:\n└── empty/\n");

        expect($parsed['tree'])->toBe('└── empty/');
        expect($parsed['files'])->toBe([]);
    });
});
