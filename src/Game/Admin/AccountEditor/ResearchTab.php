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
        $reslist = $this->state->reslist;
        $resource = $this->state->resource;
        $db = $this->db;
        if ($this->state->requestPost) {
            $id = $this->http()->_GP('id', 0);
            foreach ($reslist['tech'] as $ID) {
                $fields[] = "`" . $resource[$ID] . "`";
            }
            // the columns come from the game's resource table
            $beforeResearch = $db->fetchRow(
                $db->qb()
                    ->select(...$fields)
                    ->from($db->table('USERS'))
                    ->where('`id` = :userID')
                    ->setParameter('userID', $id)
            );
            $afterResearch = [];
            if (isset($this->state->requestPost['add'])) {
                $QryUpdate = [];
                foreach ($reslist['tech'] as $ID) {
                    $count = max(0, round($this->http()->_GP($resource[$ID], 0.0)));
                    if ($count > 0) {
                        $QryUpdate[$resource[$ID]] = $count;
                        $afterResearch[$ID] = $beforeResearch[$resource[$ID]] + $count;
                    }
                }
                if (!empty($QryUpdate)) {
                    $db->updateRows('USERS', [], ['id' => $id, 'universe' => $this->universe()->getEmulated()], [], $QryUpdate);
                }
            } elseif (isset($this->state->requestPost['delete'])) {
                $QryUpdate = [];
                foreach ($reslist['tech'] as $ID) {
                    $count = max(0, round($this->http()->_GP($resource[$ID], 0.0)));
                    if ($count > 0) {
                        $QryUpdate[$resource[$ID]] = -$count;
                        $afterResearch[$ID] = max(0, $beforeResearch[$resource[$ID]] - $count);
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
                $this->responder->message($LNG['ad_add_tech_success'], '?page=accountEditor&edit=researchs');
            } elseif (isset($this->state->requestPost['delete'])) {
                $this->responder->message($LNG['ad_delete_tech_success'], '?page=accountEditor&edit=researchs');
            }
        }

        foreach ($reslist['tech'] as $ID) {
            $INPUT[$ID] = ['type' => $resource[$ID]];
        }

        $this->responder->assign([
            'inputlist' => $INPUT,
        ]);

        return $this->responder->render('admin/AccountEditorPageResearch.html.twig');
    }
}
