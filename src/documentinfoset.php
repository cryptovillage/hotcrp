<?php
// documentinfoset.php -- HotCRP document set
// Copyright (c) 2006-2021 Eddie Kohler; see LICENSE.

class DocumentInfoSet_ZipInfo {
    /** @var ?int */
    public $local_offset;
    /** @var string */
    public $localh;
    /** @var int */
    public $date = 0;
    /** @var int */
    public $time = 0;
    /** @var int */
    public $compression;
    /** @var ?string */
    public $compressed;
    /** @var int */
    public $compressed_length;
    /** @var bool */
    public $zip64 = false;
    /** @var ?int */
    public $central_offset;
    /** @var ?string */
    public $centralh;

    function local_end_offset() {
        return $this->local_offset + strlen($this->localh) + $this->compressed_length;
    }
    function central_end_offset() {
        return $this->central_offset + strlen($this->centralh);
    }
}

class DocumentInfoSet implements ArrayAccess, IteratorAggregate, Countable {
    /** @var Conf */
    private $conf;
    /** @var list<string> */
    private $ufn = [];
    /** @var list<DocumentInfo> */
    private $docs = [];
    /** @var ?list<string> */
    private $_errors_html;
    /** @var ?string */
    private $_filename;
    /** @var ?string */
    private $_mimetype;
    /** @var ?string|false */
    private $_tmpdir;
    /** @var ?list<string> */
    private $_dirfn;
    /** @var ?string */
    private $_filestore;
    /** @var list<DocumentInfoSet_ZipInfo> */
    private $_zipi;
    /** @var ?string */
    private $_signature;

    /** @param ?string $filename */
    function __construct($filename = null) {
        $this->conf = Conf::$main;
        $this->_filename = $filename;
        assert(strpos($filename ?? "", "/") === false);
    }
    /** @param string $mimetype */
    function set_mimetype($mimetype) {
        $this->_mimetype = $mimetype;
    }

    /** @return bool */
    function add(DocumentInfo $doc) {
        return $this->add_as($doc, $doc->filename ?? "");
    }
    /** @return false */
    private function _add_fail(DocumentInfo $doc, $fn) {
        error_log("{$this->conf->dbname}: failing to add #{$doc->paperStorageId} at $fn");
        return false;
    }
    /** @param string $fn
     * @return bool */
    function add_as(DocumentInfo $doc, $fn) {
        if ($this->_filename) { // might generate a .zip later; check filename
            assert(!$doc->error && $fn !== "");
            $slash = strpos($fn, "/");
            if ($doc->error
                || $fn === ""
                || strlen($fn) > 1000
                || preg_match('/[\000]|\/\/|\/\z/', $fn)) {
                return $this->_add_fail($doc, $fn);
            }
            while ($slash !== false) {
                $dir = substr($fn, 0, $slash);
                if (in_array($dir, $this->ufn)) {
                    return $this->_add_fail($doc, $fn);
                } else if (!in_array($dir, $this->_dirfn ?? [])) {
                    $this->_dirfn[] = $dir;
                }
                $slash = strpos($fn, "/", $slash + 1);
            }
            if ($this->_dirfn !== null && in_array($fn, $this->_dirfn)) {
                return $this->_add_fail($doc, $fn);
            }
        }
        while ($fn !== "" && in_array($fn, $this->ufn)) {
            if (preg_match('/\A(.*\()(\d+)(\)(?:\.\w+|))\z/', $fn, $m)) {
                $fn = $m[1] . ((int) $m[2] + 1) . $m[3];
            } else if (preg_match('/\A(.*?)(\.\w+|)\z/', $fn, $m) && $m[1] !== "") {
                $fn = $m[1] . " (1)" . $m[2];
            } else {
                $fn .= " (1)";
            }
        }
        $this->ufn[] = $fn;
        $this->docs[] = $doc->with_member_filename($fn);
        return true;
    }
    /** @param string $text
     * @param string $fn
     * @param ?string $mimetype
     * @param ?int $timestamp
     * @return bool */
    function add_string_as($text, $fn, $mimetype = null, $timestamp = null) {
        return $this->add_as(new DocumentInfo([
            "content" => $text, "size" => strlen($text),
            "filename" => $fn, "mimetype" => $mimetype ?? "text/plain",
            "timestamp" => $timestamp ?? Conf::$now
        ], $this->conf), $fn);
    }
    /** @param string $error_html */
    function add_error_html($error_html) {
        $this->_errors_html[] = $error_html;
    }

    /** @return list<DocumentInfo> */
    function as_list() {
        return $this->docs;
    }
    /** @return list<int> */
    function document_ids() {
        return array_map(function ($doc) { return $doc->paperStorageId; }, $this->docs);
    }
    /** @return bool */
    function is_empty() {
        return empty($this->docs);
    }
    /** @return int */
    function size() {
        return count($this->docs);
    }
    /** @return int */
    function count() {
        return count($this->docs);
    }
    /** @return bool */
    function has_errors() {
        return !empty($this->_errors_html);
    }
    /** @return list<string> */
    function error_texts() {
        return $this->_errors_html ?? [];
    }
    /** @param int $i
     * @return ?DocumentInfo */
    function document_by_index($i) {
        return $this->docs[$i] ?? null;
    }
    /** @param int $i
     * @return DocumentInfo */
    function checked_document_by_index($i) {
        $doc = $this->docs[$i] ?? null;
        if (!$doc) {
            throw new Exception("DocumentInfoSet::checked_document_by_index($i) failure");
        }
        return $doc;
    }
    /** @param string $fn
     * @return ?DocumentInfo */
    function document_by_filename($fn) {
        $i = array_search($fn, $this->ufn);
        return $i !== false && $fn !== "" ? $this->docs[$i] : null;
    }
    /** @param int $i
     * @return ?string */
    function filename_by_index($i) {
        return $this->ufn[$i] ?? null;
    }
    /** @return Iterator<DocumentInfo> */
    function getIterator() {
        return new ArrayIterator($this->docs);
    }
    /** @param int|string $offset
     * @return bool */
    function offsetExists($offset) {
        return is_int($offset)
            ? isset($this->docs[$offset])
            : $offset !== "" && in_array($offset, $this->ufn);
    }
    /** @param int|string $offset
     * @return ?DocumentInfo */
    function offsetGet($offset) {
        if (!is_int($offset) && $offset !== "") {
            $offset = array_search($offset, $this->ufn);
        }
        return is_int($offset) ? $this->docs[$offset] ?? null : null;
    }
    function offsetSet($offset, $value) {
        throw new Exception("invalid DocumentInfoSet::offsetSet");
    }
    function offsetUnset($offset) {
        throw new Exception("invalid DocumentInfoSet::offsetUnset");
    }

    /** @return string|false */
    private function _tmpdir() {
        if ($this->_tmpdir === null
            && ($this->_tmpdir = tempdir()) === false) {
            $this->_errors_html[] = "Could not create temporary directory.";
        }
        return $this->_tmpdir;
    }
    private function _hotzip_progress() {
        // assign local headers
        $this->_zipi = $this->_zipi ?? [];
        $i = count($this->_zipi);
        $offset = $i === 0 ? 0 : $this->_zipi[$i - 1]->local_end_offset();
        DocumentInfo::prefetch_crc32(array_slice($this->docs, $i));
        while ($i !== count($this->docs)) {
            $this->_zipi[] = $zi = new DocumentInfoSet_ZipInfo;
            $doc = $this->docs[$i];
            $fn = $this->ufn[$i];
            $zi->local_offset = $offset;
            if ($doc->compressible()) {
                $zi->compression = 8;
                $zi->compressed = gzdeflate($doc->content());
                $zi->compressed_length = strlen($zi->compressed);
            } else {
                $zi->compression = 0;
                $zi->compressed_length = $doc->size();
            }
            if ($doc->timestamp > 1) {
                $dt = new DateTime("@" . (int) $doc->timestamp, $doc->conf->timezone());
                if (($y = (int) $dt->format("Y")) > 1980) {
                    $zi->date = (int) $dt->format("j")
                        | ((int) $dt->format("n") << 5)
                        | (($y - 1980) << 9);
                    $zi->time = ((int) $dt->format("s") >> 1)
                        | ((int) $dt->format("i") << 5)
                        | ((int) $dt->format("G") << 10);
                }
            }
            if ($zi->compressed_length >= 0xFFFFFFFF
                || $doc->size() >= 0xFFFFFFFF) {
                $ex = pack("vvPP",
                    1,         // ZIP64 tag
                    8 + 8,     // extra block length
                    $doc->size(), // uncompressed size
                    $zi->compressed_length); // compressed size
                $zi->zip64 = true;
            } else {
                $ex = "";
            }
            $zi->localh = pack("VvvvvvVVVvv",
                0x04034b50,    // local file header signature
                !$zi->zip64 ? 10 : 45, // version needed to extract
                1 << 11,       // general purpose bit flag (UTF-8 filename)
                $zi->compression, // compression method
                $zi->time,     // last mod file time
                $zi->date,     // last mod file date
                $doc->integer_crc32(), // crc-32
                !$zi->zip64 ? $zi->compressed_length : 0xFFFFFFFF, // compressed size
                !$zi->zip64 ? $doc->size() : 0xFFFFFFFF, // uncompressed size
                strlen($fn),   // file name length
                strlen($ex)    // extra field length
            ) . $fn . $ex;
            $offset = $zi->local_end_offset();
            ++$i;
        }
    }
    private function _hotzip_final() {
        // assign central headers
        assert(count($this->_zipi) === count($this->docs));
        $n = count($this->_zipi);
        $offset = $n === 0 ? 0 : $this->_zipi[$n - 1]->local_end_offset();
        $central_offset = $offset;
        $zip64 = false;
        for ($i = 0; $i !== $n; ++$i) {
            $zi = $this->_zipi[$i];
            $doc = $this->docs[$i];
            $fn = $this->ufn[$i];
            $zi->central_offset = $offset;
            if ($zi->zip64) {
                $ex = pack("vvPPP",
                    1,         // ZIP64 tag
                    8 + 8 + 8, // extra block length
                    $doc->size(), // uncompressed size
                    $zi->compressed_length, // compressed size
                    $zi->local_offset); // relative offset of local header
                $zip64 = true;
            } else if ($zi->local_offset >= 0xFFFFFFFF) {
                $ex = pack("vvP",
                    1,         // ZIP64 tag
                    8,         // extra block length
                    $zi->local_offset); // relative offset of local header
                $zip64 = true;
            } else {
                $ex = "";
            }
            $zi->centralh = pack("VvvvvvvVVVvvvvvVV",
                0x02014b50,     // central file header signature
                0x300 + 45,     // version made by
                $ex === "" ? 10 : 45, // version needed to extract
                1 << 11,        // general purpose bit flag (UTF-8 filename)
                $zi->compression, // compression method
                $zi->time,      // last mod file time
                $zi->date,      // last mod file date
                $doc->integer_crc32(),  // crc-32
                !$zi->zip64 ? $zi->compressed_length : 0xFFFFFFFF, // compressed size
                !$zi->zip64 ? $doc->size() : 0xFFFFFFFF, // uncompressed size
                strlen($fn),    // file name length
                strlen($ex),    // extra field length
                0,              // file comment length
                0,              // disk number start
                Mimetype::textual($doc->mimetype) ? 1 : 0, // internal file attributes
                0100644 << 16,  // external file attributes
                $ex === "" ? $zi->local_offset : 0xFFFFFFFF // relative offset of local header
            ) . $fn . $ex;
            $offset += strlen($zi->centralh);
        }
        // assign final central offset
        $this->_zipi[] = $zi = new DocumentInfoSet_ZipInfo;
        $zi->central_offset = $offset;
        if ($zip64
            || $offset >= 0xFFFFFFFF
            || count($this->docs) >= 0xFFFF) {
            $ex = pack("VPvvVVPPPP",
                0x06064b50,   // zip64 end of central dir signature
                4 + 4 + 4 + 8 + 8 + 8 + 8, // size of this record
                0x300 + 45,   // version made by
                45,           // version needed to extract
                0,            // number of this disk
                0,            // number of the disk with the start of the central dir
                count($this->docs), // total number of entries in central dir this disk
                count($this->docs), // total number of entries in central dir
                $offset - $central_offset, // size of central dir
                $central_offset // offset of central dir this disk
            ) . pack("VVPV",
                0x07064b50,   // zip64 end of central dir locator
                0,            // number of disk with start of zip64 end of central dir
                $offset,      // offset of zip64 end of central dir
                1);           // total number of disks
            $zip64 = true;
        } else {
            $ex = "";
        }
        $zi->centralh = $ex . pack("VvvvvVVv",
            0x06054b50,       // end of central dir signature
            0,                // number of this disk
            0,                // number of the disk with the start of the central dir
            min(count($this->docs), 0xFFFF), // total entries in central dir this disk
            min(count($this->docs), 0xFFFF), // total entries in central dir
            $offset - $central_offset, // size of central dir
            min($central_offset, 0xFFFFFFFF), // offset of start of central dir
            0                 // .ZIP file comment length
        );
    }
    /** @return int */
    private function _hotzip_filesize() {
        $nz = count($this->_zipi);
        assert($nz === count($this->docs) + 1);
        return $this->_zipi[$nz - 1]->central_end_offset();
    }
    private function _hotzip_make() {
        $this->_hotzip_progress();
        if (!empty($this->_errors_html)) {
            $this->add_string_as(Text::html_to_text(join("\n", $this->_errors_html) . "\n"), "README-warnings.txt");
            $this->_hotzip_progress();
        }
        $this->_hotzip_final();
    }
    /** @return string */
    private function content_signature() {
        if ($this->_signature === null) {
            $s = count($this->docs) . "\n";
            foreach ($this->docs as $doc) {
                $s .= $doc->member_filename() . "\n" . $doc->text_hash() . "\n";
            }
            if (!empty($this->_errors_html)) {
                $s .= "README-warnings.txt\nsha2-" . hash("sha256", join("\n", $this->_errors_html)) . "\n";
            }
            $this->_signature = "content.sha2-" . hash("sha256", $s);
        }
        return $this->_signature;
    }
    /** @return ?DocumentInfo */
    function make_zip_document() {
        if (($dstore_tmp = Filer::docstore_tmpdir($this->conf))) {
            $this->_filestore = $dstore_tmp . $this->content_signature() . ".zip";
            // maybe zipfile with that signature already exists
            if (file_exists($this->_filestore)) {
                if (@filemtime($this->_filestore) < Conf::$now - 21600) {
                    @touch($this->_filestore);
                }
                return $this->_make_success_document();
            }
        }

        // otherwise, need to create new .zip
        if (!$this->_filestore) {
            if (!($tmpdir = $this->_tmpdir())) {
                $this->add_error_html("Cannot create temporary directory.");
                return null;
            }
            $this->_filestore = $tmpdir . "/_hotcrp.zip";
        }

        if (!($out = fopen($this->_filestore . "~", "wb"))) {
            $this->add_error_html("Cannot create temporary file.");
            return null;
        }

        $this->_hotzip_make();
        DocumentInfo::prefetch_content($this->docs);

        // write data to stream
        for ($i = 0; $i !== count($this->docs); ++$i) {
            $zi = $this->_zipi[$i];
            $doc = $this->docs[$i];
            $sz = fwrite($out, $zi->localh);
            if ($zi->compressed !== null) {
                $sz += fwrite($out, $zi->compressed);
            } else if (($f = $doc->available_content_file())) {
                $filesize = $doc->size();
                $sz += Filer::readfile_subrange($out, 0, $filesize, 0, $f, $filesize);
            } else {
                $sz += fwrite($out, $doc->content());
            }
            if ($sz !== $zi->local_end_offset() - $zi->local_offset) {
                $this->add_error_html("Failure writing {$this->ufn[$i]}, wrote $sz, expected " . ($zi->local_end_offset() - $zi->local_offset) . ".");
                fclose($out);
                return null;
            }
        }
        $sz = 0;
        foreach ($this->_zipi as $zi) {
            $sz += fwrite($out, $zi->centralh);
        }
        fclose($out);
        if ($sz !== $this->_hotzip_filesize() - $this->_zipi[0]->central_offset) {
            $this->add_error_html("Failure creating temporary file (dir @{$this->_zipi[0]->central_offset}).");
            return null;
        }

        // success
        rename($this->_filestore . "~", $this->_filestore);
        return $this->_make_success_document();
    }
    /** @return DocumentInfo */
    private function _make_success_document() {
        return new DocumentInfo([
            "filename" => $this->_filename,
            "mimetype" => $this->_mimetype ?? "application/zip",
            "documentType" => DTYPE_EXPORT,
            "content_file" => $this->_filestore
        ], $this->conf);
    }
    /** @param resource $out
     * @param int $r0
     * @param int $r1 */
    private function _write_range($out, $r0, $r1) {
        $d0 = 0;
        while ($d0 !== count($this->docs)
               && $this->_zipi[$d0]->local_end_offset() <= $r0) {
            ++$d0;
        }
        $pfd0 = $d1 = $d0;
        while ($d1 !== count($this->docs)
               && $this->_zipi[$d1]->local_offset < $r1) {
            ++$d1;
        }
        while ($d0 !== $d1) {
            set_time_limit(120);
            if ($pfd0 === $d0) {
                $pfd0 = min($d0 + 12, $d1);
                DocumentInfo::prefetch_content(array_slice($this->docs, $d0, $pfd0 - $d0));
            }
            $zi = $this->_zipi[$d0];
            $doc = $this->docs[$d0];
            $p0 = $zi->local_offset;
            $p0 += Filer::echo_subrange($out, $r0, $r1, $p0, $zi->localh);
            if ($zi->compressed !== null) {
                $p0 += Filer::echo_subrange($out, $r0, $r1, $p0, $zi->compressed);
            } else if (($f = $doc->available_content_file())) {
                $p0 += Filer::readfile_subrange($out, $r0, $r1, $p0, $f, $doc->size());
            } else {
                $p0 += Filer::echo_subrange($out, $r0, $r1, $p0, $doc->content());
            }
            if ($p0 < min($r1, $zi->local_end_offset())) {
                throw new Exception("Failure writing {$this->ufn[$d0]}, wrote " . ($p0 - $zi->local_offset) . ", expected " . (min($r1, $zi->local_end_offset()) - $zi->local_offset));
            }
            ++$d0;
        }
        if ($d0 === count($this->docs)) {
            foreach ($this->_zipi as $zi) {
                Filer::echo_subrange($out, $r0, $r1, $zi->central_offset, $zi->centralh);
            }
        }
    }
    /** @return bool */
    private function _download_directly($opts = []) {
        $opts["etag"] = "\"" . $this->content_signature() . "\"";
        if (isset($opts["if-none-match"])
            && $opts["if-none-match"] === $opts["etag"]) {
            header("HTTP/1.1 304 Not Modified");
            return true;
        }

        $this->_hotzip_make();
        $filesize = $this->_hotzip_filesize();

        if (!Filer::check_download_opts($filesize, $opts)) {
            return true;
        }

        // Print headers
        if (zlib_get_coding_type() !== false) {
            ini_set("zlib.output_compression", "0");
        }
        $mimetype = Mimetype::type_with_charset($this->_mimetype);
        if (!isset($opts["range"])) {
            if (isset($opts["attachment"])) {
                $attachment = $opts["attachment"];
            } else {
                $attachment = !Mimetype::disposition_inline($this->_mimetype);
            }
            header("Content-Disposition: " . ($attachment ? "attachment" : "inline") . "; filename=" . mime_quote_string($this->_filename));
            // reduce likelihood of XSS attacks in IE
            header("X-Content-Type-Options: nosniff");
        }
        if ($opts["cacheable"] ?? false) {
            header("Cache-Control: max-age=315576000, private");
            header("Expires: " . gmdate("D, d M Y H:i:s", Conf::$now + 315576000) . " GMT");
        }
        $out = fopen("php://output", "wb");
        foreach (Filer::download_ranges($filesize, $mimetype, $opts) as $r) {
            $this->_write_range($out, $r[0], $r[1]);
        }
        fclose($out);
        return true;
    }

    /** @return bool */
    function download($opts = []) {
        if (!$this->_filename) {
            throw new Exception("trying to download blank-named DocumentInfoSet");
        }
        if (count($this->docs) === 1
            && empty($this->_errors_html)
            && ($opts["single"] ?? false)) {
            if ($this->docs[0]->download($opts)) {
                return true;
            } else {
                $this->add_error_html($this->docs[0]->error_html);
                return false;
            }
        } else {
            return $this->_download_directly($opts);
        }
    }
}
