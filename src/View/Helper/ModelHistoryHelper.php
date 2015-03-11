<?php
namespace ModelHistory\View\Helper;

use Cake\Datasource\EntityInterface;
use Cake\Utility\Hash;
use Cake\View\Helper;
use Cake\View\View;
use ModelHistory\Model\Entity\ModelHistory;

/**
 * ContactPersons helper
 */
class ModelHistoryHelper extends Helper
{

    public $helpers = ['Html'];

    /**
     * Render an Contact Perosns area for the given entity
     * @return contact_persons_area View
     */
    public function modelHistoryArea($entity)
    {
        $entity = [
            'id' => $entity->id,
            'repository' => $entity->source()
        ];
        return $this->_View->element('ModelHistory.model_history_area', array('data' => $entity));
    }

    /**
     * Returns the text displayed in the widget
     *
     * @return string
     */
    public function historyText($history)
    {
        $action = '';
        switch ($history['action']) {
            case ModelHistory::ACTION_CREATE:
                $action = __d('model_history', 'model_history');
                break;
            case ModelHistory::ACTION_UPDATE:
                $action = __d('model_history', 'updated');
                break;
            case ModelHistory::ACTION_DELETE:
                $action = __d('model_history', 'deleted');
                break;
            case ModelHistory::ACTION_COMMENT:
                $action = __d('model_history', 'commented');
                break;
        }
        return ucfirst($action) . ' ' . __d('model_history', 'by') . ' ' . $history->user->full_name . ' ' . __d('model_history', 'on') . ' ' . $history->created;
    }
}