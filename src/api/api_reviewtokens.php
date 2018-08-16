<?php
// api_reviewtokens.php -- HotCRP tag completion API call
// Copyright (c) 2008-2018 Eddie Kohler; see LICENSE.

class ReviewTokens_API {
    static function run(Contact $user, $qreq, $prow) {
        if ($qreq->method() === "POST"
            && isset($qreq->reviewtokens)) {
            $tokens = $new_tokens = $bad_tokens = $result = [];
            foreach (preg_split('/\s+/', $qreq->review_tokens) as $x) {
                if ($x === "")
                    /* skip */;
                else if (($token = decode_token($x, "V"))
                         && ($pid = $user->conf->fetch_ivalue("select paperId from PaperReview where reviewToken=?", $token))) {
                    if (in_array($token, $user->review_tokens()))
                        $tokens[] = $token;
                    else {
                        $new_tokens[] = $token;
                        $result[] = "Review token “" . htmlspecialchars($x) . "” lets you review " . Ht::link("paper #$pid", $user->conf->hoturl("paper", "p=$pid")) . ".";
                    }
                } else
                    $bad_tokens[] = $x;
            }
            if (($fail = $user->active_session("rev_token_fail", 0) + count($bad_tokens)))
                $user->save_active_session("rev_token_fail", $fail);
            if ($fail >= 5 && (!empty($bad_tokens) || !empty($new_tokens)))
                json_exit(400, "Too many invalid review tokens; you must sign out and try again.");
            $tokens = array_merge($tokens, $new_tokens);
            foreach ($bad_tokens as $x)
                $result[] = "Invalid token “" . htmlspecialchars($x) . "”.";
            $user->save_review_tokens($tokens);
            $result[] = empty($tokens) ? "Review tokens cleared." : "Review tokens saved.";
            return ["ok" => true, "result" => join("<br>", $result), "review_tokens" => $tokens];
        }
        return ["ok" => true, "review_tokens" => $user->review_tokens()];
    }
}
