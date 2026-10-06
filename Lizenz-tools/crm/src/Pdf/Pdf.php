<?php

declare(strict_types=1);

namespace App\Pdf;

/**
 * Minimaler PDF-Schreiber (A4, Standardschrift Helvetica) ohne externe Abhängigkeiten.
 * Koordinaten in Punkt, Ursprung oben links, y wächst nach unten.
 */
final class Pdf
{
    public const WIDTH = 595.28;
    public const HEIGHT = 841.89;

    /** @var list<string> */
    private array $pages = [];
    private int $current = -1;

    public function __construct(private readonly string $title = '', private readonly string $author = '')
    {
        $this->addPage();
    }

    public function addPage(): void
    {
        $this->pages[] = '';
        $this->current = count($this->pages) - 1;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    /** Wechselt zu einer bestehenden Seite (0-basiert), z. B. um Fußzeilen nachträglich zu zeichnen. */
    public function setPage(int $index): void
    {
        $this->current = $index;
    }

    /** @param array{0:float,1:float,2:float} $rgb Werte 0–1 */
    public function text(float $x, float $y, string $text, float $size = 10, bool $bold = false, array $rgb = [0, 0, 0]): void
    {
        if ($text === '') {
            return;
        }
        $this->add(sprintf(
            "BT /%s %s Tf %s rg %s %s Td (%s) Tj ET\n",
            $bold ? 'F2' : 'F1',
            self::n($size),
            self::color($rgb),
            self::n($x),
            self::n(self::HEIGHT - $y),
            self::escape($text),
        ));
    }

    /** Text rechtsbündig an der Kante $xRight. */
    public function textRight(float $xRight, float $y, string $text, float $size = 10, bool $bold = false, array $rgb = [0, 0, 0]): void
    {
        $this->text($xRight - $this->width($text, $size, $bold), $y, $text, $size, $bold, $rgb);
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.5, array $rgb = [0.8, 0.8, 0.8]): void
    {
        $this->add(sprintf(
            "%s w %s RG %s %s m %s %s l S\n",
            self::n($width),
            self::color($rgb),
            self::n($x1),
            self::n(self::HEIGHT - $y1),
            self::n($x2),
            self::n(self::HEIGHT - $y2),
        ));
    }

    public function rect(float $x, float $y, float $w, float $h, array $fill): void
    {
        $this->add(sprintf(
            "%s rg %s %s %s %s re f\n",
            self::color($fill),
            self::n($x),
            self::n(self::HEIGHT - $y - $h),
            self::n($w),
            self::n($h),
        ));
    }

    public function width(string $text, float $size = 10, bool $bold = false): float
    {
        $table = $bold ? FontMetrics::BOLD : FontMetrics::REGULAR;
        $sum = 0;
        foreach (str_split(self::encode($text)) as $char) {
            $code = ord($char);
            $sum += $code >= 32 ? $table[$code - 32] : 0;
        }
        return $sum * $size / 1000;
    }

    /**
     * Bricht Text an Wortgrenzen auf die Breite $maxWidth um (überlange Wörter werden hart getrennt).
     *
     * @return list<string>
     */
    public function wrap(string $text, float $maxWidth, float $size = 10, bool $bold = false): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [''] as $paragraph) {
            $line = '';
            foreach (preg_split('/\s+/u', trim($paragraph), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                while ($this->width($word, $size, $bold) > $maxWidth) {
                    $cut = mb_strlen($word);
                    while ($cut > 1 && $this->width(mb_substr($word, 0, $cut), $size, $bold) > $maxWidth) {
                        $cut--;
                    }
                    if ($line !== '') {
                        $lines[] = $line;
                        $line = '';
                    }
                    $lines[] = mb_substr($word, 0, $cut);
                    $word = mb_substr($word, $cut);
                }
                $candidate = $line === '' ? $word : "$line $word";
                if ($line !== '' && $this->width($candidate, $size, $bold) > $maxWidth) {
                    $lines[] = $line;
                    $line = $word;
                } else {
                    $line = $candidate;
                }
            }
            $lines[] = $line;
        }
        return $lines;
    }

    public function output(): string
    {
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        $next = 5;
        foreach ($this->pages as $content) {
            $contentId = $next++;
            $pageId = $next++;
            $kids[] = "$pageId 0 R";

            $stream = $content;
            $filter = '';
            if (function_exists('gzcompress')) {
                $stream = (string) gzcompress($content, 6);
                $filter = ' /Filter /FlateDecode';
            }
            $objects[$contentId] = "<< /Length " . strlen($stream) . "$filter >>\nstream\n$stream\nendstream";
            $objects[$pageId] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                self::n(self::WIDTH),
                self::n(self::HEIGHT),
                $contentId,
            );
        }
        $objects[2] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', $kids), count($kids));

        $infoId = $next++;
        $objects[$infoId] = sprintf(
            '<< /Title (%s) /Author (%s) /Producer (Webdesigner CRM) /CreationDate (D:%s) >>',
            self::escape($this->title),
            self::escape($this->author),
            gmdate('YmdHis') . 'Z',
        );

        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "$id 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . ($infoId + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $infoId; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        return $pdf . "trailer\n<< /Size " . ($infoId + 1) . " /Root 1 0 R /Info $infoId 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }

    private function add(string $ops): void
    {
        $this->pages[$this->current] .= $ops;
    }

    /** UTF-8 → Windows-1252 (nicht darstellbare Zeichen werden zu "?"). */
    private static function encode(string $text): string
    {
        $text = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $text) ?? '';
        return mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
    }

    private static function escape(string $text): string
    {
        return strtr(self::encode($text), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']);
    }

    private static function n(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') ?: '0';
    }

    /** @param array{0:float,1:float,2:float} $rgb */
    private static function color(array $rgb): string
    {
        return self::n($rgb[0]) . ' ' . self::n($rgb[1]) . ' ' . self::n($rgb[2]);
    }
}
