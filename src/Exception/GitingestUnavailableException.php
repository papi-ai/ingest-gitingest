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

namespace PapiAI\Ingest\Gitingest\Exception;

use PapiAI\Ingest\Exception\IngestException;

/**
 * Thrown when the gitingest binary is not on PATH.
 *
 * Raised before any work starts, and carries the install command, because "command not found"
 * three layers down a clone is a poor way to learn about a missing dependency.
 */
class GitingestUnavailableException extends IngestException
{
    /**
     * @param string $binary The binary that could not be found
     */
    public static function notOnPath(string $binary): self
    {
        return new self(sprintf(
            'The "%s" binary was not found. Install it with "pipx install gitingest" or "pip install gitingest", or pass the path to the binary as the first constructor argument.',
            $binary,
        ));
    }
}
