<?php
// pc_shepherd.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class Shepherd_PaperColumn extends PaperColumn {
    private $ianno;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->override = PaperColumn::OVERRIDE_IFEMPTY;
    }
    function add_decoration($decor) {
        return parent::add_user_sort_decoration($decor) || parent::add_decoration($decor);
    }
    function prepare(PaperList $pl, $visible) {
        return $pl->user->can_view_shepherd(null)
            && ($pl->conf->has_any_lead_or_shepherd() || $visible);
    }
    static private function cid(PaperList $pl, PaperInfo $row) {
        if ($row->shepherdContactId > 0 && $pl->user->can_view_shepherd($row)) {
            return $row->shepherdContactId;
        } else {
            return 0;
        }
    }
    function prepare_sort(PaperList $pl, $sortindex) {
        $this->ianno = Contact::parse_sortspec($pl->conf, $this->decorations);
    }
    function compare(PaperInfo $a, PaperInfo $b, PaperList $pl) {
        return $pl->_compare_pc(self::cid($pl, $a), self::cid($pl, $b), $this->ianno);
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !self::cid($pl, $row);
    }
    function content(PaperList $pl, PaperInfo $row) {
        return $pl->_content_pc($row->shepherdContactId);
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $pl->_text_pc($row->shepherdContactId);
    }
}
