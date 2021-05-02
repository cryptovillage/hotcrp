<?php
// search/st_formula.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2021 Eddie Kohler; see LICENSE.

class Formula_SearchTerm extends SearchTerm {
    /** @var Contact */
    private $user;
    /** @var Formula */
    private $formula;
    private $function;
    function __construct(Formula $formula) {
        parent::__construct("formula");
        $this->user = $formula->user;
        $this->formula = $formula;
        $this->function = $formula->compile_function();
    }
    static private function read_formula($word, $quoted, $is_graph, PaperSearch $srch) {
        $formula = null;
        if (preg_match('/\A[^(){}\[\]]+\z/', $word)) {
            $formula = $srch->conf->find_named_formula($word);
        }
        if (!$formula) {
            $formula = new Formula($word, $is_graph ? Formula::ALLOW_INDEXED : 0);
        }
        if (!$formula->check($srch->user)) {
            $srch->warning("Formula error: " . $formula->error_html());
            $formula = null;
        }
        return $formula;
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        if (($formula = self::read_formula($word, $sword->quoted, false, $srch))) {
            return new Formula_SearchTerm($formula);
        }
        return new False_SearchTerm;
    }
    static function parse_graph($word, SearchWord $sword, PaperSearch $srch) {
        if (($formula = self::read_formula($word, $sword->quoted, true, $srch))) {
            return SearchTerm::make_float(["view" => [["graph($word)", "show"]]]);
        }
        return null;
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $this->formula->add_query_options($sqi->query_options);
        return "true";
    }
    function test(PaperInfo $row, $rrow) {
        $formulaf = $this->function;
        return !!$formulaf($row, $rrow ? $rrow->contactId : null, $this->user);
    }
}
