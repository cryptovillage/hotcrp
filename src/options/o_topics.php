<?php
// o_topics.php -- HotCRP helper class for topics intrinsic
// Copyright (c) 2006-2021 Eddie Kohler; see LICENSE.

class Topics_PaperOption extends PaperOption {
    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
        $this->set_exists_if(!!$this->conf->setting("has_topics"));
    }
    function value_force(PaperValue $ov) {
        $vs = $ov->prow->topic_list();
        $ov->set_value_data($vs, array_fill(0, count($vs), null));
    }
    function value_unparse_json(PaperValue $ov, PaperStatus $ps) {
        $vs = $ov->value_list();
        if (!empty($vs) && !$ps->export_ids()) {
            $tmap = $ps->conf->topic_set();
            $vs = array_map(function ($t) use ($tmap) { return $tmap[$t]; }, $vs);
        }
        return $vs;
    }
    function value_store(PaperValue $ov, PaperStatus $ps) {
        $vs = $ov->value_list();
        $bad_topics = $ov->anno("bad_topics");
        $new_topics = $ov->anno("new_topics");
        '@phan-var ?list<string> $new_topics';
        if ($ps->add_topics() && !empty($new_topics)) {
            // add new topics to topic list
            $lctopics = [];
            foreach ($new_topics as $tk) {
                if (!in_array(strtolower($tk), $lctopics)) {
                    $lctopics[] = strtolower($tk);
                    $result = $ps->conf->qe("insert into TopicArea set topicName=?", $tk);
                    $vs[] = $result->insert_id;
                }
            }
            if (!$this->conf->has_topics()) {
                $this->conf->save_setting("has_topics", 1);
            }
            $this->conf->invalidate_topics();
            $bad_topics = array_diff($bad_topics, $new_topics);
        }
        $this->conf->topic_set()->sort($vs);
        $ov->set_value_data($vs, array_fill(0, count($vs), null));
        if (!empty($bad_topics)) {
            $ov->warning($ps->_("Unknown topics ignored (%2\$s).", count($bad_topics), htmlspecialchars(join("; ", $bad_topics))));
        }
    }
    function value_save(PaperValue $ov, PaperStatus $ps) {
        $ps->mark_diff("topics");
        $ps->_topic_ins = $ov->value_list();
        return true;
    }
    function parse_web(PaperInfo $prow, Qrequest $qreq) {
        $vs = [];
        foreach ($prow->conf->topic_set() as $tid => $tname) {
            if (+$qreq["top$tid"] > 0) {
                $vs[] = $tid;
            }
        }
        return PaperValue::make_multi($prow, $this, $vs, array_fill(0, count($vs), null));
    }
    function parse_json(PaperInfo $prow, $j) {
        $bad = false;
        if (is_object($j) || is_associative_array($j)) {
            $j = array_keys(array_filter((array) $j, function ($x) use (&$bad) {
                if ($x !== null && $x !== false && $x !== true) {
                    $bad = true;
                }
                return $x === true;
            }));
        } else if ($j === false) {
            $j = [];
        }
        if (!is_array($j) || $bad) {
            return PaperValue::make_estop($prow, $this, "Validation error.");
        }

        $topicset = $prow->conf->topic_set();
        $vs = $bad_topics = $new_topics = [];
        foreach ($j as $tk) {
            if (is_int($tk)) {
                if (isset($topicset[$tk])) {
                    $vs[] = $tk;
                } else {
                    $bad_topics[] = $tk;
                }
            } else if (!is_string($tk)) {
                return PaperValue::make_estop($prow, $this, "Validation error.");
            } else if (($tk = trim($tk)) !== "") {
                $tid = array_search($tk, $topicset->as_array(), true);
                if ($tid !== false) {
                    $vs[] = $tid;
                } else if (!ctype_digit($tk)) {
                    $tids = [];
                    foreach ($topicset as $xtid => $tname) {
                        if (strcasecmp($tk, $tname) == 0)
                            $tids[] = $xtid;
                    }
                    if (count($tids) === 1) {
                        $vs[] = $tids[0];
                    } else {
                        $bad_topics[] = $tk;
                        if (empty($tids)) {
                            $new_topics[] = $tk;
                        }
                    }
                }
            }
        }

        $ov = PaperValue::make_multi($prow, $this, $vs, array_fill(0, count($vs), null));
        $ov->anno["bad_topics"] = $bad_topics;
        $ov->anno["new_topics"] = $new_topics;
        return $ov;
    }
    function echo_web_edit(PaperTable $pt, $ov, $reqov) {
        $pt->echo_editable_option_papt($this, null, ["id" => "topics"]);
        echo '<div class="papev"><ul class="ctable">';
        $ptopics = $pt->prow->topic_map();
        $topics = $this->conf->topic_set();
        $readonly = !$this->test_editable($ov->prow);
        foreach ($topics->group_list() as $tg) {
            $arg = ["class" => "uic js-range-click topic-entry", "id" => false,
                    "data-range-type" => "topic", "disabled" => $readonly];
            if (($isgroup = $tg->nontrivial())) {
                echo '<li class="ctelt cteltg"><div class="ctelti">';
                if ($tg->improper()) {
                    $arg["data-default-checked"] = isset($ptopics[$tg->tid]);
                    $checked = in_array($tg->tid, $reqov->value_list());
                    echo '<label class="checki cteltx"><span class="checkc">',
                        Ht::checkbox("top{$tg->tid}", 1, $checked, $arg),
                        '</span>', $topics->unparse_name_html($tg->tid), '</label>';
                } else {
                    echo '<div class="cteltx"><span class="topicg">',
                        htmlspecialchars($tg->name), '</span></div>';
                }
                echo '<div class="checki">';
            }
            foreach ($tg->proper_members() as $tid) {
                if ($isgroup) {
                    $tname = $topics->unparse_subtopic_name_html($tid);
                } else {
                    $tname = $topics->unparse_name_html($tid);
                }
                $arg["data-default-checked"] = isset($ptopics[$tid]);
                $checked = in_array($tid, $reqov->value_list());
                echo ($isgroup ? '<label class="checki cteltx">' : '<li class="ctelt"><label class="checki ctelti">'),
                    '<span class="checkc">',
                    Ht::checkbox("top$tid", 1, $checked, $arg),
                    '</span>', $tname, '</label>',
                    ($isgroup ? '' : '</li>');
            }
            if ($isgroup) {
                echo '</div></div></li>';
            }
        }
        echo "</ul></div></div>\n\n";
    }
    function render(FieldRender $fr, PaperValue $ov) {
        $vs = $ov->value_list();
        if (!empty($vs)) {
            $user = $fr->user;
            $interests = $user ? $user->topic_interest_map() : [];
            $lenclass = count($vs) < 4 ? "long" : "short";
            $topics = $this->conf->topic_set();
            $ts = [];
            foreach ($vs as $tid) {
                $t = '<li class="topicti';
                if ($interests) {
                    $t .= ' topic' . ($interests[$tid] ?? 0);
                }
                $tname = $topics->name($tid);
                $x = $topics->unparse_name_html($tid);
                if ($user && $user->isPC) {
                    $x = Ht::link($x, $this->conf->hoturl("search", ["q" => "topic:" . SearchWord::quote($tname)]), ["class" => "qq"]);
                }
                $ts[] = $t . '">' . $x . '</li>';
                $lenclass = TopicSet::max_topici_lenclass($lenclass, $tname);
            }
            $fr->title = $this->title(count($ts));
            $fr->set_html('<ul class="topict topict-' . $lenclass . '">' . join("", $ts) . '</ul>');
            $fr->value_long = true;
        }
    }
}
