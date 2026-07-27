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

namespace PapiAI\Ingest\Gitingest;

use PapiAI\Core\Contracts\TokenEstimatorInterface;
use PapiAI\Core\HeuristicTokenEstimator;
use PapiAI\Ingest\Contracts\RepositoryIngestorInterface;
use PapiAI\Ingest\Contracts\SymbolExtractorInterface;
use PapiAI\Ingest\Depth;
use PapiAI\Ingest\Exception\IngestException;
use PapiAI\Ingest\Exception\UnsupportedDepthException;
use PapiAI\Ingest\Gitingest\Exception\GitingestUnavailableException;
use PapiAI\Ingest\IngestedFile;
use PapiAI\Ingest\IngestRequest;
use PapiAI\Ingest\RepositoryDigest;

/**
 * Ingests a repository through the gitingest CLI.
 *
 * Its earned value is **remote sources**: hand it a GitHub URL and it clones, filters and returns
 * a digest, which a pure-PHP walker cannot do without reimplementing a clone. It also gives parity
 * with what people already paste into models by hand.
 *
 * For local paths, prefer `NativeIngestor`. gitingest handles them perfectly well, but shelling
 * out to Python to walk a directory that PHP can walk itself buys nothing and costs a dependency
 * on the common path.
 *
 * gitingest returns a finished prompt; this adapter parses it back into a digest so callers can
 * re-render at any budget, exactly like every other ingestor.
 */
class GitingestIngestor implements RepositoryIngestorInterface
{
    public function __construct(
        private readonly string $binary = 'gitingest',
        private readonly ?SymbolExtractorInterface $extractor = null,
        private readonly ?string $token = null,
        private readonly TokenEstimatorInterface $estimator = new HeuristicTokenEstimator(),
    ) {
    }

    public function supports(IngestRequest $request): bool
    {
        return $request->isRemote() || is_dir($request->source);
    }

    /**
     * Whether the gitingest binary can be found.
     *
     * Resolves PATH directly rather than spawning the binary to ask. Spawning something that is
     * not there is how you get a process warning surfacing from a method whose entire job is to
     * answer calmly, and it means a missing dependency really does cost no work at all.
     */
    public function isAvailable(): bool
    {
        if (str_contains($this->binary, DIRECTORY_SEPARATOR)) {
            return is_file($this->binary) && is_executable($this->binary);
        }

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            $candidate = $directory . DIRECTORY_SEPARATOR . $this->binary;

            if (is_file($candidate) && is_executable($candidate)) {
                return true;
            }
        }

        return false;
    }

    public function ingest(IngestRequest $request): RepositoryDigest
    {
        if (!$this->isAvailable()) {
            throw GitingestUnavailableException::notOnPath($this->binary);
        }

        if ($request->depth === Depth::Api && $this->extractor === null) {
            throw UnsupportedDepthException::needsExtractor($request->depth, 'papi-ai/ingest-php-symbols');
        }

        $result = $this->execute($this->arguments($request));

        if ($result['status'] !== 0) {
            throw new IngestException(sprintf(
                'gitingest failed for "%s": %s',
                $request->source,
                trim($result['stderr']) === '' ? 'no error output.' : trim($result['stderr']),
            ));
        }

        return $this->toDigest($result['stdout'], $request);
    }

    /**
     * Run the binary and capture what it said.
     *
     * Isolated so tests can drive the adapter from a recorded digest, and so CI never depends on
     * gitingest being installed.
     *
     * @param list<string> $arguments Arguments to pass, without the binary itself
     *
     * @return array{stdout: string, stderr: string, status: int}
     */
    protected function execute(array $arguments): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open(array_merge([$this->binary], $arguments), $descriptors, $pipes);

        if (!is_resource($process)) {
            return ['stdout' => '', 'stderr' => '', 'status' => 127];
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'stdout' => $stdout === false ? '' : $stdout,
            'stderr' => $stderr === false ? '' : $stderr,
            'status' => proc_close($process),
        ];
    }

    /**
     * Translate the request into gitingest's own flags.
     *
     * @return list<string>
     */
    private function arguments(IngestRequest $request): array
    {
        $arguments = [$request->source, '--output', '-', '--max-size', (string) $request->maxFileSize];

        foreach ($request->include as $pattern) {
            $arguments[] = '--include-pattern';
            $arguments[] = $pattern;
        }

        foreach ($request->exclude as $pattern) {
            $arguments[] = '--exclude-pattern';
            $arguments[] = $pattern;
        }

        if (!$request->respectGitignore) {
            $arguments[] = '--include-gitignored';
        }

        if ($this->token !== null) {
            $arguments[] = '--token';
            $arguments[] = $this->token;
        }

        return $arguments;
    }

    /**
     * Turn gitingest's finished prompt back into a digest.
     */
    private function toDigest(string $output, IngestRequest $request): RepositoryDigest
    {
        $parsed = (new DigestParser())->parse($output);
        $files = [];

        foreach ($parsed['files'] as $path => $contents) {
            $files[] = $this->toFile($path, $contents, $request->depth);
        }

        usort($files, static fn (IngestedFile $a, IngestedFile $b): int => strcmp($a->path, $b->path));
        $files = $this->prioritise($files, $request);
        $bodyTokens = array_sum(array_map(static fn (IngestedFile $file): int => $file->tokens, $files));

        return new RepositoryDigest(
            // Remote state has no local mtimes or blob hashes to hash, so the digest text itself is
            // the fingerprint: it changes exactly when what gitingest returned changes.
            hash('sha256', $output),
            $parsed['tree'],
            $files,
            [],
            $this->estimator->estimateTokens($parsed['tree']) + (int) $bodyTokens,
            $request->depth,
            $request->tokenBudget,
            $this->estimator,
        );
    }

    private function toFile(string $path, string $contents, Depth $depth): IngestedFile
    {
        $symbols = null;
        $body = null;

        if ($depth === Depth::Full) {
            $body = $contents;
        } elseif ($depth === Depth::Api && $this->extractor !== null && $this->extractor->supports($path)) {
            $symbols = $this->extractor->extract($path, $contents);
            $body = $symbols;
        }

        return new IngestedFile(
            $path,
            strlen($contents),
            0,
            $body === null ? 0 : $this->estimator->estimateTokens($body),
            $depth === Depth::Full ? $contents : null,
            $symbols,
        );
    }

    /**
     * @param list<IngestedFile> $files Files in path order
     *
     * @return list<IngestedFile>
     */
    private function prioritise(array $files, IngestRequest $request): array
    {
        $prioritiser = $request->prioritiser;

        if ($prioritiser === null) {
            return $files;
        }

        /** @var list<array{score: float, file: IngestedFile}> $scored */
        $scored = array_map(
            static fn (IngestedFile $file): array => ['score' => $prioritiser($file), 'file' => $file],
            $files,
        );

        usort(
            $scored,
            static fn (array $a, array $b): int => ($b['score'] <=> $a['score']) ?: strcmp($a['file']->path, $b['file']->path),
        );

        return array_map(static fn (array $entry): IngestedFile => $entry['file'], $scored);
    }
}
