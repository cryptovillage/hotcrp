<?php
// api_user.php -- HotCRP user-related API calls
// Copyright (c) 2008-2020 Eddie Kohler; see LICENSE.

class User_API {
    static function whoami(Contact $user, Qrequest $qreq) {
        return ["ok" => true, "email" => $user->email];
    }

    static function user(Contact $user, Qrequest $qreq, PaperInfo $prow = null) {
        if (!$user->can_lookup_user()) {
            return new JsonResult(403, "Permission error.");
        }
        if (!($email = trim($qreq->email))) {
            return new JsonResult(400, "Parameter error.");
        }

        $users = [];
        if ($user->privChair || $user->can_view_pc()) {
            $roles = $user->is_manager() ? "" : " and roles!=0 and (roles&" . Contact::ROLE_PC . ")!=0";
            $result = $user->conf->qe("select contactId, email, firstName, lastName, affiliation, collaborators from ContactInfo where email>=? and email<? and not disabled$roles order by email asc limit 2", $email, $email . "~");
            while (($u = Contact::fetch($result, $user->conf))) {
                $users[] = $u;
            }
            Dbl::free($result);
        }

        if ((empty($users) || strcasecmp($users[0]->email, $email) !== 0)
            && $user->conf->opt("allowLookupUser")) {
            if (($db = $user->conf->contactdb())) {
                $idk = "contactDbId";
            } else {
                $db = $user->conf->dblink;
                $idk = "contactId";
            }
            $result = Dbl::qe($db, "select $idk, email, firstName, lastName, affiliation, collaborators from ContactInfo where email>=? and email<? and not disabled order by email asc limit 2", $email, $email . "~");
            $users = [];
            while (($u = Contact::fetch($result, $user->conf))) {
                $users[] = $u;
            }
            Dbl::free($result);
        }

        if (empty($users)
            && strcasecmp($user->email, $email) >= 0
            && strcasecmp($user->email, $email . "~") < 0) {
            $users[] = $user;
        }

        if (empty($users)) {
            return new JsonResult(404, ["ok" => false, "user_error" => true]);
        } else {
            $u = $users[0];
            $ok = strcasecmp($u->email, $email) === 0;
            $rj = ["ok" => $ok, "email" => $u->email, "firstName" => $u->firstName, "lastName" => $u->lastName, "affiliation" => $u->affiliation];
            if ($prow
                && $user->allow_view_authors($prow)
                && $qreq->potential_conflict
                && ($pc = $prow->potential_conflict_html($u))) {
                $rj["potential_conflict"] = PaperInfo::potential_conflict_tooltip_html($pc);
            }
            if (!$ok) {
                $rj["user_error"] = true;
            }
            return new JsonResult($ok ? 200 : 404, $rj);
        }
    }

    static function clickthrough(Contact $user, Qrequest $qreq) {
        if ($qreq->accept
            && $qreq->clickthrough_id
            && ($hash = Filer::sha1_hash_as_text($qreq->clickthrough_id))) {
            if ($user->has_email()) {
                $dest_user = $user;
            } else if ($qreq->p
                       && ctype_digit($qreq->p)
                       && ($ru = $user->reviewer_capability_user(intval($qreq->p)))) {
                $dest_user = $ru;
            } else {
                return new JsonResult(400, "No such user.");
            }
            $dest_user->ensure_account_here();
            $dest_user->merge_and_save_data(["clickthrough" => [$hash => Conf::$now]]);
            $user->log_activity_for($dest_user, "Terms agreed " . substr($hash, 0, 10) . "...");
            return ["ok" => true];
        } else if ($qreq->clickthrough_accept) {
            return new JsonResult(400, "Parameter error.");
        } else {
            return ["ok" => false];
        }
    }

    static function account_disable(Contact $user, Contact $viewer, $disabled) {
        if (!$viewer->privChair) {
            return new JsonResult(403, "Permission error.");
        } else if ($viewer->contactId === $user->contactId) {
            return new JsonResult(400, ["ok" => false, "error" => "You cannot disable your own account."]);
        } else {
            $ustatus = new UserStatus($viewer);
            $ustatus->set_user($user);
            if ($ustatus->save((object) ["disabled" => $disabled], $user)) {
                return new JsonResult(["ok" => true, "u" => $user->email, "disabled" => $user->disabled]);
            } else {
                return new JsonResult(400, ["ok" => false, "u" => $user->email]);
            }
        }
    }

    static function account_sendinfo(Contact $user, Contact $viewer) {
        if (!$viewer->privChair) {
            return new JsonResult(403, "Permission error.");
        } else if (!$user->is_disabled()) {
            $user->send_mail("@accountinfo");
            return new JsonResult(["ok" => true, "u" => $user->email]);
        } else {
            return new JsonResult(["ok" => false, "u" => $user->email, "error" => "User disabled.", "user_error" => true]);
        }
    }

    static function account(Contact $viewer, Qrequest $qreq) {
        if (!isset($qreq->u) || $qreq->u === "me" || strcasecmp($qreq->u, $viewer->email) === 0) {
            $user = $viewer;
        } else if ($viewer->isPC) {
            $user = $viewer->conf->user_by_email($qreq->u);
        } else {
            return new JsonResult(403, "Permission error.");
        }
        if (!$user) {
            return new JsonResult(404, ["ok" => false, "error" => "No such user."]);
        }
        if ($qreq->valid_post() && ($qreq->disable || $qreq->enable)) {
            return self::account_disable($user, $viewer, !!$qreq->disable);
        } else if ($qreq->valid_post() && $qreq->sendinfo) {
            return self::account_sendinfo($user, $viewer);
        } else {
            return new JsonResult(200, ["ok" => true]);
        }
    }
}
