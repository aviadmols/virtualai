<?php

namespace App\Domain\Scan\Fetch;

/**
 * BoundedSink — a streaming write sink with a hard byte ceiling.
 *
 * The response body is fed in CHUNK BY CHUNK; the moment the running total would
 * cross MAX_BYTES the sink signals "stop" so the transport aborts the transfer
 * MID-STREAM. The whole body is therefore never buffered — an oversize, slow-loris
 * or decompression-bomb response cannot OOM the worker. The accepted bytes (capped
 * to exactly the ceiling) are available via body().
 *
 * Used as the curl CURLOPT_WRITEFUNCTION callback in production and exercised
 * directly by the streaming-cap test with a fake chunk stream.
 */
final class BoundedSink
{
    private string $buffer = '';

    private bool $exceeded = false;

    public function __construct(
        private readonly int $maxBytes,
    ) {}

    /**
     * Accept a chunk. Returns the number of bytes "consumed" — for the curl
     * write-callback contract, returning a value != strlen($chunk) aborts the
     * transfer. We accept up to the remaining budget then signal abort.
     */
    public function write(string $chunk): int
    {
        $remaining = $this->maxBytes - strlen($this->buffer);

        if ($remaining <= 0) {
            $this->exceeded = true;

            return 0; // already full → abort.
        }

        if (strlen($chunk) > $remaining) {
            $this->buffer .= substr($chunk, 0, $remaining);
            $this->exceeded = true;

            return 0; // crossing the ceiling → abort mid-stream.
        }

        $this->buffer .= $chunk;

        return strlen($chunk);
    }

    /** True when the cap fired and the transfer was (or should be) aborted. */
    public function exceeded(): bool
    {
        return $this->exceeded;
    }

    /** The accepted body, never longer than the ceiling. */
    public function body(): string
    {
        return $this->buffer;
    }
}
