<?php
// a_review.php -- HotCRP assignment helper classes
// Copyright (c) 2006-2021 Eddie Kohler; see LICENSE.

class Review_Assignable extends Assignable {
    /** @var ?int */
    public $cid;
    /** @var ?int */
    public $_rtype;
    /** @var ?string */
    public $_round;
    /** @var ?int */
    public $_rsubmitted;
    /** @var ?int */
    public $_rnondraft;
    /** @var ?int */
    public $_requested_by;
    /** @var ?string */
    public $_reason;
    /** @var ?int */
    public $_override;
    /** @param ?int $pid
     * @param ?int $cid
     * @param ?int $rtype
     * @param ?string $round */
    function __construct($pid, $cid, $rtype = null, $round = null) {
        $this->type = "review";
        $this->pid = $pid;
        $this->cid = $cid;
        $this->_rtype = $rtype;
        $this->_round = $round;
    }
    /** @return self */
    function fresh() {
        return new Review_Assignable($this->pid, $this->cid);
    }
    /** @param int $x
     * @return $this */
    function set_rsubmitted($x) {
        $this->_rsubmitted = $x;
        return $this;
    }
    /** @param int $x
     * @return $this */
    function set_rnondraft($x) {
        $this->_rnondraft = $x;
        return $this;
    }
    /** @param int $x
     * @return $this */
    function set_requested_by($x) {
        $this->_requested_by = $x;
        return $this;
    }
    /** @param int $reviewId
     * @return ReviewInfo */
    function make_reviewinfo(Conf $conf, $reviewId) {
        $rrow = new ReviewInfo;
        $rrow->conf = $conf;
        $rrow->paperId = $this->pid;
        $rrow->contactId = $this->cid;
        $rrow->reviewType = $this->_rtype;
        $rrow->reviewId = $reviewId;
        $rrow->reviewRound = $this->_round ? (int) $conf->round_number($this->_round, false) : 0;
        $rrow->requestedBy = $this->_requested_by;
        return $rrow;
    }
}

class Review_AssignmentParser extends AssignmentParser {
    private $rtype;
    function __construct(Conf $conf, $aj) {
        parent::__construct($aj->name);
        if ($aj->review_type) {
            $this->rtype = (int) ReviewInfo::parse_type($aj->review_type);
        } else {
            $this->rtype = -1;
        }
    }
    static function load_review_state(AssignmentState $state) {
        if ($state->mark_type("review", ["pid", "cid"], "Review_Assigner::make")) {
            $result = $state->conf->qe("select paperId, contactId, reviewType, reviewRound, reviewSubmitted, timeApprovalRequested, requestedBy from PaperReview where paperId?a", $state->paper_ids());
            while (($row = $result->fetch_row())) {
                $round = $state->conf->round_name((int) $row[3]);
                $ra = new Review_Assignable((int) $row[0], (int) $row[1], (int) $row[2], $round);
                $ra->set_rsubmitted($row[4] > 0 ? 1 : 0);
                $ra->set_rnondraft($row[4] > 0 || $row[5] != 0 ? 1 : 0);
                $ra->set_requested_by((int) $row[6]);
                $state->load($ra);
            }
            Dbl::free($result);
        }
    }
    function load_state(AssignmentState $state) {
        self::load_review_state($state);
        Conflict_AssignmentParser::load_conflict_state($state);
    }
    /** @param CsvRow $req */
    private function make_rdata($req, AssignmentState $state) {
        return ReviewAssigner_Data::make($req, $state, $this->rtype);
    }
    /** @param CsvRow $req */
    function user_universe($req, AssignmentState $state) {
        if ($this->rtype > REVIEW_EXTERNAL) {
            return "pc";
        } else if ($this->rtype == 0
                   || (($rdata = $this->make_rdata($req, $state))
                       && !$rdata->might_create_review())) {
            return "reviewers";
        } else {
            return "any";
        }
    }
    function paper_filter($contact, $req, AssignmentState $state) {
        $rdata = $this->make_rdata($req, $state);
        if ($rdata->might_create_review()) {
            return null;
        } else {
            return $state->make_filter("pid",
                new Review_Assignable(null, $contact->contactId, $rdata->oldtype ? : null, $rdata->oldround));
        }
    }
    function expand_any_user(PaperInfo $prow, $req, AssignmentState $state) {
        $rdata = $this->make_rdata($req, $state);
        if ($rdata->might_create_review()) {
            return false;
        } else {
            $cf = $state->make_filter("cid",
                new Review_Assignable($prow->paperId, null, $rdata->oldtype ? : null, $rdata->oldround));
            return $state->users_by_id(array_keys($cf));
        }
    }
    function expand_missing_user(PaperInfo $prow, $req, AssignmentState $state) {
        return $this->expand_any_user($prow, $req, $state);
    }
    function expand_anonymous_user(PaperInfo $prow, $req, $user, AssignmentState $state) {
        if ($user === "anonymous-new") {
            $suf = "";
            while (($u = $state->user_by_email("anonymous" . $suf))
                   && $state->query(new Review_Assignable($prow->paperId, $u->contactId))) {
                $suf = $suf === "" ? 2 : $suf + 1;
            }
            $user = "anonymous" . $suf;
        }
        if (Contact::is_anonymous_email($user)
            && ($u = $state->user_by_email($user, true, []))) {
            return [$u];
        } else {
            return false;
        }
    }
    function allow_user(PaperInfo $prow, Contact $contact, $req, AssignmentState $state) {
        // User “none” is never allowed
        if (!$contact->contactId) {
            return false;
        }
        // PC reviews must be PC members
        $rdata = $this->make_rdata($req, $state);
        if ($rdata->newtype >= REVIEW_PC && !$contact->is_pc_member()) {
            return $contact->name_h(NAME_E) . " is not a PC member and cannot be assigned a PC review.";
        }
        // Conflict allowed if we're not going to assign a new review
        if ($this->rtype == 0
            || $prow->has_reviewer($contact)
            || !$rdata->might_create_review()) {
            return true;
        }
        // Check whether review assignments are acceptable
        if ($contact->is_pc_member()
            && !$contact->can_accept_review_assignment_ignore_conflict($prow)) {
            return $contact->name_h(NAME_E) . " cannot be assigned to review #{$prow->paperId}.";
        }
        // Conflicts are checked later
        return true;
    }
    function apply(PaperInfo $prow, Contact $contact, $req, AssignmentState $state) {
        $rdata = $this->make_rdata($req, $state);
        if ($rdata->error) {
            return $rdata->error;
        }

        $revmatch = new Review_Assignable($prow->paperId, $contact->contactId);
        $res = $state->remove($revmatch);
        assert(count($res) <= 1);
        $rev = empty($res) ? null : $res[0];

        if ($rev !== null
            && (($rdata->oldtype !== null && $rdata->oldtype !== $rev->_rtype)
                || ($rdata->oldround !== null && $rdata->oldround !== $rev->_round)
                || (!$rdata->newtype && $rev->_rsubmitted))) {
            $state->add($rev);
            return true;
        } else if (!$rdata->newtype
                   || ($rev === null && !$rdata->might_create_review())) {
            return true;
        }

        if ($rev === null) {
            $rev = $revmatch;
            $rev->_rtype = 0;
            $rev->_round = $rdata->newround;
            $rev->_rsubmitted = 0;
            $rev->_rnondraft = 0;
            $rev->_requested_by = $state->user->contactId;
        }
        if (!$rev->_rtype || $rdata->newtype > 0) {
            $rev->_rtype = $rdata->newtype;
        }
        if ($rev->_rtype <= 0) {
            $rev->_rtype = REVIEW_EXTERNAL;
        }
        if ($rev->_rtype === REVIEW_EXTERNAL
            && $state->conf->pc_member_by_id($rev->cid)) {
            $rev->_rtype = REVIEW_PC;
        }
        if ($rdata->newround !== null && $rdata->explicitround) {
            $rev->_round = $rdata->newround;
        }
        if ($rev->_rtype && isset($req["reason"])) {
            $rev->_reason = $req["reason"];
        }
        if (isset($req["override"]) && friendly_boolean($req["override"])) {
            $rev->_override = 1;
        }
        $state->add($rev);
        return true;
    }
}

class Review_Assigner extends Assigner {
    private $rtype;
    private $notify = false;
    private $unsubmit = false;
    private $token = false;
    function __construct(AssignmentItem $item, AssignmentState $state) {
        parent::__construct($item, $state);
        $this->rtype = $item->post("_rtype");
        $this->unsubmit = $item->pre("_rnondraft") && !$item->post("_rnondraft");
        if (!$item->existed()
            && $this->rtype == REVIEW_EXTERNAL
            && !$this->contact->is_anonymous_user()
            && ($notify = $state->defaults["extrev_notify"] ?? null)) {
            $this->notify = $notify;
        }
    }
    static function make(AssignmentItem $item, AssignmentState $state) {
        if (!$item->pre("_rtype") && $item->post("_rtype")) {
            Conflict_Assigner::check_unconflicted($item, $state);
        }
        return new Review_Assigner($item, $state);
    }
    function unparse_description() {
        return "review";
    }
    private function unparse_item(AssignmentSet $aset, $before) {
        if (!$this->item->get($before, "_rtype")) {
            return "";
        }
        $t = $aset->user->reviewer_html_for($this->contact) . ' '
            . review_type_icon($this->item->get($before, "_rtype"),
                               !$this->item->get($before, "_rsubmitted"));
        if (($round = $this->item->get($before, "_round"))) {
            $t .= ' <span class="revround" title="Review round">'
                . htmlspecialchars($round) . '</span>';
        }
        return $t . unparse_preference_span($aset->prow($this->pid)->preference($this->cid, true));
    }
    private function icon($before) {
        return review_type_icon($this->item->get($before, "_rtype"),
                                !$this->item->get($before, "_rsubmitted"));
    }
    function unparse_display(AssignmentSet $aset) {
        $t = $aset->user->reviewer_html_for($this->contact);
        if ($this->item->deleted()) {
            $t = '<del>' . $t . '</del>';
        }
        if ($this->item->differs("_rtype") || $this->item->differs("_rsubmitted")) {
            if ($this->item->pre("_rtype")) {
                $t .= ' <del>' . $this->icon(true) . '</del>';
            }
            if ($this->item->post("_rtype")) {
                $t .= ' <ins>' . $this->icon(false) . '</ins>';
            }
        } else if ($this->item["_rtype"]) {
            $t .= ' ' . $this->icon(false);
        }
        if ($this->item->differs("_round")) {
            if (($round = $this->item->pre("_round"))) {
                $t .= ' <del><span class="revround" title="Review round">' . htmlspecialchars($round) . '</span></del>';
            }
            if (($round = $this->item->post("_round"))) {
                $t .= ' <ins><span class="revround" title="Review round">' . htmlspecialchars($round) . '</span></ins>';
            }
        } else if (($round = $this->item["_round"])) {
            $t .= ' <span class="revround" title="Review round">' . htmlspecialchars($round) . '</span>';
        }
        if (!$this->item->existed()) {
            $t .= unparse_preference_span($aset->prow($this->pid)->preference($this->cid, true));
        }
        return $t;
    }
    function unparse_csv(AssignmentSet $aset, AssignmentCsv $acsv) {
        $x = ["pid" => $this->pid, "action" => ReviewInfo::unparse_assigner_action($this->rtype),
              "email" => $this->contact->email, "name" => $this->contact->name()];
        if (($round = $this->item["_round"])) {
            $x["round"] = $this->item["_round"];
        }
        if ($this->token) {
            $x["review_token"] = encode_token($this->token);
        }
        $acsv->add($x);
        if ($this->unsubmit) {
            $acsv->add(["action" => "unsubmitreview", "pid" => $this->pid,
                        "email" => $this->contact->email, "name" => $this->contact->name()]);
        }
    }
    function account(AssignmentSet $aset, AssignmentCountSet $deltarev) {
        $aset->show_column("reviewers");
        if ($this->cid > 0) {
            $deltarev->has |= AssignmentCountSet::HAS_REVIEW;
            $ct = $deltarev->ensure($this->cid);
            ++$ct->ass;
            $oldtype = $this->item->pre("_rtype") ? : 0;
            $ct->rev += ($this->rtype != 0 ? 1 : 0) - ($oldtype != 0 ? 1 : 0);
            $ct->meta += ($this->rtype == REVIEW_META ? 1 : 0) - ($oldtype == REVIEW_META ? 1 : 0);
            $ct->pri += ($this->rtype == REVIEW_PRIMARY ? 1 : 0) - ($oldtype == REVIEW_PRIMARY ? 1 : 0);
            $ct->sec += ($this->rtype == REVIEW_SECONDARY ? 1 : 0) - ($oldtype == REVIEW_SECONDARY ? 1 : 0);
        }
    }
    function add_locks(AssignmentSet $aset, &$locks) {
        $locks["PaperReview"] = $locks["PaperReviewRefused"] = $locks["Settings"] = "write";
    }
    function execute(AssignmentSet $aset) {
        $extra = ["no_autosearch" => true];
        $round = $this->item->post("_round");
        if ($round !== null && $this->rtype) {
            $extra["round_number"] = (int) $aset->conf->round_number($round, true);
        }
        if ($this->contact->is_anonymous_user()
            && (!$this->item->existed() || $this->item->deleted())) {
            $extra["token"] = true;
            $aset->cleanup_callback("rev_token", function ($vals) use ($aset) {
                $aset->conf->update_rev_tokens_setting(min($vals));
            }, $this->item->existed() ? 0 : 1);
        }
        $reviewId = $aset->user->assign_review($this->pid, $this->cid, $this->rtype, $extra);
        if ($this->unsubmit && $reviewId) {
            assert($this->item->after !== null);
            /** @phan-suppress-next-line PhanUndeclaredMethod */
            $rrow = $this->item->after->make_reviewinfo($aset->conf, $reviewId);
            $aset->user->unsubmit_review_row($rrow, ["no_autosearch" => true]);
        }
        if (($extra["token"] ?? false) && $reviewId) {
            $this->token = $aset->conf->fetch_ivalue("select reviewToken from PaperReview where paperId=? and reviewId=?", $this->pid, $reviewId);
        }
    }
    function cleanup(AssignmentSet $aset) {
        if ($this->notify) {
            $reviewer = $aset->conf->user_by_id($this->cid);
            $prow = $aset->conf->paper_by_id($this->pid, $reviewer);
            HotCRPMailer::send_to($reviewer, $this->notify, [
                "prow" => $prow, "rrow" => $prow->fresh_review_by_user($this->cid),
                "requester_contact" => $aset->user, "reason" => $this->item["_reason"]
            ]);
        }
    }
}
