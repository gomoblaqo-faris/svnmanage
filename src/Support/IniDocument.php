<?php

declare(strict_types=1);

namespace SvnManage\Support;

use InvalidArgumentException;

/**
 * Immutable, line-preserving INI document.
 *
 * Used for the Subversion authz file, config.ini and the role-assignment file.
 * Unlike parse_ini_file() it keeps comments, blank lines, ordering and
 * RFC-822 style continuation lines, so editing one rule never reformats
 * a hand-maintained file. Every mutator returns a new instance.
 */
final class IniDocument
{
    /**
     * @param list<array{type: string, section: ?string, name?: string, key?: string, value?: string, raw: list<string>}> $lines
     */
    private function __construct(
        private readonly array $lines,
        private readonly string $assign,
    ) {
    }

    public static function empty(string $assign = ' = '): self
    {
        return new self([], $assign);
    }

    public static function parse(string $text, string $assign = ' = ', string $commentChars = '#;'): self
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        $rawLines = explode("\n", $text);
        if (end($rawLines) === '') {
            array_pop($rawLines);
        }

        $lines = [];
        $section = null;
        foreach ($rawLines as $raw) {
            $trim = trim($raw);
            $last = count($lines) - 1;
            $isContinuation = $trim !== ''
                && ($raw[0] === ' ' || $raw[0] === "\t")
                && $last >= 0
                && $lines[$last]['type'] === 'entry';

            if ($trim === '' || strpbrk($trim[0], $commentChars) !== false) {
                $lines[] = ['type' => 'raw', 'section' => $section, 'raw' => [$raw]];
            } elseif ($isContinuation) {
                $lines[$last]['value'] = trim($lines[$last]['value'] . ' ' . $trim);
                $lines[$last]['raw'][] = $raw;
            } elseif ($trim[0] === '[' && str_ends_with($trim, ']')) {
                $section = substr($trim, 1, -1);
                $lines[] = ['type' => 'section', 'section' => $section, 'name' => $section, 'raw' => [$raw]];
            } else {
                $parts = explode('=', $trim, 2);
                $lines[] = [
                    'type' => 'entry',
                    'section' => $section,
                    'key' => trim($parts[0]),
                    'value' => trim($parts[1] ?? ''),
                    'raw' => [$raw],
                ];
            }
        }

        return new self($lines, $assign);
    }

    /** @return list<string> */
    public function sections(): array
    {
        $names = [];
        foreach ($this->lines as $line) {
            if ($line['type'] === 'section' && !in_array($line['name'], $names, true)) {
                $names[] = $line['name'];
            }
        }

        return $names;
    }

    public function hasSection(string $section): bool
    {
        return in_array($section, $this->sections(), true);
    }

    /** @return array<string, string> ordered key => value (last duplicate wins) */
    public function entries(string $section): array
    {
        $out = [];
        foreach ($this->lines as $line) {
            if ($line['type'] === 'entry' && $line['section'] === $section) {
                $out[$line['key']] = $line['value'];
            }
        }

        return $out;
    }

    /** @return list<string> */
    public function keys(string $section): array
    {
        return array_map('strval', array_keys($this->entries($section)));
    }

    public function get(string $section, string $key, ?string $default = null): ?string
    {
        return $this->entries($section)[$key] ?? $default;
    }

    public function has(string $section, string $key): bool
    {
        return array_key_exists($key, $this->entries($section));
    }

    public function with(string $section, string $key, string $value): self
    {
        self::assertKey($key);
        self::assertValue($value);
        $doc = $this->withSection($section);
        $lines = $doc->lines;
        $newLine = [
            'type' => 'entry',
            'section' => $section,
            'key' => $key,
            'value' => $value,
            'raw' => [$this->formatEntry($key, $value)],
        ];

        $matches = array_keys(array_filter(
            $lines,
            static fn (array $l): bool => $l['type'] === 'entry' && $l['section'] === $section && $l['key'] === $key,
        ));

        if ($matches !== []) {
            $keep = array_pop($matches);
            $lines[$keep] = $newLine;
            foreach ($matches as $idx) {
                unset($lines[$idx]);
            }

            return new self(array_values($lines), $this->assign);
        }

        array_splice($lines, $doc->insertionPoint($section), 0, [$newLine]);

        return new self($lines, $this->assign);
    }

    public function without(string $section, string $key): self
    {
        $lines = array_values(array_filter(
            $this->lines,
            static fn (array $l): bool => !($l['type'] === 'entry' && $l['section'] === $section && $l['key'] === $key),
        ));

        return new self($lines, $this->assign);
    }

    public function withSection(string $section): self
    {
        self::assertSectionName($section);
        if ($this->hasSection($section)) {
            return $this;
        }
        $lines = $this->lines;
        $last = end($lines);
        if ($last !== false && trim($last['raw'][0]) !== '') {
            $lines[] = ['type' => 'raw', 'section' => $last['section'], 'raw' => ['']];
        }
        $lines[] = ['type' => 'section', 'section' => $section, 'name' => $section, 'raw' => ['[' . $section . ']']];

        return new self($lines, $this->assign);
    }

    public function withoutSection(string $section): self
    {
        $lines = array_values(array_filter(
            $this->lines,
            static fn (array $l): bool => $l['section'] !== $section,
        ));

        return new self($lines, $this->assign);
    }

    public function withRenamedSection(string $from, string $to): self
    {
        self::assertSectionName($to);
        if (!$this->hasSection($from) || $this->hasSection($to)) {
            return $this;
        }
        $lines = array_map(static function (array $l) use ($from, $to): array {
            if ($l['section'] !== $from) {
                return $l;
            }
            $l['section'] = $to;
            if ($l['type'] === 'section') {
                $l['name'] = $to;
                $l['raw'] = ['[' . $to . ']'];
            }

            return $l;
        }, $this->lines);

        return new self($lines, $this->assign);
    }

    /** Renames a key inside a section, keeping its position and value. */
    public function withRenamedKey(string $section, string $from, string $to): self
    {
        self::assertKey($to);
        if (!$this->has($section, $from) || $from === $to) {
            return $this;
        }
        $value = (string) $this->get($section, $from);
        $doc = $this->has($section, $to) ? $this->without($section, $to) : $this;
        $lines = array_map(function (array $l) use ($section, $from, $to, $value): array {
            if ($l['type'] === 'entry' && $l['section'] === $section && $l['key'] === $from) {
                return ['type' => 'entry', 'section' => $section, 'key' => $to, 'value' => $value, 'raw' => [$this->formatEntry($to, $value)]];
            }

            return $l;
        }, $doc->lines);

        return new self($lines, $this->assign);
    }

    public function render(): string
    {
        $out = [];
        foreach ($this->lines as $line) {
            foreach ($line['raw'] as $raw) {
                $out[] = $raw;
            }
        }

        return $out === [] ? '' : implode("\n", $out) . "\n";
    }

    /** @return array<string, array<string, string>> */
    public function toArray(): array
    {
        $out = [];
        foreach ($this->sections() as $section) {
            $out[$section] = $this->entries($section);
        }

        return $out;
    }

    /*
     * Structure guards: whatever reaches the file must not be able to start a new
     * line, close a section header or masquerade as a header/comment/assignment.
     */
    private static function assertSectionName(string $section): void
    {
        if ($section === '' || preg_match('/[\r\n\0\]]/', $section)) {
            throw new InvalidArgumentException(sprintf('Invalid section name "%s".', self::printable($section)));
        }
    }

    private static function assertKey(string $key): void
    {
        if (trim($key) === '' || preg_match('/[\r\n\0=]/', $key) || preg_match('/^\s*[\[#;]/', $key)) {
            throw new InvalidArgumentException(sprintf('Invalid entry name "%s".', self::printable($key)));
        }
    }

    private static function assertValue(string $value): void
    {
        if (preg_match('/[\r\n\0]/', $value)) {
            throw new InvalidArgumentException('Values must not contain line breaks.');
        }
    }

    private static function printable(string $text): string
    {
        return addcslashes(substr($text, 0, 60), "\0..\37");
    }

    private function formatEntry(string $key, string $value): string
    {
        return $value === '' ? rtrim($key . $this->assign) : $key . $this->assign . $value;
    }

    /** Index right after the last entry (or the header) of $section. */
    private function insertionPoint(string $section): int
    {
        $point = null;
        foreach ($this->lines as $idx => $line) {
            if ($line['section'] !== $section) {
                continue;
            }
            if ($line['type'] === 'section' || $line['type'] === 'entry') {
                $point = $idx + 1;
            }
        }

        return $point ?? count($this->lines);
    }
}
