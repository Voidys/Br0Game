<?php

namespace App\Game\Admin\AccountEditor;

use App\Game\Core\Log;
use Symfony\Component\HttpFoundation\Response;

/** Account editor tab "researchs": edits the research levels of a player. */
class ResearchTab extends AccountEditorTab
{
    /** Shows the form, or applies the posted changes (logged) and redirects with a message. */
    public function handle(): Response
    {
        $LNG = $this->state->LNG;
        $e = $this->elements();
        $db = $this->db;
        if ($this->state->requestPost) {
            $id = $this->input()->int('id');
            foreach ($e->ids('tech') as $ID) {
                $fields[] = "`" . $e->column($ID) . "`";
            }
            // the columns come from the game's resource table
            $beforeResearch = $db->fetchRow(
                $db->qb()
                    ->select(...$fields)
                    ->from($db->table('USERS'))
                    ->where('`id` = :userID')
                    ->setParameter('userID', $id),
            );
            $afterResearch = [];
            if (isset($this->state->requestPost['add'])) {
                $QryUpdate = [];
                foreach ($e->ids('tech') as $ID) {
                    $count = max(0, round($this->input()->float($e->column($ID))));
                    if ($count > 0) {
                        $QryUpdate[$e->column($ID)] = $count;
                        $afterResearch[$ID] = $beforeResearch[$e->column($ID)] + $count;
                    }
                }
                if (!empty($QryUpdate)) {
                    $db->updateRows('USERS', [], ['id' => $id, 'universe' => $this->universe()->getEmulated()], [], $QryUpdate);
                }
            } elseif (isset($this->state->requestPost['delete'])) {
                $QryUpdate = [];
                foreach ($e->ids('tech') as $ID) {
                    $count = max(0, round($this->input()->float($e->column($ID))));
                    if ($count > 0) {
                        $QryUpdate[$e->column($ID)] = -$count;
                        $afterResearch[$ID] = max(0, $beforeResearch[$e->column($ID)] - $count);
                    }
                }
                if (!empty($QryUpdate)) {
                    $db->updateRows('USERS', [], ['id' => $id, 'universe' => $this->universe()->getEmulated()], [], $QryUpdate);
                }
            }

            if (!empty($QryUpdate)) {
                $LOG = new Log($this->db, $this->configs, $this->state, 1);
                $LOG->target = $id;
                $LOG->universe = $this->universe()->getEmulated();
                $LOG->old = $beforeResearch;
                $LOG->new = $afterResearch;
                $LOG->save();
            }
            if (isset($this->state->requestPost['add'])) {
                $this->responder->message($LNG['ad_add_tech_success'], 'admin/accounts/edit/researchs');
            } elseif (isset($this->state->requestPost['delete'])) {
                $this->responder->message($LNG['ad_delete_tech_success'], 'admin/accounts/edit/researchs');
            }
        }

        foreach ($e->ids('tech') as $ID) {
            $INPUT[$ID] = ['type' => $e->column($ID)];
        }

        $this->responder->assign([
            'inputlist' => $INPUT,
        ]);

        return $this->responder->render('admin/AccountEditorPageResearch.html.twig');
    }
}
