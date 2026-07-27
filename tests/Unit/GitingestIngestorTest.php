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

use PapiAI\Ingest\Contracts\RepositoryIngestorInterface;
use PapiAI\Ingest\Contracts\SymbolExtractorInterface;
use PapiAI\Ingest\Depth;
use PapiAI\Ingest\Exception\IngestException;
use PapiAI\Ingest\Exception\UnsupportedDepthException;
use PapiAI\Ingest\Gitingest\Exception\GitingestUnavailableException;
use PapiAI\Ingest\Gitingest\GitingestIngestor;
use PapiAI\Ingest\IngestedFile;
use PapiAI\Ingest\IngestRequest;

/**
 * Replays a recorded gitingest digest, so CI never needs the binary installed.
 */
class RecordedGitingestIngestor extends GitingestIngestor
{
    /** @var list<array<int, string>> */
    public array $calls = [];

    public string $stdout = '';

    public string $stderr = '';

    public int $status = 0;

    public bool $available = true;

    public function isAvailable(): bool
    {
        return $this->available;
    }

    protected function execute(array $arguments): array
    {
        $this->calls[] = $arguments;

        return ['stdout' => $this->stdout, 'stderr' => $this->stderr, 'status' => $this->status];
    }

    /** @return list<string> The arguments of the ingest call */
    public function ingestCall(): array
    {
        return $this->calls[0] ?? [];
    }
}

class StubPhpExtractor implements SymbolExtractorInterface
{
    public function extract(string $path, string $source): ?string
    {
        return 'SYMBOLS(' . $path . ')';
    }

    public function supports(string $path): bool
    {
        return str_ends_with($path, '.php');
    }
}

describe('GitingestIngestor', function () {
    beforeEach(function () {
        $this->ingestor = new RecordedGitingestIngestor();
        $this->ingestor->stdout = file_get_contents(__DIR__ . '/../fixtures/gitingest-digest.txt');
        $this->remote = 'https://github.com/papi-ai/example';
    });

    it('implements the ingestor contract', function () {
        expect($this->ingestor)->toBeInstanceOf(RepositoryIngestorInterface::class);
    });

    describe('routing', function () {
        it('supports remote sources, which is the reason it exists', function () {
            expect($this->ingestor->supports(new IngestRequest($this->remote)))->toBeTrue();
            expect($this->ingestor->supports(new IngestRequest('git@github.com:papi-ai/example.git')))->toBeTrue();
        });

        it('supports local directories too, though NativeIngestor is the better choice there', function () {
            expect($this->ingestor->supports(new IngestRequest(__DIR__)))->toBeTrue();
        });

        it('does not claim a local path that is not there', function () {
            expect($this->ingestor->supports(new IngestRequest('/no/such/place')))->toBeFalse();
        });
    });

    describe('when the binary is missing', function () {
        it('fails with the install command before doing any work', function () {
            $this->ingestor->available = false;

            expect(fn () => $this->ingestor->ingest(new IngestRequest($this->remote)))
                ->toThrow(GitingestUnavailableException::class, 'pipx install gitingest');

            // Nothing was spawned at all: a missing dependency costs no work.
            expect($this->ingestor->calls)->toBe([]);
        });
    });

    describe('digest', function () {
        it('parses the files back out at Full depth', function () {
            $digest = $this->ingestor->ingest(new IngestRequest($this->remote, Depth::Full));

            expect(array_map(fn (IngestedFile $f) => $f->path, $digest->files))
                ->toBe(['README.md', 'src/Money.php', 'src/Order.php']);
            expect($digest->files[2]->content)->toContain('class Order implements Payable');
        });

        it('carries the tree', function () {
            expect($this->ingestor->ingest(new IngestRequest($this->remote))->tree)->toContain('└── example/');
        });

        it('drops contents at Tree depth', function () {
            $digest = $this->ingestor->ingest(new IngestRequest($this->remote, Depth::Tree));

            foreach ($digest->files as $file) {
                expect($file->content)->toBeNull();
                expect($file->symbols)->toBeNull();
            }
        });

        it('renders through the same budget renderer as every other ingestor', function () {
            $prompt = $this->ingestor->ingest(new IngestRequest($this->remote, Depth::Full, tokenBudget: 30))->toPrompt();

            expect($prompt)->toContain('Directory structure:');
            expect($prompt)->toContain('omitted to fit a 30 token budget');
        });

        it('fingerprints the output, so an unchanged remote gives an unchanged answer', function () {
            $first = $this->ingestor->ingest(new IngestRequest($this->remote));
            $second = $this->ingestor->ingest(new IngestRequest($this->remote));

            expect($first->fingerprint)->toBe($second->fingerprint);

            $this->ingestor->stdout .= "\n";

            expect($this->ingestor->ingest(new IngestRequest($this->remote))->fingerprint)->not->toBe($first->fingerprint);
        });

        it('honours a prioritiser', function () {
            $digest = $this->ingestor->ingest(new IngestRequest(
                $this->remote,
                Depth::Full,
                prioritiser: fn (IngestedFile $file): float => $file->path === 'src/Order.php' ? 5.0 : 0.0,
            ));

            expect($digest->files[0]->path)->toBe('src/Order.php');
        });
    });

    describe('Api depth', function () {
        it('throws without an extractor rather than returning a tree', function () {
            expect(fn () => $this->ingestor->ingest(new IngestRequest($this->remote, Depth::Api)))
                ->toThrow(UnsupportedDepthException::class, 'papi-ai/ingest-php-symbols');
        });

        it('extracts symbols from the returned contents when given one', function () {
            $ingestor = new RecordedGitingestIngestor('gitingest', new StubPhpExtractor());
            $ingestor->stdout = $this->ingestor->stdout;
            $digest = $ingestor->ingest(new IngestRequest($this->remote, Depth::Api));
            $order = current(array_filter($digest->files, fn (IngestedFile $f) => $f->path === 'src/Order.php'));
            $readme = current(array_filter($digest->files, fn (IngestedFile $f) => $f->path === 'README.md'));

            expect($order->symbols)->toBe('SYMBOLS(src/Order.php)');
            expect($readme->symbols)->toBeNull();
        });
    });

    describe('argument mapping', function () {
        it('always asks for stdout and passes the size cap', function () {
            $this->ingestor->ingest(new IngestRequest($this->remote, maxFileSize: 1234));

            expect($this->ingestor->ingestCall())
                ->toBe([$this->remote, '--output', '-', '--max-size', '1234']);
        });

        it('passes include and exclude patterns through', function () {
            $this->ingestor->ingest(new IngestRequest($this->remote, include: ['*.php'], exclude: ['tests/*']));
            $call = $this->ingestor->ingestCall();

            expect($call)->toContain('--include-pattern', '*.php');
            expect($call)->toContain('--exclude-pattern', 'tests/*');
        });

        it('only asks for ignored files when told not to respect gitignore', function () {
            $this->ingestor->ingest(new IngestRequest($this->remote));
            expect($this->ingestor->ingestCall())->not->toContain('--include-gitignored');

            $this->ingestor->calls = [];
            $this->ingestor->ingest(new IngestRequest($this->remote, respectGitignore: false));
            expect($this->ingestor->ingestCall())->toContain('--include-gitignored');
        });

        it('passes a token for private repositories when configured with one', function () {
            $ingestor = new RecordedGitingestIngestor('gitingest', null, 'ghp_secret');
            $ingestor->stdout = $this->ingestor->stdout;
            $ingestor->ingest(new IngestRequest($this->remote));

            expect($ingestor->ingestCall())->toContain('--token', 'ghp_secret');
        });
    });

    describe('when gitingest fails', function () {
        it('reports what it said on stderr', function () {
            $this->ingestor->status = 1;
            $this->ingestor->stderr = 'Repository not found';

            expect(fn () => $this->ingestor->ingest(new IngestRequest($this->remote)))
                ->toThrow(IngestException::class, 'Repository not found');
        });

        it('says so plainly when it failed silently', function () {
            $this->ingestor->status = 1;

            expect(fn () => $this->ingestor->ingest(new IngestRequest($this->remote)))
                ->toThrow(IngestException::class, 'no error output');
        });
    });
});

describe('GitingestIngestor against the real binary', function () {
    it('reports availability honestly', function () {
        $ingestor = new GitingestIngestor();

        if (!$ingestor->isAvailable()) {
            expect(fn () => $ingestor->ingest(new IngestRequest('https://github.com/papi-ai/papi-core')))
                ->toThrow(GitingestUnavailableException::class);

            return;
        }

        expect($ingestor->isAvailable())->toBeTrue();
    });
});
