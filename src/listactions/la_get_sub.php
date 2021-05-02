<?php
// listactions/la_get_sub.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class GetCheckFormat_ListAction extends ListAction {
    function run(Contact $user, Qrequest $qreq, SearchSelection $ssel) {
        $papers = [];
        foreach ($ssel->paper_set($user) as $prow) {
            if ($user->can_view_pdf($prow))
                $papers[$prow->paperId] = $prow;
        }
        $csvg = $user->conf->make_csvg("formatcheck")->select(["paper", "title", "pages", "format", "messages"]);
        $csvg->export_headers();
        header("Content-Type: " . $csvg->mimetype_with_charset());
        echo $csvg->unparse();
        $cf = new CheckFormat($user->conf, CheckFormat::RUN_IF_NECESSARY);
        foreach ($papers as $prow) {
            $dtype = $prow->finalPaperStorageId ? DTYPE_FINAL : DTYPE_SUBMISSION;
            $doc = $prow->document($dtype, 0, true);
            if ($doc && $doc->mimetype === "application/pdf") {
                $cf->check_document($prow, $doc);
                $pages = $cf->npages ?? "?";
                $errf = $cf->problem_fields();
                if (empty($errf)) {
                    $format = "ok";
                    $messages = "";
                } else {
                    $format = join(" ", $errf);
                    $messages = join("\n", $cf->message_texts());
                }
            } else {
                $pages = "";
                $format = "notpdf";
                $messages = "";
            }
            echo $prow->paperId, ",", CsvGenerator::quote($prow->title), ",", $pages, ",", CsvGenerator::quote($format), ",", CsvGenerator::quote($messages), "\n";
            ob_flush();
            flush();
        }
        exit;
    }
}

class GetPcconflicts_ListAction extends ListAction {
    function allow(Contact $user, Qrequest $qreq) {
        return $user->is_manager();
    }
    function run(Contact $user, Qrequest $qreq, SearchSelection $ssel) {
        $confset = $user->conf->conflict_types();
        $pcm = $user->conf->pc_members();
        $csvg = $user->conf->make_csvg("pcconflicts")
            ->select(["paper", "title", "first", "last", "email", "conflicttype"]);
        $old_overrides = $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        foreach ($ssel->paper_set($user, ["allConflictType" => 1]) as $prow) {
            if ($user->can_view_conflicts($prow)) {
                $m = [];
                foreach ($prow->conflicts() as $cid => $cflt) {
                    if (($pc = $pcm[$cid] ?? null) && $cflt->is_conflicted()) {
                        $m[$pc->sort_position] = [$prow->paperId, $prow->title, $pc->firstName, $pc->lastName, $pc->email, $confset->unparse_text($cflt->conflictType)];
                    }
                }
                if ($m) {
                    ksort($m);
                    $csvg->append(array_values($m));
                }
            }
        }
        $user->set_overrides($old_overrides);
        return $csvg;
    }
}

class GetTopics_ListAction extends ListAction {
    function run(Contact $user, Qrequest $qreq, SearchSelection $ssel) {
        $texts = [];
        foreach ($ssel->paper_set($user, ["topics" => 1]) as $row) {
            if ($user->can_view_paper($row)) {
                $n = count($texts);
                foreach ($row->topic_map() as $t) {
                    $texts[] = [$row->paperId, $row->title, $t];
                }
                if (count($texts) === $n) {
                    $texts[] = [$row->paperId, $row->title, "<none>"];
                }
            }
        }
        return $user->conf->make_csvg("topics")
            ->select(["paper", "title", "topic"])
            ->append($texts);
    }
}

class GetCSV_ListAction extends ListAction {
    function run(Contact $user, Qrequest $qreq, SearchSelection $ssel) {
        $search = new PaperSearch($user, $qreq);
        $search->restrict_match([$ssel, "is_selected"]);
        assert(!isset($qreq->display));
        $pl = new PaperList("pl", $search, ["sort" => true], $qreq);
        $pl->apply_view_report_default();
        $pl->apply_view_session();
        $pl->set_view("sel", false);
        list($header, $data) = $pl->text_csv();
        return $user->conf->make_csvg("data", CsvGenerator::FLAG_ITEM_COMMENTS)
            ->select($header)->append($data);
    }
}
