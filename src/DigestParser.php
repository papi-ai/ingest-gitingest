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

use PapiAI\Ingest\RepositoryDigest;

/**
 * Reads gitingest's text output back into structured parts.
 *
 * gitingest hands back a finished prompt, but the contract here is a digest a caller can
 * re-render at any budget. So the text is parsed back apart: the tree block, then one entry per
 * file, keyed on the framing gitingest itself uses (a 48-character rule, then `FILE: <path>`).
 * Those constants live in `papi-ai/ingest` precisely so both ends agree on them.
 */
final class DigestParser
{
    /**
     * Split a gitingest digest into its tree and its files.
     *
     * @param string $digest The raw text gitingest produced
     *
     * @return array{tree: string, files: array<string, string>} The tree block, and path to contents
     */
    public function parse(string $digest): array
    {
        $normalised = str_replace("\r\n", "\n", $digest);

        return [
            'tree' => $this->tree($normalised),
            'files' => $this->files($normalised),
        ];
    }

    /**
     * Pull out the directory listing that follows gitingest's tree header.
     */
    private function tree(string $digest): string
    {
        $start = strpos($digest, RepositoryDigest::TREE_HEADER);

        if ($start === false) {
            return '';
        }

        $body = substr($digest, $start + strlen(RepositoryDigest::TREE_HEADER));
        $end = strpos($body, RepositoryDigest::SEPARATOR);

        return trim($end === false ? $body : substr($body, 0, $end), "\n");
    }

    /**
     * Pull out every file block, keyed by path.
     *
     * @return array<string, string> Path to contents
     */
    private function files(string $digest): array
    {
        $rule = preg_quote(RepositoryDigest::SEPARATOR, '/');
        $pattern = sprintf('/^%s\nFILE: (?P<path>.+)\n%s\n/m', $rule, $rule);

        $parts = preg_split($pattern, $digest, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false || count($parts) < 3) {
            return [];
        }

        $files = [];

        // Element 0 is everything before the first block (summary and tree), then the split
        // alternates captured path, following contents.
        for ($i = 1; $i < count($parts) - 1; $i += 2) {
            $files[trim($parts[$i])] = rtrim($parts[$i + 1], "\n");
        }

        return $files;
    }
}
