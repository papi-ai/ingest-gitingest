# papi-ai/ingest-gitingest

Remote repository ingestion for [PapiAI](https://papi-ai.org), via the
[gitingest](https://github.com/coderamp-labs/gitingest) CLI.

## Install

```bash
composer require papi-ai/ingest-gitingest
pipx install gitingest        # or: pip install gitingest
```

The `gitingest` binary is a runtime requirement, not a Composer dependency.

## Usage

```php
use PapiAI\Ingest\Depth;
use PapiAI\Ingest\Gitingest\GitingestIngestor;
use PapiAI\Ingest\IngestRequest;

$ingestor = new GitingestIngestor();

$digest = $ingestor->ingest(new IngestRequest(
    source: 'https://github.com/papi-ai/papi-core',
    depth: Depth::Full,
    tokenBudget: 8000,
));

echo $digest->toPrompt();
```

Private repositories take a token, which gitingest also reads from `GITHUB_TOKEN`:

```php
new GitingestIngestor(token: $_ENV['GITHUB_TOKEN']);
```

## When to use this, and when not to

**Use it for remote sources.** Hand it a GitHub URL and it clones, filters and returns a digest,
which a pure-PHP walker cannot do without reimplementing a clone. It also gives parity with what
people already paste into models by hand.

**For local paths, use `NativeIngestor` instead.** gitingest handles them perfectly well, but
shelling out to Python to walk a directory PHP can walk itself buys nothing and puts a dependency
on the common path. `supports()` returns true for both, so ask `NativeIngestor` first when routing.

## Behaviour

gitingest returns a finished prompt. This adapter parses it back into a `RepositoryDigest`, so
callers can re-render at any budget through the same renderer every other ingestor uses. Parsing
keys on the framing gitingest itself emits (a 48-character rule, then `FILE: <path>`), and those
constants live in `papi-ai/ingest` so both ends agree.

A missing binary throws before any work happens, with the install command in the message. The
check resolves `PATH` rather than spawning anything, so asking costs nothing.

Remote state has no local mtimes or git blobs to hash, so the fingerprint is a hash of the digest
gitingest returned: it changes exactly when what came back changes.

### Depth

`Depth::Full` and `Depth::Tree` work out of the box. `Depth::Api` needs a symbol extractor, the
same as everywhere else:

```php
use PapiAI\Ingest\PhpSymbols\PhpSymbolExtractor;

new GitingestIngestor(extractor: new PhpSymbolExtractor());
```

Asking for `Api` without one throws rather than quietly returning a tree.

## License

MIT, Marcello Duarte
