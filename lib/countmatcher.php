<?php
// countmatcher.php -- HotCRP helper class for textual comparators
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class CountMatcher {
    /** @var int */
    private $op = 0;
    /** @var float */
    private $value = 0.0;

    static public $opmap = ["" => 2, "#" => 2, "=" => 2, "==" => 2,
                            "!" => 5, "!=" => 5, "≠" => 5,
                            "<" => 1, "<=" => 3, "≤" => 3,
                            "≥" => 6, ">=" => 6, ">" => 4];
    static public $oparray = [null, "<", "=", "<=", ">", "!=", ">=", null];

    /** @param string $s
     * @return ?array{string,int,float} */
    static function unpack_comparison($s) {
        if (preg_match('/(?::\s*|(?=[=!<>\xE2]))(|[=!<>]=?|≤|≥|≠)\s*([-+]?(?:\d+\.?\d*|\.\d+))\s*\z/', $s, $m)) {
            $p = rtrim(substr($s, 0, -strlen($m[0])));
            return [$p, self::$opmap[$m[1]], (float) $m[2]];
        } else {
            return null;
        }
    }
    /** @param string $s
     * @return ?array{string,int,int} */
    static function unpack_int_comparison($s) {
        if (preg_match('/(?::\s*|(?=[=!<>\xE2]))(|[=!<>]=?|≤|≥|≠)\s*([-+]?\d+)\s*\z/', $s, $m)) {
            $p = rtrim(substr($s, 0, -strlen($m[0])));
            return [$p, self::$opmap[$m[1]], (int) $m[2]];
        } else {
            return null;
        }
    }
    /** @param string $s
     * @return array{string,int,int} */
    static function unpack_search_comparison($s) {
        if ($s === "" || $s === "any" || $s === "yes") {
            return ["", 4, 0];
        } else if ($s === "none" || $s === "no") {
            return ["", 2, 0];
        } else if (preg_match('/(?::\s*|(?=[=!<>\xE2]))(|[=!<>]=?|≤|≥|≠)\s*([-+]?\d+)\s*\z/', $s, $m)) {
            $p = rtrim(substr($s, 0, -strlen($m[0])));
            $a = self::$opmap[$m[1]];
            $v = (int) $m[2];
        } else if (preg_match('/:\s*(any|none)\s*\z/', $s, $m)) {
            $p = rtrim(substr($s, 0, -strlen($m[0])));
            $a = $m[1] === "any" ? 4 : 2;
            $v = 0;
        } else if (ctype_digit($s)) {
            return ["", 4, (int) $s];
        } else {
            $p = $s;
            $a = 4;
            $v = 0;
        }
        if ($p !== "" && $p[0] === "\"") {
            $p = SearchWord::unquote($p);
        } else if ($p === "any") {
            $p = "";
        }
        return [$p, $a, $v];
    }
    /** @param string $s
     * @return ?array{int,float} */
    static function parse_comparison($s) {
        if (preg_match('/\A(|[=!<>]=?|≠|≤|≥)\s*([-+]?(?:\d+\.?\d*|\.\d+))\z/', $s, $m)) {
            return [self::$opmap[$m[1]], (float) $m[2]];
        } else {
            return null;
        }
    }
    /** @param int $relation
     * @param int|float $value
     * @return string */
    static function unparse_comparison($relation, $value) {
        return self::$oparray[$relation] . $value;
    }

    /** @param string $s */
    function __construct($s) {
        if ((string) $s !== "" && !$this->set_comparison($s)) {
            error_log(caller_landmark() . ": bogus countexpr $s");
        }
    }
    /** @param int $relation
     * @param int|float $value
     * @return CountMatcher */
    static function make($relation, $value) {
        $cm = new CountMatcher("");
        $cm->op = $relation;
        $cm->value = $value;
        return $cm;
    }
    /** @param int $relation
     * @param float $value */
    function set_relation_value($relation, $value) {
        $this->op = $relation;
        $this->value = $value;
    }
    /** @param string $s */
    function set_comparison($s) {
        if (($a = self::parse_comparison($s))) {
            $this->op = $a[0];
            $this->value = $a[1];
            return true;
        } else {
            return false;
        }
    }
    /** @return bool */
    function ok() {
        return $this->op !== 0;
    }
    /** @param int|float $n
     * @return bool */
    function test($n) {
        return self::compare($n, $this->op, $this->value);
    }
    /** @param array<mixed,int|float> $x
     * @return array<mixed,int|float> */
    function filter($x) {
        return array_filter($x, [$this, "test"]);
    }
    /** @param int|float $x
     * @param int|string $compar
     * @param int|float $y
     * @return bool */
    static function compare($x, $compar, $y) {
        if (!is_int($compar)) {
            $compar = self::$opmap[$compar];
        }
        $delta = $x - $y;
        if ($delta > 0.000001) {
            return ($compar & 4) !== 0;
        } else if ($delta > -0.000001) {
            return ($compar & 2) !== 0;
        } else {
            return ($compar & 1) !== 0;
        }
    }
    static function sqlexpr_using($compar_y) {
        if (is_array($compar_y)) {
            if (empty($compar_y)) {
                return "=NULL";
            } else {
                return " in (" . join(",", $compar_y) . ")";
            }
        } else {
            return $compar_y;
        }
    }
    /** @param int|float $x
     * @param list<int|float>|string $compar_y
     * @return bool */
    static function compare_using($x, $compar_y) {
        if (is_array($compar_y)) {
            return in_array($x, $compar_y);
        } else if (preg_match('/\A([=!<>]=?|≠|≤|≥)\s*(-?(?:\.\d+|\d+\.?\d*))\z/', $compar_y, $m)) {
            return self::compare($x, $m[1], (float) $m[2]);
        } else {
            return false;
        }
    }
    static function filter_using($x, $compar_y) {
        if (is_array($compar_y)) {
            return array_intersect($x, $compar_y);
        } else {
            $cm = new CountMatcher($compar_y);
            return $cm->filter($x);
        }
    }
    /** @return string */
    function relation() {
        assert(!!$this->op);
        return self::$oparray[$this->op];
    }
    /** @return int|float */
    function value() {
        return $this->value;
    }
    /** @return string */
    function comparison() {
        assert(!!$this->op);
        return self::$oparray[$this->op] . $this->value;
    }
    /** @return string */
    function simplified_nonnegative_comparison() {
        if ($this->value === 1.0 && $this->op === 6) {
            return ">0";
        } else if (($this->value === 1.0 && $this->op === 1)
                   || ($this->value === 0.0 && $this->op === 3)) {
            return "=0";
        } else {
            return $this->comparison();
        }
    }
    /** @return string */
    function conservative_nonnegative_comparison() {
        if ($this->op & 1) {
            return ">=0";
        } else {
            return ($this->op & 2 ? ">=" : ">") . $this->value;
        }
    }
    /** @param string $str
     * @return string */
    static function flip_comparator($str) {
        $t = new CountMatcher($str);
        if ($t->op & 5) {
            return self::$oparray[$t->op ^ 5] . $t->value;
        } else {
            return $str;
        }
    }
    /** @param string $str
     * @return ?int */
    static function parse_relation($str) {
        return self::$opmap[$str] ?? null;
    }
    /** @param int $relation
     * @return ?string */
    static function unparse_relation($relation) {
        return self::$oparray[$relation] ?? null;
    }
    /** @param string $str
     * @return ?string */
    static function canonical_relation($str) {
        if (($x = self::$opmap[trim($str)])) {
            return self::$oparray[$x];
        } else {
            return null;
        }
    }
    /** @param string $comparison
     * @return ?string */
    static function canonicalize($comparison) {
        if (($a = self::parse_comparison($comparison))) {
            return self::$oparray[$a[0]] . $a[1];
        } else {
            return null;
        }
    }
}
