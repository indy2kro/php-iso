<?php

declare(strict_types=1);

namespace PhpIso\Util;

use PhpIso\Exception;
use PhpIso\IsoFile;

/**
 * Read only stream wrapper over the byte ranges of a file entry: the data is read from the image on demand
 *
 * Use EntryStream::open(); the wrapper is registered under a private scheme the first time.
 * A range with a negative offset is a sparse one and reads as zeros.
 */
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the stream_* names are fixed by PHP's wrapper protocol
final class EntryStream
{
    public const string SCHEME = 'phpiso-entry';

    /**
     * @var resource|null set by PHP, holds the options given to open()
     */
    public $context;

    private ?IsoFile $isoFile = null;

    /**
     * @var list<array{int, int, int}> stream position, image offset (negative: sparse) and length of every range
     */
    private array $ranges = [];

    private int $size = 0;

    private int $position = 0;

    /**
     * Open a stream over byte ranges of an image
     *
     * @param list<array{int, int}> $ranges (absolute offset in the image, length) pairs, a negative offset is sparse
     *
     * @return resource
     *
     * @throws Exception
     */
    public static function open(IsoFile $isoFile, array $ranges): mixed
    {
        // fail early, like a copy would, instead of in the middle of a read
        foreach ($ranges as [$offset, $length]) {
            if ($length < 0 || ($offset >= 0 && $offset + $length > $isoFile->getSize())) {
                throw new Exception('Requested range is outside of the ISO file');
            }
        }

        if (! in_array(self::SCHEME, stream_get_wrappers(), true) && ! stream_wrapper_register(self::SCHEME, self::class)) {
            throw new Exception('Failed to register the entry stream wrapper');
        }

        $context = stream_context_create([self::SCHEME => ['file' => $isoFile, 'ranges' => $ranges]]);
        $stream = @fopen(self::SCHEME . '://entry', 'rb', false, $context);

        if ($stream === false) {
            throw new Exception('Failed to open the entry stream');
        }

        return $stream;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $options = is_resource($this->context) ? stream_context_get_options($this->context) : [];
        $settings = $options[self::SCHEME] ?? null;
        $isoFile = is_array($settings) ? ($settings['file'] ?? null) : null;
        $ranges = is_array($settings) ? ($settings['ranges'] ?? null) : null;

        if (! $isoFile instanceof IsoFile || ! is_array($ranges)) {
            return false;
        }

        $this->isoFile = $isoFile;
        $position = 0;
        foreach ($ranges as $range) {
            if (! is_array($range) || ! is_int($range[0] ?? null) || ! is_int($range[1] ?? null)) {
                return false;
            }

            [$offset, $length] = $range;
            if ($length <= 0) {
                continue;
            }

            $this->ranges[] = [$position, $offset, $length];
            $position += $length;
        }

        $this->size = $position;

        return true;
    }

    public function stream_read(int $count): string|false
    {
        $result = '';
        while (strlen($result) < $count && $this->position < $this->size) {
            $chunk = $this->readChunk($count - strlen($result));
            if ($chunk === false) {
                // keep what was read, report the failure on the next call
                return $result === '' ? false : $result;
            }

            if ($chunk === '') {
                break;
            }

            $result .= $chunk;
        }

        return $result;
    }

    /**
     * Read from the range holding the current position, never past its end
     */
    private function readChunk(int $count): string|false
    {
        if ($this->position >= $this->size || ! $this->isoFile instanceof IsoFile) {
            return '';
        }

        foreach ($this->ranges as [$start, $offset, $length]) {
            if ($this->position >= $start + $length) {
                continue;
            }

            $inside = $this->position - $start;
            $wanted = min($count, $length - $inside);

            if ($offset < 0) {
                $data = str_repeat("\0", $wanted);
            } else {
                if ($offset + $inside + $wanted > $this->isoFile->getSize() || $this->isoFile->seek($offset + $inside, SEEK_SET) === -1) {
                    return false;
                }

                $data = $this->isoFile->read($wanted);
                if ($data === false || $data === '') {
                    return false;
                }
            }

            $this->position += strlen($data);

            return $data;
        }

        return '';
    }

    public function stream_eof(): bool
    {
        return $this->position >= $this->size;
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        $target = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->position + $offset,
            SEEK_END => $this->size + $offset,
            default => -1,
        };

        if ($target < 0) {
            return false;
        }

        $this->position = $target;

        return true;
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return ['size' => $this->size, 'mode' => 0100444];
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }
}
