<?php

declare(strict_types=1);

namespace app\Application\File;

use app\Api\Support\ApiProblem;

/** Restricted ISO BMFF input proof for generic-branded, single-track fragmented AAC. */
final class FragmentedM4aAudioInspector
{
    private const MAX_BOXES = 100000;
    private const MAX_METADATA_BYTES = 8 * 1024 * 1024;
    private const MAX_FRAGMENT_BYTES = 1024 * 1024;
    private const MAX_SAMPLES = 2000000;
    private const MAX_FRAGMENTS = 8192;

    /** File bytes remain untouched; audio decode validity belongs to the configured driver. */
    public function assertAudioOnly(string $path, int $expectedBytes): void
    {
        $stream = @fopen($path, 'rb');
        if ($stream === false) self::invalid();
        try {
            $stat = fstat($stream);
            if (!is_array($stat) || !is_int($stat['size'] ?? null)
                || $stat['size'] !== $expectedBytes || $expectedBytes < 32
                || $expectedBytes > 2147483647) self::invalid();
            $boxes = 0;
            $metadataBytes = 0;
            $position = 0;
            $track = null;
            $pending = null;
            $sequence = 0;
            $decodeEnd = 0;
            $fragments = 0;
            $samples = 0;
            while ($position < $expectedBytes) {
                $box = $this->fileBox($stream, $position, $expectedBytes, $boxes);
                $type = $box['type'];
                if ($pending !== null && $type !== 'mdat') self::invalid();
                if ($type === 'mdat') {
                    if ($pending === null || $pending['start'] !== $box['body']
                        || $pending['end'] !== $box['end']) self::invalid();
                    $pending = null;
                } else {
                    $length = $box['end'] - $box['body'];
                    if ($length > self::MAX_FRAGMENT_BYTES
                        || $metadataBytes > self::MAX_METADATA_BYTES - $length) self::invalid();
                    $metadataBytes += $length;
                    $body = $this->read($stream, $box['body'], $length);
                    switch ($type) {
                        case 'ftyp':
                            if ($position !== 0) self::invalid();
                            $this->brand($body);
                            break;
                        case 'moov':
                            if ($track !== null || $fragments !== 0 || $position === 0) self::invalid();
                            $track = $this->movie($body, $boxes);
                            break;
                        case 'moof':
                            if ($track === null || ++$fragments > self::MAX_FRAGMENTS) self::invalid();
                            $pending = $this->fragment($body, $position, $expectedBytes, $track, $boxes);
                            // Encoder counters can repeat at segment boundaries;
                            // byte ownership and decode ranges still prove media order.
                            if ($pending['sequence'] < $sequence || $pending['decode_start'] < $decodeEnd
                                || $samples > self::MAX_SAMPLES - $pending['samples']) self::invalid();
                            $sequence = $pending['sequence'];
                            $decodeEnd = $pending['decode_end'];
                            $samples += $pending['samples'];
                            break;
                        case 'styp':
                            if ($track === null) self::invalid();
                            $this->brand($body);
                            break;
                        case 'free':
                        case 'skip':
                            if ($position === 0) self::invalid();
                            break;
                        default:
                            // Unknown indexes, encrypted tracks, auxiliary media and
                            // alternate movie structures are not an audio proof.
                            self::invalid();
                            break;
                    }
                }
                $position = $box['end'];
            }
            $after = fstat($stream);
            if ($track === null || $pending !== null || $fragments < 1 || $samples < 1
                || !is_array($after) || $after['size'] !== $expectedBytes) self::invalid();
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream @return array{type:string,body:int,end:int} */
    private function fileBox($stream, int $offset, int $end, int &$boxes): array
    {
        if (++$boxes > self::MAX_BOXES || $end - $offset < 8) self::invalid();
        $header = $this->read($stream, $offset, 8);
        $size = self::u32($header, 0);
        $headerBytes = 8;
        if ($size === 1) {
            if ($end - $offset < 16) self::invalid();
            $size = self::u64($this->read($stream, $offset + 8, 8), 0);
            $headerBytes = 16;
        }
        // A zero-size box obscures following fragments; this limited proof
        // requires every box to have an explicit bounded size.
        if ($size < $headerBytes || $size > $end - $offset) self::invalid();
        return ['type' => substr($header, 4, 4), 'body' => $offset + $headerBytes, 'end' => $offset + $size];
    }

    /** @param resource $stream */
    private function read($stream, int $offset, int $length): string
    {
        if ($length < 0 || $length > self::MAX_FRAGMENT_BYTES || fseek($stream, $offset) !== 0) self::invalid();
        $value = '';
        while (strlen($value) < $length) {
            $part = fread($stream, $length - strlen($value));
            if (!is_string($part) || $part === '') self::invalid();
            $value .= $part;
        }
        return $value;
    }

    /** @return list<array{type:string,body:string}> */
    private function children(string $body, int &$boxes): array
    {
        $offset = 0;
        $end = strlen($body);
        $result = [];
        while ($offset < $end) {
            if (++$boxes > self::MAX_BOXES || $end - $offset < 8) self::invalid();
            $size = self::u32($body, $offset);
            $header = 8;
            if ($size === 1) {
                if ($end - $offset < 16) self::invalid();
                $size = self::u64($body, $offset + 8);
                $header = 16;
            }
            if ($size < $header || $size > $end - $offset) self::invalid();
            $result[] = ['type' => substr($body, $offset + 4, 4),
                'body' => substr($body, $offset + $header, $size - $header)];
            $offset += $size;
        }
        return $result;
    }

    /** @param list<string> $allowed @return array<string,string> */
    private function unique(string $body, array $allowed, int &$boxes): array
    {
        $result = [];
        foreach ($this->children($body, $boxes) as $child) {
            if (!in_array($child['type'], $allowed, true) || isset($result[$child['type']])) self::invalid();
            $result[$child['type']] = $child['body'];
        }
        return $result;
    }

    private function brand(string $body): void
    {
        if (strlen($body) < 8 || strlen($body) > 256 || (strlen($body) - 8) % 4 !== 0) self::invalid();
        $brands = [substr($body, 0, 4)];
        for ($offset = 8; $offset < strlen($body); $offset += 4) $brands[] = substr($body, $offset, 4);
        if (array_intersect($brands, ['isom', 'iso5', 'iso6', 'mp41', 'mp42', 'M4A ', 'dash', 'msdh', 'msix']) === []) self::invalid();
    }

    /** @return array{id:int,duration:int,size:int} */
    private function movie(string $body, int &$boxes): array
    {
        $movie = $this->unique($body, ['mvhd', 'trak', 'mvex'], $boxes);
        if (!isset($movie['mvhd'], $movie['trak'], $movie['mvex'])) self::invalid();
        $movieVersion = $this->full($movie['mvhd'], [0, 1]);
        if (strlen($movie['mvhd']) !== ($movieVersion === 0 ? 100 : 112)
            || self::u32($movie['mvhd'], $movieVersion === 0 ? 12 : 20) < 1) self::invalid();
        $track = $this->unique($movie['trak'], ['tkhd', 'mdia', 'edts'], $boxes);
        if (!isset($track['tkhd'], $track['mdia']) || isset($track['edts'])) self::invalid();
        $version = $this->full($track['tkhd'], [0, 1], false);
        $trackId = self::u32($track['tkhd'], $version === 0 ? 12 : 20);
        if ($trackId < 1 || strlen($track['tkhd']) !== ($version === 0 ? 84 : 96)
            || (self::u32($track['tkhd'], 0) & 0xfffff0) !== 0) self::invalid();
        $media = $this->unique($track['mdia'], ['mdhd', 'hdlr', 'minf'], $boxes);
        if (!isset($media['mdhd'], $media['hdlr'], $media['minf'])) self::invalid();
        $mediaVersion = $this->full($media['mdhd'], [0, 1]);
        if (strlen($media['mdhd']) !== ($mediaVersion === 0 ? 24 : 36)
            || self::u32($media['mdhd'], $mediaVersion === 0 ? 12 : 20) < 1) self::invalid();
        $this->full($media['hdlr'], [0]);
        if (strlen($media['hdlr']) < 24 || self::u32($media['hdlr'], 4) !== 0
            || substr($media['hdlr'], 8, 4) !== 'soun'
            || substr($media['hdlr'], 12, 12) !== str_repeat("\0", 12)) self::invalid();
        $info = $this->unique($media['minf'], ['smhd', 'dinf', 'stbl'], $boxes);
        if (!isset($info['smhd'], $info['dinf'], $info['stbl'])) self::invalid();
        $this->full($info['smhd'], [0]);
        if (strlen($info['smhd']) !== 8) self::invalid();
        $this->dataReference($info['dinf'], $boxes);
        $this->sampleTable($info['stbl'], $boxes);
        $extends = $this->unique($movie['mvex'], ['mehd', 'trex'], $boxes);
        if (!isset($extends['trex'])) self::invalid();
        if (isset($extends['mehd'])) {
            $v = $this->full($extends['mehd'], [0, 1]);
            if (strlen($extends['mehd']) !== ($v === 0 ? 8 : 12)) self::invalid();
        }
        $this->full($extends['trex'], [0]);
        if (strlen($extends['trex']) !== 24 || self::u32($extends['trex'], 4) !== $trackId
            || self::u32($extends['trex'], 8) !== 1) self::invalid();
        return ['id' => $trackId, 'duration' => self::u32($extends['trex'], 12), 'size' => self::u32($extends['trex'], 16)];
    }

    private function dataReference(string $body, int &$boxes): void
    {
        $info = $this->unique($body, ['dref'], $boxes);
        if (!isset($info['dref'])) self::invalid();
        $this->full($info['dref'], [0]);
        if (self::u32($info['dref'], 4) !== 1) self::invalid();
        $refs = $this->children(substr($info['dref'], 8), $boxes);
        if (count($refs) !== 1 || $refs[0]['type'] !== 'url ' || $refs[0]['body'] !== "\0\0\0\1") self::invalid();
    }

    private function sampleTable(string $body, int &$boxes): void
    {
        $table = $this->unique($body, ['stsd', 'stsz', 'stsc', 'stts', 'stco', 'co64'], $boxes);
        if (!isset($table['stsd'], $table['stsz'], $table['stsc'], $table['stts'])
            || (isset($table['stco']) === isset($table['co64']))) self::invalid();
        foreach (['stsc', 'stts', isset($table['stco']) ? 'stco' : 'co64'] as $type) {
            $this->full($table[$type], [0]);
            if (strlen($table[$type]) !== 8 || self::u32($table[$type], 4) !== 0) self::invalid();
        }
        $this->full($table['stsz'], [0]);
        if (strlen($table['stsz']) !== 12 || self::u32($table['stsz'], 4) !== 0
            || self::u32($table['stsz'], 8) !== 0) self::invalid();
        $this->full($table['stsd'], [0]);
        if (self::u32($table['stsd'], 4) !== 1) self::invalid();
        $entries = $this->children(substr($table['stsd'], 8), $boxes);
        if (count($entries) !== 1 || $entries[0]['type'] !== 'mp4a') self::invalid();
        $audio = $entries[0]['body'];
        if (strlen($audio) < 28 || substr($audio, 0, 6) !== str_repeat("\0", 6)
            || self::u16($audio, 6) !== 1 || substr($audio, 8, 8) !== str_repeat("\0", 8)
            || self::u16($audio, 16) < 1 || self::u16($audio, 16) > 8
            || self::u16($audio, 18) !== 16 || self::u32($audio, 24) < 65536) self::invalid();
        $codec = $this->unique(substr($audio, 28), ['esds', 'btrt'], $boxes);
        if (!isset($codec['esds'])) self::invalid();
        if (isset($codec['btrt']) && strlen($codec['btrt']) !== 12) self::invalid();
        $this->aacDescription($codec['esds']);
    }

    private function aacDescription(string $body): void
    {
        $this->full($body, [0]);
        $offset = 4;
        $es = $this->descriptor($body, $offset);
        if ($es['tag'] !== 3 || $offset !== strlen($body) || strlen($es['body']) < 3) self::invalid();
        // External dependency, URL and OCR stream references are unsupported.
        if ((ord($es['body'][2]) & 0xe0) !== 0) self::invalid();
        $cursor = 3;
        $decoder = $this->descriptor($es['body'], $cursor);
        if ($decoder['tag'] !== 4 || strlen($decoder['body']) < 15
            || ord($decoder['body'][0]) !== 0x40
            || (ord($decoder['body'][1]) >> 2) !== 5
            || (ord($decoder['body'][1]) & 1) !== 1) self::invalid();
        $configOffset = 13;
        $config = $this->descriptor($decoder['body'], $configOffset);
        if ($config['tag'] !== 5 || strlen($config['body']) < 2
            || $configOffset !== strlen($decoder['body'])
            || !in_array(ord($config['body'][0]) >> 3, [2, 5, 29], true)) self::invalid();
        $frequencyIndex = ((ord($config['body'][0]) & 7) << 1) | (ord($config['body'][1]) >> 7);
        if ($frequencyIndex === 13 || $frequencyIndex === 14
            || ($frequencyIndex === 15 && strlen($config['body']) < 5)) self::invalid();
        $sl = $this->descriptor($es['body'], $cursor);
        if ($sl['tag'] !== 6 || $sl['body'] !== "\2" || $cursor !== strlen($es['body'])) self::invalid();
    }

    /** @return array{tag:int,body:string} */
    private function descriptor(string $body, int &$offset): array
    {
        if ($offset >= strlen($body)) self::invalid();
        $tag = ord($body[$offset++]);
        $size = 0;
        $complete = false;
        for ($index = 0; $index < 4; $index++) {
            if ($offset >= strlen($body)) self::invalid();
            $byte = ord($body[$offset++]);
            $size = ($size << 7) | ($byte & 0x7f);
            if (($byte & 0x80) === 0) { $complete = true; break; }
        }
        if (!$complete || $size < 1 || $size > strlen($body) - $offset) self::invalid();
        $value = substr($body, $offset, $size);
        $offset += $size;
        return ['tag' => $tag, 'body' => $value];
    }

    /** @param array{id:int,duration:int,size:int} $track
     * @return array{start:int,end:int,sequence:int,decode_start:int,decode_end:int,samples:int} */
    private function fragment(string $body, int $moofOffset, int $fileBytes, array $track, int &$boxes): array
    {
        $fragment = $this->unique($body, ['mfhd', 'traf'], $boxes);
        if (!isset($fragment['mfhd'], $fragment['traf'])) self::invalid();
        $this->full($fragment['mfhd'], [0]);
        if (strlen($fragment['mfhd']) !== 8) self::invalid();
        $header = null;
        $decode = null;
        $runs = [];
        foreach ($this->children($fragment['traf'], $boxes) as $child) {
            if ($child['type'] === 'tfhd' && $header === null && $runs === []) $header = $child['body'];
            elseif ($child['type'] === 'tfdt' && $decode === null && $runs === []) $decode = $child['body'];
            elseif ($child['type'] === 'trun' && $header !== null && $decode !== null && count($runs) < 32) $runs[] = $child['body'];
            else self::invalid();
        }
        if ($header === null || $decode === null || $runs === []) self::invalid();
        $this->full($header, [0], false);
        $flags = self::u32($header, 0) & 0xffffff;
        if (($flags & ~0x02003b) !== 0 || self::u32($header, 4) !== $track['id']) self::invalid();
        $offset = 8;
        $base = $moofOffset;
        if (($flags & 1) !== 0) { $base = self::u64($header, $offset); $offset += 8; }
        if ($base !== $moofOffset) self::invalid();
        if (($flags & 2) !== 0) {
            if (self::u32($header, $offset) !== 1) self::invalid();
            $offset += 4;
        }
        $duration = $track['duration'];
        $size = $track['size'];
        if (($flags & 8) !== 0) { $duration = self::u32($header, $offset); $offset += 4; }
        if (($flags & 16) !== 0) { $size = self::u32($header, $offset); $offset += 4; }
        if (($flags & 32) !== 0) { self::u32($header, $offset); $offset += 4; }
        if ($offset !== strlen($header)) self::invalid();
        $version = $this->full($decode, [0, 1]);
        if (strlen($decode) !== ($version === 0 ? 8 : 12)) self::invalid();
        $decodeStart = $version === 0 ? self::u32($decode, 4) : self::u64($decode, 4);
        $decodeEnd = $decodeStart;
        $start = null;
        $end = null;
        $samples = 0;
        foreach ($runs as $run) {
            $this->full($run, [0, 1], false);
            $runFlags = self::u32($run, 0) & 0xffffff;
            if (($runFlags & ~0xf05) !== 0 || (($runFlags & 4) !== 0 && ($runFlags & 0x400) !== 0)) self::invalid();
            $count = self::u32($run, 4);
            if ($count < 1 || $samples > self::MAX_SAMPLES - $count) self::invalid();
            $samples += $count;
            $cursor = 8;
            $position = $end;
            if (($runFlags & 1) !== 0) {
                $dataOffset = self::u32($run, $cursor);
                $cursor += 4;
                // This limited proof accepts forward sample bytes in the
                // immediately following mdat, never external or backward data.
                if ($dataOffset > 2147483647) self::invalid();
                $position = $base + $dataOffset;
            }
            if ($position === null || ($end !== null && $position !== $end)) self::invalid();
            if ($start === null) $start = $position;
            if (($runFlags & 4) !== 0) { self::u32($run, $cursor); $cursor += 4; }
            $fields = 0;
            foreach ([0x100, 0x200, 0x400, 0x800] as $field) if (($runFlags & $field) !== 0) ++$fields;
            if (strlen($run) - $cursor !== $count * $fields * 4) self::invalid();
            for ($index = 0; $index < $count; $index++) {
                $sampleDuration = $duration;
                $sampleSize = $size;
                if (($runFlags & 0x100) !== 0) { $sampleDuration = self::u32($run, $cursor); $cursor += 4; }
                if (($runFlags & 0x200) !== 0) { $sampleSize = self::u32($run, $cursor); $cursor += 4; }
                if (($runFlags & 0x400) !== 0) { self::u32($run, $cursor); $cursor += 4; }
                if (($runFlags & 0x800) !== 0) { self::u32($run, $cursor); $cursor += 4; }
                if ($sampleDuration < 1 || $sampleSize < 1 || $position > $fileBytes - $sampleSize
                    || $decodeEnd > PHP_INT_MAX - $sampleDuration) self::invalid();
                $position += $sampleSize;
                $decodeEnd += $sampleDuration;
            }
            $end = $position;
        }
        if ($start === null || $end === null || $start < $moofOffset || $end <= $start) self::invalid();
        return ['start' => $start, 'end' => $end, 'sequence' => self::u32($fragment['mfhd'], 4),
            'decode_start' => $decodeStart, 'decode_end' => $decodeEnd, 'samples' => $samples];
    }

    /** @param list<int> $versions */
    private function full(string $body, array $versions, bool $zeroFlags = true): int
    {
        if (strlen($body) < 4) self::invalid();
        $version = ord($body[0]);
        if (!in_array($version, $versions, true)
            || ($zeroFlags && (self::u32($body, 0) & 0xffffff) !== 0)) self::invalid();
        return $version;
    }

    private static function u16(string $body, int $offset): int
    {
        if ($offset < 0 || strlen($body) - $offset < 2) self::invalid();
        return (ord($body[$offset]) << 8) | ord($body[$offset + 1]);
    }

    private static function u32(string $body, int $offset): int
    {
        if ($offset < 0 || strlen($body) - $offset < 4) self::invalid();
        return (ord($body[$offset]) << 24) | (ord($body[$offset + 1]) << 16)
            | (ord($body[$offset + 2]) << 8) | ord($body[$offset + 3]);
    }

    private static function u64(string $body, int $offset): int
    {
        $high = self::u32($body, $offset);
        $low = self::u32($body, $offset + 4);
        if ($high > 0x7fffffff) self::invalid();
        return ($high << 32) | $low;
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_FILE_INVALID', 'The fragmented M4A audio container could not be verified');
    }
}
