<?php
// paperapi.php -- HotCRP paper-related API calls
// HotCRP is Copyright (c) 2008-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class PaperApi {
    static function setdecision_api($user, $qreq, $prow) {
        global $Conf;
        if (!$user->can_set_decision($prow))
            json_exit(["ok" => false, "error" => "You can’t set the decision for paper #$prow->paperId."]);
        $dnum = cvtint($qreq->decision);
        $decs = $Conf->decision_map();
        if (!isset($decs[$dnum]))
            json_exit(["ok" => false, "error" => "Bad decision value."]);
        $result = Dbl::qe_raw("update Paper set outcome=$dnum where paperId=$prow->paperId");
        if ($result && ($dnum > 0 || $prow->outcome > 0))
            $Conf->update_paperacc_setting($dnum > 0);
        Dbl::free($result);
        if ($result)
            json_exit(["ok" => true, "result" => htmlspecialchars($decs[$dnum])]);
        else
            json_exit(["ok" => false]);
    }

    private static function set_paper_pc($prow, $value, $contact, $ajax, $type) {
        global $Conf, $Error, $Me, $OK;

        // canonicalize $value
        if ($value === "0" || $value === 0 || $value === "none")
            $pc = 0;
        else if (is_string($value))
            $pc = pcByEmail($value);
        else if (is_object($value) && ($value instanceof Contact))
            $pc = $value;
        else
            $pc = null;

        if ($type == "manager" ? !$contact->privChair : !$contact->can_administer($prow)) {
            Conf::msg_error("You don’t have permission to set the $type.");
            $Error[$type] = true;
        } else if ($pc === 0
                   || ($pc && $pc->isPC && $pc->can_accept_review_assignment($prow))) {
            $contact->assign_paper_pc($prow, $type, $pc);
            if ($OK && $ajax)
                $Conf->confirmMsg("Saved");
        } else if ($pc) {
            Conf::msg_error(Text::user_html($pc) . " can’t be the $type for paper #" . $prow->paperId . ".");
            $Error[$type] = true;
        } else {
            Conf::msg_error("Bad $type setting “" . htmlspecialchars($value) . "”.");
            $Error[$type] = true;
        }

        if ($ajax) {
            $result = ["ok" => $OK && !@$Error[$type], "result" => $OK && $pc ? $pc->name_html() : "None"];
            if ($Me->can_view_reviewer_tags($prow)) {
                $tagger = new Tagger;
                $result["color_classes"] = $pc ? $tagger->viewable_color_classes($pc->contactTags) : "";
            }
            $Conf->ajaxExit($result);
        }
        return $OK && !@$Error[$type];
    }

    static function set_lead($prow, $value, $contact, $ajax = false) {
        return self::set_paper_pc($prow, $value, $contact, $ajax, "lead");
    }

    static function set_shepherd($prow, $value, $contact, $ajax = false) {
        return self::set_paper_pc($prow, $value, $contact, $ajax, "shepherd");
    }

    static function set_manager($prow, $value, $contact, $ajax = false) {
        return self::set_paper_pc($prow, $value, $contact, $ajax, "manager");
    }

    static function tagreport($user, $prow) {
        if (!$user->can_view_tags($prow))
            return (object) ["ok" => false];
        $ret = (object) ["ok" => true, "warnings" => [], "messages" => []];
        if (($vt = TagInfo::vote_tags())) {
            $myprefix = $user->contactId . "~";
            $qv = $myvotes = array();
            foreach ($vt as $tag => $v) {
                $qv[] = $myprefix . $tag;
                $myvotes[strtolower($tag)] = 0;
            }
            $result = Dbl::qe("select tag, sum(tagIndex) from PaperTag where tag ?a group by tag", $qv);
            while (($row = edb_row($result))) {
                $lbase = strtolower(substr($row[0], strlen($myprefix)));
                $myvotes[$lbase] += +$row[1];
            }
            Dbl::free($result);
            $vlo = $vhi = array();
            foreach ($vt as $tag => $vlim) {
                $lbase = strtolower($tag);
                if ($myvotes[$lbase] < $vlim)
                    $vlo[] = '<a class="q" href="' . hoturl("search", "q=editsort:-%23~$tag") . '">~' . $tag . '</a>#' . ($vlim - $myvotes[$lbase]);
                else if ($myvotes[$lbase] > $vlim
                         && (!$prow || $prow->has_tag($myprefix . $tag)))
                    $vhi[] = '<span class="nw"><a class="q" href="' . hoturl("search", "q=sort:-%23~$tag+edit:%23~$tag") . '">~' . $tag . '</a> (' . ($myvotes[$lbase] - $vlim) . " over)</span>";
            }
            if (count($vlo))
                $ret->messages[] = 'Remaining <a class="q" href="' . hoturl("help", "t=votetags") . '">votes</a>: ' . join(", ", $vlo);
            if (count($vhi))
                $ret->warnings[] = 'Overallocated <a class="q" href="' . hoturl("help", "t=votetags") . '">votes</a>: ' . join(", ", $vhi);
        }
        return $ret;
    }

    static function tagreport_api($user, $qreq, $prow) {
        global $Conf;
        $treport = self::tagreport($user, $prow);
        $response = "";
        if (count($treport->warnings))
            $response .= Ht::xmsg("warning", join("<br>", $treport->warnings));
        if (count($treport->messages))
            $response .= Ht::xmsg("info", join("<br>", $treport->messages));
        json_exit(["ok" => $treport->ok, "response" => $response], true);
    }

    static function settags_api($user, $qreq, $prow) {
        global $Conf;
        if ($qreq->cancelsettags)
            json_exit(["ok" => true]);

        // save tags using assigner
        $x = array("paper,tag");
        if (isset($qreq->tags)) {
            $x[] = "$prow->paperId,all#clear";
            foreach (TagInfo::split($qreq->tags) as $t)
                $x[] = "$prow->paperId," . CsvGenerator::quote($t);
        }
        foreach (TagInfo::split((string) $qreq->addtags) as $t)
            $x[] = "$prow->paperId," . CsvGenerator::quote($t);
        foreach (TagInfo::split((string) $qreq->deltags) as $t)
            $x[] = "$prow->paperId," . CsvGenerator::quote($t . "#clear");
        $assigner = new AssignmentSet($user, $user->is_admin_force());
        $assigner->parse(join("\n", $x));
        $error = join("<br>", $assigner->errors_html());
        $ok = $assigner->execute();

        // exit
        $prow->load_tags();
        if ($ok) {
            $treport = self::tagreport($user, $prow);
            if ($treport->warnings)
                $Conf->warnMsg(join("<br>", $treport->warnings));
            $taginfo = $prow->tag_info_json($user);
            $taginfo->ok = true;
            json_exit($taginfo, true);
        } else
            json_exit(["ok" => false, "error" => $error], true);
    }

    static function alltags_api($user, $qreq, $prow) {
        global $Conf;
        if (!$user->isPC)
            json_exit(["ok" => false]);

        $need_paper = $conflict_where = false;
        $where = $args = array();

        if ($user->allow_administer(null)) {
            $need_paper = true;
            if ($Conf->has_any_manager() && !$Conf->setting("tag_seeall"))
                $conflict_where = "(p.managerContactId=0 or p.managerContactId=$user->contactId or pc.conflictType is null)";
        } else if ($Conf->check_track_sensitivity("view")) {
            $where[] = "t.paperId ?a";
            $args[] = $user->list_submitted_papers_with_viewable_tags();
        } else {
            $need_paper = true;
            if ($Conf->has_any_manager() && !$Conf->setting("tag_seeall"))
                $conflict_where = "(p.managerContactId=$user->contactId or pc.conflictType is null)";
            else if (!$Conf->setting("tag_seeall"))
                $conflict_where = "pc.conflictType is null";
        }

        $q = "select distinct tag from PaperTag t";
        if ($need_paper) {
            $q .= " join Paper p on (p.paperId=t.paperId)";
            $where[] = "p.timeSubmitted>0";
        }
        if ($conflict_where) {
            $q .= " left join PaperConflict pc on (pc.paperId=t.paperId and pc.contactId=$user->contactId)";
            $where[] = $conflict_where;
        }
        $q .= " where " . join(" and ", $where);

        $tags = array();
        $result = Dbl::qe_apply($q, $args);
        while (($row = edb_row($result))) {
            $twiddle = strpos($row[0], "~");
            if ($twiddle === false
                || ($twiddle == 0 && $row[0][1] === "~" && $user->privChair))
                $tags[] = $row[0];
            else if ($twiddle > 0 && substr($row[0], 0, $twiddle) == $user->contactId)
                $tags[] = substr($row[0], $twiddle);
        }
        Dbl::free($result);
        json_exit(["ok" => true, "tags" => $tags]);
    }
}
