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
    private array $attachments = [];
    private string $xmp = '';


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
        $f = $this->font($bold);
        $s = self::enc($s); $w = 0;
        for ($i = 0, $n = strlen($s); $i < $n; $i++) $w += $f->widths[ord($s[$i])] ?? 0;
        return $w * $size / 1000 / self::MM;
    }

    private array $fonts = [];
    private function font(bool $bold): TtfFont
    {
        return $this->fonts[(int)$bold] ??= new TtfFont(__DIR__ . '/fonts/LiberationSans-' . ($bold ? 'Bold' : 'Regular') . '.ttf');
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

    /** Datei einbetten (z. B. ZUGFeRD-XML, AFRelationship=Alternative). */
    public function attach(string $name, string $data, string $mime, string $desc): void
    {
        $this->attachments[] = compact('name', 'data', 'mime', 'desc');
    }
    private function stamp(): string { return date('YmdHis') . 'Z00\'00'; }
    public function setXmp(string $xml): void { $this->xmp = $xml; }

    /** TrueType-Schrift vollständig einbetten (Voraussetzung für PDF/A). */
    private function embedFont(callable $add, bool $bold): int
    {
        $f = $this->font($bold);
        $data = function_exists('gzcompress') ? gzcompress($f->data, 6) : $f->data;
        $filter = function_exists('gzcompress') ? '/Filter /FlateDecode ' : '';
        $ff = $add(sprintf("<< %s/Length %d /Length1 %d >>\nstream\n%s\nendstream", $filter, strlen($data), strlen($f->data), $data));
        $fd = $add(sprintf('<< /Type /FontDescriptor /FontName /%s /Flags 32 /FontBBox [%d %d %d %d] /ItalicAngle 0 /Ascent %d /Descent %d /CapHeight %d /StemV %d /FontFile2 %d 0 R >>',
            $f->psName, $f->bbox[0], $f->bbox[1], $f->bbox[2], $f->bbox[3], $f->ascent, $f->descent, $f->capHeight, $bold ? 140 : 80, $ff));
        $w = implode(' ', array_map(fn($b) => (string)($f->widths[$b] ?? 0), range(32, 255)));
        return $add(sprintf('<< /Type /Font /Subtype /TrueType /BaseFont /%s /FirstChar 32 /LastChar 255 /Widths [%s] /FontDescriptor %d 0 R /Encoding /WinAnsiEncoding >>', $f->psName, $w, $fd));
    }

    public function output(string $title = ''): string
    {
        $objs = []; // 1-basiert über Index+1
        $add = function (string $s) use (&$objs): int { $objs[] = $s; return count($objs); };
        $add(''); $add(''); // 1 Catalog, 2 Pages (später)
        $f1 = $this->embedFont($add, false);
        $f2 = $this->embedFont($add, true);
        $imgRefs = [];
        foreach ($this->images as $im) {
            $cs = $im['ch'] === 1 ? '/DeviceGray' : ($im['ch'] === 4 ? '/DeviceCMYK /Decode [1 0 1 0 1 0 1 0]' : '/DeviceRGB');
            $imgRefs[$im['n']] = $add(sprintf("<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace %s /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n%s\nendstream", $im['w'], $im['h'], $cs, strlen($im['data']), $im['data']));
        }
        $xo = '';
        foreach ($imgRefs as $n => $ref) $xo .= "/Im$n $ref 0 R ";
        $kids = [];
        foreach ($this->pages as $content) {
            $c = $add(sprintf("<< /Length %d >>\nstream\n%s\nendstream", strlen($content), $content));
            $kids[] = $add(sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Resources << /Font << /F1 %d 0 R /F2 %d 0 R >> /XObject << %s>> >> /Contents %d 0 R >>', $f1, $f2, $xo, $c)) ;
        }
        $cat = '/Type /Catalog /Pages 2 0 R';
        if ($this->xmp !== '') {
            $m = $add(sprintf("<< /Type /Metadata /Subtype /XML /Length %d >>\nstream\n%s\nendstream", strlen($this->xmp), $this->xmp));
            $cat .= " /Metadata $m 0 R";
        }
        if ($this->xmp !== '') { // PDF/A: Ausgabe-Farbprofil (sRGB)
            $icc = file_get_contents(__DIR__ . '/fonts/sRGB.icc');
            $ip = $add(sprintf("<< /N 3 /Length %d >>\nstream\n%sendstream", strlen($icc), $icc . "\n"));
            $oi = $add(sprintf('<< /Type /OutputIntent /S /GTS_PDFA1 /OutputConditionIdentifier (sRGB) /Info (sRGB IEC61966-2.1) /DestOutputProfile %d 0 R >>', $ip));
            $cat .= " /OutputIntents [$oi 0 R]";
        }
        if ($this->attachments) {
            $names = []; $afs = [];
            foreach ($this->attachments as $a) {
                $mime = str_replace('/', '#2F', $a['mime']);
                $ef = $add(sprintf("<< /Type /EmbeddedFile /Subtype /%s /Params << /Size %d /ModDate (D:%s) >> /Length %d >>\nstream\n%s\nendstream", $mime, strlen($a['data']), $this->stamp(), strlen($a['data']), $a['data']));
                $fs = $add(sprintf('<< /Type /Filespec /F (%s) /UF (%s) /Desc (%s) /AFRelationship /Alternative /EmbeddedFile %d 0 R /EF << /F %d 0 R /UF %d 0 R >> >>', self::esc($a['name']), self::esc($a['name']), self::esc($a['desc']), $ef, $ef, $ef));
                $names[] = sprintf('(%s) %d 0 R', self::esc($a['name']), $fs); $afs[] = "$fs 0 R";
            }
            $cat .= ' /Names << /EmbeddedFiles << /Names [' . implode(' ', $names) . '] >> >> /AF [' . implode(' ', $afs) . '] /MarkInfo << /Marked true >>';
        }
        $objs[0] = "<< $cat >>";
        $objs[1] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', array_map(fn($k) => "$k 0 R", $kids)), count($kids));
        $info = $add(sprintf('<< /Title (%s) /Producer (Rechnungsprogramm) /CreationDate (D:%s) /ModDate (D:%s) >>', self::esc(self::enc($title)), $this->stamp(), $this->stamp()));
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $offs = [];
        foreach ($objs as $i => $o) { $offs[$i + 1] = strlen($out); $out .= ($i + 1) . " 0 obj\n$o\nendobj\n"; }
        $xref = strlen($out);
        $out .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($offs as $o) $out .= sprintf("%010d 00000 n \n", $o);
        $out .= sprintf("trailer\n<< /Size %d /Root 1 0 R /Info %d 0 R /ID [<%s> <%s>] >>\nstartxref\n%d\n%%%%EOF", count($objs) + 1, $info, $id = md5($out . $title), $id, $xref);
        return $out;
    }
}

/** Liest Metriken und Namen aus einer TrueType-Datei (für Breitenberechnung und PDF-Einbettung). */
final class TtfFont
{
    public string $data; public string $psName = 'LiberationSans'; public array $widths = []; public array $bbox = [0, 0, 1000, 1000];
    public int $ascent = 905, $descent = -212, $capHeight = 729;

    public function __construct(string $file)
    {
        $d = $this->data = (string)file_get_contents($file);
        $u16 = fn(int $o) => unpack('n', substr($d, $o, 2))[1];
        $s16 = fn(int $o) => unpack('n', substr($d, $o, 2))[1] - (unpack('n', substr($d, $o, 2))[1] >= 32768 ? 65536 : 0);
        $u32 = fn(int $o) => unpack('N', substr($d, $o, 4))[1];
        $t = [];
        for ($i = 0, $n = $u16(4); $i < $n; $i++) { $o = 12 + $i * 16; $t[substr($d, $o, 4)] = $u32($o + 8); }
        $upm = $u16($t['head'] + 18);
        $k = 1000 / $upm;
        $this->bbox = [(int)round($s16($t['head'] + 36) * $k), (int)round($s16($t['head'] + 38) * $k), (int)round($s16($t['head'] + 40) * $k), (int)round($s16($t['head'] + 42) * $k)];
        $this->ascent = (int)round($s16($t['hhea'] + 4) * $k); $this->descent = (int)round($s16($t['hhea'] + 6) * $k);
        $nh = $u16($t['hhea'] + 34);
        // cmap Format 4 (Windows, Unicode BMP)
        $cm = $t['cmap']; $sub = null;
        for ($i = 0, $n = $u16($cm + 2); $i < $n; $i++) { $o = $cm + 4 + $i * 8; if ($u16($o) === 3 && $u16($o + 2) === 1) $sub = $cm + $u32($o + 4); }
        $segX2 = $u16($sub + 6); $segs = $segX2 / 2;
        $endO = $sub + 14; $startO = $endO + $segX2 + 2; $deltaO = $startO + $segX2; $rangeO = $deltaO + $segX2;
        $glyph = function (int $cp) use ($u16, $s16, $segs, $endO, $startO, $deltaO, $rangeO): int {
            for ($i = 0; $i < $segs; $i++) {
                if ($cp <= $u16($endO + 2 * $i)) {
                    $st = $u16($startO + 2 * $i); if ($cp < $st) return 0;
                    $ro = $u16($rangeO + 2 * $i); $dl = $u16($deltaO + 2 * $i);
                    if ($ro === 0) return ($cp + $dl) & 0xFFFF;
                    $g = $u16($rangeO + 2 * $i + $ro + 2 * ($cp - $st));
                    return $g === 0 ? 0 : ($g + $dl) & 0xFFFF;
                }
            }
            return 0;
        };
        $adv = fn(int $g) => $u16($t['hmtx'] + 4 * min($g, $nh - 1));
        for ($b = 32; $b <= 255; $b++) {
            $uni = mb_convert_encoding(chr($b), 'UTF-8', 'Windows-1252');
            $cp = mb_ord($uni, 'UTF-8');
            $this->widths[$b] = (int)round($adv($glyph($cp)) * $k);
        }
        // PostScript-Name aus 'name'-Tabelle (ID 6)
        $nm = $t['name']; $cnt = $u16($nm + 2); $so = $nm + $u16($nm + 4);
        for ($i = 0; $i < $cnt; $i++) {
            $o = $nm + 6 + $i * 12;
            if ($u16($o + 6) === 6 && $u16($o) === 3) { $this->psName = preg_replace('/[^A-Za-z0-9\-]/', '', mb_convert_encoding(substr($d, $so + $u16($o + 10), $u16($o + 8)), 'UTF-8', 'UTF-16BE')); break; }
        }
    }
}
