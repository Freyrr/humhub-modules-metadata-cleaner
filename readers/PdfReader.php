<?php

/**
 * @license AGPL-3.0-or-later
 */

namespace humhub\modules\metadataCleaner\readers;

/**
 * Reads the entries of a PDF document information dictionary, for the log.
 */
class PdfReader
{
    private const MAX_ENTRIES = 100;

    /**
     * @param string $dict the dictionary text, "<<" to ">>"
     * @return array<string, string>
     */
    public function info(string $dict): array
    {
        $fields = [];
        $p = 2;
        $length = strlen($dict) - 2;
        $count = 0;
        while ($p < $length && $count++ < self::MAX_ENTRIES) {
            if (!preg_match('#\G[\s\x00]*/([^\s/<>\[\]()%]+)[\s\x00]*#', $dict, $m, 0, $p)) {
                break;
            }
            $key = $m[1];
            $p += strlen($m[0]);
            [$value, $p] = $this->value($dict, $p);
            $fields['PDF:' . $this->name($key)] = $value;
        }

        return $fields;
    }

    /**
     * @return array{0: string, 1: int} decoded value and position after it
     */
    private function value(string $dict, int $p): array
    {
        $c = $dict[$p] ?? '';
        if ($c === '(') {
            $out = '';
            $depth = 0;
            for ($i = $p; $i < strlen($dict); $i++) {
                $ch = $dict[$i];
                if ($ch === '\\') {
                    $next = $dict[$i + 1] ?? '';
                    if (preg_match('/\G[0-7]{1,3}/', $dict, $oct, 0, $i + 1)) {
                        $out .= chr(octdec($oct[0]) & 0xFF);
                        $i += strlen($oct[0]);
                    } else {
                        $out .= ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f", "\n" => '', "\r" => ''][$next] ?? $next;
                        $i++;
                    }
                    continue;
                }
                if ($ch === '(') {
                    if ($depth++ === 0) {
                        continue;
                    }
                } elseif ($ch === ')') {
                    if (--$depth === 0) {
                        return [$this->text($out), $i + 1];
                    }
                }
                $out .= $ch;
            }

            return [$this->text($out), strlen($dict)];
        }
        if ($c === '<' && ($dict[$p + 1] ?? '') !== '<') {
            $end = strpos($dict, '>', $p);
            $end = $end === false ? strlen($dict) : $end;
            $hex = preg_replace('/[^0-9A-Fa-f]/', '', substr($dict, $p + 1, $end - $p - 1));
            if (strlen($hex) % 2) {
                $hex .= '0';
            }

            return [$this->text((string)hex2bin($hex)), $end + 1];
        }
        // Names, numbers, references, arrays...: kept as text up to the next key.
        if (preg_match('#\G[^/]*#', $dict, $m, 0, $p)) {
            $raw = trim($m[0]);
            if ($c === '/' && preg_match('#\G/[^\s/<>\[\]()%]*#', $dict, $n, 0, $p)) {
                return [substr($n[0], 1), $p + strlen($n[0])];
            }

            return [$raw, $p + max(1, strlen($m[0]))];
        }

        return ['', $p + 1];
    }

    /**
     * PDF text strings are UTF-16BE with a byte order mark, or PDFDocEncoding.
     */
    private function text(string $raw): string
    {
        if (str_starts_with($raw, "\xFE\xFF")) {
            return (string)@mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
        }
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            return substr($raw, 3);
        }

        return mb_check_encoding($raw, 'UTF-8') ? $raw : (string)mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
    }

    private function name(string $key): string
    {
        return preg_replace_callback('/#([0-9A-Fa-f]{2})/', static fn($m) => chr(hexdec($m[1])), $key) ?? $key;
    }
}
