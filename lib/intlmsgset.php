<?php
// intlmsg.php -- HotCRP helper functions for message i18n
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class IntlMsg {
    /** @var ?string */
    public $context;
    /** @var string */
    public $otext;
    public $require;
    /** @var float */
    public $priority = 0.0;
    /** @var ?int */
    public $format;
    /** @var bool */
    public $no_conversions = false;
    /** @var bool */
    public $template = false;
    /** @var ?IntlMsg */
    public $next;

    private function resolve_arg(IntlMsgSet $ms, $args, $argname, &$val) {
        $component = false;
        if (strpos($argname, "[") !== false
            && preg_match('/\A(.*?)\[([^\]]*)\]\z/', $argname, $m)) {
            $argname = $m[1];
            $component = $m[2];
        }
        if ($argname[0] === "\$") {
            $which = substr($argname, 1);
            if (ctype_digit($which)) {
                $val = $args[+$which] ?? null;
            } else {
                return false;
            }
        } else if (($ans = $ms->resolve_requirement_argument($argname))) {
            $val = is_array($ans) ? $ans[0] : $ans;
        } else {
            return false;
        }
        if ($component !== false) {
            if (is_array($val)) {
                $val = $val[$component] ?? null;
            } else if (is_object($val)) {
                $val = $val->$component ?? null;
            } else {
                return false;
            }
        }
        return true;
    }
    function check_require(IntlMsgSet $ms, $args) {
        if (!$this->require) {
            return 0;
        }
        $nreq = 0;
        foreach ($this->require as $req) {
            if (preg_match('/\A\s*(!*)\s*(\S+?)\s*(\z|[=!<>]=?|≠|≤|≥|!?\^=)\s*(\S*)\s*\z/', $req, $m)
                && ($m[1] === "" || ($m[3] === "" && $m[4] === ""))
                && ($m[3] === "") === ($m[4] === "")) {
                if (!$this->resolve_arg($ms, $args, $m[2], $val)) {
                    return false;
                }
                $compar = $m[3];
                $compval = $m[4];
                if ($compar === "") {
                    $bval = (bool) $val && $val !== "0";
                    $weight = $bval === (strlen($m[1]) % 2 === 0) ? 1 : 0;
                } else if (!is_scalar($val)) {
                    $weight = 0;
                } else if ($compar === "^=") {
                    $weight = str_starts_with($val, $compval) ? 0.9 : 0;
                } else if ($compar === "!^=") {
                    $weight = !str_starts_with($val, $compval) ? 0.9 : 0;
                } else if (is_numeric($compval)) {
                    $weight = CountMatcher::compare((float) $val, $compar, (float) $compval) ? 1 : 0;
                } else if ($compar === "=" || $compar === "==") {
                    $weight = (string) $val === (string) $compval ? 1 : 0;
                } else if ($compar === "!=" || $compar === "≠") {
                    $weight = (string) $val === (string) $compval ? 0 : 1;
                } else {
                    $weight = 0;
                }
                if ($weight === 0) {
                    return false;
                }
                $nreq += $weight;
            } else if (($weight = $ms->resolve_requirement($req)) !== null) {
                if ($weight <= 0) {
                    return false;
                }
                $nreq += $weight;
            }
        }
        return $nreq;
    }
}

class IntlMsgSet {
    /** @var array<string,IntlMsg> */
    private $ims = [];
    private $require_resolvers = [];
    private $_context_prefix;
    private $_default_priority;
    private $_default_format;
    private $_recursion = 0;

    const PRIO_OVERRIDE = 1000.0;

    function set_default_priority($p) {
        $this->_default_priority = (float) $p;
    }
    function clear_default_priority() {
        $this->_default_priority = null;
    }

    function add($m, $ctx = null) {
        if (is_string($m)) {
            $x = $this->addj(func_get_args());
        } else if (!$ctx) {
            $x = $this->addj($m);
        } else {
            $octx = $this->_context_prefix;
            $this->_context_prefix = $ctx;
            $x = $this->addj($m);
            $this->_context_prefix = $octx;
        }
        return $x;
    }

    /** @param object $m
     * @return bool */
    private function _addj_object($m) {
        if (isset($m->members) && is_array($m->members)) {
            $octx = $this->_context_prefix;
            $oprio = $this->_default_priority;
            $ofmt = $this->_default_format;
            if (isset($m->context) && is_string($m->context)) {
                $cp = $this->_context_prefix ?? "";
                $this->_context_prefix = ($cp === "" ? $m->context : $cp . "/" . $m->context);
            }
            if (isset($m->priority) && (is_int($m->priority) || is_float($m->priority))) {
                $this->_default_priority = (float) $m->priority;
            }
            if (isset($m->format) && is_int($m->format)) {
                $this->_default_format = $m->format;
            }
            $ret = true;
            foreach ($m->members as $mm) {
                $ret = $this->addj($mm) && $ret;
            }
            $this->_context_prefix = $octx;
            $this->_default_priority = $oprio;
            $this->_default_format = $ofmt;
            return $ret;
        } else {
            $im = new IntlMsg;
            if (isset($m->context) && is_string($m->context)) {
                $im->context = $m->context;
            }
            if (isset($m->id) && is_string($m->id)) {
                $itext = $m->id;
            } else if (isset($m->itext) && is_string($m->itext)) {
                $itext = $m->itext;
            } else {
                return false;
            }
            if (isset($m->otext) && is_string($m->otext)) {
                $im->otext = $m->otext;
            } else if (isset($m->itext) && is_string($m->itext)) {
                $im->otext = $m->itext;
            } else {
                return false;
            }
            if (isset($m->priority) && (is_float($m->priority) || is_int($m->priority))) {
                $im->priority = (float) $m->priority;
            }
            if (isset($m->require) && is_array($m->require)) {
                $im->require = $m->require;
            }
            if (isset($m->format) && (is_int($m->format) || is_string($m->format))) {
                $im->format = $m->format;
            }
            if (isset($m->no_conversions) && is_bool($m->no_conversions)) {
                $im->no_conversions = $m->no_conversions;
            }
            if (isset($m->template) && is_bool($m->template)) {
                $im->template = $m->template;
            }
            $this->_addj_finish($itext, $im);
            return true;
        }
    }

    /** @param array{string,string} $m */
    private function _addj_list($m) {
        $im = new IntlMsg;
        $n = count($m);
        $p = false;
        while ($n > 0 && !is_string($m[$n - 1])) {
            if ((is_int($m[$n - 1]) || is_float($m[$n - 1])) && $p === false) {
                $p = $im->priority = (float) $m[$n - 1];
            } else if (is_array($m[$n - 1]) && $im->require === null) {
                $im->require = $m[$n - 1];
            } else {
                return false;
            }
            --$n;
        }
        if ($n < 2 || $n > 3 || !is_string($m[0]) || !is_string($m[1])
            || ($n === 3 && !is_string($m[2]))) {
            return false;
        }
        if ($n === 3) {
            $im->context = $m[0];
            $itext = $m[1];
            $im->otext = $m[2];
        } else {
            $itext = $m[0];
            $im->otext = $m[1];
        }
        $this->_addj_finish($itext, $im);
        return true;
    }

    /** @param string $itext
     * @param IntlMsg $im */
    private function _addj_finish($itext, $im) {
        if ($this->_context_prefix) {
            $im->context = $this->_context_prefix . ($im->context ? "/" . $im->context : "");
        }
        $im->priority = $im->priority ?? $this->_default_priority;
        $im->format = $im->format ?? $this->_default_format;
        $im->next = $this->ims[$itext] ?? null;
        $this->ims[$itext] = $im;
    }

    /** @param array{string,string}|array{string,string,int}|object|array<string,mixed> $m */
    function addj($m) {
        if (is_associative_array($m)) {
            return $this->_addj_object((object) $m);
        } else if (is_array($m)) {
            return $this->_addj_list($m);
        } else if (is_object($m)) {
            return $this->_addj_object($m);
        } else  {
            return false;
        }
    }

    /** @param string $id
     * @param string $otext */
    function add_override($id, $otext) {
        $im = $this->ims[$id] ?? null;
        return $this->addj(["id" => $id, "otext" => $otext, "priority" => self::PRIO_OVERRIDE, "no_conversions" => true, "template" => $im && $im->template]);
    }

    function add_requirement_resolver($function) {
        $this->require_resolvers[] = $function;
    }
    function resolve_requirement($requirement) {
        foreach ($this->require_resolvers as $fn) {
            if (($x = call_user_func($fn, $requirement, true)) !== null) {
                return $x;
            }
        }
        return null;
    }
    function resolve_requirement_argument($argname) {
        foreach ($this->require_resolvers as $fn) {
            if (($x = call_user_func($fn, $argname, false)) !== null) {
                return $x;
            }
        }
        return null;
    }

    /** @param ?string $context
     * @param string $itext
     * @param list<mixed> $args
     * @param ?float $priobound
     * @return ?IntlMsg */
    private function find($context, $itext, $args, $priobound) {
        assert(is_string($args[0]));
        if (++$this->_recursion > 5) {
            throw new Exception("too much recursion");
        }
        $match = null;
        $matchnreq = $matchctxlen = 0;
        if ($context === "") {
            $context = null;
        }
        for ($im = $this->ims[$itext] ?? null; $im; $im = $im->next) {
            $ctxlen = $nreq = 0;
            if ($context !== null && $im->context !== null) {
                if ($context === $im->context) {
                    $ctxlen = 10000;
                } else {
                    $ctxlen = strlen($im->context);
                    if ($ctxlen > strlen($context)
                        || strncmp($context, $im->context, $ctxlen) !== 0
                        || $context[$ctxlen] !== "/") {
                        continue;
                    }
                }
            } else if ($context === null && $im->context !== null) {
                continue;
            }
            if ($im->require
                && ($nreq = $im->check_require($this, $args)) === false) {
                continue;
            }
            if ($priobound !== null
                && $im->priority >= $priobound) {
                continue;
            }
            if (!$match
                || $im->priority > $match->priority
                || ($im->priority == $match->priority
                    && ($ctxlen > $matchctxlen
                        || ($ctxlen == $matchctxlen
                            && $nreq > $matchnreq)))) {
                $match = $im;
                $matchnreq = $nreq;
                $matchctxlen = $ctxlen;
            }
        }
        --$this->_recursion;
        return $match;
    }

    /** @param string $s
     * @param list<mixed> $args
     * @param ?string $context
     * @param ?IntlMsg $im
     * @param ?int $format
     * @return string */
    private function expand($s, $args, $context, $im, $format) {
        if ($s === null || $s === false || $s === "") {
            return $s;
        }
        $pos = strpos($s, "%");
        $argnum = 0;
        while ($pos !== false) {
            ++$pos;
            if (preg_match('/(?!\d+)\w+(?=%)/A', $s, $m, 0, $pos)
                && ($imt = $this->find($context, strtolower($m[0]), [$m[0]], null))
                && $imt->template) {
                $t = substr($s, 0, $pos - 1);
                ++$this->_recursion;
                if ($this->_recursion < 5) {
                    $t .= $this->expand($imt->otext, $args, null, null, $format);
                } else {
                    error_log("RECURSION ERROR ON {$m[0]} " . debug_string_backtrace());
                }
                --$this->_recursion;
                $s = $t . substr($s, $pos + strlen($m[0]) + 1);
                $pos = strlen($t);
            } else if (($im && $im->no_conversions) || count($args) === 1) {
                /* do nothing */
            } else if ($pos < strlen($s) && $s[$pos] === "%") {
                $s = substr($s, 0, $pos) . substr($s, $pos + 1);
            } else if (preg_match('/(?:(\d+)(\[[^\[\]\$]*\]|)\$)?(\d*(?:\.\d+)?)([deEifgosxXHU])/A', $s, $m, 0, $pos)) {
                $argi = $m[1] ? +$m[1] : ++$argnum;
                if (isset($args[$argi])) {
                    $val = $args[$argi];
                    if ($m[2]) {
                        assert(is_array($val));
                        $val = $val[substr($m[2], 1, -1)] ?? null;
                    }
                    $conv = $m[3] . ($m[4] === "H" || $m[4] === "U" ? "s" : $m[4]);
                    $x = sprintf("%$conv", $val);
                    if ($m[4] === "H") {
                        $x = htmlspecialchars($x);
                    } else if ($m[4] === "U") {
                        $x = urlencode($x);
                    }
                    $s = substr($s, 0, $pos - 1) . $x . substr($s, $pos + strlen($m[0]));
                    $pos = $pos - 1 + strlen($x);
                }
            }
            $pos = strpos($s, "%", $pos);
        }
        return $s;
    }

    /** @return string */
    function _(...$args) {
        if (($im = $this->find(null, $args[0], $args, null))) {
            $args[0] = $im->otext;
        }
        return $this->expand($args[0], $args, null, $im, null);
    }

    /** @param string $context
     * @return string */
    function _c($context, ...$args) {
        if (($im = $this->find($context, $args[0], $args, null))) {
            $args[0] = $im->otext;
        }
        return $this->expand($args[0], $args, $context, $im, null);
    }

    /** @param string $id
     * @return string */
    function _i($id, ...$args) {
        $args[0] = $args[0] ?? "";
        if (($im = $this->find(null, $id, $args, null))
            && ($args[0] === "" || $im->priority > 0.0)) {
            $args[0] = $im->otext;
        }
        return $this->expand($args[0], $args, $id, $im, null);
    }

    /** @param string $context
     * @param string $id
     * @return string */
    function _ci($context, $id, ...$args) {
        $args[0] = $args[0] ?? "";
        if (($im = $this->find($context, $id, $args, null))
            && ($args[0] === "" || $im->priority > 0.0)) {
            $args[0] = $im->otext;
        }
        $cid = (string) $context === "" ? $id : "$context/$id";
        return $this->expand($args[0], $args, $cid, $im, null);
    }

    /** @param FieldRender $fr
     * @param string $context
     * @param string $id */
    function render_ci($fr, $context, $id, ...$args) {
        $args[0] = $args[0] ?? "";
        if (($im = $this->find($context, $id, $args, null))
            && ($args[0] === "" || $im->priority > 0.0)) {
            $args[0] = $im->otext;
            if ($im->format !== null) {
                $fr->value_format = $im->format;
            }
        }
        $cid = (string) $context === "" ? $id : "$context/$id";
        $fr->value = $this->expand($args[0], $args, $cid, $im, null);
    }

    /** @param string $id
     * @return string */
    function default_itext($id, ...$args) {
        $args[0] = $args[0] ?? "";
        if (($im = $this->find(null, $id, $args, self::PRIO_OVERRIDE))) {
            $args[0] = $im->otext;
        }
        return $args[0];
    }
}
