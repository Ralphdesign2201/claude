<?php
declare(strict_types=1);

/** Minimaler PDF-Writer (A4, Helvetica, JPEG-Bilder) – keine Abhängigkeiten. Koordinaten in mm, Ursprung oben links. */
final class Pdf
{
    private const MM = 72 / 25.4;
    public const W = 210.0;
    public const H = 297.0;

    private array $pages = [];
    private int $cur = -1;
    private array $images = [];

    private static array $reg = [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584];
    private static array $bold = [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584];
    private static array $high = [ // cp1252-Sonderzeichen: [regular, bold]
        0x80 => [556,556], 0xC4 => [667,722], 0xD6 => [778,778], 0xDC => [722,722], 0xDF => [611,611],
        0xE4 => [556,556], 0xF6 => [556,611], 0xFC => [556,611], 0xA7 => [556,556], 0xB2 => [333,333], 0xB3 => [333,333],
        0xB0 => [400,400], 0xE9 => [556,556], 0xE8 => [556,556], 0xE0 => [556,556], 0xA0 => [278,278], 0x96 => [556,556], 0xB7 => [278,278],
    ];

    public function addPage(): void { $this->pages[] = ''; $this->cur++; }

    private static function enc(string $s): string
    {
        $o = function_exists('iconv') ? @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s) : false;
        if ($o === false) $o = function_exists('mb_convert_encoding') ? mb_convert_encoding($s, 'Windows-1252', 'UTF-8') : utf8_decode($s);
        return str_replace("\xA0", ' ', $o); // geschütztes Leerzeichen als normales
    }
    private static function esc(string $s): string { return str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', ''], $s); }

    public function width(string $s, float $size = 10, bool $bold = false): float
    {
        $s = self::enc($s); $w = 0;
        $t = $bold ? self::$bold : self::$reg;
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $c = ord($s[$i]);
            if ($c >= 32 && $c <= 126) $w += $t[$c - 32];
            else $w += self::$high[$c][$bold ? 1 : 0] ?? 556;
        }
        return $w * $size / 1000 / self::MM;
    }

    /** Zeilenumbruch auf maximale Breite (mm); respektiert \n. */
    public function wrap(string $text, float $maxW, float $size = 10, bool $bold = false): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $text) as $para) {
            $line = '';
            foreach (preg_split('/ +/', $para) as $word) {
                $try = $line === '' ? $word : $line . ' ' . $word;
                if ($line !== '' && $this->width($try, $size, $bold) > $maxW) { $out[] = $line; $line = $word; }
                else $line = $try;
                while ($this->width($line, $size, $bold) > $maxW && mb_strlen($line) > 1) { // überlanges Wort trennen
                    $k = mb_strlen($line);
                    while ($k > 1 && $this->width(mb_substr($line, 0, $k), $size, $bold) > $maxW) $k--;
                    $out[] = mb_substr($line, 0, $k); $line = mb_substr($line, $k);
                }
            }
            $out[] = $line;
        }
        return $out;
    }

    private static function col(array $c, bool $stroke = false): string
    {
        return sprintf('%.3F %.3F %.3F %s ', $c[0] / 255, $c[1] / 255, $c[2] / 255, $stroke ? 'RG' : 'rg');
    }

    /** $x: linke Kante (L), rechte Kante (R) oder Mitte (C); $y: Grundlinie von oben. */
    public function text(float $x, float $y, string $s, float $size = 10, bool $bold = false, string $align = 'L', array $color = [0, 0, 0]): void
    {
        if ($s === '') return;
        $w = $this->width($s, $size, $bold);
        if ($align === 'R') $x -= $w; elseif ($align === 'C') $x -= $w / 2;
        $this->pages[$this->cur] .= sprintf("BT %s/%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n", self::col($color), $bold ? 'F2' : 'F1', $size, $x * self::MM, (self::H - $y) * self::MM, self::esc(self::enc($s)));
    }

    public function textRotated(float $x, float $y, string $s, float $size, float $deg, array $color): void
    {
        $a = deg2rad($deg);
        $this->pages[$this->cur] .= sprintf("BT %s/F2 %.2F Tf %.4F %.4F %.4F %.4F %.2F %.2F Tm (%s) Tj ET\n", self::col($color), $size, cos($a), sin($a), -sin($a), cos($a), $x * self::MM, (self::H - $y) * self::MM, self::esc(self::enc($s)));
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $w = 0.2, array $color = [0, 0, 0]): void
    {
        $this->pages[$this->cur] .= sprintf("%s%.2F w %.2F %.2F m %.2F %.2F l S\n", self::col($color, true), $w, $x1 * self::MM, (self::H - $y1) * self::MM, $x2 * self::MM, (self::H - $y2) * self::MM);
    }

    public function rect(float $x, float $y, float $w, float $h, array $fill): void
    {
        $this->pages[$this->cur] .= sprintf("%s%.2F %.2F %.2F %.2F re f\n", self::col($fill), $x * self::MM, (self::H - $y - $h) * self::MM, $w * self::MM, $h * self::MM);
    }

    /** JPEG-Bild aus Datei einbetten; Breite/Höhe in mm. */
    public function image(string $file, float $x, float $y, float $w, float $h): void
    {
        if (!isset($this->images[$file])) {
            $info = @getimagesize($file);
            $data = @file_get_contents($file);
            if (!$info || $info[2] !== IMAGETYPE_JPEG || $data === false) return;
            $this->images[$file] = ['w' => $info[0], 'h' => $info[1], 'ch' => $info['channels'] ?? 3, 'data' => $data, 'n' => count($this->images) + 1];
        }
        $this->pages[$this->cur] .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /Im%d Do Q\n", $w * self::MM, $h * self::MM, $x * self::MM, (self::H - $y - $h) * self::MM, $this->images[$file]['n']);
    }

    public function output(string $title = ''): string
    {
        $objs = []; // 1-basiert über Index+1
        $add = function (string $s) use (&$objs): int { $objs[] = $s; return count($objs); };
        $add(''); $add(''); // 1 Catalog, 2 Pages (später)
        $f1 = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
        $f2 = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>');
        $imgRefs = [];
        foreach ($this->images as $im) {
            $cs = $im['ch'] === 1 ? '/DeviceGray' : ($im['ch'] === 4 ? '/DeviceCMYK /Decode [1 0 1 0 1 0 1 0]' : '/DeviceRGB');
            $imgRefs[$im['n']] = $add(sprintf("<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace %s /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n%s\nendstream", $im['w'], $im['h'], $cs, strlen($im['data']), $im['data']));
        }
        $xo = '';
        foreach ($imgRefs as $n => $ref) $xo .= "/Im$n $ref 0 R ";
        $kids = [];
        foreach ($this->pages as $content) {
            $c = $add(sprintf("<< /Length %d >>\nstream\n%sendstream", strlen($content), $content));
            $kids[] = $add(sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Resources << /Font << /F1 %d 0 R /F2 %d 0 R >> /XObject << %s>> >> /Contents %d 0 R >>', $f1, $f2, $xo, $c)) ;
        }
        $objs[0] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[1] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', array_map(fn($k) => "$k 0 R", $kids)), count($kids));
        $info = $add(sprintf('<< /Title (%s) /Producer (Rechnungsprogramm) /CreationDate (D:%s) >>', self::esc(self::enc($title)), date('YmdHis')));
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $offs = [];
        foreach ($objs as $i => $o) { $offs[$i + 1] = strlen($out); $out .= ($i + 1) . " 0 obj\n$o\nendobj\n"; }
        $xref = strlen($out);
        $out .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($offs as $o) $out .= sprintf("%010d 00000 n \n", $o);
        $out .= sprintf("trailer\n<< /Size %d /Root 1 0 R /Info %d 0 R >>\nstartxref\n%d\n%%%%EOF", count($objs) + 1, $info, $xref);
        return $out;
    }
}
